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
//                           modelli[{modello, etichetta, costo_stimato_usd, costo_massimo_usd}],
//                           spesa_mese_usd, tetto_mensile_usd, cambio_eur, utenze[] }
// azione = "elenco"     Body opz.: pagina (1), per_pagina (48, max 100),
//                       filtro (tutte|attive|inattive|senza_descrizione), cerca
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
        api_success([
            "gd_disponibile"      => img_gd_disponibile(),
            "chiave_configurata"  => ai_config() !== null && ai_chiave_configurata(),
            "max_upload"          => ini_get("upload_max_filesize"),
            "max_totale"          => (int) img_cfg("max_totale", 600),
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
        $cerca = trim((string) ($input["cerca"] ?? ""));
        if ($cerca !== "") {
            $c = mysqli_real_escape_string($conn, str_replace(["\\", "%", "_"], ["\\\\", "\\%", "\\_"], mb_substr($cerca, 0, 60)));
            $where[] = "(descrizione LIKE '%$c%' OR tag LIKE '%$c%' OR nome_file LIKE '%$c%' OR utenze LIKE '%$c%')";
        }
        $w = implode(" AND ", $where);
        $tot = query_one("SELECT COUNT(*) AS n FROM NEW_IMMAGINI_GAZZETTA WHERE $w");
        $off = ($pagina - 1) * $per;
        $righe = query_all("SELECT " . IMG_CAMPI . ", miniatura FROM NEW_IMMAGINI_GAZZETTA
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
        api_success($r);

    // --------------------------------------------------------
    case "carica":
        $modalita = (($input["modalita"] ?? "manuale") === "ai") ? "ai" : "manuale";
        $modello  = trim((string) ($input["modello"] ?? ""));
        if (!isset($_FILES["file"])) api_error("Nessun file ricevuto (campo 'file')", 400);
        $f = $_FILES["file"];
        if (!is_array($f) || is_array($f["error"] ?? null)) api_error("Inviare un solo file per chiamata", 400);
        if ((int) $f["error"] !== UPLOAD_ERR_OK) api_error(ai_img_errore_upload((int) $f["error"]), 400);
        if (!is_uploaded_file($f["tmp_name"])) api_error("Upload non valido", 400);

        $meta = ai_img_metadati($input);
        $attivo = strtoupper(trim((string) ($input["attivo"] ?? "Y"))) === "N" ? "N" : "Y";

        $tot = query_one("SELECT COUNT(*) AS n FROM NEW_IMMAGINI_GAZZETTA");
        if ((int) $tot["n"] >= (int) img_cfg("max_totale", 600)) {
            api_error("Libreria piena (" . (int) img_cfg("max_totale", 600) . " immagini): elimina qualche immagine inutilizzata", 409);
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

        $st = mysqli_prepare($conn, "INSERT INTO NEW_IMMAGINI_GAZZETTA
                (nome_file, mime, larghezza, altezza, hash_file, dati, miniatura,
                 descrizione, tag, tono, utenze, attivo, caricata_da)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        if (!$st) api_error("Salvataggio non riuscito: " . mysqli_error($conn), 500);
        mysqli_stmt_bind_param($st, "ssiisssssssss", $nome, $mime, $larg, $alt, $hash, $dati, $mini,
                               $descrizione, $tag, $tono, $utenzeCsv, $attivo, $creatoDa);
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
        api_error("Azione non valida: usare info, elenco, dettaglio, carica, descrivi, salva o elimina", 400);
}
