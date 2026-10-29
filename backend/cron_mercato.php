<?php
// ============================================================
// api/cron_mercato.php
//
// Endpoint per il CRON del Mercato di riparazione: serve SOLO per i solleciti
// alla squadra che ha superato il tempo limite. Non apre ne' chiude sessioni
// e non chiude turni (il turno si chiude solo alla conferma dell'utente).
// Da chiamare ogni 15-30 minuti (cron-job.org o cron Altervista).
//
// GET/POST: ?token=<cron_token di config_api_football.php>[&stagione=2026]
// Stesso token del cron API-Football (nessuna nuova configurazione).
// ============================================================
require_once __DIR__ . "/connect.php";
require_once __DIR__ . "/lib/ApiFootballClient.php";
require_once __DIR__ . "/lib/MercatoRiparazione.php";

$input = $_SERVER["REQUEST_METHOD"] === "POST"
    ? (json_decode(file_get_contents("php://input"), true) ?? $_POST)
    : $_GET;

if (!af_cron_token_valido(trim((string) ($input["token"] ?? "")))) {
    sleep(1);
    api_error("Accesso negato", 403);
}

$stagione = (int) ($input["stagione"] ?? 0);
if ($stagione < 2000 || $stagione > 2100) $stagione = sg_ultima_stagione();
if ($stagione < 2000) api_error("Nessuna stagione disponibile", 404);

try {
    api_success(["stagione" => $stagione] + mr_tick($stagione, MR_FINESTRA_1, false, true));
} catch (Throwable $e) {
    api_error("Elaborazione mercato non riuscita: " . $e->getMessage(), 500);
}
