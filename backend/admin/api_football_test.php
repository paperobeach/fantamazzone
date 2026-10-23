<?php
// ============================================================
// api/admin/api_football_test.php
//
// FASE 0 - Verifiche preliminari dell'integrazione con API-Football:
// raggiungibilità dall'hosting, chiave/piano/quota, copertura della Serie A
// e, soprattutto, prova su UNA partita conclusa del formato dei dati
// (parametro "ids", presenza di giocatori/eventi, minuti, rating).
// Usato dalla pagina "Test API-Football" (menu Admin → Avvio stagione).
// Non scrive nulla sul database.
//
// Solo POST. Body: { stagione, utenza, password, azione, [fixture_id] }
// ACCESSO: utenza amministratore (password verificata lato server).
//
// azione = "diagnostica"   controlli locali, nessuna chiamata, nessun costo
// azione = "rete"          raggiungibilità di API-Football, gratuita
// azione = "stato"         GET /status: piano, scadenza, richieste usate (1 richiesta)
// azione = "copertura"     GET /leagues?id=135: copertura per stagione (1 richiesta)
// azione = "prova_fixture" una partita conclusa recente (o fixture_id indicato):
//                          2-4 richieste. Nessun salvataggio.
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
$azione   = strtolower(trim((string) ($input["azione"] ?? "diagnostica")));

if ($stagione < 2000 || $stagione > 2100) {
    api_error("Parametro 'stagione' obbligatorio e deve essere un anno a 4 cifre plausibile (2000-2100)", 400);
}
if (!in_array($azione, ["diagnostica", "rete", "stato", "copertura", "prova_fixture"], true)) {
    api_error("Azione non valida", 400);
}
if (!bks_verifica_admin($conn, $stagione, $utenza, $password)) {
    api_error("Credenziali non valide oppure utenza non amministratore", 403);
}

// ------------------------------------------------------------
if ($azione === "diagnostica") {
    $cfgFile = af_config() !== null;
    api_success([
        "ambiente"            => af_ambiente(),
        "ora_roma"            => (new DateTimeImmutable("now", af_fuso()))->format("Y-m-d H:i:s"),
        "file_configurazione" => $cfgFile,
        "chiave_impostata"    => $cfgFile && af_chiave_configurata(),
        "token_cron_valido"   => $cfgFile && af_cron_token_valido((string) af_cfg("cron_token", "")),
        "proxy"               => (string) af_cfg("proxy", "auto"),
        "lega"                => (int) af_cfg("league_id", 135),
        "season_api"          => af_season($stagione),
        "regole"              => af_regole(),
        "quota_max_locale"    => (int) af_cfg("quota_giornaliera_max", 90),
        "richieste_oggi"      => af_richieste_oggi(),
        "tabelle_mancanti"    => af_tabelle_mancanti(),
    ]);
}

if ($azione === "rete") {
    api_success(af_sonda_rete());
}

if ($azione === "stato") {
    $res = af_chiamata("status", [], $stagione);
    $r = $res["risposta"] ?? [];
    api_success([
        "ok"      => $res["ok"], "errore" => $res["errore"], "http" => $res["http"], "ms" => $res["ms"],
        "account" => $r["account"] ?? null,
        "piano"   => $r["subscription"] ?? null,
        "richieste" => $r["requests"] ?? null,
        "richieste_oggi_registro" => af_richieste_oggi(),
    ]);
}

if ($azione === "copertura") {
    $lega = (int) af_cfg("league_id", 135);
    $season = af_season($stagione);
    $res = af_chiamata("leagues", ["id" => $lega], $stagione);
    if (!$res["ok"]) {
        api_success(["ok" => false, "errore" => $res["errore"], "http" => $res["http"]]);
    }
    $l = $res["risposta"][0] ?? null;
    if (!$l) api_success(["ok" => false, "errore" => "Lega $lega non trovata"]);
    $stagioni = [];
    $trovata = null;
    foreach ($l["seasons"] ?? [] as $s) {
        $riga = ["anno" => (int) $s["year"], "inizio" => $s["start"] ?? null, "fine" => $s["end"] ?? null,
                 "corrente" => (bool) ($s["current"] ?? false), "copertura" => $s["coverage"] ?? null];
        $stagioni[] = $riga;
        if ((int) $s["year"] === $season) $trovata = $riga;
    }
    $ultime = array_slice($stagioni, -4);
    api_success([
        "ok" => true, "lega" => ["id" => $lega, "nome" => $l["league"]["name"] ?? "", "paese" => $l["country"]["name"] ?? ""],
        "season_richiesta" => $season, "season_trovata" => $trovata !== null, "dettaglio_season" => $trovata,
        "ultime_stagioni" => $ultime,
    ]);
}

// ============================================================
// prova_fixture
// ============================================================
$lega   = (int) af_cfg("league_id", 135);
$season = af_season($stagione);
$out = ["ok" => true, "passi" => [], "avvisi" => []];

$fixtureId = (int) ($input["fixture_id"] ?? 0);
if ($fixtureId <= 0) {
    $oggi = new DateTimeImmutable("now", af_fuso());
    $res = af_chiamata("fixtures", [
        "league" => $lega, "season" => $season, "status" => "FT",
        "from" => $oggi->modify("-21 days")->format("Y-m-d"), "to" => $oggi->format("Y-m-d"),
    ], $stagione);
    $out["passi"][] = ["passo" => "Ricerca partita conclusa (ultime 3 settimane)", "ok" => $res["ok"], "errore" => $res["errore"], "trovate" => count($res["risposta"])];
    if (!$res["ok"]) { $out["ok"] = false; api_success($out); }
    if (!$res["risposta"]) {
        $out["ok"] = false;
        $out["avvisi"][] = "Nessuna partita conclusa nelle ultime 3 settimane per lega $lega / stagione $season (pausa del campionato, stagione non disponibile nel piano o season_api errato). Indicare un fixture_id.";
        api_success($out);
    }
    usort($res["risposta"], fn($a, $b) => strcmp((string) ($b["fixture"]["date"] ?? ""), (string) ($a["fixture"]["date"] ?? "")));
    $fixtureId = (int) $res["risposta"][0]["fixture"]["id"];
}

// Dettaglio con il parametro "ids" (lo stesso usato dal cron)
$res = af_chiamata("fixtures", ["ids" => (string) $fixtureId], $stagione);
$fx = $res["risposta"][0] ?? null;
$out["passi"][] = ["passo" => "fixtures?ids=$fixtureId (chiamata del cron)", "ok" => $res["ok"] && $fx !== null,
                   "errore" => $res["errore"], "http" => $res["http"], "ms" => $res["ms"]];
if (!$res["ok"] || !$fx) { $out["ok"] = false; $out["ids_supportato"] = false; api_success($out); }

$out["ids_supportato"] = (int) ($fx["fixture"]["id"] ?? 0) === $fixtureId;
$out["partita"] = [
    "fixture_id" => $fixtureId,
    "casa" => $fx["teams"]["home"]["name"] ?? "", "ospite" => $fx["teams"]["away"]["name"] ?? "",
    "gol" => ($fx["goals"]["home"] ?? "?") . "-" . ($fx["goals"]["away"] ?? "?"),
    "stato" => $fx["fixture"]["status"]["short"] ?? "", "data" => $fx["fixture"]["date"] ?? "",
];
$out["sezioni_nella_risposta"] = [
    "players" => !empty($fx["players"]), "events" => isset($fx["events"]),
    "lineups" => !empty($fx["lineups"]), "statistics" => !empty($fx["statistics"]),
];

$giocFb = null; $evFb = null;
if (empty($fx["players"])) {
    $r2 = af_chiamata("fixtures/players", ["fixture" => $fixtureId], $stagione);
    $out["passi"][] = ["passo" => "Fallback fixtures/players", "ok" => $r2["ok"] && $r2["risposta"], "errore" => $r2["errore"]];
    $giocFb = $r2["ok"] ? $r2["risposta"] : null;
    $out["avvisi"][] = "Il dettaglio con 'ids' NON contiene i giocatori: il cron userà il fallback (1 chiamata in più per partita).";
}
if (!isset($fx["events"])) {
    $r3 = af_chiamata("fixtures/events", ["fixture" => $fixtureId], $stagione);
    $out["passi"][] = ["passo" => "Fallback fixtures/events", "ok" => $r3["ok"], "errore" => $r3["errore"]];
    $evFb = $r3["ok"] ? $r3["risposta"] : null;
}

$righe = af_righe_statistiche($fx, $giocFb, $evFb);
$presenti = array_filter($righe, fn($r) => $r["presente"] === "Y");
$conRating = array_filter($presenti, fn($r) => $r["voto_api"] !== null);
$out["giocatori"] = [
    "totale" => count($righe), "con_minuti" => count($presenti), "con_rating" => count($conRating),
    "gol" => array_sum(array_column($righe, "gf")) + array_sum(array_column($righe, "rf")),
    "assist" => array_sum(array_column($righe, "ass")),
    "ammonizioni" => array_sum(array_column($righe, "amm")), "espulsioni" => array_sum(array_column($righe, "esp")),
    "autogol" => array_sum(array_column($righe, "au")),
];
if (!$righe) {
    $out["ok"] = false;
    $out["avvisi"][] = "Nessuna statistica giocatore disponibile per questa partita: l'integrazione non è utilizzabile con questo piano/lega.";
} elseif (!$presenti) {
    $out["avvisi"][] = "Nessun giocatore con minuti: dati incompleti.";
}
if ($righe && !$conRating) {
    $out["avvisi"][] = "Il rating dei giocatori non è presente: il voto base dovrà essere sempre 6 (politica A).";
}

// Esempio: i primi giocatori con minuti, più eventuali con bonus/malus
$conBonus = array_values(array_filter($presenti, fn($r) => $r["gf"] + $r["rf"] + $r["ass"] + $r["amm"] + $r["esp"] + $r["au"] + $r["rp"] + $r["rs"] > 0));
$altri = array_values(array_filter($presenti, fn($r) => !in_array($r, $conBonus, true)));
$esempio = array_slice(array_merge($conBonus, $altri), 0, 8);
$out["esempio"] = array_map(fn($r) => [
    "nome" => $r["nome"], "ruolo" => $r["posizione"], "minuti" => $r["minuti"], "rating" => $r["voto_api"],
    "gf" => $r["gf"], "rf" => $r["rf"], "gs" => $r["gs"], "ass" => $r["ass"], "amm" => $r["amm"], "esp" => $r["esp"], "au" => $r["au"],
], $esempio);
$out["richieste_oggi"] = af_richieste_oggi();
api_success($out);
