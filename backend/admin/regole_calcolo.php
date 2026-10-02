<?php
// ============================================================
// api/admin/regole_calcolo.php
//
// Gestione della configurazione usata da CalcolatoreVoti (fase 2 di
// "Gestione voti" e, in futuro, da un'ipotetica simulazione): voci di
// bonus/malus del giocatore, parametri degli algoritmi di modificatore
// (difesa, centrocampo, attacco) e parametri di stagione non
// riconducibili a bonus/malus o modificatori (limiti di sostituzione,
// ultima giornata con fattore casa, fasce gol-da-punteggio, ecc.). Vedi
// backend/lib/CalcolatoreVoti.php e backend/admin/calcolo_giornata.php.
//
// GET  ?stagione=2026
//      → { stagione, bonus:[...], algoritmi:{ DIFESA:{...}, CENTROCAMPO:{...}, ATTACCO:{...} },
//          parametri:[{codice, etichetta, valore}, ...] }
//      Se la stagione non ha ancora configurazione, viene copiata
//      automaticamente dalla stagione più recente che ne ha una,
//      oppure (se è la prima in assoluto) dai valori di default
//      codificati in questo file.
//
// POST { stagione, bonus: [{codice, etichetta, valore, ordine, attivo}, ...],
//        algoritmi: { DIFESA: {algoritmo, parametri, descrizione}, ... },
//        parametri: [{codice, etichetta, valore}, ...] }
//      Sostituisce integralmente la configurazione della stagione.
//      Si può inviare solo una parte (bonus / algoritmi / parametri):
//      quelle non inviate restano invariate.
// ============================================================
require_once __DIR__ . "/../connect.php";
require_once __DIR__ . "/../lib/CalcolatoreVoti.php";

mysqli_set_charset($conn, "utf8mb4");

const CODICI_BONUS_DEFAULT = [
    ['codice' => 'GOL_FATTO',          'etichetta' => 'Gol fatto',          'valore' =>  3.00, 'ordine' => 1],
    ['codice' => 'GOL_SUBITO',         'etichetta' => 'Gol subito',         'valore' => -1.00, 'ordine' => 2],
    ['codice' => 'RIGORE_REALIZZATO',  'etichetta' => 'Rigore realizzato',  'valore' =>  2.00, 'ordine' => 3],
    ['codice' => 'RIGORE_SBAGLIATO',   'etichetta' => 'Rigore sbagliato',   'valore' => -3.00, 'ordine' => 4],
    ['codice' => 'RIGORE_PARATO',      'etichetta' => 'Rigore parato',      'valore' =>  3.00, 'ordine' => 5],
    ['codice' => 'ASSIST',             'etichetta' => 'Assist',             'valore' =>  0.50, 'ordine' => 6],
    ['codice' => 'AMMONIZIONE',        'etichetta' => 'Ammonizione',        'valore' => -0.50, 'ordine' => 7],
    ['codice' => 'ESPULSIONE',         'etichetta' => 'Espulsione',         'valore' => -1.00, 'ordine' => 8],
    ['codice' => 'AUTOGOL',            'etichetta' => 'Autogol',            'valore' => -3.00, 'ordine' => 9],
    // Non è un bonus per-occorrenza come gli altri: si applica UNA
    // volta alla squadra che gioca in casa (vedi calcolo_giornata.php),
    // fino alla giornata indicata dal parametro ULTIMA_GIORNATA_FATTORE_CASA.
    ['codice' => 'FATTORE_CASA',       'etichetta' => 'Fattore casa',       'valore' =>  2.00, 'ordine' => 10],
];

const ALGORITMI_DEFAULT = [
    'DIFESA' => [
        'algoritmo' => 'fasce_media_v1',
        'descrizione' => 'Bonus/malus alla squadra in base alla media dei propri difensori, corretto dal modulo schierato',
        'parametri' => [
            'fasce' => [
                ['da' => 6.5,  'a' => null, 'bonus' => 3],
                ['da' => 6.0,  'a' => 6.49, 'bonus' => 1],
                ['da' => 5.5,  'a' => 5.99, 'bonus' => 0],
                ['da' => 5.0,  'a' => 5.49, 'bonus' => -1],
                ['da' => null, 'a' => 4.99, 'bonus' => -3],
            ],
            'aggiustamento_modulo' => ['3' => -1, '4' => 0, '5' => 1],
        ],
    ],
    'CENTROCAMPO' => [
        'algoritmo' => 'fasce_differenza_v1',
        'descrizione' => 'Bonus/malus incrociato in base alla differenza fra le somme dei voti dei centrocampisti, a parità di numero (i mancanti si equiparano con un voto fittizio)',
        'parametri' => [
            'fasce' => [
                ['da' => 0,   'a' => 0.99, 'bonus' => 0],
                ['da' => 1.0, 'a' => 1.99, 'bonus' => 1],
                ['da' => 2.0, 'a' => null, 'bonus' => 2],
            ],
            'voto_fittizio' => 5,
        ],
    ],
    'ATTACCO' => [
        'algoritmo' => 'bonus_voto_netto_v1',
        'descrizione' => 'Bonus all\'attaccante senza gol fatto, in base al voto netto già maturato',
        'parametri' => [
            'fasce' => [
                ['da' => 6.0, 'a' => 6.49, 'bonus' => 0.5],
                ['da' => 6.5, 'a' => 6.99, 'bonus' => 1],
                ['da' => 7.0, 'a' => null, 'bonus' => 1.5],
            ],
        ],
    ],
];

// Fasce di default per derivare i gol fatti di una squadra dal suo
// punteggio totale (NEW_RISULTATI.golf). "da"/"a" null = illimitato.
const FASCE_GOL_PUNTEGGIO_DEFAULT = [
    ['da' => null, 'a' => 65.999,  'gol' => 0],
    ['da' => 66,   'a' => 71.999,  'gol' => 1],
    ['da' => 72,   'a' => 76.999,  'gol' => 2],
    ['da' => 77,   'a' => 80.999,  'gol' => 3],
    ['da' => 81,   'a' => 84.999,  'gol' => 4],
    ['da' => 85,   'a' => 88.999,  'gol' => 5],
    ['da' => 89,   'a' => 92.999,  'gol' => 6],
    ['da' => 93,   'a' => 96.999,  'gol' => 7],
    ['da' => 97,   'a' => 100.999, 'gol' => 8],
    ['da' => 101,  'a' => 104.999, 'gol' => 9],
    ['da' => 105,  'a' => 108.999, 'gol' => 10],
    ['da' => 109,  'a' => null,    'gol' => 11],
];

// Parametri di stagione non riconducibili a bonus/malus o modificatori.
// Funzione (non const): una "const" non può contenere una chiamata di
// funzione come json_encode() nell'inizializzatore.
function parametri_default(): array
{
    return [
        ['codice' => 'SOSTITUZIONI_MAX_MOVIMENTO',   'etichetta' => 'Sostituzioni di movimento (portiere escluso)', 'valore' => '5'],
        ['codice' => 'SOSTITUZIONI_MAX_PORTIERE',    'etichetta' => 'Sostituzioni portiere',                        'valore' => '1'],
        ['codice' => 'ULTIMA_GIORNATA_FATTORE_CASA', 'etichetta' => 'Ultima giornata con fattore casa (vuoto = sempre)', 'valore' => ''],
        ['codice' => 'NUMERO_GIORNATE',              'etichetta' => 'Numero di giornate della stagione',            'valore' => '38'],
        ['codice' => 'TOPFLOP_SOGLIA_PERCENTUALE',   'etichetta' => 'Top/Flop 11: giocate minime (% delle partite giocate)', 'valore' => '50'],
        ['codice' => 'MARCATORI_MIN_GIOCATE',        'etichetta' => 'Marcatori: giocate minime per migliori/peggiori',       'valore' => '5'],
        ['codice' => 'FASCE_GOL_PUNTEGGIO',          'etichetta' => 'Fasce gol fatti da punteggio totale',
            'valore' => json_encode(FASCE_GOL_PUNTEGGIO_DEFAULT, JSON_UNESCAPED_UNICODE)],
    ];
}

function carica_bonus(int $stagione, $conn): array
{
    return query_all("SELECT codice, etichetta, valore, ordine, attivo
                       FROM NEW_REGOLE_BONUS
                       WHERE stagione = $stagione
                       ORDER BY ordine, codice");
}

function carica_algoritmi(int $stagione, $conn): array
{
    $rows = query_all("SELECT tipo, algoritmo, parametri, descrizione
                        FROM NEW_REGOLE_ALGORITMI
                        WHERE stagione = $stagione");
    $out = [];
    foreach ($rows as $r) {
        $out[$r['tipo']] = [
            'algoritmo'   => $r['algoritmo'],
            'descrizione' => $r['descrizione'],
            'parametri'   => json_decode($r['parametri'], true),
        ];
    }
    return $out;
}

function carica_parametri(int $stagione, $conn): array
{
    return query_all("SELECT codice, etichetta, valore
                       FROM NEW_PARAMETRI_STAGIONE
                       WHERE stagione = $stagione
                       ORDER BY codice");
}

function salva_parametro(int $stagione, string $codice, string $etichetta, string $valore, $conn): void
{
    $codiceEsc    = mysqli_real_escape_string($conn, $codice);
    $etichettaEsc = mysqli_real_escape_string($conn, $etichetta);
    $valoreEsc    = mysqli_real_escape_string($conn, $valore);

    $exists = query_one("SELECT COUNT(*) AS n FROM NEW_PARAMETRI_STAGIONE
                         WHERE stagione = $stagione AND codice = '$codiceEsc'");
    if ((int) $exists['n'] > 0) {
        mysqli_query($conn, "UPDATE NEW_PARAMETRI_STAGIONE
            SET etichetta = '$etichettaEsc', valore = '$valoreEsc'
            WHERE stagione = $stagione AND codice = '$codiceEsc'");
    } else {
        mysqli_query($conn, "INSERT INTO NEW_PARAMETRI_STAGIONE (stagione, codice, etichetta, valore)
            VALUES ($stagione, '$codiceEsc', '$etichettaEsc', '$valoreEsc')");
    }
}

// Copia bonus/algoritmi/parametri dalla stagione sorgente a quella nuova.
function copia_configurazione(int $stagioneOrigine, int $stagioneNuova, $conn): void
{
    $bonus = carica_bonus($stagioneOrigine, $conn);
    foreach ($bonus as $b) {
        $codice    = mysqli_real_escape_string($conn, $b['codice']);
        $etichetta = mysqli_real_escape_string($conn, $b['etichetta']);
        mysqli_query($conn, "INSERT INTO NEW_REGOLE_BONUS
            (stagione, codice, etichetta, valore, ordine, attivo)
            VALUES ($stagioneNuova, '$codice', '$etichetta', {$b['valore']}, {$b['ordine']}, {$b['attivo']})");
    }
    $algoritmi = carica_algoritmi($stagioneOrigine, $conn);
    foreach ($algoritmi as $tipo => $a) {
        salva_algoritmo($stagioneNuova, $tipo, $a['algoritmo'], $a['parametri'], $a['descrizione'], $conn);
    }
    $parametri = carica_parametri($stagioneOrigine, $conn);
    foreach ($parametri as $p) {
        salva_parametro($stagioneNuova, $p['codice'], $p['etichetta'], $p['valore'], $conn);
    }
}

function semina_default(int $stagione, $conn): void
{
    foreach (CODICI_BONUS_DEFAULT as $b) {
        mysqli_query($conn, "INSERT INTO NEW_REGOLE_BONUS
            (stagione, codice, etichetta, valore, ordine, attivo)
            VALUES ($stagione, '{$b['codice']}', '{$b['etichetta']}', {$b['valore']}, {$b['ordine']}, 1)");
    }
    foreach (ALGORITMI_DEFAULT as $tipo => $a) {
        salva_algoritmo($stagione, $tipo, $a['algoritmo'], $a['parametri'], $a['descrizione'], $conn);
    }
    foreach (parametri_default() as $p) {
        salva_parametro($stagione, $p['codice'], $p['etichetta'], $p['valore'], $conn);
    }
}

function salva_algoritmo(int $stagione, string $tipo, string $algoritmo, array $parametri, ?string $descrizione, $conn): void
{
    $tipoEsc  = mysqli_real_escape_string($conn, $tipo);
    $algoEsc  = mysqli_real_escape_string($conn, $algoritmo);
    $descEsc  = $descrizione !== null ? "'" . mysqli_real_escape_string($conn, $descrizione) . "'" : "NULL";
    $paramEsc = mysqli_real_escape_string($conn, json_encode($parametri, JSON_UNESCAPED_UNICODE));

    $exists = query_one("SELECT COUNT(*) AS n FROM NEW_REGOLE_ALGORITMI
                         WHERE stagione = $stagione AND tipo = '$tipoEsc'");
    if ((int) $exists['n'] > 0) {
        mysqli_query($conn, "UPDATE NEW_REGOLE_ALGORITMI
            SET algoritmo = '$algoEsc', parametri = '$paramEsc', descrizione = $descEsc
            WHERE stagione = $stagione AND tipo = '$tipoEsc'");
    } else {
        mysqli_query($conn, "INSERT INTO NEW_REGOLE_ALGORITMI (stagione, tipo, algoritmo, parametri, descrizione)
            VALUES ($stagione, '$tipoEsc', '$algoEsc', '$paramEsc', $descEsc)");
    }
}

// ============================================================
// POST
// ============================================================
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $input    = json_decode(file_get_contents("php://input"), true) ?? $_POST;
    $stagione = (int) ($input['stagione'] ?? 0);
    if (!$stagione) api_error("Parametro obbligatorio mancante: stagione", 400);

    if (isset($input['bonus']) && is_array($input['bonus'])) {
        foreach ($input['bonus'] as $b) {
            $codice = trim((string) ($b['codice'] ?? ''));
            if ($codice === '') continue;
            if (!preg_match('/^[A-Z0-9_]{2,30}$/', $codice)) {
                api_error("Codice bonus non valido: \"$codice\" (solo lettere maiuscole, cifre e underscore)", 400);
            }
            $etichetta = trim((string) ($b['etichetta'] ?? $codice));
            if (!is_numeric($b['valore'] ?? null)) {
                api_error("Valore non numerico per la voce \"$codice\"", 400);
            }
            $valore = (float) $b['valore'];
            $ordine = (int) ($b['ordine'] ?? 0);
            $attivo = empty($b['attivo']) ? 0 : 1;

            $codiceEsc    = mysqli_real_escape_string($conn, $codice);
            $etichettaEsc = mysqli_real_escape_string($conn, $etichetta);

            $exists = query_one("SELECT COUNT(*) AS n FROM NEW_REGOLE_BONUS
                                 WHERE stagione = $stagione AND codice = '$codiceEsc'");
            if ((int) $exists['n'] > 0) {
                mysqli_query($conn, "UPDATE NEW_REGOLE_BONUS
                    SET etichetta = '$etichettaEsc', valore = $valore, ordine = $ordine, attivo = $attivo
                    WHERE stagione = $stagione AND codice = '$codiceEsc'");
            } else {
                mysqli_query($conn, "INSERT INTO NEW_REGOLE_BONUS
                    (stagione, codice, etichetta, valore, ordine, attivo)
                    VALUES ($stagione, '$codiceEsc', '$etichettaEsc', $valore, $ordine, $attivo)");
            }
        }
    }

    if (isset($input['algoritmi']) && is_array($input['algoritmi'])) {
        foreach ($input['algoritmi'] as $tipo => $a) {
            if (!in_array($tipo, ['DIFESA', 'CENTROCAMPO', 'ATTACCO'], true)) {
                api_error("Tipo di algoritmo non valido: \"$tipo\"", 400);
            }
            $algoritmo = trim((string) ($a['algoritmo'] ?? ''));
            if ($algoritmo === '') api_error("Manca il codice algoritmo per \"$tipo\"", 400);
            $parametri = $a['parametri'] ?? null;
            if (!is_array($parametri)) api_error("Parametri mancanti o non validi per \"$tipo\"", 400);

            // Validazione minima delle fasce: da/a numerici o null, bonus numerico
            foreach ($parametri['fasce'] ?? [] as $f) {
                foreach (['da', 'a'] as $k) {
                    if (isset($f[$k]) && $f[$k] !== null && !is_numeric($f[$k])) {
                        api_error("Fascia non valida in \"$tipo\": \"$k\" deve essere numerico o vuoto", 400);
                    }
                }
                if (!isset($f['bonus']) || !is_numeric($f['bonus'])) {
                    api_error("Fascia non valida in \"$tipo\": \"bonus\" deve essere numerico", 400);
                }
            }
            if ($tipo === 'CENTROCAMPO' && isset($parametri['voto_fittizio']) && !is_numeric($parametri['voto_fittizio'])) {
                api_error("\"voto_fittizio\" del centrocampo deve essere numerico", 400);
            }

            salva_algoritmo($stagione, $tipo, $algoritmo, $parametri, $a['descrizione'] ?? null, $conn);
        }
    }

    if (isset($input['parametri']) && is_array($input['parametri'])) {
        foreach ($input['parametri'] as $p) {
            $codice = trim((string) ($p['codice'] ?? ''));
            if ($codice === '') continue;
            if (!preg_match('/^[A-Z0-9_]{2,50}$/', $codice)) {
                api_error("Codice parametro non valido: \"$codice\" (solo lettere maiuscole, cifre e underscore)", 400);
            }
            $etichetta = trim((string) ($p['etichetta'] ?? $codice));
            $valore    = (string) ($p['valore'] ?? '');
            salva_parametro($stagione, $codice, $etichetta, $valore, $conn);
        }
    }

    api_success(["ok" => true]);
}

// ============================================================
// GET
// ============================================================
if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    api_error("Metodo non consentito. Usare GET o POST.", 405);
}

$stagione = param_int("stagione");
if (!$stagione) api_error("Parametro obbligatorio mancante: stagione", 400);

$bonus        = carica_bonus($stagione, $conn);
$algoritmi    = carica_algoritmi($stagione, $conn);
$parametri    = carica_parametri($stagione, $conn);
$ereditata_da = null;

if (empty($bonus) && empty($algoritmi) && empty($parametri)) {
    // Prima configurazione per questa stagione: eredita dalla stagione
    // configurata più recente, se esiste, altrimenti semina i default.
    $altra = query_one("SELECT MAX(stagione) AS s FROM NEW_REGOLE_BONUS WHERE stagione <> $stagione");
    $stagioneOrigine = (int) ($altra['s'] ?? 0);

    if ($stagioneOrigine > 0) {
        copia_configurazione($stagioneOrigine, $stagione, $conn);
        $ereditata_da = $stagioneOrigine;
    } else {
        semina_default($stagione, $conn);
    }
    $bonus     = carica_bonus($stagione, $conn);
    $algoritmi = carica_algoritmi($stagione, $conn);
    $parametri = carica_parametri($stagione, $conn);
}

api_success([
    "stagione"      => $stagione,
    "ereditata_da"  => $ereditata_da,
    "bonus"         => $bonus,
    "algoritmi"     => $algoritmi,
    "parametri"     => $parametri,
]);
