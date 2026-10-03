<?php
// ============================================================
// api/admin/calendario_serie_a.php
//
// GET  ?stagione=2026
//      Restituisce il riepilogo del calendario Serie A già caricato
//      per la stagione (totale partite, elenco giornate).
//
// POST multipart/form-data: stagione, file (.xlsx, sheet "Tutte le partite")
//
// Colonne attese (prima riga = intestazione):
//   Giornata | N° partita nella giornata | Data | Ora | Partita |
//   Prima partita? | Stato orario
//
// Il caricamento è ripetibile: il calendario della stessa stagione
// viene sostituito. Se il file contiene errori non viene scritto nulla.
// Tabella: NEW_CALENDARIO_SERIE_A (vedi Script DB).
// ============================================================
require_once __DIR__ . "/../connect.php";

mysqli_set_charset($conn, "utf8mb4");

// ============================================================
// GET
// ============================================================
if ($_SERVER["REQUEST_METHOD"] === "GET") {
    $stagione = param_int("stagione");
    if (!$stagione) api_error("Parametro obbligatorio mancante: stagione", 400);

    $giornate = [];
    $totale   = 0;
    $res = mysqli_query($conn, "SELECT giornata,
                                       MIN(data_partita) AS data_inizio,
                                       MAX(data_partita) AS data_fine,
                                       COUNT(*) AS n_partite,
                                       SUM(CASE WHEN ora_partita IS NULL THEN 1 ELSE 0 END) AS n_tbd
                                FROM NEW_CALENDARIO_SERIE_A
                                WHERE stagione = $stagione
                                GROUP BY giornata
                                ORDER BY giornata");
    while ($res && $r = mysqli_fetch_assoc($res)) {
        $giornate[] = [
            "giornata"    => (int) $r["giornata"],
            "data_inizio" => $r["data_inizio"],
            "data_fine"   => $r["data_fine"],
            "n_partite"   => (int) $r["n_partite"],
            "n_tbd"       => (int) $r["n_tbd"],
        ];
        $totale += (int) $r["n_partite"];
    }

    api_success([
        "stagione" => $stagione,
        "totale"   => $totale,
        "giornate" => $giornate,
    ]);
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    api_error("Metodo non consentito. Usare GET o POST.", 405);
}

// ============================================================
// POST - upload file
// ============================================================
$stagione = post_int("stagione");
if (!$stagione) api_error("Parametro obbligatorio mancante: stagione", 400);

if (empty($_FILES["file"]) || $_FILES["file"]["error"] !== UPLOAD_ERR_OK) {
    api_error("File Excel mancante o non caricato correttamente", 400);
}
if (!preg_match('/\.xlsx$/i', $_FILES["file"]["name"])) {
    api_error("Il file deve essere in formato .xlsx", 400);
}
$tmpPath = $_FILES["file"]["tmp_name"];

// ------------------------------------------------------------
// Parser XLSX minimale (senza dipendenze esterne, come in
// inserimento_rose.php / voti_serie_a.php)
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

/**
 * Converte il valore di una cella data in 'YYYY-MM-DD'.
 * Accetta il seriale Excel (numero di giorni dal 30/12/1899),
 * 'YYYY-MM-DD[ ...]' e 'DD/MM/YYYY'. Restituisce null se non valida.
 */
function parse_data_excel(string $v): ?string {
    $v = trim($v);
    if ($v === '') return null;
    if (is_numeric($v)) {
        $serial = (int) floor((float) $v);
        if ($serial < 20000 || $serial > 80000) return null;
        return gmdate('Y-m-d', ($serial - 25569) * 86400);
    }
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $v, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
        return "$m[1]-$m[2]-$m[3]";
    }
    if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $v, $m) && checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
        return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
    }
    return null;
}

/**
 * Converte l'ora in 'HH:MM:00'. Accetta 'HH:MM' o la frazione di giorno
 * Excel. 'TBD' o vuoto => null (orario da definire). false se non valida.
 */
function parse_ora_excel(string $v) {
    $v = trim($v);
    if ($v === '' || strtoupper($v) === 'TBD') return null;
    if (preg_match('/^(\d{1,2}):(\d{2})(:\d{2})?$/', $v, $m)) {
        if ((int) $m[1] > 23 || (int) $m[2] > 59) return false;
        return sprintf('%02d:%02d:00', $m[1], $m[2]);
    }
    if (is_numeric($v) && (float) $v >= 0 && (float) $v < 1) {
        $min = (int) round((float) $v * 1440);
        return sprintf('%02d:%02d:00', intdiv($min, 60) % 24, $min % 60);
    }
    return false;
}

// ------------------------------------------------------------
// 1) Lettura sheet "Tutte le partite"
// ------------------------------------------------------------
try {
    $rows = xlsx_read_sheet($tmpPath, "Tutte le partite");
} catch (Throwable $e) {
    api_error("Impossibile leggere il file: " . $e->getMessage(), 400);
}
if (empty($rows)) {
    api_error("Il file non contiene dati nello sheet \"Tutte le partite\"", 400);
}

// ------------------------------------------------------------
// 2) Intestazione (prima riga non vuota) -> mappa colonne
// ------------------------------------------------------------
$labels = [
    'giornata'                   => 'giornata',
    'n° partita nella giornata'  => 'n_partita',
    'n. partita nella giornata'  => 'n_partita',
    'data'                       => 'data',
    'ora'                        => 'ora',
    'partita'                    => 'partita',
    'prima partita?'             => 'prima',
    'stato orario'               => 'stato',
];
$colIndex  = null;
$headerRow = 0;
$dataRows  = [];
foreach ($rows as [$nr, $r]) {
    $nonEmpty = false;
    foreach ($r as $v) { if (trim((string) $v) !== '') { $nonEmpty = true; break; } }
    if (!$nonEmpty) continue;

    if ($colIndex === null) {
        $colIndex = [];
        foreach ($r as $idx => $label) {
            $key = mb_strtolower(trim((string) $label));
            if (isset($labels[$key])) $colIndex[$labels[$key]] = $idx;
        }
        foreach (['giornata', 'n_partita', 'data', 'ora', 'partita'] as $c) {
            if (!isset($colIndex[$c])) {
                api_error("Colonna obbligatoria mancante nell'intestazione del file: $c (riga $nr)", 400);
            }
        }
        $headerRow = $nr;
        continue;
    }
    $dataRows[] = [$nr, $r];
}
if ($colIndex === null || empty($dataRows)) {
    api_error("Nessuna partita trovata nello sheet \"Tutte le partite\"", 400);
}

// ------------------------------------------------------------
// 3) Validazione righe
// ------------------------------------------------------------
$errors = [];
$data   = [];
$visti  = [];
foreach ($dataRows as [$nr, $r]) {
    $get = fn($k) => isset($colIndex[$k], $r[$colIndex[$k]]) ? trim((string) $r[$colIndex[$k]]) : '';

    $giornataRaw = $get('giornata');
    $nPartitaRaw = $get('n_partita');
    $dataRaw     = $get('data');
    $oraRaw      = $get('ora');
    $partitaRaw  = $get('partita');
    $primaRaw    = $get('prima');
    $statoRaw    = $get('stato');

    $ok = true;
    if ($giornataRaw === '' || !ctype_digit($giornataRaw) || (int) $giornataRaw < 1 || (int) $giornataRaw > 99) {
        $errors[] = err_row($nr, 'Giornata', "Deve essere un numero intero da 1 a 99 (trovato \"$giornataRaw\")");
        $ok = false;
    }
    if ($nPartitaRaw === '' || !ctype_digit($nPartitaRaw) || (int) $nPartitaRaw < 1) {
        $errors[] = err_row($nr, 'N° partita nella giornata', "Deve essere un numero intero maggiore di zero (trovato \"$nPartitaRaw\")");
        $ok = false;
    }

    $dataIso = parse_data_excel($dataRaw);
    if ($dataIso === null) {
        $errors[] = err_row($nr, 'Data', "Data non valida: \"$dataRaw\"");
        $ok = false;
    }

    $ora = parse_ora_excel($oraRaw);
    if ($ora === false) {
        $errors[] = err_row($nr, 'Ora', "Ora non valida: \"$oraRaw\" (atteso HH:MM oppure TBD)");
        $ok = false;
    }

    $casa = $ospite = '';
    if (substr_count($partitaRaw, '-') !== 1) {
        $errors[] = err_row($nr, 'Partita', "Formato atteso \"Squadra casa-Squadra ospite\" (trovato \"$partitaRaw\")");
        $ok = false;
    } else {
        [$casa, $ospite] = array_map('trim', explode('-', $partitaRaw, 2));
        if ($casa === '' || $ospite === '') {
            $errors[] = err_row($nr, 'Partita', "Squadra casa o ospite mancante (trovato \"$partitaRaw\")");
            $ok = false;
        } elseif (mb_strtolower($casa) === mb_strtolower($ospite)) {
            $errors[] = err_row($nr, 'Partita', "Squadra casa e ospite coincidono (\"$partitaRaw\")");
            $ok = false;
        } elseif (mb_strlen($casa) > 50 || mb_strlen($ospite) > 50) {
            $errors[] = err_row($nr, 'Partita', "Nome squadra troppo lungo (max 50 caratteri)");
            $ok = false;
        }
    }

    if (!$ok) continue;

    $chiave = (int) $giornataRaw . '-' . (int) $nPartitaRaw;
    if (isset($visti[$chiave])) {
        $errors[] = err_row($nr, 'N° partita nella giornata',
            "Partita $nPartitaRaw della giornata $giornataRaw duplicata (già presente alla riga {$visti[$chiave]})");
        continue;
    }
    $visti[$chiave] = $nr;

    $prima = in_array(mb_strtolower($primaRaw), ['sì', 'si', 's', 'y', 'yes', 'true', '1'], true) ? 'S' : 'N';
    $stato = $statoRaw !== '' ? $statoRaw : ($ora === null ? 'Orario da definire' : 'Ufficiale');

    $data[] = [
        'giornata'  => (int) $giornataRaw,
        'n_partita' => (int) $nPartitaRaw,
        'data'      => $dataIso,
        'ora'       => $ora,
        'casa'      => $casa,
        'ospite'    => $ospite,
        'prima'     => $prima,
        'stato'     => mb_substr($stato, 0, 30),
    ];
}

if (!empty($errors)) {
    json_error("VALIDATION_ERROR", "Il file contiene " . count($errors) . " errore/i", 422, $errors);
}

// ------------------------------------------------------------
// 4) Avvisi non bloccanti: giornate con numero di partite anomalo
//    o stessa squadra presente più volte nella stessa giornata
// ------------------------------------------------------------
$perGiornata = [];
$squadrePerGiornata = [];
foreach ($data as $d) {
    $g = $d['giornata'];
    $perGiornata[$g] = ($perGiornata[$g] ?? 0) + 1;
    foreach ([$d['casa'], $d['ospite']] as $s) {
        $squadrePerGiornata[$g][$s] = ($squadrePerGiornata[$g][$s] ?? 0) + 1;
    }
}
ksort($perGiornata);
$freq   = array_count_values($perGiornata);
arsort($freq);
$tipico = (int) array_key_first($freq);
$avvisi = [];
foreach ($perGiornata as $g => $n) {
    if ($n !== $tipico) {
        $avvisi[] = "Giornata $g: $n partite (le altre giornate ne hanno $tipico)";
    }
    foreach ($squadrePerGiornata[$g] as $s => $cnt) {
        if ($cnt > 1) $avvisi[] = "Giornata $g: la squadra \"$s\" compare $cnt volte";
    }
}

// ------------------------------------------------------------
// 5) Caricamento (delete preventiva della stagione, poi insert)
// ------------------------------------------------------------
mysqli_begin_transaction($conn);
try {
    if (!mysqli_query($conn, "DELETE FROM NEW_CALENDARIO_SERIE_A WHERE stagione = $stagione")) {
        throw new Exception(mysqli_error($conn));
    }

    $stmt = mysqli_prepare($conn, "INSERT INTO NEW_CALENDARIO_SERIE_A
        (stagione, giornata, n_partita, data_partita, ora_partita,
         squadra_casa, squadra_ospite, prima_partita, stato_orario)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    if (!$stmt) throw new Exception(mysqli_error($conn));

    $senzaOrario = 0;
    foreach ($data as $d) {
        mysqli_stmt_bind_param(
            $stmt, "iiissssss",
            $stagione, $d['giornata'], $d['n_partita'], $d['data'], $d['ora'],
            $d['casa'], $d['ospite'], $d['prima'], $d['stato']
        );
        if (!mysqli_stmt_execute($stmt)) throw new Exception(mysqli_stmt_error($stmt));
        if ($d['ora'] === null) $senzaOrario++;
    }
    mysqli_stmt_close($stmt);
    mysqli_commit($conn);

    api_success([
        "ok"           => true,
        "stagione"     => $stagione,
        "totale"       => count($data),
        "giornate"     => count($perGiornata),
        "senza_orario" => $senzaOrario,
        "avvisi"       => $avvisi,
    ]);
} catch (Throwable $e) {
    mysqli_rollback($conn);
    api_error("Errore durante il salvataggio: " . $e->getMessage(), 500);
}
