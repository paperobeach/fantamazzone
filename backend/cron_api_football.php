<?php
// ============================================================
// api/cron_api_football.php
//
// Endpoint per il CRON (una chiamata all'ora, anche da un servizio esterno
// come cron-job.org). Applica le regole di lib/ApiFootballSync.php:
//   - se non ci sono partite in corso o appena concluse e non ancora
//     sincronizzate, NON viene effettuata alcuna chiamata ad API-Football;
//   - altrimenti si interroga l'API e, a partita conclusa, si salvano le
//     statistiche dei giocatori in NEW_API_STATISTICHE.
//
// GET/POST: ?token=<cron_token di config_api_football.php>
//           [&stagione=2026]   (default: ultima stagione del calendario)
//           [&dry=1]           (solo decisioni, nessuna chiamata all'API)
//
// L'accesso è protetto dal token: senza token valido la risposta è 403 e
// non viene rivelato altro. Esito HTTP 500 se la sincronizzazione fallisce
// (utile per gli avvisi di errore del servizio di cron).
// ============================================================

require_once __DIR__ . "/connect.php";
require_once __DIR__ . "/lib/ApiFootballClient.php";
require_once __DIR__ . "/lib/ApiFootballSync.php";

$input = $_SERVER["REQUEST_METHOD"] === "POST"
    ? (json_decode(file_get_contents("php://input"), true) ?? $_POST)
    : $_GET;

$token = trim((string) ($input["token"] ?? ""));
if (!af_cron_token_valido($token)) {
    sleep(1);   // rallenta eventuali tentativi a vuoto
    api_error("Accesso negato", 403);
}

$stagione = (int) ($input["stagione"] ?? 0);
if ($stagione < 2000 || $stagione > 2100) {
    $r = query_one("SELECT MAX(stagione) AS s FROM NEW_CALENDARIO_SERIE_A");
    $stagione = (int) ($r["s"] ?? 0);
}
if ($stagione < 2000) {
    api_error("Nessuna stagione con calendario Serie A", 404);
}

$rep = af_sync_esegui($stagione, ["dry" => !empty($input["dry"])]);
if (!$rep["ok"]) {
    api_error($rep["messaggio"] !== "" ? $rep["messaggio"] : "Sincronizzazione non riuscita", 500);
}
api_success($rep);
