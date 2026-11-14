<?php
// ============================================================
// backend/lib/ImmaginiGazzetta.php
//
// Libreria immagini della Gazzetta (tabella NEW_IMMAGINI_GAZZETTA):
//   - elaborazione dei file caricati (controllo, orientamento EXIF,
//     ridimensionamento, conversione in JPEG, miniatura) con GD;
//   - descrizione automatica con visione (opzione C): una chiamata a
//     un modello Anthropic per ogni immagine, che propone descrizione,
//     tag e tono. L'opzione B (descrizione scritta a mano) non usa l'AI.
//
// Richiede connect.php, lib/AnthropicClient.php e lib/ArticoloGiornata.php
// già inclusi dal chiamante.
//
// Parametri opzionali nella sezione "immagini" di config_ai.php:
//   max_lato (1400)          lato lungo massimo dell'immagine salvata, in px
//   max_byte (650000)        peso massimo dell'immagine salvata
//   max_lato_ai (1000)       lato lungo dell'immagine inviata all'AI (più è
//                            piccola, meno token costa)
//   max_pixel (25000000)     superficie massima accettata in ingresso
//   max_totale (5000)        numero massimo di immagini in libreria
//   token_input_stimati (1500), token_output_stimati (220)
//                            per la stima del costo di una descrizione AI
//   max_token_descrizione (500)  tetto di output della descrizione AI
//   timeout (60)             timeout in secondi della chiamata di visione
//
// Funzioni principali:
//   img_cfg($chiave, $default)
//   img_gd_disponibile()
//   img_elabora($percorso)           → jpeg + miniatura pronti per il DB
//   img_ridimensiona_bytes($jpeg, $lato)
//   img_descrivi_ai($modello, $jpeg) → descrizione, tag, tono, token, costo
//   img_costo_stimato_usd($modello)  costo previsto di una descrizione AI
//   img_riga_api($riga, $conMiniatura) normalizza una riga per le risposte JSON
//   img_conta_usi($conn, $id)        in quanti articoli è usata
//
// Ritratti dei giocatori (Script DB/migrazione_immagini_giocatori.sql):
//   img_parse_nome_file($nome)       "alex-meret-napoli-2026-27-portrait-footylogos" → nome+squadra, stagione, tipo
//   img_abbina_giocatore($conn, ...) abbina il file a NEW_GIOCATORI_BASE_ASTA (stagione, squadra, cognome)
//   img_metadati_giocatore(...)      descrizione, tag e tono automatici (nessun costo AI)
//   img_riabbina($conn)              ripete l'abbinamento per i ritratti non ancora abbinati
// ============================================================

/** Numero massimo di immagini in libreria se config_ai.php non indica "max_totale". */
const IMG_MAX_TOTALE_DEFAULT = 5000;

/** Colonne aggiunte da Script DB/migrazione_immagini_giocatori.sql. */
const IMG_CAMPI_GIOCATORE = "tipo, id_giocatore, stagione_giocatore, nome_giocatore, squadra_giocatore, stato_abbinamento";

/** Elenco colonne (senza i dati binari) da leggere per le risposte JSON. */
const IMG_CAMPI = "id, nome_file, mime, larghezza, altezza, descrizione, tag, tono, utenze,
                   origine_descrizione, modello, token_input, token_output, costo_usd,
                   data_descrizione, attivo, ultimo_utilizzo, caricata_da, data_caricamento";

/** Toni ammessi per una descrizione. */
const IMG_TONI = ["comico", "epico", "drammatico", "malinconico", "festoso", "neutro"];

/** Parametro della sezione "immagini" di config_ai.php (con valore di default). */
function img_cfg(string $chiave, $default)
{
    $cfg = ai_config();
    return $cfg["immagini"][$chiave] ?? $default;
}

/** Numero massimo di immagini ammesse in libreria. */
function img_max_totale(): int
{
    return max(1, (int) img_cfg("max_totale", IMG_MAX_TOTALE_DEFAULT));
}

/** Lo script Script DB/migrazione_immagini_giocatori.sql è stato eseguito? */
function img_giocatori_presenti($conn): bool
{
    return art_colonna_presente($conn, "NEW_IMMAGINI_GAZZETTA", "stato_abbinamento");
}

/** Colonne (senza dati binari) da leggere: quelle dei ritratti solo se la migrazione c'è. */
function img_campi($conn): string
{
    return IMG_CAMPI . (img_giocatori_presenti($conn) ? ", " . IMG_CAMPI_GIOCATORE : "");
}

/** L'estensione GD di PHP è disponibile per ridimensionare le immagini? */
function img_gd_disponibile(): bool
{
    return function_exists("imagecreatetruecolor") && function_exists("imagecopyresampled")
        && function_exists("imagejpeg") && function_exists("imagecreatefromstring");
}

/** Copia ridimensionata di una risorsa GD (lato lungo $lato; mai ingrandita), sfondo bianco. */
function img_scala($src, int $lato)
{
    $w = imagesx($src);
    $h = imagesy($src);
    $max = max($w, $h);
    if ($max > $lato) {
        $f = $lato / $max;
        $nw = max(1, (int) round($w * $f));
        $nh = max(1, (int) round($h * $f));
    } else {
        $nw = $w;
        $nh = $h;
    }
    $dst = imagecreatetruecolor($nw, $nh);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));   // PNG/GIF trasparenti su bianco
    imagealphablending($dst, true);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    return $dst;
}

/** Codifica una risorsa GD in JPEG (stringa binaria). */
function img_jpeg_bytes($img, int $qualita): string
{
    ob_start();
    imagejpeg($img, null, $qualita);
    return (string) ob_get_clean();
}

/**
 * Elabora un file caricato: verifica che sia un'immagine supportata
 * (JPEG, PNG, GIF, WebP), corregge l'orientamento EXIF, ridimensiona e
 * converte in JPEG, e crea la miniatura (240 px).
 * Ritorna ["ok"=>true, "jpeg", "miniatura"(|null), "larghezza", "altezza"]
 *      o  ["ok"=>false, "errore"=>string].
 */
function img_elabora(string $percorso): array
{
    $info = @getimagesize($percorso);
    if (!$info) return ["ok" => false, "errore" => "Il file non è un'immagine riconosciuta"];
    $tipo = $info[2];
    if (!in_array($tipo, [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true)) {
        return ["ok" => false, "errore" => "Formato non supportato: usare JPG, PNG, GIF o WebP"];
    }
    $maxPixel = (int) img_cfg("max_pixel", 25000000);
    if ($info[0] * $info[1] > $maxPixel) {
        return ["ok" => false, "errore" => "Immagine troppo grande (" . $info[0] . "×" . $info[1] . " px): ridimensionala prima del caricamento"];
    }
    $maxLato = max(300, (int) img_cfg("max_lato", 1400));
    $maxByte = max(100000, (int) img_cfg("max_byte", 650000));

    if (!img_gd_disponibile()) {
        // Senza GD non si può ridimensionare: solo JPEG già leggeri, senza miniatura
        if ($tipo !== IMAGETYPE_JPEG) {
            return ["ok" => false, "errore" => "L'estensione GD di PHP non è disponibile: caricare solo file JPEG"];
        }
        $bytes = (string) file_get_contents($percorso);
        if (strlen($bytes) > 1500000) {
            return ["ok" => false, "errore" => "L'estensione GD di PHP non è disponibile e il JPEG supera 1,5 MB: ridimensionalo prima"];
        }
        return ["ok" => true, "jpeg" => $bytes, "miniatura" => null, "larghezza" => (int) $info[0], "altezza" => (int) $info[1]];
    }

    $src = @imagecreatefromstring((string) file_get_contents($percorso));
    if (!$src) return ["ok" => false, "errore" => "Impossibile leggere l'immagine (file danneggiato o formato non gestito da GD)"];

    // Orientamento EXIF (foto scattate col telefono)
    if ($tipo === IMAGETYPE_JPEG && function_exists("exif_read_data") && function_exists("imagerotate")) {
        $ex = @exif_read_data($percorso);
        $or = (int) ($ex["Orientation"] ?? 1);
        $gradi = $or === 3 ? 180 : ($or === 6 ? -90 : ($or === 8 ? 90 : 0));
        if ($gradi !== 0) {
            $ruotata = @imagerotate($src, $gradi, 0);
            if ($ruotata) $src = $ruotata;
        }
    }

    $grande = img_scala($src, $maxLato);
    $jpeg = "";
    foreach ([82, 72, 62] as $q) {            // se pesa troppo, abbasso la qualità
        $jpeg = img_jpeg_bytes($grande, $q);
        if (strlen($jpeg) <= $maxByte) break;
    }
    $piccola = img_scala($grande, 240);
    return [
        "ok"        => true,
        "jpeg"      => $jpeg,
        "miniatura" => img_jpeg_bytes($piccola, 70),
        "larghezza" => imagesx($grande),
        "altezza"   => imagesy($grande),
    ];
}

/** Ridimensiona un JPEG già salvato (usato per ridurre i token inviati all'AI). Senza GD ritorna l'originale. */
function img_ridimensiona_bytes(string $jpeg, int $lato): string
{
    if (!img_gd_disponibile()) return $jpeg;
    $src = @imagecreatefromstring($jpeg);
    if (!$src) return $jpeg;
    if (max(imagesx($src), imagesy($src)) <= $lato) return $jpeg;
    return img_jpeg_bytes(img_scala($src, $lato), 80);
}

// ------------------------------------------------------------
// DESCRIZIONE AUTOMATICA (opzione C)
// ------------------------------------------------------------

/** Costo previsto (USD) di una descrizione AI con il modello indicato. */
function img_costo_stimato_usd(string $modello): ?float
{
    return ai_costo_usd($modello, (int) img_cfg("token_input_stimati", 1500), (int) img_cfg("token_output_stimati", 220));
}

/** Costo massimo (USD) di una descrizione AI: garantito dal tetto di token di output. */
function img_costo_massimo_usd(string $modello): ?float
{
    return ai_costo_usd($modello, (int) img_cfg("token_input_stimati", 1500), (int) img_cfg("max_token_descrizione", 500));
}

/** Normalizza i tag proposti (array o stringa) in un elenco CSV di parole chiave minuscole. */
function img_normalizza_tag($tag): string
{
    $lista = is_array($tag) ? $tag : preg_split('/[,;]+/u', (string) $tag);
    $out = [];
    foreach ($lista as $t) {
        $t = mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $t)));
        $t = trim($t, " #.");
        if ($t === "" || mb_strlen($t) > 30) continue;
        $out[$t] = true;
        if (count($out) >= 8) break;
    }
    $csv = implode(",", array_keys($out));
    return mb_strlen($csv) > 300 ? mb_substr($csv, 0, 300) : $csv;
}

/** Unisce due elenchi CSV di tag (senza doppioni). */
function img_unisci_tag(string $a, string $b): string
{
    return img_normalizza_tag(array_merge(explode(",", $a), explode(",", $b)));
}

/** Un tono ammesso, oppure stringa vuota. */
function img_normalizza_tono($tono): string
{
    $t = mb_strtolower(trim((string) $tono));
    return in_array($t, IMG_TONI, true) ? $t : "";
}

/**
 * Chiede a un modello con visione di descrivere un'immagine (A PAGAMENTO).
 * $jpeg = bytes JPEG (meglio già ridimensionati con img_ridimensiona_bytes).
 * Ritorna sempre: ok, errore, token_input, token_output, costo_usd, ms e,
 * se ok: descrizione, tag (CSV), tono.
 * L'AI descrive solo ciò che si vede: non identifica le persone (l'abbinamento
 * foto → fantallenatore si fa a mano nel campo "utenze").
 */
function img_descrivi_ai(string $modello, string $jpeg): array
{
    $system = "Sei l'archivista fotografico de «La Gazzetta del Fantamazzone», un giornale goliardico di una lega di fantacalcio tra amici. "
        . "Descrivi in italiano la fotografia ricevuta, perché la redazione possa scegliere le immagini giuste per i suoi articoli.\n"
        . "Regole: descrivi SOLO ciò che si vede (soggetti, azione, luogo, oggetti, espressioni, atmosfera, eventuale testo ben leggibile). "
        . "Non identificare né indovinare chi sono le persone, non fare ipotesi su nome, età, salute, origini o vita privata; non trascrivere targhe o dati personali. "
        . "Se la foto non contiene nulla di descrivibile, dillo.\n"
        . "Rispondi SOLO con un oggetto JSON, senza altro testo, in questa forma:\n"
        . "{\"descrizione\": \"1-2 frasi, massimo 220 caratteri\", \"tag\": [\"da 3 a 6 parole chiave minuscole, singole\"], \"tono\": \"uno tra: comico, epico, drammatico, malinconico, festoso, neutro\"}";
    $messaggi = [[
        "role"    => "user",
        "content" => [
            ["type" => "image", "source" => ["type" => "base64", "media_type" => "image/jpeg", "data" => base64_encode($jpeg)]],
            ["type" => "text", "text" => "Descrivi questa immagine come da istruzioni."],
        ],
    ]];
    $gen = ai_messaggio($modello, $messaggi, (int) img_cfg("max_token_descrizione", 500), $system, (int) img_cfg("timeout", 60));
    if (!$gen["ok"]) {
        return ["ok" => false, "errore" => (string) $gen["errore"], "token_input" => 0, "token_output" => 0,
                "costo_usd" => 0.0, "ms" => (int) $gen["ms"]];
    }
    $d = $gen["dati"];
    $tokIn  = (int) ($d["usage"]["input_tokens"] ?? 0);
    $tokOut = (int) ($d["usage"]["output_tokens"] ?? 0);
    $costo  = (float) ai_costo_usd($modello, $tokIn, $tokOut);
    $base = ["token_input" => $tokIn, "token_output" => $tokOut, "costo_usd" => $costo, "ms" => (int) $gen["ms"]];

    $testo = "";
    foreach (($d["content"] ?? []) as $b) {
        if (($b["type"] ?? "") === "text") $testo .= (string) ($b["text"] ?? "");
    }
    $json = null;
    if (preg_match('/\{.*\}/su', $testo, $m)) $json = json_decode($m[0], true);
    $desc = is_array($json) ? trim(preg_replace('/\s+/u', ' ', (string) ($json["descrizione"] ?? ""))) : "";
    if ($desc === "") {
        return $base + ["ok" => false, "errore" => "Risposta dell'AI non interpretabile (descrizione mancante)"];
    }
    if (mb_strlen($desc) > 500) $desc = mb_substr($desc, 0, 500);
    return $base + [
        "ok"          => true,
        "errore"      => null,
        "descrizione" => $desc,
        "tag"         => img_normalizza_tag($json["tag"] ?? []),
        "tono"        => img_normalizza_tono($json["tono"] ?? ""),
    ];
}

// ------------------------------------------------------------
// RISPOSTE JSON
// ------------------------------------------------------------

/** Normalizza una riga di NEW_IMMAGINI_GAZZETTA per le risposte JSON (la miniatura è un data URI). */
function img_riga_api(array $r, ?string $miniaturaBytes = null): array
{
    foreach (["id", "larghezza", "altezza", "token_input", "token_output"] as $k) {
        if (array_key_exists($k, $r) && $r[$k] !== null) $r[$k] = (int) $r[$k];
    }
    if (array_key_exists("costo_usd", $r) && $r["costo_usd"] !== null) $r["costo_usd"] = (float) $r["costo_usd"];
    foreach (["id_giocatore", "stagione_giocatore"] as $k) {
        if (array_key_exists($k, $r)) $r[$k] = $r[$k] !== null ? (int) $r[$k] : null;
    }
    $r["attivo"] = ($r["attivo"] ?? "Y") === "Y";
    $r["miniatura"] = $miniaturaBytes !== null && $miniaturaBytes !== ""
        ? "data:image/jpeg;base64," . base64_encode($miniaturaBytes)
        : null;
    return $r;
}

/** Una riga di libreria (senza dati binari) o null. */
function img_riga($conn, int $id): ?array
{
    return query_one("SELECT " . img_campi($conn) . " FROM NEW_IMMAGINI_GAZZETTA WHERE id = $id");
}

/** Una riga con la miniatura, già normalizzata per l'API, o null. */
function img_riga_completa($conn, int $id): ?array
{
    $r = img_riga($conn, $id);
    if (!$r) return null;
    $m = query_one("SELECT miniatura FROM NEW_IMMAGINI_GAZZETTA WHERE id = $id");
    return img_riga_api($r, $m["miniatura"] ?? null);
}

/** In quanti articoli (copertina, pagine o segnaposto nel testo) è usata l'immagine? */
function img_conta_usi($conn, int $id): int
{
    $r = query_one("SELECT COUNT(DISTINCT a.id) AS n
                    FROM NEW_ARTICOLI a
                    LEFT JOIN NEW_ARTICOLI_PAGINE p ON p.id_articolo = a.id
                    WHERE FIND_IN_SET($id, a.immagini_usate) > 0
                       OR a.id_immagine_copertina = $id
                       OR p.id_immagine = $id");
    return (int) ($r["n"] ?? 0);
}

// ------------------------------------------------------------
// RITRATTI DEI GIOCATORI (importazione dal nome file)
// ------------------------------------------------------------

/** Lettere accentate → ASCII, per confrontare nomi di file e nomi del listone. */
const IMG_ACCENTI = [
    "à" => "a", "á" => "a", "â" => "a", "ä" => "a", "ã" => "a", "å" => "a", "ā" => "a", "ą" => "a",
    "ç" => "c", "ć" => "c", "č" => "c",
    "ď" => "d", "đ" => "d",
    "è" => "e", "é" => "e", "ê" => "e", "ë" => "e", "ē" => "e", "ę" => "e", "ě" => "e",
    "ğ" => "g",
    "ì" => "i", "í" => "i", "î" => "i", "ï" => "i", "ı" => "i", "ī" => "i",
    "ł" => "l", "ľ" => "l",
    "ñ" => "n", "ń" => "n", "ň" => "n",
    "ò" => "o", "ó" => "o", "ô" => "o", "ö" => "o", "õ" => "o", "ø" => "o", "ő" => "o",
    "ř" => "r",
    "š" => "s", "ś" => "s", "ş" => "s", "ș" => "s",
    "ť" => "t", "ţ" => "t", "ț" => "t",
    "ù" => "u", "ú" => "u", "û" => "u", "ü" => "u", "ű" => "u", "ū" => "u", "ů" => "u",
    "ý" => "y", "ÿ" => "y",
    "ž" => "z", "ź" => "z", "ż" => "z",
    "ß" => "ss", "æ" => "ae", "œ" => "oe",
];

/** Parole da ignorare nel nome di una squadra (sigle societarie, anni di fondazione). */
const IMG_SQUADRA_STOP = ["ac", "as", "fc", "ssc", "us", "ss", "acf", "bc", "cfc", "sc", "ca", "calcio", "hellas", "asd", "fbc"];

/** Varianti note del nome di una squadra → chiave canonica. */
const IMG_SQUADRA_ALIAS = [
    "inter milan" => "inter", "internazionale" => "inter", "internazionale milano" => "inter",
    "inter milano" => "inter", "milano" => "milan", "ac milan" => "milan",
];

/** Minuscolo, senza accenti né apostrofi, solo lettere/cifre separate da un solo spazio. */
function img_norm_testo(string $s): string
{
    $s = mb_strtolower($s, "UTF-8");
    $s = strtr($s, IMG_ACCENTI);
    $s = str_replace(["'", "’", "`", "´"], "", $s);
    $s = preg_replace('/[^a-z0-9]+/', ' ', $s);
    return trim((string) $s);
}

/** Parole di un testo normalizzato. */
function img_parole(string $s): array
{
    return preg_split('/\s+/', img_norm_testo($s), -1, PREG_SPLIT_NO_EMPTY) ?: [];
}

/**
 * Legge il nome di un file di ritratto, es.
 *   "alex-meret-napoli-2026-27-portrait-footylogos.jpg"
 *   = nome e squadra (alex-meret-napoli) · stagione (2026-27) · tipo (portrait) · fonte (footylogos)
 * Ritorna ["ok"=>true, "tok"(parole di nome+squadra), "stagione"(int|null: 2026 = 2026/27),
 *          "tipo_foto", "fonte"] oppure ["ok"=>false, "errore"].
 * Senza stagione nel nome, "stagione" è null e tutto il testo è considerato nome+squadra.
 */
function img_parse_nome_file(string $nomeFile): array
{
    $base = preg_replace('/\.[A-Za-z0-9]{2,5}$/', '', basename($nomeFile));
    $t = img_parole((string) $base);
    $n = count($t);
    $iSt = null;
    for ($i = 1; $i < $n - 1; $i++) {                      // la stagione non può essere la prima parola
        if (!preg_match('/^(19|20)\d{2}$/', $t[$i])) continue;
        $a = (int) $t[$i];
        $b = $t[$i + 1];
        if (preg_match('/^\d{2}$/', $b) && (int) $b === ($a + 1) % 100) { $iSt = $i; $len = 2; break; }
        if (preg_match('/^\d{4}$/', $b) && (int) $b === $a + 1)         { $iSt = $i; $len = 2; break; }
    }
    if ($iSt === null) {
        if ($n < 2) return ["ok" => false, "errore" => "Nome file non riconosciuto: atteso nome-cognome-squadra-2026-27-portrait"];
        return ["ok" => true, "tok" => $t, "stagione" => null, "tipo_foto" => "portrait", "fonte" => ""];
    }
    $resto = array_slice($t, $iSt + $len);
    return [
        "ok"        => true,
        "tok"       => array_slice($t, 0, $iSt),
        "stagione"  => (int) $t[$iSt],
        "tipo_foto" => $resto[0] ?? "portrait",
        "fonte"     => implode(" ", array_slice($resto, 1)),
    ];
}

/** Chiave di confronto del nome di una squadra di Serie A (senza sigle, anni e varianti note). */
function img_chiave_squadra(string $nome): string
{
    $t = array_values(array_filter(
        img_parole($nome),
        fn($x) => !in_array($x, IMG_SQUADRA_STOP, true) && !preg_match('/^\d{4}$/', $x)
    ));
    $k = implode(" ", $t);
    return IMG_SQUADRA_ALIAS[$k] ?? $k;
}

/** Giocatori del listone della stagione (in cache per la durata della richiesta). */
function img_giocatori_stagione($conn, int $stagione): array
{
    static $cache = [];
    if (!isset($cache[$stagione])) {
        $inLista = art_colonna_presente($conn, "NEW_GIOCATORI_BASE_ASTA", "in_lista") ? "in_lista" : "'Y' AS in_lista";
        $cache[$stagione] = query_all("SELECT id_giocatore, nome, ruolo, squadra_serie_a, $inLista
                                        FROM NEW_GIOCATORI_BASE_ASTA WHERE stagione = $stagione");
    }
    return $cache[$stagione];
}

/**
 * Quanto il nome del listone ("Meret", "Martinez L.", "Di Lorenzo") combacia con le parole del file
 * ("alex", "meret"): il cognome deve comparire per intero (anche scritto unito o separato) e le
 * eventuali iniziali del listone devono corrispondere all'inizio di un'altra parola del file.
 * 0 = non combacia; più alto = più sicuro.
 */
function img_punteggio_nome(array $tokFile, string $nomeListone): int
{
    $cognomi = [];
    $iniziali = [];
    foreach (preg_split('/\s+/', trim($nomeListone), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $parte) {
        $x = img_norm_testo($parte);
        if ($x === "") continue;
        // "L." / "Lo." (con il punto) o una sola lettera sono iniziali del nome di battesimo
        if (strlen($x) === 1 || substr($parte, -1) === ".") $iniziali[] = $x;
        else foreach (img_parole($parte) as $w) $cognomi[] = $w;
    }
    $target = implode("", $cognomi);
    if (strlen($target) < 3) return 0;
    $n = count($tokFile);
    for ($a = 0; $a < $n; $a++) {
        $acc = "";
        for ($b = $a; $b < $n; $b++) {
            $acc .= $tokFile[$b];
            if (strlen($acc) > strlen($target)) break;
            if ($acc !== $target) continue;
            foreach ($iniziali as $ini) {
                $trovata = false;
                foreach ($tokFile as $k => $w) {
                    if (($k < $a || $k > $b) && $w !== "" && strpos($w, $ini) === 0) { $trovata = true; break; }
                }
                if (!$trovata) return 0;
            }
            return strlen($target) + ($iniziali ? 3 : 0);
        }
    }
    return 0;
}

/** Iniziali maiuscole per mostrare un nome letto dal file ("alex meret" → "Alex Meret"). */
function img_titolo(array $parole): string
{
    return implode(" ", array_map(fn($w) => mb_convert_case($w, MB_CASE_TITLE, "UTF-8"), $parole));
}

/**
 * Abbina un ritratto al giocatore del listone (NEW_GIOCATORI_BASE_ASTA).
 * $tok = parole di nome+squadra lette dal file; $stagione = 2026 per 2026/27.
 * Ritorna: stato ('abbinata' | 'da_verificare' | 'non_trovata'), id_giocatore (int|null),
 *          nome_giocatore (dal file), squadra_giocatore (dal listone, se riconosciuta),
 *          nome_listone (stringa|null)
 *  - abbinata       un solo giocatore combacia, nella squadra indicata dal file;
 *    (anche se la squadra del file non è riconosciuta, purché il nome sia unico nel listone);
 *  - da_verificare  più giocatori possibili (id nullo) oppure uno solo ma in un'altra squadra
 *                   (id = proposta da confermare a mano: tipico di un trasferimento);
 *  - non_trovata    nessuno combacia (o il listone della stagione non è ancora caricato).
 */
function img_abbina_giocatore($conn, array $tok, ?int $stagione): array
{
    $esito = ["stato" => "non_trovata", "id_giocatore" => null, "nome_giocatore" => img_titolo($tok),
              "squadra_giocatore" => "", "nome_listone" => null];
    if ($stagione === null || !$tok) return $esito;
    $giocatori = img_giocatori_stagione($conn, $stagione);
    if (!$giocatori) return $esito;

    // Squadre del listone: chiave → nome
    $squadre = [];
    foreach ($giocatori as $g) {
        $nomeSq = trim((string) $g["squadra_serie_a"]);
        if ($nomeSq !== "") $squadre[img_chiave_squadra($nomeSq)] = $nomeSq;
    }

    // Le ultime parole prima della stagione sono la squadra: provo dalla più lunga
    $n = count($tok);
    $chiaveSq = null;
    $parNome = $tok;
    for ($s = 1; $s < $n; $s++) {
        $k = img_chiave_squadra(implode(" ", array_slice($tok, $s)));
        if ($k !== "" && isset($squadre[$k])) {
            $chiaveSq = $k;
            $parNome = array_slice($tok, 0, $s);
            $esito["squadra_giocatore"] = $squadre[$k];
            break;
        }
    }
    $esito["nome_giocatore"] = img_titolo($parNome);

    $migliori = function (array $lista) use ($parNome): array {
        $best = 0; $out = [];
        foreach ($lista as $g) {
            $p = img_punteggio_nome($parNome, (string) $g["nome"]);
            if ($p <= 0) continue;
            if ($p > $best) { $best = $p; $out = []; }
            if ($p === $best) $out[] = $g;
        }
        return $out;
    };
    // Con più pari merito, se uno solo è ancora in lista vince quello
    $scegli = function (array $pari): ?array {
        if (count($pari) === 1) return $pari[0];
        $inLista = array_values(array_filter($pari, fn($g) => ($g["in_lista"] ?? "Y") === "Y"));
        return count($inLista) === 1 ? $inLista[0] : null;
    };

    if ($chiaveSq !== null) {
        $dellaSquadra = array_filter($giocatori, fn($g) => img_chiave_squadra((string) $g["squadra_serie_a"]) === $chiaveSq);
        $pari = $migliori(array_values($dellaSquadra));
        if ($pari) {
            $g = $scegli($pari);
            if ($g) {
                return ["stato" => "abbinata", "id_giocatore" => (int) $g["id_giocatore"], "nome_listone" => (string) $g["nome"]] + $esito;
            }
            return ["stato" => "da_verificare"] + $esito;
        }
    }

    // Nessun combaciamento nella squadra indicata (o squadra non riconosciuta): cerco in tutto il listone
    $pari = $migliori($giocatori);
    if (!$pari) return $esito;
    $g = count($pari) === 1 ? $pari[0] : null;
    if (!$g) return ["stato" => "da_verificare"] + $esito;
    if ($chiaveSq !== null) {
        // un solo giocatore con quel nome, ma in un'altra squadra: proposta da confermare
        return ["stato" => "da_verificare", "id_giocatore" => (int) $g["id_giocatore"], "nome_listone" => (string) $g["nome"]] + $esito;
    }
    // squadra del file non riconosciuta, ma il nome è unico in tutto il listone
    return ["stato" => "abbinata", "id_giocatore" => (int) $g["id_giocatore"], "nome_listone" => (string) $g["nome"],
            "squadra_giocatore" => trim((string) $g["squadra_serie_a"])] + $esito;
}

/**
 * Descrizione, tag e tono automatici di un ritratto (nessuna chiamata AI).
 * $info: nome (stringa), squadra (stringa), stagione (int|null), tipo_foto (stringa).
 */
function img_metadati_giocatore(array $info): array
{
    $tipiFrase = ["portrait" => "Ritratto di", "headshot" => "Primo piano di", "action" => "Foto in azione di",
                  "celebration" => "Foto di esultanza di"];
    $tipiTag = ["portrait" => "ritratto", "headshot" => "primo piano", "action" => "azione", "celebration" => "esultanza"];
    $tipo = (string) ($info["tipo_foto"] ?? "portrait");
    $nome = trim((string) ($info["nome"] ?? ""));
    $sq   = trim((string) ($info["squadra"] ?? ""));
    $st   = (int) ($info["stagione"] ?? 0);

    $desc = ($tipiFrase[$tipo] ?? "Foto di") . " " . $nome;
    if ($sq !== "") $desc .= ", " . $sq;
    if ($st > 0) $desc .= " (stagione " . $st . "/" . substr((string) ($st + 1), -2) . ")";
    $desc .= ".";

    $parole = img_parole($nome);
    $tag = [$nome, $parole ? end($parole) : "", $sq, $tipiTag[$tipo] ?? $tipo, "calciatore"];
    return ["descrizione" => $desc, "tag" => img_normalizza_tag($tag), "tono" => "neutro"];
}

/**
 * Ripete l'abbinamento dei ritratti non ancora abbinati (utile dopo aver caricato o
 * aggiornato il listone della stagione). Gli abbinamenti già confermati non si toccano.
 * La descrizione viene rigenerata solo se non è stata ritoccata a mano.
 * Ritorna [esaminate, abbinate, da_verificare, non_trovate].
 */
function img_riabbina($conn): array
{
    $out = ["esaminate" => 0, "abbinate" => 0, "da_verificare" => 0, "non_trovate" => 0];
    if (!img_giocatori_presenti($conn)) return $out;
    $righe = query_all("SELECT id, nome_file, descrizione, nome_giocatore, squadra_giocatore, stagione_giocatore
                        FROM NEW_IMMAGINI_GAZZETTA
                        WHERE tipo = 'giocatore' AND stato_abbinamento <> 'abbinata'");
    foreach ($righe as $r) {
        $p = img_parse_nome_file((string) $r["nome_file"]);
        if (!$p["ok"]) continue;
        $stagione = $p["stagione"] ?? ($r["stagione_giocatore"] !== null ? (int) $r["stagione_giocatore"] : null);
        $vecchia = img_metadati_giocatore([
            "nome" => (string) $r["nome_giocatore"], "squadra" => (string) $r["squadra_giocatore"],
            "stagione" => $stagione, "tipo_foto" => $p["tipo_foto"],
        ]);
        $ab = img_abbina_giocatore($conn, $p["tok"], $stagione);
        $nuova = img_metadati_giocatore([
            "nome" => $ab["nome_giocatore"], "squadra" => $ab["squadra_giocatore"],
            "stagione" => $stagione, "tipo_foto" => $p["tipo_foto"],
        ]);
        $id = (int) $r["id"];
        $idG = $ab["id_giocatore"] !== null ? (int) $ab["id_giocatore"] : null;
        $st = mysqli_prepare($conn, "UPDATE NEW_IMMAGINI_GAZZETTA
                SET id_giocatore = ?, nome_giocatore = ?, squadra_giocatore = ?, stato_abbinamento = ?"
                . (trim((string) $r["descrizione"]) === $vecchia["descrizione"] ? ", descrizione = ?, tag = ?" : "")
                . " WHERE id = ?");
        if (!$st) continue;
        $nomeG = $ab["nome_giocatore"]; $sqG = $ab["squadra_giocatore"]; $stato = $ab["stato"];
        if (trim((string) $r["descrizione"]) === $vecchia["descrizione"]) {
            $d = $nuova["descrizione"]; $tg = $nuova["tag"];
            mysqli_stmt_bind_param($st, "isssssi", $idG, $nomeG, $sqG, $stato, $d, $tg, $id);
        } else {
            mysqli_stmt_bind_param($st, "isssi", $idG, $nomeG, $sqG, $stato, $id);
        }
        mysqli_stmt_execute($st);
        mysqli_stmt_close($st);
        $out["esaminate"]++;
        if ($stato === "abbinata") $out["abbinate"]++;
        elseif ($stato === "da_verificare") $out["da_verificare"]++;
        else $out["non_trovate"]++;
    }
    return $out;
}
