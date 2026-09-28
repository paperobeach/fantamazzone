<?php
// ============================================================
// api/admin/voti_serie_a.php
//
// GET  ?stagione=2026
//      Restituisce la giornata di riferimento (ultima non chiusa),
//      l'elenco delle giornate del calendario con stato di chiusura e
//      numero di voti Serie A già caricati.
//
// POST multipart/form-data: stagione, giornata, file (.xlsx, sheet "Italia")
//      [forza=1 per caricare anche se la giornata indicata nel titolo
//       del file è diversa da quella selezionata]
//
// Fase 1 del flusso "Gestione voti": carica i voti della Serie A su
// NEW_VOTI_SERIE_A (chiave: stagione, giornata, id_giocatore).
// Il caricamento è ripetibile: i voti della stessa stagione/giornata
// vengono sostituiti. Se il file contiene errori non viene scritto nulla.
// ============================================================
require_once __DIR__ . "/../connect.php";

mysqli_set_charset($conn, "utf8mb4");

// ------------------------------------------------------------
// Giornata di riferimento: prima giornata successiva all'ultima
// chiusa, limitata all'ultima del calendario (stessa logica di
// giornata_corrente.php)
// ------------------------------------------------------------
function calcola_giornata_corrente(int $stagione): array {
    $row = query_one("SELECT MAX(giornata) AS ultima_chiusa
                       FROM NEW_CALENDARIO_CK
                       WHERE stagione = $stagione AND ck_giocata = 'S'");
    $ultima_chiusa = (int) ($row["ultima_chiusa"] ?? 0);
    $giornata      = $ultima_chiusa + 1;

    $max_row = query_one("SELECT MAX(giornata) AS ultima
                           FROM NEW_CALENDARIO
                           WHERE stagione = $stagione");
    $ultima_calendario = (int) ($max_row["ultima"] ?? 0);

    if ($ultima_calendario > 0 && $giornata > $ultima_calendario) {
        $giornata = $ultima_calendario;
    }
    return [$giornata, $ultima_chiusa, $ultima_calendario];
}

// ============================================================
// GET
// ============================================================
if ($_SERVER["REQUEST_METHOD"] === "GET") {
    $stagione = param_int("stagione");
    if (!$stagione) api_error("Parametro obbligatorio mancante: stagione", 400);

    [$giornata, $ultima_chiusa, $ultima_calendario] = calcola_giornata_corrente($stagione);

    $chiuse = [];
    $res = mysqli_query($conn, "SELECT giornata FROM NEW_CALENDARIO_CK
                                WHERE stagione = $stagione AND ck_giocata = 'S'");
    while ($res && $r = mysqli_fetch_assoc($res)) $chiuse[(int) $r["giornata"]] = true;

    $caricati = [];
    $res = mysqli_query($conn, "SELECT giornata, COUNT(*) AS n FROM NEW_VOTI_SERIE_A
                                WHERE stagione = $stagione GROUP BY giornata");
    while ($res && $r = mysqli_fetch_assoc($res)) $caricati[(int) $r["giornata"]] = (int) $r["n"];

    $giornate = [];
    $res = mysqli_query($conn, "SELECT DISTINCT giornata FROM NEW_CALENDARIO
                                WHERE stagione = $stagione ORDER BY giornata");
    while ($res && $r = mysqli_fetch_assoc($res)) {
        $g = (int) $r["giornata"];
        $giornate[] = [
            "giornata"      => $g,
            "chiusa"        => isset($chiuse[$g]),
            "voti_caricati" => $caricati[$g] ?? 0,
        ];
    }

    api_success([
        "stagione"          => $stagione,
        "giornata_corrente" => $giornata,
        "ultima_chiusa"     => $ultima_chiusa,
        "ultima_calendario" => $ultima_calendario,
        "giornate"          => $giornate,
    ]);
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    api_error("Metodo non consentito. Usare GET o POST.", 405);
}

// ============================================================
// POST - upload file
// ============================================================
$stagione = post_int("stagione");
$giornata = post_int("giornata");
$forza    = !empty($_POST["forza"]);

if (!$stagione) api_error("Parametro obbligatorio mancante: stagione", 400);
if (!$giornata) api_error("Parametro obbligatorio mancante: giornata", 400);

$max_row = query_one("SELECT MAX(giornata) AS ultima FROM NEW_CALENDARIO WHERE stagione = $stagione");
$ultima_calendario = (int) ($max_row["ultima"] ?? 0);
if ($giornata < 1 || ($ultima_calendario > 0 && $giornata > $ultima_calendario)) {
    api_error("Giornata $giornata non valida per la stagione $stagione", 400);
}

if (empty($_FILES["file"]) || $_FILES["file"]["error"] !== UPLOAD_ERR_OK) {
    api_error("File Excel mancante o non caricato correttamente", 400);
}
if (!preg_match('/\.xlsx$/i', $_FILES["file"]["name"])) {
    api_error("Il file deve essere in formato .xlsx", 400);
}
$tmpPath = $_FILES["file"]["tmp_name"];

// ------------------------------------------------------------
// Parser XLSX minimale (senza dipendenze esterne, come in
// inserimento_rose.php)
// ------------------------------------------------------------
function xlsx_col_to_index(string $col): int {
    $col = strtoupper($col);
    $result = 0;
    for ($i = 0; $i < strlen($col); $i++) {
        $result = $result * 26 + (ord($col[$i]) - 64);
    }
    return $result - 1;
}

/** Restituisce le righe dello sheet: [ [numero_riga_excel, [col => valore]], ... ] */
function xlsx_read_sheet(string $filePath, string $sheetName): array {
    if (!class_exists('ZipArchive')) {
        throw new Exception("Estensione PHP ZipArchive non disponibile sul server");
    }
    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) {
        throw new Exception("Impossibile aprire il file, non sembra un .xlsx valido");
    }
    $workbookXml = $zip->getFromName('xl/workbook.xml');
    $relsXml     = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($workbookXml === false || $relsXml === false) {
        $zip->close();
        throw new Exception("Struttura del file .xlsx non valida");
    }

    $workbook = simplexml_load_string($workbookXml);
    $rId = null;
    foreach ($workbook->sheets->sheet as $sheet) {
        $name   = (string) $sheet->attributes()['name'];
        $attrsR = $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        if (strcasecmp($name, $sheetName) === 0) {
            $rId = (string) $attrsR['id'];
            break;
        }
    }
    if ($rId === null) {
        $zip->close();
        throw new Exception("Lo sheet \"$sheetName\" non è presente nel file");
    }

    $rels = simplexml_load_string($relsXml);
    $target = null;
    foreach ($rels->Relationship as $rel) {
        if ((string) $rel['Id'] === $rId) { $target = (string) $rel['Target']; break; }
    }
    if ($target === null) {
        $zip->close();
        throw new Exception("Impossibile risolvere il foglio richiesto nel workbook");
    }
    $sheetPath = 'xl/' . ltrim($target, '/');
    if (strpos($target, '/xl/') === 0) $sheetPath = ltrim($target, '/');

    $shared = [];
    $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($sharedXml !== false) {
        $sharedDoc = simplexml_load_string($sharedXml);
        foreach ($sharedDoc->si as $si) {
            if (isset($si->t)) {
                $shared[] = (string) $si->t;
            } else {
                $text = '';
                foreach ($si->r as $r) { $text .= (string) $r->t; }
                $shared[] = $text;
            }
        }
    }

    $sheetXml = $zip->getFromName($sheetPath);
    $zip->close();
    if ($sheetXml === false) {
        throw new Exception("Impossibile leggere il contenuto dello sheet");
    }

    $sheetDoc = simplexml_load_string($sheetXml);
    $rows = [];
    foreach ($sheetDoc->sheetData->row as $rowXml) {
        $rowData = [];
        foreach ($rowXml->c as $cell) {
            $ref = (string) $cell['r'];
            if (!preg_match('/([A-Z]+)\d+/', $ref, $m)) continue;
            $colIdx = xlsx_col_to_index($m[1]);
            $type   = (string) $cell['t'];
            if ($type === 's') {
                $value = $shared[(int) $cell->v] ?? '';
            } elseif ($type === 'inlineStr') {
                $value = (string) ($cell->is->t ?? '');
            } else {
                $value = (string) $cell->v;
            }
            $rowData[$colIdx] = $value;
        }
        $rows[] = [(int) $rowXml['r'], $rowData];
    }
    return $rows;
}

function err_row($riga, $campo, $messaggio) {
    return ["riga" => $riga, "campo" => $campo, "messaggio" => $messaggio];
}

function json_error(string $code, string $message, int $status, $details = null) {
    http_response_code($status);
    $err = ["code" => $code, "message" => $message, "status" => $status];
    if ($details !== null) $err["details"] = $details;
    echo json_encode(["error" => $err]);
    exit;
}

// ------------------------------------------------------------
// 1) Lettura sheet "Italia"
// ------------------------------------------------------------
try {
    $rows = xlsx_read_sheet($tmpPath, "Italia");
} catch (Throwable $e) {
    api_error("Impossibile leggere il file: " . $e->getMessage(), 400);
}
if (empty($rows)) {
    api_error("Il file non contiene dati nello sheet \"Italia\"", 400);
}

// Giornata indicata nel titolo (es. "Voti Italia 5ª giornata di campionato")
$giornataFile = null;
foreach ($rows as [$nr, $r]) {
    $first = trim((string) ($r[0] ?? ''));
    if ($first !== '' && preg_match('/(\d+)\s*[^\d\s]{0,2}\s*giornata/iu', $first, $m)) {
        $giornataFile = (int) $m[1];
        break;
    }
}
if ($giornataFile !== null && $giornataFile !== $giornata && !$forza) {
    json_error(
        "GIORNATA_MISMATCH",
        "Il file si riferisce alla giornata $giornataFile, ma è selezionata la giornata $giornata",
        409,
        ["giornata_file" => $giornataFile, "giornata_selezionata" => $giornata]
    );
}

// ------------------------------------------------------------
// 2) Parsing a blocchi: nome squadra -> riga intestazione "Cod." -> giocatori
//    Il nome squadra è la riga non vuota che precede l'intestazione.
// ------------------------------------------------------------
$labels = [
    'cod' => 'cod', 'cod.' => 'cod', 'codice' => 'cod',
    'ruolo' => 'ruolo', 'nome' => 'nome', 'voto' => 'voto',
    'gf' => 'gf', 'gs' => 'gs', 'rp' => 'rp', 'rs' => 'rs', 'rf' => 'rf',
    'au' => 'au', 'amm' => 'amm', 'esp' => 'esp', 'ass' => 'ass',
];
$statFields = ['gf', 'gs', 'rp', 'rs', 'rf', 'au', 'amm', 'esp', 'ass'];

$errors  = [];
$data    = [];
$idsVisti = [];
$squadra = null;
$colIndex = null;
$prevText = null;      // ultima riga "testuale" non vuota (candidata nome squadra)
$squadreViste = [];

foreach ($rows as [$nr, $r]) {
    $nonEmpty = [];
    foreach ($r as $k => $v) { if (trim((string) $v) !== '') $nonEmpty[$k] = trim((string) $v); }
    if (empty($nonEmpty)) continue;

    // Riga di intestazione colonne
    if (mb_strtolower($nonEmpty[0] ?? '') === 'cod.' || mb_strtolower($nonEmpty[0] ?? '') === 'cod') {
        $colIndex = [];
        foreach ($nonEmpty as $idx => $label) {
            $key = mb_strtolower($label);
            if (isset($labels[$key])) $colIndex[$labels[$key]] = $idx;
        }
        foreach (['cod', 'ruolo', 'nome', 'voto'] as $c) {
            if (!isset($colIndex[$c])) {
                api_error("Colonna obbligatoria mancante nel file: $c (riga $nr)", 400);
            }
        }
        $squadra = $prevText;
        if ($squadra === null || $squadra === '') {
            $errors[] = err_row($nr, 'squadra', 'Nome della squadra non trovato sopra l\'intestazione');
            $squadra = '?';
        }
        $squadreViste[$squadra] = true;
        continue;
    }

    // Riga di solo testo (titolo o nome squadra)
    if (count($nonEmpty) === 1) {
        $unico = reset($nonEmpty);
        if (!ctype_digit($unico)) {
            $prevText = $unico;
            continue;
        }
    }

    // Riga giocatore
    if ($colIndex === null) {
        $errors[] = err_row($nr, 'file', 'Riga dati trovata prima di un\'intestazione "Cod."');
        continue;
    }
    $get = fn($k) => isset($colIndex[$k], $r[$colIndex[$k]]) ? trim((string) $r[$colIndex[$k]]) : '';

    $cod   = $get('cod');
    $ruolo = strtoupper($get('ruolo'));
    $nome  = $get('nome');
    $votoRaw = $get('voto');

    if ($cod === '' || !ctype_digit($cod)) {
        $errors[] = err_row($nr, 'cod', 'Campo obbligatorio: deve essere un codice numerico');
    } elseif (isset($idsVisti[$cod])) {
        $errors[] = err_row($nr, 'cod', "Codice duplicato nel file (già presente alla riga {$idsVisti[$cod]})");
    } else {
        $idsVisti[$cod] = $nr;
    }

    if (!in_array($ruolo, ['P', 'D', 'C', 'A', 'ALL'], true)) {
        $errors[] = err_row($nr, 'ruolo', 'Deve essere P, D, C, A oppure ALL');
    }
    if ($nome === '') {
        $errors[] = err_row($nr, 'nome', 'Campo obbligatorio');
    }

    // Voto: numerico; un asterisco finale (es. "6*") viene tolto e segnalato
    $politico = 0;
    $votoNorm = str_replace(',', '.', $votoRaw);
    if (substr($votoNorm, -1) === '*') {
        $politico = 1;
        $votoNorm = rtrim($votoNorm, '*');
    }
    if ($votoNorm === '' || !is_numeric($votoNorm) || (float) $votoNorm < 0 || (float) $votoNorm > 10) {
        $errors[] = err_row($nr, 'voto', "Valore non valido: \"$votoRaw\" (atteso numero da 0 a 10)");
        $votoNorm = 0;
    }

    $stat = [];
    foreach ($statFields as $f) {
        $v = $get($f);
        if ($v === '') { $stat[$f] = 0; continue; }
        if (!is_numeric($v) || (int) $v < 0 || (float) $v != (int) $v) {
            $errors[] = err_row($nr, $f, "Deve essere un intero non negativo (trovato \"$v\")");
            $stat[$f] = 0;
        } else {
            $stat[$f] = (int) $v;
        }
    }

    $data[] = [
        'id_giocatore' => (int) $cod,
        'squadra'      => $squadra,
        'ruolo'        => $ruolo,
        'nome'         => $nome,
        'voto'         => (float) $votoNorm,
        'politico'     => $politico,
    ] + $stat;
}

if (empty($data) && empty($errors)) {
    $errors[] = err_row(0, 'file', 'Nessun voto trovato nello sheet "Italia"');
}
if (!empty($errors)) {
    json_error("VALIDATION_ERROR", "Il file contiene " . count($errors) . " errore/i", 422, $errors);
}

// ------------------------------------------------------------
// 3) Caricamento (delete preventiva stagione+giornata, poi insert)
// ------------------------------------------------------------
mysqli_begin_transaction($conn);
try {
    if (!mysqli_query($conn, "DELETE FROM NEW_VOTI_SERIE_A WHERE stagione = $stagione AND giornata = $giornata")) {
        throw new Exception(mysqli_error($conn));
    }

    $stmt = mysqli_prepare($conn, "INSERT INTO NEW_VOTI_SERIE_A
        (stagione, giornata, id_giocatore, squadra, ruolo, nome, voto, politico,
         gf, gs, rp, rs, rf, au, amm, esp, ass)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    if (!$stmt) throw new Exception(mysqli_error($conn));

    $perRuolo = ['P' => 0, 'D' => 0, 'C' => 0, 'A' => 0, 'ALL' => 0];
    $politici = 0;

    foreach ($data as $d) {
        mysqli_stmt_bind_param(
            $stmt, "iiisssd" . str_repeat("i", 10),
            $stagione, $giornata, $d['id_giocatore'], $d['squadra'], $d['ruolo'], $d['nome'],
            $d['voto'], $d['politico'],
            $d['gf'], $d['gs'], $d['rp'], $d['rs'], $d['rf'], $d['au'], $d['amm'], $d['esp'], $d['ass']
        );
        if (!mysqli_stmt_execute($stmt)) throw new Exception(mysqli_stmt_error($stmt));
        $perRuolo[$d['ruolo']]++;
        $politici += $d['politico'];
    }
    mysqli_stmt_close($stmt);
    mysqli_commit($conn);

    api_success([
        "ok"            => true,
        "stagione"      => $stagione,
        "giornata"      => $giornata,
        "giornata_file" => $giornataFile,
        "totale"        => count($data),
        "squadre"       => count($squadreViste),
        "per_ruolo"     => $perRuolo,
        "politici"      => $politici,
    ]);
} catch (Throwable $e) {
    mysqli_rollback($conn);
    api_error("Errore durante il salvataggio: " . $e->getMessage(), 500);
}
