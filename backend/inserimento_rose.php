<?php
// ============================================================
// api/admin/inserimento_rose.php
//
// POST multipart/form-data: stagione, file (.xlsx, sheet "Giocatori")
//
// Flusso:
//  1) Legge lo sheet "Giocatori" (parser XLSX puro PHP, nessuna
//     libreria esterna: usa ZipArchive + SimpleXML, entrambe
//     estensioni standard disponibili su Altervista)
//  2) Valida ogni riga secondo le regole di business
//  3) Se ci sono errori, non scrive nulla e restituisce l'elenco
//     completo (riga, campo, messaggio)
//  4) Se tutto valido, esegue 3 step di caricamento:
//       a. NEW_GIOCATORI_BASE_ASTA        (staging, tutte le righe)
//       b. NEW_GIOCATORI                  (righe con id_squadra_lega valorizzato)
//       c. NEW_GIOCATORI_SVINCOLATI       (righe con id_squadra_lega NON valorizzato)
//     Ogni step cancella preventivamente i record della stessa
//     stagione, per permettere di ripetere il caricamento.
//
// NOTA: NEW_GIOCATORI è una tabella MyISAM esistente e non
// partecipa alla transazione InnoDB (MyISAM non supporta le
// transazioni). Se serve atomicità completa, valutare la
// migrazione di NEW_GIOCATORI a InnoDB.
// ============================================================
require_once __DIR__ . "/../connect.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    api_error("Metodo non consentito. Usare POST.", 405);
}

$stagione = post_int("stagione");

if (!$stagione) {
    api_error("Parametro obbligatorio mancante: stagione", 400);
}

if (empty($_FILES["file"]) || $_FILES["file"]["error"] !== UPLOAD_ERR_OK) {
    api_error("File Excel mancante o non caricato correttamente", 400);
}

$origName = $_FILES["file"]["name"];
$tmpPath  = $_FILES["file"]["tmp_name"];

if (!preg_match('/\.xlsx$/i', $origName)) {
    api_error("Il file deve essere in formato .xlsx", 400);
}

// ------------------------------------------------------------
// Parser XLSX minimale (senza dipendenze esterne)
// ------------------------------------------------------------
function xlsx_col_to_index(string $col): int {
    $col = strtoupper($col);
    $result = 0;
    for ($i = 0; $i < strlen($col); $i++) {
        $result = $result * 26 + (ord($col[$i]) - 64);
    }
    return $result - 1; // 0-based
}

/**
 * Legge un foglio di un file .xlsx e restituisce un array di righe,
 * ognuna rappresentata come [indice_colonna => valore_stringa].
 * Lancia Exception in caso di file non valido o foglio non trovato.
 */
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
        $name    = (string) $sheet->attributes()['name'];
        $attrsR  = $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
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
        if ((string) $rel['Id'] === $rId) {
            $target = (string) $rel['Target'];
            break;
        }
    }
    if ($target === null) {
        $zip->close();
        throw new Exception("Impossibile risolvere il foglio richiesto nel workbook");
    }
    $sheetPath = 'xl/' . ltrim($target, '/');

    // Shared strings (opzionale: assente se il file non ne usa)
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
                $idx = (int) $cell->v;
                $value = $shared[$idx] ?? '';
            } elseif ($type === 'inlineStr') {
                $value = (string) ($cell->is->t ?? '');
            } else {
                $value = (string) $cell->v;
            }
            $rowData[$colIdx] = $value;
        }
        $rows[] = $rowData; // può essere [] per righe vuote: gestito dopo
    }
    return $rows;
}

function err_row($riga, $campo, $messaggio) {
    return ["riga" => $riga, "campo" => $campo, "messaggio" => $messaggio];
}

function validation_error(array $errors) {
    http_response_code(422);
    echo json_encode([
        "error" => [
            "code"    => "VALIDATION_ERROR",
            "message" => "Il file contiene " . count($errors) . " errore/i",
            "status"  => 422,
            "details" => $errors,
        ]
    ]);
    exit;
}

// ------------------------------------------------------------
// 1) Lettura file
// ------------------------------------------------------------
try {
    $rows = xlsx_read_sheet($tmpPath, "Giocatori");
} catch (Throwable $e) {
    api_error("Impossibile leggere il file: " . $e->getMessage(), 400);
}

if (empty($rows)) {
    api_error("Il file non contiene dati nello sheet \"Giocatori\"", 400);
}

// ------------------------------------------------------------
// 2) Mapping intestazioni -> campi interni
// ------------------------------------------------------------
$headerMap = [
    'id_giocatore'    => 'id_giocatore',
    'nome'            => 'nome',
    'ruolo'           => 'ruolo',
    'squadra_serie_a' => 'squadra_serie_a',
    'nazionalità'     => 'nazione',
    'nazionalita'     => 'nazione',
    'id_squadra_lega' => 'id_squadra_lega',
    'crediti'         => 'crediti',
    'stagione'        => 'stagione',
];

$headerRow = array_shift($rows);
$colIndex  = [];
foreach ($headerRow as $idx => $label) {
    $key = mb_strtolower(trim((string) $label));
    if (isset($headerMap[$key])) {
        $colIndex[$headerMap[$key]] = $idx;
    }
}

foreach (['id_giocatore', 'ruolo', 'squadra_serie_a', 'nazione', 'crediti', 'stagione'] as $c) {
    if (!isset($colIndex[$c])) {
        api_error("Colonna obbligatoria mancante nel file: $c", 400);
    }
}

// ------------------------------------------------------------
// 3) Validazione riga per riga
// ------------------------------------------------------------
$errors   = [];
$data     = [];
$idsVisti = [];

foreach ($rows as $i => $r) {
    $riga = $i + 2; // +1 header, +1 indice base 0 -> 1

    $isEmpty = true;
    foreach ($r as $v) { if (trim((string) $v) !== '') { $isEmpty = false; break; } }
    if ($isEmpty) continue;

    $get = fn($k) => isset($colIndex[$k], $r[$colIndex[$k]]) ? trim((string) $r[$colIndex[$k]]) : '';

    $idGiocatore   = $get('id_giocatore');
    $nome          = $get('nome');
    $ruolo         = $get('ruolo');
    $squadraSerieA = $get('squadra_serie_a');
    $nazione       = $get('nazione');
    $idSquadraLega = $get('id_squadra_lega');
    $crediti       = $get('crediti');
    $stagioneRiga  = $get('stagione');

    // id_giocatore: numerico, obbligatorio, univoco
    if ($idGiocatore === '' || !ctype_digit($idGiocatore)) {
        $errors[] = err_row($riga, 'id_giocatore', 'Campo obbligatorio: deve essere un codice numerico');
    } elseif (isset($idsVisti[$idGiocatore])) {
        $errors[] = err_row($riga, 'id_giocatore', "Valore duplicato nel file (già presente alla riga {$idsVisti[$idGiocatore]})");
    } else {
        $idsVisti[$idGiocatore] = $riga;
    }

    // ruolo: dominio 1-4, obbligatorio
    if (!in_array($ruolo, ['1', '2', '3', '4'], true)) {
        $errors[] = err_row($riga, 'ruolo', 'Deve essere 1 (portiere), 2 (difensore), 3 (centrocampista) o 4 (attaccante)');
    }

    // squadra_serie_a: alfanumerico obbligatorio
    if ($squadraSerieA === '') {
        $errors[] = err_row($riga, 'squadra_serie_a', 'Campo obbligatorio');
    }

    // nazionalità: alfanumerico obbligatorio
    if ($nazione === '') {
        $errors[] = err_row($riga, 'nazionalità', 'Campo obbligatorio');
    }

    // id_squadra_lega: se valorizzato, 1-8
    if ($idSquadraLega !== '' && (!ctype_digit($idSquadraLega) || (int) $idSquadraLega < 1 || (int) $idSquadraLega > 8)) {
        $errors[] = err_row($riga, 'id_squadra_lega', 'Se valorizzato deve essere un numero da 1 a 8');
    }

    // crediti: numerico obbligatorio, <= 470
    if ($crediti === '' || !is_numeric($crediti) || (int) $crediti < 0) {
        $errors[] = err_row($riga, 'crediti', 'Campo obbligatorio: valore numerico');
    } elseif ((int) $crediti > 470) {
        $errors[] = err_row($riga, 'crediti', 'Non può superare 470 crediti');
    }

    // stagione: numerica obbligatoria
    if ($stagioneRiga === '' || !ctype_digit($stagioneRiga)) {
        $errors[] = err_row($riga, 'stagione', 'Campo obbligatorio: valore numerico');
    }

    $data[] = [
        'id_giocatore'    => $idGiocatore,
        'nome'            => $nome,
        'ruolo'           => $ruolo,
        'squadra_serie_a' => $squadraSerieA,
        'nazione'         => $nazione,
        'id_squadra_lega' => $idSquadraLega === '' ? null : (int) $idSquadraLega,
        'crediti'         => $crediti,
        'stagione'        => $stagioneRiga,
    ];
}

if (empty($data)) {
    $errors[] = err_row(0, 'file', 'Nessuna riga valida trovata nello sheet "Giocatori"');
}

if (!empty($errors)) {
    validation_error($errors);
}

// ------------------------------------------------------------
// 4) Caricamento sul DB (3 step, con delete preventiva per stagione)
// ------------------------------------------------------------
mysqli_begin_transaction($conn);
try {
    // STEP 1 - staging NEW_GIOCATORI_BASE_ASTA
    mysqli_query($conn, "DELETE FROM NEW_GIOCATORI_BASE_ASTA WHERE stagione = $stagione");

    foreach ($data as $r) {
        $nome_esc  = mysqli_real_escape_string($conn, $r['nome']);
        $ssa_esc   = mysqli_real_escape_string($conn, $r['squadra_serie_a']);
        $naz_esc   = mysqli_real_escape_string($conn, $r['nazione']);
        $idsl      = $r['id_squadra_lega'] === null ? "NULL" : (int) $r['id_squadra_lega'];

        mysqli_query($conn, "INSERT INTO NEW_GIOCATORI_BASE_ASTA
            (id_giocatore, stagione, nome, ruolo, squadra_serie_a, nazione, id_squadra_lega, crediti)
            VALUES (
                " . (int) $r['id_giocatore'] . ",
                $stagione,
                '$nome_esc',
                " . (int) $r['ruolo'] . ",
                '$ssa_esc',
                '$naz_esc',
                $idsl,
                " . (int) $r['crediti'] . "
            )");
    }

    // STEP 2 - NEW_GIOCATORI (id_squadra_lega valorizzato)
    // NOTA: la tabella NEW_GIOCATORI usa il campo "squadra" per
    // rappresentare l'id squadra in lega (vedi api/squadre.php).
    mysqli_query($conn, "DELETE FROM NEW_GIOCATORI WHERE stagione = $stagione");

    $caricati = 0;
    foreach ($data as $r) {
        if ($r['id_squadra_lega'] === null) continue;

        $descr_esc = mysqli_real_escape_string($conn, $r['nome']);
        $naz_esc   = mysqli_real_escape_string($conn, $r['nazione']);

        mysqli_query($conn, "INSERT INTO NEW_GIOCATORI
            (id, stagione, descrizione, squadra, flag, ruolo, nazione, crediti)
            VALUES (
                " . (int) $r['id_giocatore'] . ",
                $stagione,
                '$descr_esc',
                " . (int) $r['id_squadra_lega'] . ",
                0,
                " . (int) $r['ruolo'] . ",
                '$naz_esc',
                " . (int) $r['crediti'] . "
            )");
        $caricati++;
    }

    // STEP 3 - NEW_GIOCATORI_SVINCOLATI (id_squadra_lega NON valorizzato)
    mysqli_query($conn, "DELETE FROM NEW_GIOCATORI_SVINCOLATI WHERE stagione = $stagione");

    $svincolati = 0;
    foreach ($data as $r) {
        if ($r['id_squadra_lega'] !== null) continue;

        $descr_esc = mysqli_real_escape_string($conn, $r['nome']);
        $naz_esc   = mysqli_real_escape_string($conn, $r['nazione']);

        mysqli_query($conn, "INSERT INTO NEW_GIOCATORI_SVINCOLATI
            (id, stagione, descrizione, ruolo, nazione)
            VALUES (
                " . (int) $r['id_giocatore'] . ",
                $stagione,
                '$descr_esc',
                " . (int) $r['ruolo'] . ",
                '$naz_esc'
            )");
        $svincolati++;
    }

    mysqli_commit($conn);

    api_success([
        "ok"         => true,
        "totale"     => count($data),
        "caricati"   => $caricati,
        "svincolati" => $svincolati,
    ]);
} catch (Throwable $e) {
    mysqli_rollback($conn);
    api_error("Errore durante il salvataggio: " . $e->getMessage(), 500);
}
