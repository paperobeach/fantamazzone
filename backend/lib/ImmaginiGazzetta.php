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
//   max_totale (600)         numero massimo di immagini in libreria
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
// ============================================================

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
    $r["attivo"] = ($r["attivo"] ?? "Y") === "Y";
    $r["miniatura"] = $miniaturaBytes !== null && $miniaturaBytes !== ""
        ? "data:image/jpeg;base64," . base64_encode($miniaturaBytes)
        : null;
    return $r;
}

/** Una riga di libreria (senza dati binari) o null. */
function img_riga($conn, int $id): ?array
{
    return query_one("SELECT " . IMG_CAMPI . " FROM NEW_IMMAGINI_GAZZETTA WHERE id = $id");
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
