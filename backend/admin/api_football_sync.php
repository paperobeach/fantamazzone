<?php
// ============================================================
// api/admin/api_football_sync.php
//
// FASE 1 - Monitor e comandi manuali della sincronizzazione con API-Football
// (stessa logica del cron, vedi lib/ApiFootballSync.php). Usato dalla pagina
// "Sincronizzazione API-Football" (menu Admin → Avvio stagione).
//
// Solo POST. Body: { stagione, utenza, password, azione, ... }
// ACCESSO: utenza amministratore (password verificata lato server).
//
// azione = "stato"      { giornata?: giornata di SERIE A }
//                       partite della giornata con stato di sincronizzazione, quota,
//                       ultime chiamate. Nessuna chiamata API.
// azione = "simula"     applica le regole del cron SENZA chiamare l'API
// azione = "esegui"     esegue subito una sincronizzazione (come il cron)
// azione = "forza"      { giornata, n_partita }: rilegge UNA partita anche se già
//                       sincronizzata (1-2 richieste)
// ============================================================

require_once __DIR__ . "/../connect.php";
require_once __DIR__ . "/../lib/BackupStagione.php";
require_once __DIR__ . "/../lib/ApiFootballClient.php";
require_once __DIR__ . "/../lib/ApiFootballSync.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    api_error("Metodo non consentito. Usare POST.", 405);
}

$input    = json_decode(file_get_contents("php://input"), true) ?? $_POST;
$stagione = (int) ($input["stagione"] ?? 0);
$utenza   = trim((string) ($input["utenza"] ?? ""));
$password = (string) ($input["password"] ?? "");
$azione   = strtolower(trim((string) ($input["azione"] ?? "stato")));

if ($stagione < 2000 || $stagione > 2100) {
    api_error("Parametro 'stagione' obbligatorio e deve essere un anno a 4 cifre plausibile (2000-2100)", 400);
}
if (!in_array($azione, ["stato", "simula", "esegui", "forza"], true)) {
    api_error("Azione non valida", 400);
}
if (!bks_verifica_admin($conn, $stagione, $utenza, $password)) {
    api_error("Credenziali non valide oppure utenza non amministratore", 403);
}
$manc = af_tabelle_mancanti();
if ($manc) {
    api_error("Tabelle mancanti: " . implode(", ", $manc) . ". Eseguire Script DB/NEW_API_FOOTBALL.sql", 500);
}

if ($azione === "simula") {
    api_success(af_sync_esegui($stagione, ["dry" => true]));
}
if ($azione === "esegui") {
    api_success(af_sync_esegui($stagione));
}
if ($azione === "forza") {
    $g = (int) ($input["giornata"] ?? 0);
    $n = (int) ($input["n_partita"] ?? 0);
    if ($g <= 0 || $n <= 0) api_error("Indicare 'giornata' (Serie A) e 'n_partita'", 400);
    api_success(af_sync_esegui($stagione, ["forza" => ["giornata" => $g, "n_partita" => $n]]));
}

// ------------------------------------------------------------ stato
$ora = new DateTimeImmutable("now", af_fuso());
$giornate = array_map(fn($r) => (int) $r["giornata"],
    query_all("SELECT DISTINCT giornata FROM NEW_CALENDARIO_SERIE_A WHERE stagione = $stagione ORDER BY giornata"));

$giornata = (int) ($input["giornata"] ?? 0);
if (!$giornata || !in_array($giornata, $giornate, true)) {
    // Predefinita: la giornata col calcio d'inizio più vicino all'ora attuale
    $r = query_one("SELECT giornata FROM NEW_CALENDARIO_SERIE_A WHERE stagione = $stagione
                    ORDER BY ABS(TIMESTAMPDIFF(MINUTE, CONCAT(data_partita, ' ', COALESCE(ora_partita, '12:00:00')), '" . $ora->format("Y-m-d H:i:s") . "'))
                    LIMIT 1");
    $giornata = $r ? (int) $r["giornata"] : ($giornate[0] ?? 0);
}

$tm = query_one("SELECT COUNT(*) AS n, SUM(squadra_serie_a IS NOT NULL) AS m FROM NEW_API_MAPPING_SQUADRE WHERE stagione = $stagione");
$gm = query_one("SELECT COUNT(*) AS n, SUM(id_giocatore IS NOT NULL) AS m FROM NEW_API_MAPPING_GIOCATORI WHERE stagione = $stagione");

api_success([
    "ora_roma"        => $ora->format("Y-m-d H:i:s"),
    "chiave_impostata" => af_chiave_configurata(),
    "giornate"        => $giornate,
    "giornata"        => $giornata,
    "partite"         => $giornata ? af_stato_giornata($stagione, $giornata, $ora) : [],
    "regole"          => af_regole(),
    "richieste_oggi"  => af_richieste_oggi(),
    "quota_max_locale" => (int) af_cfg("quota_giornaliera_max", 90),
    "mappatura"       => [
        "squadre_api" => (int) $tm["n"], "squadre_associate" => (int) $tm["m"],
        "giocatori_api" => (int) $gm["n"], "giocatori_associati" => (int) $gm["m"],
    ],
    "ultime_chiamate" => af_ultime_chiamate(12),
]);
