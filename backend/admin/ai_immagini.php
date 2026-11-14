<?php
// ============================================================
// api/admin/ai_immagini.php
//
// Libreria immagini della Gazzetta (NEW_IMMAGINI_GAZZETTA): caricamento
// multiplo, descrizione (manuale o automatica con AI), modifica ed
// eliminazione. Le immagini vengono ridimensionate (JPEG, lato lungo max
// ~1400 px) e salvate nel DB; l'AI sceglie quali usare negli articoli
// leggendo descrizione, tag, tono e persone indicate.
//
// Solo POST. JSON, oppure multipart/form-data per azione "carica".
// Body base: { stagione, utenza, password, azione, ... }
// ACCESSO: utenza con NEW_UTENZE.abilita_ai = 'Y', password verificata
// lato server (vedi lib/AiAdmin.php).
//
// azione = "info"       → { gd_disponibile, chiave_configurata, max_upload, max_totale,
//                           n_immagini, n_attive, n_senza_descrizione,
//                           giocatori_ok (migrazione eseguita), n_giocatori, n_giocatori_abbinate,
//                           n_giocatori_da_verificare, n_giocatori_non_trovate, n_giocatori_proposte,
//                           modelli[{modello, etichetta, costo_stimato_usd, costo_massimo_usd}],
//                           spesa_mese_usd, tetto_mensile_usd, cambio_eur, utenze[] }
// azione = "elenco"     Body opz.: pagina (1), per_pagina (48, max 100),
//                       filtro (tutte|attive|inattive|senza_descrizione|gazzetta|giocatori|da_abbinare), cerca
//      → { immagini[], totale, pagina, per_pagina }  (miniatura = data URI)
// azione = "dettaglio"  Body: id → immagine + "immagine" (data URI a piena
//                       risoluzione) + "usi" (articoli che la usano)
// azione = "carica"     UN file per chiamata (campo "file"), così il browser
//                       può mostrare l'avanzamento di un caricamento multiplo.
//      Body: modalita ('manuale' = opzione B | 'ai' = opzione C),
//            modello (solo 'ai'), descrizione, tag, tono, utenze, attivo
//      Opzione B: i metadati inviati si salvano così come sono (la descrizione
//      si può completare dopo). Opzione C: la descrizione la propone l'AI
//      (a pagamento: vedi "info" per il costo previsto per immagine).
//      → { immagine, duplicata, ai{ richiesta, ok, errore, costo_usd } }
//      Un file identico a uno già presente non viene salvato (duplicata = true).
//      modalita = 'giocatore' (gratuita): ritratto di un calciatore. Giocatore, squadra, stagione e
//      tipo si leggono dal NOME DEL FILE (es. alex-meret-napoli-2026-27-portrait-footylogos);
//      descrizione, tag e tono si generano da soli e il file si abbina a NEW_GIOCATORI_BASE_ASTA.
//      → l'immagine riporta tipo, id_giocatore, nome_giocatore, squadra_giocatore, stato_abbinamento
//        (abbinata | da_verificare | non_trovata). Serve Script DB/migrazione_immagini_giocatori.sql.
// azione = "cerca_giocatori"  Body: q (min. 2 lettere), stagione_giocatore → giocatori[{id_giocatore, nome,
//                       ruolo, squadra_serie_a, in_lista}] del listone (max 20)
// azione = "abbina"     Body: id, id_giocatore (0 = scollega) → collega a mano un ritratto a un giocatore
// azione = "conferma_proposte"  conferma tutti gli abbinamenti proposti e 'da_verificare' (es. trasferimenti)
// azione = "riabbina"   ripete l'abbinamento automatico dei ritratti non abbinati (dopo aver caricato il listone)
// azione = "descrivi"   Body: id, modello → descrizione AI di un'immagine già
//                       presente (A PAGAMENTO; sovrascrive descrizione e tono,
//                       aggiunge i tag)
// azione = "salva"      Body: id, descrizione, tag, tono, utenze, attivo ('Y'/'N')
// azione = "elimina"    Body: id (rifiutata se l'immagine è usata in qualche articolo)
// ============================================================

require_once __DIR__ . "/../connect.php";
require_once __DIR__ . "/../lib/AnthropicClient.php";
require_once __DIR__ . "/../lib/AiAdmin.php";
require_once __DIR__ . "/../lib/ArticoloGiornata.php";
require_once __DIR__ . "/../lib/ImmaginiGazzetta.php";

mysqli_set_charset($conn, "utf8mb4");

// Un file oltre post_max_size svuota $_POST e $_FILES: lo si riconosce qui
if ($_SERVER["REQUEST_METHOD"] === "POST" && empty($_POST) && empty($_FILES)
    && (int) ($_SERVER["CONTENT_LENGTH"] ?? 0) > 0
    && stripos((string) ($_SERVER["CONTENT_TYPE"] ?? ""), "multipart/") === 0) {
    api_error("Il file supera il limite di upload del server (post_max_size = " . ini_get("post_max_size") . ")", 413);
}

$input  = aiadmin_input($conn);
$azione = strtolower(trim((string) ($input["azione"] ?? "")));

if (!art_fase4_presente($conn)) {
    api_error("Tabelle della Gazzetta mancanti: eseguire Script DB/NEW_AI_GAZZETTA_EDIZIONE.sql", 500);
}

/** Messaggio per un codice di errore di upload di PHP. */
function ai_img_errore_upload(int $e): string
{
    switch ($e) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE: return "File troppo grande per il server (upload_max_filesize = " . ini_get("upload_max_filesize") . ")";
        case UPLOAD_ERR_PARTIAL:   return "Upload interrotto: riprova";
        case UPLOAD_ERR_NO_FILE:   return "Nessun file ricevuto";
        default:                   return "Upload non riuscito (codice $e)";
    }
}

/** Modello valido per la visione + chiave configurata + tetto di spesa: altrimenti termina con errore. */
function ai_img_prepara_ai(string $modello, $conn): void
{
    if (ai_config() === null || !ai_chiave_configurata()) {
        api_error("Chiave API non configurata: creare backend/config_ai.php da config_ai.example.php e caricarlo via FTP", 500);
    }
    if (!isset((ai_config()["modelli"] ?? [])[$modello])) {
        api_error("Modello non valido o non presente nel listino di config_ai.php", 400);
    }
    $tetto = (float) art_cfg("tetto_mensile_usd", 0);
    if ($tetto > 0) {
        $spesa = art_spesa_mese_usd($conn);
        $max = (float) img_costo_massimo_usd($modello);
        if ($spesa + $max > $tetto) {
            api_error(sprintf("Tetto mensile di spesa (%.2f USD) raggiunto: spesi %.4f USD questo mese", $tetto, $spesa), 402);
        }
    }
}

/**
 * Descrive con l'AI un'immagine e salva l'esito (costo compreso, anche se la
 * risposta non è utilizzabile: l'AI è stata comunque pagata).
 */
function ai_img_descrivi($conn, int $id, string $modello, string $jpeg): array
{
    $r = img_descrivi_ai($modello, img_ridimensiona_bytes($jpeg, (int) img_cfg("max_lato_ai", 1000)));
    if ($r["ok"]) {
        $att = query_one("SELECT tag FROM NEW_IMMAGINI_GAZZETTA WHERE id = $id");
        $tag = img_unisci_tag((string) ($att["tag"] ?? ""), (string) $r["tag"]);
        $st = mysqli_prepare($conn, "UPDATE NEW_IMMAGINI_GAZZETTA
                SET descrizione = ?, tag = ?, tono = ?, origine_descrizione = 'ai', modello = ?,
                    token_input = ?, token_output = ?, costo_usd = COALESCE(costo_usd, 0) + ?,
                    data_descrizione = NOW()
                WHERE id = ?");
        $desc = $r["descrizione"]; $tono = $r["tono"]; $ti = $r["token_input"]; $to = $r["token_output"]; $co = $r["costo_usd"];
        mysqli_stmt_bind_param($st, "ssssiidi", $desc, $tag, $tono, $modello, $ti, $to, $co, $id);
        mysqli_stmt_execute($st);
        mysqli_stmt_close($st);
    } elseif ($r["costo_usd"] > 0) {
        $st = mysqli_prepare($conn, "UPDATE NEW_IMMAGINI_GAZZETTA
                SET costo_usd = COALESCE(costo_usd, 0) + ?, data_descrizione = NOW() WHERE id = ?");
        $co = $r["costo_usd"];
        mysqli_stmt_bind_param($st, "di", $co, $id);
        mysqli_stmt_execute($st);
        mysqli_stmt_close($st);
    }
    return $r;
}

/** Metadati comuni validati (descrizione, tag, tono, utenze). */
function ai_img_metadati(array $input): array
{
    return [
        "descrizione" => aiadmin_testo($input, "descrizione", 600),
        "tag"         => img_normalizza_tag((string) ($input["tag"] ?? "")),
        "tono"        => img_normalizza_tono($input["tono"] ?? ""),
        "utenze"      => aiadmin_utenze_csv((string) ($input["utenze"] ?? "")),
    ];
}

function ai_img_riga_o_404($conn, int $id): array
{
    $r = img_riga_completa($conn, $id);
    if (!$r) api_error("Immagine non trovata", 404);
    return $r;
}

switch ($azione) {

    // --------------------------------------------------------
    case "info":
        $modelli = [];
        foreach ((ai_config()["modelli"] ?? []) as $id => $p) {
            $modelli[] = [
                "modello"           => (string) $id,
                "etichetta"         => $p["etichetta"] ?? (string) $id,
                "costo_stimato_usd" => img_costo_stimato_usd((string) $id),
                "costo_massimo_usd" => img_costo_massimo_usd((string) $id),
            ];
        }
        $n = query_one("SELECT COUNT(*) AS tot,
                               COALESCE(SUM(attivo = 'Y'), 0) AS attive,
                               COALESCE(SUM(descrizione IS NULL OR descrizione = ''), 0) AS senza
                        FROM NEW_IMMAGINI_GAZZETTA");
        $utenze = query_all("SELECT u.utenza, a.descrizione AS allenatore
                             FROM NEW_UTENZE u
                             LEFT JOIN NEW_ALLENATORI a ON a.id = u.id AND a.stagione = u.stagione
                             WHERE u.stagione = " . (int) $input["stagione"] . "
                             ORDER BY u.id");
        $tetto = (float) art_cfg("tetto_mensile_usd", 0);
        $cambio = art_cfg("cambio_eur_per_usd", null);
        $giocOk = img_giocatori_presenti($conn);
        $g = $giocOk ? query_one("SELECT COALESCE(SUM(tipo = 'giocatore'), 0) AS tot,
                               COALESCE(SUM(tipo = 'giocatore' AND stato_abbinamento = 'abbinata'), 0) AS abb,
                               COALESCE(SUM(tipo = 'giocatore' AND stato_abbinamento = 'da_verificare'), 0) AS ver,
                               COALESCE(SUM(tipo = 'giocatore' AND stato_abbinamento = 'non_trovata'), 0) AS nt,
                               COALESCE(SUM(tipo = 'giocatore' AND stato_abbinamento = 'da_verificare' AND id_giocatore IS NOT NULL), 0) AS prop
                        FROM NEW_IMMAGINI_GAZZETTA") : null;
        api_success([
            "gd_disponibile"      => img_gd_disponibile(),
            "chiave_configurata"  => ai_config() !== null && ai_chiave_configurata(),
            "max_upload"          => ini_get("upload_max_filesize"),
            "max_totale"          => img_max_totale(),
            "giocatori_ok"        => $giocOk,
            "n_giocatori"                => (int) ($g["tot"] ?? 0),
            "n_giocatori_abbinate"       => (int) ($g["abb"] ?? 0),
            "n_giocatori_da_verificare"  => (int) ($g["ver"] ?? 0),
            "n_giocatori_non_trovate"    => (int) ($g["nt"] ?? 0),
            "n_giocatori_proposte"       => (int) ($g["prop"] ?? 0),
            "n_immagini"          => (int) $n["tot"],
            "n_attive"            => (int) $n["attive"],
            "n_senza_descrizione" => (int) $n["senza"],
            "modelli"             => $modelli,
            "spesa_mese_usd"      => round(art_spesa_mese_usd($conn), 6),
            "tetto_mensile_usd"   => $tetto > 0 ? $tetto : null,
            "cambio_eur"          => $cambio !== null ? (float) $cambio : null,
            "utenze"              => $utenze,
        ]);

    // --------------------------------------------------------
    case "elenco":
        $pagina = max(1, (int) ($input["pagina"] ?? 1));
        $per    = min(100, max(1, (int) ($input["per_pagina"] ?? 48)));
        $filtro = strtolower(trim((string) ($input["filtro"] ?? "tutte")));
        $where  = ["1 = 1"];
        if ($filtro === "attive")                $where[] = "attivo = 'Y'";
        elseif ($filtro === "inattive")          $where[] = "attivo = 'N'";
        elseif ($filtro === "senza_descrizione") $where[] = "(descrizione IS NULL OR descrizione = '')";
        elseif (in_array($filtro, ["gazzetta", "giocatori", "da_abbinare"], true)) {
            if (!img_giocatori_presenti($conn)) api_error("Eseguire prima Script DB/migrazione_immagini_giocatori.sql", 409);
            if ($filtro === "gazzetta")      $where[] = "tipo <> 'giocatore'";
            elseif ($filtro === "giocatori") $where[] = "tipo = 'giocatore'";
            else                             $where[] = "(tipo = 'giocatore' AND stato_abbinamento <> 'abbinata')";
        }
        $cerca = trim((string) ($input["cerca"] ?? ""));
        if ($cerca !== "") {
            $c = mysqli_real_escape_string($conn, str_replace(["\\", "%", "_"], ["\\\\", "\\%", "\\_"], mb_substr($cerca, 0, 60)));
            $extra = img_giocatori_presenti($conn) ? " OR nome_giocatore LIKE '%$c%' OR squadra_giocatore LIKE '%$c%'" : "";
            $where[] = "(descrizione LIKE '%$c%' OR tag LIKE '%$c%' OR nome_file LIKE '%$c%' OR utenze LIKE '%$c%'$extra)";
        }
        $w = implode(" AND ", $where);
        $tot = query_one("SELECT COUNT(*) AS n FROM NEW_IMMAGINI_GAZZETTA WHERE $w");
        $off = ($pagina - 1) * $per;
        $righe = query_all("SELECT " . img_campi($conn) . ", miniatura FROM NEW_IMMAGINI_GAZZETTA
                            WHERE $w ORDER BY id DESC LIMIT $per OFFSET $off");
        $out = [];
        foreach ($righe as $r) $out[] = img_riga_api($r, $r["miniatura"] ?? null);
        api_success(["immagini" => $out, "totale" => (int) $tot["n"], "pagina" => $pagina, "per_pagina" => $per]);

    // --------------------------------------------------------
    case "dettaglio":
        $id = aiadmin_id($input);
        $r = ai_img_riga_o_404($conn, $id);
        $d = query_one("SELECT dati FROM NEW_IMMAGINI_GAZZETTA WHERE id = $id");
        $r["immagine"] = "data:image/jpeg;base64," . base64_encode((string) ($d["dati"] ?? ""));
        $r["usi"] = img_conta_usi($conn, $id);
        $r["giocatore"] = null;
        if (!empty($r["id_giocatore"]) && !empty($r["stagione_giocatore"])) {
            $r["giocatore"] = query_one("SELECT id_giocatore, nome, ruolo, squadra_serie_a, in_lista
                                          FROM NEW_GIOCATORI_BASE_ASTA
                                          WHERE stagione = " . (int) $r["stagione_giocatore"] . "
                                            AND id_giocatore = " . (int) $r["id_giocatore"]) ?: null;
        }
        api_success($r);

    // --------------------------------------------------------
    case "carica":
        $modalita = in_array(($input["modalita"] ?? "manuale"), ["ai", "giocatore"], true) ? $input["modalita"] : "manuale";
        $modello  = trim((string) ($input["modello"] ?? ""));
        if ($modalita === "giocatore" && !img_giocatori_presenti($conn)) {
            api_error("Eseguire prima Script DB/migrazione_immagini_giocatori.sql", 409);
        }
        if (!isset($_FILES["file"])) api_error("Nessun file ricevuto (campo 'file')", 400);
        $f = $_FILES["file"];
        if (!is_array($f) || is_array($f["error"] ?? null)) api_error("Inviare un solo file per chiamata", 400);
        if ((int) $f["error"] !== UPLOAD_ERR_OK) api_error(ai_img_errore_upload((int) $f["error"]), 400);
        if (!is_uploaded_file($f["tmp_name"])) api_error("Upload non valido", 400);

        $meta = ai_img_metadati($input);
        $attivo = strtoupper(trim((string) ($input["attivo"] ?? "Y"))) === "N" ? "N" : "Y";

        $tot = query_one("SELECT COUNT(*) AS n FROM NEW_IMMAGINI_GAZZETTA");
        if ((int) $tot["n"] >= img_max_totale()) {
            api_error("Libreria piena (" . img_max_totale() . " immagini): elimina qualche immagine inutilizzata oppure alza \"max_totale\" in config_ai.php", 409);
        }

        // Ritratto di un giocatore: tutto ciò che serve si legge dal nome del file (prima di elaborare l'immagine)
        $gioc = null;
        if ($modalita === "giocatore") {
            $pf = img_parse_nome_file((string) $f["name"]);
            if (!$pf["ok"]) api_error($pf["errore"], 400);
            $stGioc = $pf["stagione"] ?? (int) $input["stagione"];
            $ab = img_abbina_giocatore($conn, $pf["tok"], $stGioc);
            $md = img_metadati_giocatore([
                "nome" => $ab["nome_giocatore"], "squadra" => $ab["squadra_giocatore"],
                "stagione" => $stGioc, "tipo_foto" => $pf["tipo_foto"],
            ]);
            $meta = ["descrizione" => $md["descrizione"], "tag" => $md["tag"], "tono" => $md["tono"], "utenze" => ""];
            $gioc = ["ab" => $ab, "stagione" => $stGioc];
        }

        $el = img_elabora($f["tmp_name"]);
        if (!$el["ok"]) api_error($el["errore"], 400);

        $hash = sha1($el["jpeg"]);
        $dup = query_one("SELECT id FROM NEW_IMMAGINI_GAZZETTA WHERE hash_file = '$hash' LIMIT 1");
        if ($dup) {
            api_success(["immagine" => ai_img_riga_o_404($conn, (int) $dup["id"]), "duplicata" => true,
                         "ai" => ["richiesta" => false]]);
        }

        // Opzione C: controlli PRIMA di salvare, così un errore (chiave, tetto) non lascia immagini a metà
        if ($modalita === "ai") ai_img_prepara_ai($modello, $conn);

        $nome = mb_substr(preg_replace('/[^\p{L}\p{N}._ -]+/u', "_", basename((string) $f["name"])), 0, 200);
        $mime = "image/jpeg";
        $descrizione = $modalita === "ai" ? "" : $meta["descrizione"];
        $tag = $meta["tag"]; $tono = $meta["tono"]; $utenzeCsv = $meta["utenze"];
        $larg = (int) $el["larghezza"]; $alt = (int) $el["altezza"];
        $dati = $el["jpeg"]; $mini = $el["miniatura"] ?? "";
        $creatoDa = (string) $input["utenza"];

        if ($gioc) {
            $tipoImg = "giocatore";
            $idGioc = $gioc["ab"]["id_giocatore"];
            $stGiocDb = (int) $gioc["stagione"];
            $nomeGioc = mb_substr((string) $gioc["ab"]["nome_giocatore"], 0, 80);
            $sqGioc = mb_substr((string) $gioc["ab"]["squadra_giocatore"], 0, 60);
            $statoAb = (string) $gioc["ab"]["stato"];
            $st = mysqli_prepare($conn, "INSERT INTO NEW_IMMAGINI_GAZZETTA
                    (nome_file, mime, larghezza, altezza, hash_file, dati, miniatura,
                     descrizione, tag, tono, utenze, attivo, caricata_da,
                     tipo, id_giocatore, stagione_giocatore, nome_giocatore, squadra_giocatore, stato_abbinamento)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            if (!$st) api_error("Salvataggio non riuscito: " . mysqli_error($conn), 500);
            mysqli_stmt_bind_param($st, "ssiissssssssssiisss", $nome, $mime, $larg, $alt, $hash, $dati, $mini,
                                   $descrizione, $tag, $tono, $utenzeCsv, $attivo, $creatoDa,
                                   $tipoImg, $idGioc, $stGiocDb, $nomeGioc, $sqGioc, $statoAb);
        } else {
            $st = mysqli_prepare($conn, "INSERT INTO NEW_IMMAGINI_GAZZETTA
                    (nome_file, mime, larghezza, altezza, hash_file, dati, miniatura,
                     descrizione, tag, tono, utenze, attivo, caricata_da)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            if (!$st) api_error("Salvataggio non riuscito: " . mysqli_error($conn), 500);
            mysqli_stmt_bind_param($st, "ssiisssssssss", $nome, $mime, $larg, $alt, $hash, $dati, $mini,
                                   $descrizione, $tag, $tono, $utenzeCsv, $attivo, $creatoDa);
        }
        if (!mysqli_stmt_execute($st)) {
            api_error("Salvataggio non riuscito: " . mysqli_stmt_error($st) . " (se l'errore parla di 'max_allowed_packet' ridurre \"max_byte\" in config_ai.php)", 500);
        }
        $id = (int) mysqli_insert_id($conn);
        mysqli_stmt_close($st);

        $ai = ["richiesta" => false];
        if ($modalita === "ai") {
            $r = ai_img_descrivi($conn, $id, $modello, $el["jpeg"]);
            $ai = ["richiesta" => true, "ok" => $r["ok"], "errore" => $r["errore"], "costo_usd" => $r["costo_usd"],
                   "token_input" => $r["token_input"], "token_output" => $r["token_output"]];
        }
        api_success(["immagine" => ai_img_riga_o_404($conn, $id), "duplicata" => false, "ai" => $ai]);

    // --------------------------------------------------------
    case "cerca_giocatori":
        $q = trim((string) ($input["q"] ?? ""));
        if (mb_strlen($q) < 2) api_error("Scrivi almeno 2 lettere del cognome", 400);
        $stG = (int) ($input["stagione_giocatore"] ?? 0) ?: (int) $input["stagione"];
            $c = mysqli_real_escape_string($conn, str_replace(["\\", "%", "_"], ["\\\\", "\\%", "\\_"], mb_substr($q, 0, 40)));
        $inLista = art_colonna_presente($conn, "NEW_GIOCATORI_BASE_ASTA", "in_lista") ? "in_lista" : "'Y' AS in_lista";
        $righe = query_all("SELECT id_giocatore, nome, ruolo, squadra_serie_a, $inLista
                            FROM NEW_GIOCATORI_BASE_ASTA
                            WHERE stagione = $stG AND nome LIKE '%$c%'
                            ORDER BY nome LIMIT 20");
        foreach ($righe as &$r) { $r["id_giocatore"] = (int) $r["id_giocatore"]; $r["ruolo"] = (int) $r["ruolo"]; }
        unset($r);
        api_success(["giocatori" => $righe, "stagione_giocatore" => $stG]);

    // --------------------------------------------------------
    case "abbina":
        $id = aiadmin_id($input);
        $r = ai_img_riga_o_404($conn, $id);
        if (($r["tipo"] ?? "gazzetta") !== "giocatore") api_error("Solo i ritratti di giocatori si abbinano al listone", 400);
        $idG = (int) ($input["id_giocatore"] ?? 0);
        $stG = (int) ($r["stagione_giocatore"] ?? 0) ?: (int) $input["stagione"];
        if ($idG > 0) {
            $g = query_one("SELECT nome FROM NEW_GIOCATORI_BASE_ASTA WHERE stagione = $stG AND id_giocatore = $idG");
            if (!$g) api_error("Giocatore $idG non presente nel listone della stagione $stG", 404);
            mysqli_query($conn, "UPDATE NEW_IMMAGINI_GAZZETTA
                                 SET id_giocatore = $idG, stagione_giocatore = $stG, stato_abbinamento = 'abbinata'
                                 WHERE id = $id");
        } else {
            mysqli_query($conn, "UPDATE NEW_IMMAGINI_GAZZETTA
                                 SET id_giocatore = NULL, stato_abbinamento = 'da_verificare' WHERE id = $id");
        }
        api_success(ai_img_riga_o_404($conn, $id));

    // --------------------------------------------------------
    case "conferma_proposte":
        if (!img_giocatori_presenti($conn)) api_error("Eseguire prima Script DB/migrazione_immagini_giocatori.sql", 409);
        mysqli_query($conn, "UPDATE NEW_IMMAGINI_GAZZETTA SET stato_abbinamento = 'abbinata'
                             WHERE tipo = 'giocatore' AND stato_abbinamento = 'da_verificare' AND id_giocatore IS NOT NULL");
        api_success(["confermate" => (int) mysqli_affected_rows($conn)]);

    // --------------------------------------------------------
    case "riabbina":
        if (!img_giocatori_presenti($conn)) api_error("Eseguire prima Script DB/migrazione_immagini_giocatori.sql", 409);
        api_success(img_riabbina($conn));

    // --------------------------------------------------------
    case "descrivi":
        $id = aiadmin_id($input);
        ai_img_riga_o_404($conn, $id);
        $modello = trim((string) ($input["modello"] ?? ""));
        ai_img_prepara_ai($modello, $conn);
        $d = query_one("SELECT dati FROM NEW_IMMAGINI_GAZZETTA WHERE id = $id");
        $r = ai_img_descrivi($conn, $id, $modello, (string) ($d["dati"] ?? ""));
        if (!$r["ok"]) {
            api_error("Descrizione AI non riuscita: " . $r["errore"] . ($r["costo_usd"] > 0 ? " (la chiamata è stata comunque conteggiata)" : ""), 502);
        }
        api_success(["immagine" => ai_img_riga_o_404($conn, $id), "costo_usd" => $r["costo_usd"],
                     "token_input" => $r["token_input"], "token_output" => $r["token_output"]]);

    // --------------------------------------------------------
    case "salva":
        $id = aiadmin_id($input);
        $att = ai_img_riga_o_404($conn, $id);
        $meta = ai_img_metadati($input);
        $attivo = strtoupper(trim((string) ($input["attivo"] ?? "Y"))) === "N" ? "N" : "Y";
        $origine = ($meta["descrizione"] !== trim((string) $att["descrizione"])) ? "manuale" : $att["origine_descrizione"];
        $st = mysqli_prepare($conn, "UPDATE NEW_IMMAGINI_GAZZETTA
                SET descrizione = ?, tag = ?, tono = ?, utenze = ?, attivo = ?, origine_descrizione = ?
                WHERE id = ?");
        $d = $meta["descrizione"]; $t = $meta["tag"]; $to = $meta["tono"]; $u = $meta["utenze"];
        mysqli_stmt_bind_param($st, "ssssssi", $d, $t, $to, $u, $attivo, $origine, $id);
        if (!mysqli_stmt_execute($st)) api_error("Salvataggio non riuscito: " . mysqli_stmt_error($st), 500);
        mysqli_stmt_close($st);
        api_success(ai_img_riga_o_404($conn, $id));

    // --------------------------------------------------------
    case "elimina":
        $id = aiadmin_id($input);
        ai_img_riga_o_404($conn, $id);
        $usi = img_conta_usi($conn, $id);
        if ($usi > 0) {
            api_error("L'immagine è usata in $usi articol" . ($usi === 1 ? "o" : "i") . ": toglila da lì (o disattivala) prima di eliminarla", 409);
        }
        mysqli_query($conn, "DELETE FROM NEW_IMMAGINI_GAZZETTA WHERE id = $id");
        api_success(["id" => $id, "eliminata" => true]);

    default:
        api_error("Azione non valida: usare info, elenco, dettaglio, carica, cerca_giocatori, abbina, conferma_proposte, riabbina, descrivi, salva o elimina", 400);
}
