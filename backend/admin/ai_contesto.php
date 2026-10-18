<?php
// ============================================================
// api/admin/ai_contesto.php
//
// FASE 3 - Gestione del contesto usato dagli articoli AI: profili dei
// fantallenatori (NEW_PROFILI_FANTALLENATORE, chiave = nome utenza), episodi
// storici della lega (NEW_EPISODI_LEGA) e contesto fisso / stile di scrittura
// (NEW_CONTESTO_FISSO).
//
// Solo POST. Body: { stagione, utenza, password, tipo, azione, ... }
// ACCESSO: utenza con NEW_UTENZE.abilita_ai = 'Y', password verificata
// lato server (vedi lib/AiAdmin.php).
//
// tipo = "profili"
//   azione "elenco"   → { profili[], utenze[] }
//        utenze = utenze della stagione indicata (con allenatore e
//        squadra), per mostrare a chi manca ancora il profilo.
//   azione "salva"    Body: utenza_profilo (nome utenza del fantallenatore,
//        deve esistere in NEW_UTENZE; "utenza" resta quella di chi opera),
//        soprannome, carattere, squadra_cuore, tormentoni, rivalita, note
//        → inserisce o aggiorna il profilo.
//   azione "elimina"  Body: utenza_profilo
//
// tipo = "episodi"
//   azione "elenco"   → { episodi[] }
//   azione "salva"    Body: id (assente/0 = nuovo), data_episodio
//        (YYYY-MM-DD, opzionale), titolo, descrizione, tag, utenze
//        (nomi utenza separati da virgola, tutti esistenti; vuoto =
//        episodio generico), attivo ('Y'/'N')
//   azione "elimina"  Body: id
//
// tipo = "fisso"   (contesto fisso e stile: blocchi inseriti nel prompt di sistema)
//   azione "elenco"   → { blocchi[], max_caratteri_contesto, max_caratteri_stile, stile_default }
//   azione "salva"    Body: id (assente/0 = nuovo), tipo_blocco ('contesto'|'stile'),
//        titolo, testo, ordine, attivo ('Y'/'N'). Il totale dei blocchi ATTIVI
//        dello stesso tipo non può superare il tetto di caratteri.
//        Se nessun blocco 'stile' è attivo si usa lo stile predefinito.
//   azione "elimina"  Body: id
// ============================================================

require_once __DIR__ . "/../connect.php";
require_once __DIR__ . "/../lib/AnthropicClient.php";
require_once __DIR__ . "/../lib/AiAdmin.php";
require_once __DIR__ . "/../lib/ArticoloGiornata.php";

mysqli_set_charset($conn, "utf8mb4");

$input  = aiadmin_input($conn);
$stagione = (int) $input["stagione"];
$tipo   = strtolower(trim((string) ($input["tipo"] ?? "")));
$azione = strtolower(trim((string) ($input["azione"] ?? "")));

if (!art_tabelle_presenti($conn)) {
    api_error("Tabelle degli articoli AI mancanti: eseguire Script DB/NEW_AI_ARTICOLI.sql", 500);
}
if (!in_array($tipo, ["profili", "episodi", "fisso"], true)) {
    api_error("Parametro 'tipo' non valido: usare 'profili', 'episodi' o 'fisso'", 400);
}
if (!in_array($azione, ["elenco", "salva", "elimina"], true)) {
    api_error("Azione non valida: usare elenco, salva o elimina", 400);
}

/** Tutte le utenze note (qualsiasi stagione): minuscolo → forma canonica. */
function ai_ctx_utenze_note(): array
{
    $m = [];
    foreach (query_all("SELECT DISTINCT utenza FROM NEW_UTENZE") as $r) {
        $u = trim((string) $r["utenza"]);
        if ($u !== "") $m[mb_strtolower($u)] = $u;
    }
    return $m;
}

// ============================================================
// CONTESTO FISSO E STILE
// ============================================================
if ($tipo === "fisso") {
    if (!art_fase4_presente($conn)) {
        api_error("Tabelle della Gazzetta mancanti: eseguire Script DB/NEW_AI_GAZZETTA_EDIZIONE.sql", 500);
    }
    $capContesto = (int) art_cfg("max_caratteri_contesto_fisso", 12000);
    $capStile    = 4000;

    $leggiBlocco = function (int $id): ?array {
        $r = query_one("SELECT id, tipo, titolo, testo, ordine, attivo, data_aggiornamento
                        FROM NEW_CONTESTO_FISSO WHERE id = $id");
        if ($r) { $r["id"] = (int) $r["id"]; $r["ordine"] = (int) $r["ordine"]; }
        return $r;
    };

    if ($azione === "elenco") {
        $righe = query_all("SELECT id, tipo, titolo, testo, ordine, attivo, data_aggiornamento
                            FROM NEW_CONTESTO_FISSO ORDER BY tipo, ordine, id");
        foreach ($righe as &$r) { $r["id"] = (int) $r["id"]; $r["ordine"] = (int) $r["ordine"]; }
        unset($r);
        api_success([
            "blocchi"                => $righe,
            "max_caratteri_contesto" => $capContesto,
            "max_caratteri_stile"    => $capStile,
            "stile_default"          => art_stile_default(),
        ]);
    }

    if ($azione === "elimina") {
        $id = aiadmin_id($input);
        if (!$leggiBlocco($id)) api_error("Blocco non trovato", 404);
        mysqli_query($conn, "DELETE FROM NEW_CONTESTO_FISSO WHERE id = $id");
        api_success(["id" => $id, "eliminato" => true]);
    }

    // salva (nuovo se id assente/0)
    $id        = (int) ($input["id"] ?? 0);
    $tipoBl    = strtolower(trim((string) ($input["tipo_blocco"] ?? "contesto")));
    if (!in_array($tipoBl, ["contesto", "stile"], true)) api_error("Il campo 'tipo_blocco' deve essere 'contesto' o 'stile'", 400);
    $titolo    = aiadmin_testo($input, "titolo", 100, true);
    $testo     = aiadmin_testo($input, "testo", 6000, true);
    $ordine    = max(0, min(9999, (int) ($input["ordine"] ?? 0)));
    $attivo    = strtoupper(trim((string) ($input["attivo"] ?? "Y")));
    if (!in_array($attivo, ["Y", "N"], true)) api_error("Il campo 'attivo' deve essere 'Y' o 'N'", 400);
    if ($id > 0 && !$leggiBlocco($id)) api_error("Blocco non trovato", 404);

    if ($attivo === "Y") {
        $altri = query_one("SELECT COALESCE(SUM(CHAR_LENGTH(titolo) + CHAR_LENGTH(testo) + 6), 0) AS c
                            FROM NEW_CONTESTO_FISSO
                            WHERE tipo = '$tipoBl' AND attivo = 'Y' AND id <> $id");
        $totale = (int) $altri["c"] + mb_strlen($titolo) + mb_strlen($testo) + 6;
        $cap = $tipoBl === "stile" ? $capStile : $capContesto;
        if ($totale > $cap) {
            api_error("Troppo testo attivo ($totale caratteri su un massimo di $cap per i blocchi di tipo '$tipoBl'): accorcia o disattiva qualche blocco. Ogni carattere finisce nel prompt di ogni articolo e ne aumenta il costo.", 400);
        }
    }

    if ($id > 0) {
        $st = mysqli_prepare($conn, "UPDATE NEW_CONTESTO_FISSO SET tipo = ?, titolo = ?, testo = ?, ordine = ?, attivo = ? WHERE id = ?");
        mysqli_stmt_bind_param($st, "sssisi", $tipoBl, $titolo, $testo, $ordine, $attivo, $id);
    } else {
        $st = mysqli_prepare($conn, "INSERT INTO NEW_CONTESTO_FISSO (tipo, titolo, testo, ordine, attivo) VALUES (?, ?, ?, ?, ?)");
        mysqli_stmt_bind_param($st, "sssis", $tipoBl, $titolo, $testo, $ordine, $attivo);
    }
    if (!mysqli_stmt_execute($st)) api_error("Salvataggio non riuscito: " . mysqli_stmt_error($st), 500);
    if ($id === 0) $id = (int) mysqli_insert_id($conn);
    mysqli_stmt_close($st);
    api_success($leggiBlocco($id));
}

// ============================================================
// PROFILI
// ============================================================
if ($tipo === "profili") {

    if ($azione === "elenco") {
        $profili = query_all("SELECT utenza, soprannome, carattere, squadra_cuore, tormentoni,
                                     rivalita, note, data_aggiornamento
                              FROM NEW_PROFILI_FANTALLENATORE ORDER BY utenza");
        $utenze = query_all("SELECT u.utenza, a.descrizione AS allenatore, s.nome AS squadra
                             FROM NEW_UTENZE u
                             LEFT JOIN NEW_ALLENATORI a ON a.id = u.id AND a.stagione = u.stagione
                             LEFT JOIN NEW_SQUADRE s    ON s.id = u.id AND s.stagione = u.stagione
                             WHERE u.stagione = $stagione
                             ORDER BY u.id");
        api_success(["profili" => $profili, "utenze" => $utenze]);
    }

    $note   = ai_ctx_utenze_note();
    $chiave = mb_strtolower(trim((string) ($input["utenza_profilo"] ?? "")));
    if ($chiave === "" || !isset($note[$chiave])) {
        api_error("Utenza del profilo sconosciuta: deve esistere in NEW_UTENZE", 400);
    }
    $utenzaProfilo = $note[$chiave];

    if ($azione === "elimina") {
        $st = mysqli_prepare($conn, "DELETE FROM NEW_PROFILI_FANTALLENATORE WHERE utenza = ?");
        mysqli_stmt_bind_param($st, "s", $utenzaProfilo);
        mysqli_stmt_execute($st);
        $n = mysqli_stmt_affected_rows($st);
        mysqli_stmt_close($st);
        api_success(["utenza" => $utenzaProfilo, "eliminato" => $n > 0]);
    }

    // salva (inserisce o aggiorna)
    $soprannome = aiadmin_testo($input, "soprannome", 100);
    $carattere  = aiadmin_testo($input, "carattere", 2000);
    $cuore      = aiadmin_testo($input, "squadra_cuore", 100);
    $tormentoni = aiadmin_testo($input, "tormentoni", 2000);
    $rivalita   = aiadmin_testo($input, "rivalita", 2000);
    $noteLibere = aiadmin_testo($input, "note", 2000);

    $st = mysqli_prepare($conn, "INSERT INTO NEW_PROFILI_FANTALLENATORE
            (utenza, soprannome, carattere, squadra_cuore, tormentoni, rivalita, note)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE soprannome = VALUES(soprannome), carattere = VALUES(carattere),
                squadra_cuore = VALUES(squadra_cuore), tormentoni = VALUES(tormentoni),
                rivalita = VALUES(rivalita), note = VALUES(note)");
    mysqli_stmt_bind_param($st, "sssssss", $utenzaProfilo, $soprannome, $carattere, $cuore,
                           $tormentoni, $rivalita, $noteLibere);
    if (!mysqli_stmt_execute($st)) api_error("Salvataggio non riuscito: " . mysqli_stmt_error($st), 500);
    mysqli_stmt_close($st);

    api_success(query_one("SELECT utenza, soprannome, carattere, squadra_cuore, tormentoni,
                                  rivalita, note, data_aggiornamento
                           FROM NEW_PROFILI_FANTALLENATORE WHERE utenza = '"
                          . mysqli_real_escape_string($conn, $utenzaProfilo) . "'"));
}

// ============================================================
// EPISODI
// ============================================================
function ai_ctx_episodio(int $id): ?array
{
    $r = query_one("SELECT id, data_episodio, titolo, descrizione, tag, utenze, attivo, ultimo_utilizzo
                    FROM NEW_EPISODI_LEGA WHERE id = $id");
    if ($r) $r["id"] = (int) $r["id"];
    return $r;
}

if ($azione === "elenco") {
    $righe = query_all("SELECT id, data_episodio, titolo, descrizione, tag, utenze, attivo, ultimo_utilizzo
                        FROM NEW_EPISODI_LEGA
                        ORDER BY attivo DESC, data_episodio DESC, id DESC");
    foreach ($righe as &$r) $r["id"] = (int) $r["id"];
    unset($r);
    api_success(["episodi" => $righe]);
}

if ($azione === "elimina") {
    $id = aiadmin_id($input);
    if (!ai_ctx_episodio($id)) api_error("Episodio non trovato", 404);
    mysqli_query($conn, "DELETE FROM NEW_EPISODI_LEGA WHERE id = $id");
    api_success(["id" => $id, "eliminato" => true]);
}

// salva (nuovo se id assente/0)
$id          = (int) ($input["id"] ?? 0);
$titolo      = aiadmin_testo($input, "titolo", 150, true);
$descrizione = aiadmin_testo($input, "descrizione", 3000, true);
$tag         = aiadmin_testo($input, "tag", 200);
$attivo      = strtoupper(trim((string) ($input["attivo"] ?? "Y")));
if (!in_array($attivo, ["Y", "N"], true)) api_error("Il campo 'attivo' deve essere 'Y' o 'N'", 400);

$data = trim((string) ($input["data_episodio"] ?? ""));
$dataSql = null;
if ($data !== "") {
    $d = DateTime::createFromFormat("Y-m-d", $data);
    if (!$d || $d->format("Y-m-d") !== $data) api_error("Data non valida: usare il formato AAAA-MM-GG", 400);
    $dataSql = $data;
}

// Utenze coinvolte: devono esistere (altrimenti l'episodio non verrebbe mai richiamato)
$note = ai_ctx_utenze_note();
$coinvolte = [];
$sconosciute = [];
foreach (explode(",", (string) ($input["utenze"] ?? "")) as $u) {
    $u = trim($u);
    if ($u === "") continue;
    $k = mb_strtolower($u);
    if (isset($note[$k])) $coinvolte[$k] = $note[$k];
    else $sconosciute[] = $u;
}
if ($sconosciute) {
    api_error("Utenze sconosciute: " . implode(", ", $sconosciute) . " (devono esistere in NEW_UTENZE)", 400);
}
$utenzeCsv = implode(",", array_values($coinvolte));
if (mb_strlen($utenzeCsv) > 500) api_error("Troppe utenze coinvolte (max 500 caratteri complessivi)", 400);

if ($id > 0) {
    if (!ai_ctx_episodio($id)) api_error("Episodio non trovato", 404);
    $st = mysqli_prepare($conn, "UPDATE NEW_EPISODI_LEGA
            SET data_episodio = ?, titolo = ?, descrizione = ?, tag = ?, utenze = ?, attivo = ?
            WHERE id = ?");
    mysqli_stmt_bind_param($st, "ssssssi", $dataSql, $titolo, $descrizione, $tag, $utenzeCsv, $attivo, $id);
} else {
    $st = mysqli_prepare($conn, "INSERT INTO NEW_EPISODI_LEGA
            (data_episodio, titolo, descrizione, tag, utenze, attivo)
            VALUES (?, ?, ?, ?, ?, ?)");
    mysqli_stmt_bind_param($st, "ssssss", $dataSql, $titolo, $descrizione, $tag, $utenzeCsv, $attivo);
}
if (!mysqli_stmt_execute($st)) api_error("Salvataggio non riuscito: " . mysqli_stmt_error($st), 500);
if ($id === 0) $id = (int) mysqli_insert_id($conn);
mysqli_stmt_close($st);

api_success(ai_ctx_episodio($id));
