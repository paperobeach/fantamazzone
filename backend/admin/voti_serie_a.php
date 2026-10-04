<?php
// ============================================================
// api/admin/voti_serie_a.php
//
// RACCORDO SERIE A ↔ FANTACAMPIONATO
//   Il parametro di stagione GIORNATA_SERIE_A_INIZIO (Gestisci regole di
//   calcolo → Parametri stagione → Struttura stagione, default 1) è la
//   giornata di Serie A che corrisponde alla 1ª giornata di
//   fantacampionato. Quindi:
//       giornata fantacampionato = giornata Serie A - (INIZIO - 1)
//   Tutto il flusso a valle (NEW_VOTI_SERIE_A, calcolo, chiusura) ragiona
//   in giornate di FANTACAMPIONATO: la conversione avviene qui.
//
// GET  ?stagione=2026
//      Restituisce la giornata di riferimento (ultima non chiusa),
//      la giornata di Serie A di partenza (giornata_serie_a_inizio),
//      l'elenco delle giornate del calendario con la giornata di Serie A
//      corrispondente, lo stato di chiusura e il numero di voti già caricati.
//
// POST multipart/form-data: stagione, file (.xlsx, sheet "Italia"),
//      giornata (OPZIONALE, giornata di FANTACAMPIONATO)
//      [forza=1 per caricare anche se la giornata indicata nel titolo
//       del file è diversa da quella selezionata]
//   - Senza "giornata" (modalità automatica): la giornata di Serie A si
//     ricava dal titolo del file (es. "Voti Italia 7ª giornata di
//     campionato") e i voti sono associati alla fantagiornata
//     corrispondente. Se la giornata non si legge dal file, o non ha una
//     fantagiornata corrispondente, o questa è già chiusa, il
//     caricamento è rifiutato senza scrivere nulla.
//   - Con "giornata" (modalità manuale): i voti vanno su quella
//     fantagiornata; se il titolo del file indica una giornata di Serie A
//     diversa da quella attesa si risponde GIORNATA_MISMATCH (409), a meno
//     di forza=1.
//
// Fase 1 del flusso "Gestione voti": carica i voti della Serie A su
// NEW_VOTI_SERIE_A (chiave: stagione, giornata di fantacampionato,
// id_giocatore). Il caricamento è ripetibile: i voti della stessa
// stagione/giornata vengono sostituiti. Se il file contiene errori non
// viene scritto nulla.
// ============================================================
require_once __DIR__ . "/../connect.php";

mysqli_set_charset($conn, "utf8mb4");

// ------------------------------------------------------------
// Giornata di Serie A corrispondente alla 1ª di fantacampionato
// (parametro GIORNATA_SERIE_A_INIZIO, default 1)
// ------------------------------------------------------------
function giornata_serie_a_inizio(int $stagione): int {
    $r = query_one("SELECT valore FROM NEW_PARAMETRI_STAGIONE
                    WHERE stagione = $stagione AND codice = 'GIORNATA_SERIE_A_INIZIO'");
    $v = $r ? trim((string) $r["valore"]) : "";
    return ($v !== "" && ctype_digit($v) && (int) $v >= 1) ? (int) $v : 1;
}

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

    $inizio   = giornata_serie_a_inizio($stagione);
    $giornate = [];
    $res = mysqli_query($conn, "SELECT DISTINCT giornata FROM NEW_CALENDARIO
                                WHERE stagione = $stagione ORDER BY giornata");
    while ($res && $r = mysqli_fetch_assoc($res)) {
        $g = (int) $r["giornata"];
        $giornate[] = [
            "giornata"        => $g,
            "giornata_serie_a" => $g + $inizio - 1,
            "chiusa"        => isset($chiuse[$g]),
            "voti_caricati" => $caricati[$g] ?? 0,
        ];
    }

    api_success([
        "stagione"          => $stagione,
        "giornata_corrente" => $giornata,
        "ultima_chiusa"     => $ultima_chiusa,
        "ultima_calendario" => $ultima_calendario,
        "giornata_serie_a_inizio" => $inizio,
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
// "giornata" (fantacampionato) è opzionale: se assente la si ricava dal file
$giornataManuale = (isset($_POST["giornata"]) && $_POST["giornata"] !== "") ? (int) $_POST["giornata"] : null;
$forza    = !empty($_POST["forza"]);

if (!$stagione) api_error("Parametro obbligatorio mancante: stagione", 400);

$max_row = query_one("SELECT MAX(giornata) AS ultima FROM NEW_CALENDARIO WHERE stagione = $stagione");
$ultima_calendario = (int) ($max_row["ultima"] ?? 0);
$inizioSerieA = giornata_serie_a_inizio($stagione);
$offsetSerieA = $inizioSerieA - 1;
if ($giornataManuale !== null
    && ($giornataManuale < 1 || ($ultima_calendario > 0 && $giornataManuale > $ultima_calendario))) {
    api_error("Giornata $giornataManuale non valida per la stagione $stagione", 400);
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
// Risoluzione della fantagiornata di destinazione
if ($giornataManuale === null) {
    // Modalità automatica: giornata Serie A del file → fantagiornata
    if ($giornataFile === null) {
        api_error("Impossibile ricavare la giornata dal file (titolo non riconosciuto): selezionare manualmente la giornata di fantacampionato", 400);
    }
    $giornata = $giornataFile - $offsetSerieA;
    if ($giornata < 1 || ($ultima_calendario > 0 && $giornata > $ultima_calendario)) {
        api_error(
            "La giornata $giornataFile di Serie A non corrisponde a nessuna giornata di fantacampionato "
            . "(la giornata 1 corrisponde alla giornata $inizioSerieA di Serie A, ultima giornata: $ultima_calendario). "
            . "Nessun dato è stato salvato.",
            400
        );
    }
    $ck = query_one("SELECT 1 AS x FROM NEW_CALENDARIO_CK
                     WHERE stagione = $stagione AND giornata = $giornata AND ck_giocata = 'S'");
    if ($ck) {
        api_error(
            "La giornata $giornata di fantacampionato (Serie A $giornataFile) è già chiusa: riaprirla prima di ricaricare i voti. Nessun dato è stato salvato.",
            409
        );
    }
} else {
    // Modalità manuale: controllo di coerenza con la giornata indicata nel file
    $giornata = $giornataManuale;
    $serieAAttesa = $giornata + $offsetSerieA;
    if ($giornataFile !== null && $giornataFile !== $serieAAttesa && !$forza) {
        json_error(
            "GIORNATA_MISMATCH",
            "Il file si riferisce alla giornata $giornataFile di Serie A, ma la giornata $giornata di fantacampionato corrisponde alla giornata $serieAAttesa di Serie A",
            409,
            [
                "giornata_file"          => $giornataFile,
                "giornata_selezionata"   => $giornata,
                "giornata_serie_a_attesa" => $serieAAttesa,
                "giornata_fanta_file"    => $giornataFile - $offsetSerieA,
            ]
        );
    }
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

    // Voto: un asterisco (es. "6*") indica un giocatore entrato ma senza
    // voto: viene salvato con voto NULL e SV = 'Y' (il numero è ignorato).
    // Altrimenti il voto deve essere numerico da 0 a 10.
    $sv       = 'N';
    $votoNorm = str_replace(',', '.', $votoRaw);
    if (strpos($votoNorm, '*') !== false) {
        $sv       = 'Y';
        $votoNorm = null;
    } elseif ($votoNorm === '' || !is_numeric($votoNorm) || (float) $votoNorm < 0 || (float) $votoNorm > 10) {
        $errors[] = err_row($nr, 'voto', "Valore non valido: \"$votoRaw\" (atteso numero da 0 a 10, oppure con asterisco per SV)");
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
        'voto'         => $votoNorm === null ? null : (float) $votoNorm,
        'sv'           => $sv,
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
        (stagione, giornata, id_giocatore, squadra, ruolo, nome, voto, sv,
         gf, gs, rp, rs, rf, au, amm, esp, ass)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    if (!$stmt) throw new Exception(mysqli_error($conn));

    $perRuolo = ['P' => 0, 'D' => 0, 'C' => 0, 'A' => 0, 'ALL' => 0];
    $senzaVoto = 0;

    foreach ($data as $d) {
        mysqli_stmt_bind_param(
            $stmt, "iiisssds" . str_repeat("i", 9),
            $stagione, $giornata, $d['id_giocatore'], $d['squadra'], $d['ruolo'], $d['nome'],
            $d['voto'], $d['sv'],
            $d['gf'], $d['gs'], $d['rp'], $d['rs'], $d['rf'], $d['au'], $d['amm'], $d['esp'], $d['ass']
        );
        if (!mysqli_stmt_execute($stmt)) throw new Exception(mysqli_stmt_error($stmt));
        $perRuolo[$d['ruolo']]++;
        if ($d['sv'] === 'Y') $senzaVoto++;
    }
    mysqli_stmt_close($stmt);
    mysqli_commit($conn);

    api_success([
        "ok"            => true,
        "stagione"      => $stagione,
        "giornata"      => $giornata,
        "giornata_serie_a" => $giornata + $offsetSerieA,
        "giornata_file" => $giornataFile,
        "automatica"    => $giornataManuale === null,
        "totale"        => count($data),
        "squadre"       => count($squadreViste),
        "per_ruolo"     => $perRuolo,
        "senza_voto"    => $senzaVoto,
    ]);
} catch (Throwable $e) {
    mysqli_rollback($conn);
    api_error("Errore durante il salvataggio: " . $e->getMessage(), 500);
}
