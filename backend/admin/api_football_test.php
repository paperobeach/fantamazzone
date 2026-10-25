<?php
// ============================================================
// api/admin/api_football_test.php
//
// FASE 0 - Verifiche preliminari dell'integrazione con API-Football / TheSportsDB
// (fornitore scelto da "provider" in config_api_football.php):
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
//
// "season" (facoltativo, per copertura e prova_fixture): anno di stagione API-Football da
// usare al posto di quello configurato. Serve col piano gratuito, che dà accesso solo a
// stagioni passate (es. 2024): si verifica il formato dei dati su una partita di quella stagione.
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
$seasonIn = (int) ($input["season"] ?? 0);   // stagione API alternativa (0 = quella configurata)

if ($stagione < 2000 || $stagione > 2100) {
    api_error("Parametro 'stagione' obbligatorio e deve essere un anno a 4 cifre plausibile (2000-2100)", 400);
}
if (!in_array($azione, ["diagnostica", "rete", "stato", "copertura", "prova_fixture"], true)) {
    api_error("Azione non valida", 400);
}
if ($seasonIn !== 0 && ($seasonIn < 2000 || $seasonIn > 2100)) {
    api_error("Parametro 'season' non valido (anno a 4 cifre)", 400);
}
if (!bks_verifica_admin($conn, $stagione, $utenza, $password)) {
    api_error("Credenziali non valide oppure utenza non amministratore", 403);
}

/** Riepilogo delle statistiche ricavate da una partita (comune ai due fornitori). */
function prova_riepilogo(array $righe, array &$out): void
{
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
}

// ------------------------------------------------------------
if ($azione === "diagnostica") {
    $cfgFile = af_config() !== null;
    api_success([
        "ambiente"            => af_ambiente(),
        "ora_roma"            => (new DateTimeImmutable("now", af_fuso()))->format("Y-m-d H:i:s"),
        "file_configurazione" => $cfgFile,
        "provider"            => af_provider(),
        "chiave_impostata"    => $cfgFile && af_chiave_configurata(),
        "token_cron_valido"   => $cfgFile && af_cron_token_valido((string) af_cfg("cron_token", "")),
        "proxy"               => (string) af_cfg("proxy", "auto"),
        "lega"                => af_provider() === "thesportsdb" ? (int) af_cfg("tsdb_league_id", 4332) : (int) af_cfg("league_id", 135),
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

if ($azione === "stato" && af_provider() === "thesportsdb") {
    $lega = (int) af_cfg("tsdb_league_id", 4332);
    $res = tsdb_chiamata("lookupleague.php", ["id" => $lega], $stagione);
    $l = $res["dati"]["leagues"][0] ?? [];
    $gratuita = tsdb_chiave_gratuita();
    api_success([
        "ok" => $res["ok"], "errore" => $res["errore"], "http" => $res["http"], "ms" => $res["ms"],
        "account" => null,
        "piano" => ["plan" => "TheSportsDB · " . ($gratuita ? "chiave GRATUITA (risposte troncate)" : "chiave personale") . (!empty($l["strLeague"]) ? " · " . $l["strLeague"] : ""),
                    "active" => true, "end" => null],
        "richieste" => ["current" => null, "limit_day" => null],
        "richieste_oggi_registro" => af_richieste_oggi(),
        "avviso" => $gratuita
            ? "Con la chiave gratuita TheSportsDB restituisce al massimo 5 elementi per formazioni e cronologia di una partita, 10 squadre, 10 giocatori per rosa e 15 partite di calendario: non bastano per le statistiche dei giocatori. Serve una chiave personale (piano a pagamento)."
            : null,
    ]);
}

if ($azione === "copertura" && af_provider() === "thesportsdb") {
    $lega = (int) af_cfg("tsdb_league_id", 4332);
    $season = $seasonIn ?: af_season($stagione);
    $stagStr = $season . "-" . ($season + 1);
    $rl = tsdb_chiamata("lookupleague.php", ["id" => $lega], $stagione);
    if (!$rl["ok"]) api_success(["ok" => false, "errore" => $rl["errore"], "http" => $rl["http"]]);
    $l = $rl["dati"]["leagues"][0] ?? [];
    $rs = tsdb_chiamata("search_all_seasons.php", ["id" => $lega], $stagione);
    if (!$rs["ok"]) api_success(["ok" => false, "errore" => $rs["errore"], "http" => $rs["http"]]);
    $anni = [];
    foreach ($rs["dati"]["seasons"] ?? [] as $x) {
        $a = (int) substr((string) ($x["strSeason"] ?? ""), 0, 4);
        if ($a > 0) $anni[$a] = ["anno" => $a, "inizio" => (string) $x["strSeason"], "fine" => null, "corrente" => false, "copertura" => null];
    }
    ksort($anni);
    $trovata = $anni[$season] ?? null;
    api_success([
        "ok" => true, "lega" => ["id" => $lega, "nome" => $l["strLeague"] ?? "", "paese" => $l["strCountry"] ?? ""],
        "season_richiesta" => $season, "season_trovata" => $trovata !== null, "dettaglio_season" => $trovata,
        "ultime_stagioni" => array_slice(array_values($anni), -4),
        "avviso" => tsdb_chiave_gratuita() ? "Chiave gratuita: l'elenco delle stagioni è limitato (5 voci) e potrebbe non mostrare la stagione cercata." : null,
    ]);
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
    $season = $seasonIn ?: af_season($stagione);
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
// prova_fixture - TheSportsDB
// ============================================================
if (af_provider() === "thesportsdb") {
    $lega = (int) af_cfg("tsdb_league_id", 4332);
    $gratuita = tsdb_chiave_gratuita();
    $season = $seasonIn ?: af_season($stagione);
    $stagStr = $season . "-" . ($season + 1);
    $out = ["ok" => true, "passi" => [], "avvisi" => [], "season_usata" => $season, "provider" => "thesportsdb"];
    $evento = null;
    $fixtureId = (int) ($input["fixture_id"] ?? 0);

    // Eventi conclusi di un elenco, dal più recente
    $conclusi = function (array $eventi): array {
        $f = array_values(array_filter($eventi, fn($e) => in_array(tsdb_stato($e["strStatus"] ?? "", $e["strPostponed"] ?? ""), AF_STATI_FINALI, true)));
        usort($f, fn($a, $b) => strcmp((string) ($b["strTimestamp"] ?? $b["dateEvent"] ?? ""), (string) ($a["strTimestamp"] ?? $a["dateEvent"] ?? "")));
        return $f;
    };

    if ($fixtureId > 0) {
        $res = tsdb_chiamata("lookupevent.php", ["id" => $fixtureId], $stagione);
        $out["passi"][] = ["passo" => "lookupevent.php?id=$fixtureId", "ok" => $res["ok"], "errore" => $res["errore"], "ms" => $res["ms"]];
        $evento = $res["ok"] ? ($res["dati"]["events"][0] ?? null) : null;
    } else {
        $ricerche = $seasonIn
            ? [["eventsseason.php", ["id" => $lega, "s" => $stagStr], "Calendario stagione $stagStr"]]
            : [["eventspastleague.php", ["id" => $lega], "Ultime partite della lega"], ["eventsseason.php", ["id" => $lega, "s" => $stagStr], "Calendario stagione $stagStr"]];
        foreach ($ricerche as [$ep, $par, $etichetta]) {
            $res = tsdb_chiamata($ep, $par, $stagione);
            $fin = $res["ok"] ? $conclusi($res["dati"]["events"] ?? []) : [];
            $out["passi"][] = ["passo" => $etichetta, "ok" => $res["ok"], "errore" => $res["errore"], "trovate" => count($fin), "ms" => $res["ms"]];
            if (!$res["ok"]) { $out["ok"] = false; api_success($out); }
            if ($fin) { $evento = $fin[0]; break; }
        }
        if ($evento === null) {
            $out["ok"] = false;
            $out["avvisi"][] = "Nessuna partita conclusa trovata per la lega $lega / stagione $stagStr. Indicare un ID partita o una stagione diversa.";
            api_success($out);
        }
    }
    if ($evento === null) { $out["ok"] = false; api_success($out); }

    $id = (int) ($evento["idEvent"] ?? $fixtureId);
    $out["partita"] = [
        "fixture_id" => $id, "casa" => $evento["strHomeTeam"] ?? "", "ospite" => $evento["strAwayTeam"] ?? "",
        "gol" => ($evento["intHomeScore"] ?? "?") . "-" . ($evento["intAwayScore"] ?? "?"),
        "stato" => tsdb_stato($evento["strStatus"] ?? "", $evento["strPostponed"] ?? ""), "data" => $evento["dateEvent"] ?? "",
    ];
    $rl = tsdb_chiamata("lookuplineup.php", ["id" => $id], $stagione);
    $lineup = $rl["ok"] && is_array($rl["dati"]["lineup"] ?? null) ? $rl["dati"]["lineup"] : [];
    $out["passi"][] = ["passo" => "lookuplineup.php (formazioni)", "ok" => $rl["ok"], "errore" => $rl["errore"], "trovate" => count($lineup), "ms" => $rl["ms"]];
    $rt = tsdb_chiamata("lookuptimeline.php", ["id" => $id], $stagione);
    $timeline = $rt["ok"] && is_array($rt["dati"]["timeline"] ?? null) ? $rt["dati"]["timeline"] : [];
    $out["passi"][] = ["passo" => "lookuptimeline.php (gol, cartellini, sostituzioni)", "ok" => $rt["ok"], "errore" => $rt["errore"], "trovate" => count($timeline), "ms" => $rt["ms"]];

    $completa = tsdb_formazioni_complete($lineup);
    $out["ids_supportato"] = true;
    $out["sezioni_nella_risposta"] = ["players" => $completa, "events" => count($timeline) > 0];

    if (!$completa) {
        $out["ok"] = false;
        $out["giocatori"] = null;
        $out["avvisi"][] = $gratuita
            ? "Chiave gratuita di TheSportsDB: ricevuti solo " . count($lineup) . " giocatori in formazione e " . count($timeline) . " eventi (limite del piano: 5). Le statistiche dei giocatori richiedono una chiave personale a pagamento."
            : "TheSportsDB ha solo " . count($lineup) . " giocatori in formazione per questa partita (dati inseriti dalla community): troppo pochi per ricavare le statistiche. Provare con altre partite.";
    } else {
        prova_riepilogo(tsdb_righe_statistiche($lineup, $timeline), $out);
        $out["avvisi"][] = "TheSportsDB non fornisce il rating né i rigori parati: restano a zero (il voto base è 6, modificabile a mano). Verificare che le sostituzioni e i minuti dell'esempio siano coerenti con la partita.";
    }
    $out["richieste_oggi"] = af_richieste_oggi();
    api_success($out);
}

// ============================================================
// prova_fixture - API-Football
// ============================================================
$lega   = (int) af_cfg("league_id", 135);
$season = $seasonIn ?: af_season($stagione);
$out = ["ok" => true, "passi" => [], "avvisi" => [], "season_usata" => $season];

$fixtureId = (int) ($input["fixture_id"] ?? 0);
if ($fixtureId <= 0) {
    $oggi = new DateTimeImmutable("now", af_fuso());
    if ($season < (int) $oggi->format("Y") - ((int) $oggi->format("n") >= 7 ? 0 : 1)) {
        // Stagione già conclusa: ultime giornate (aprile-maggio dell'anno successivo)
        $da = ($season + 1) . "-04-15";
        $a  = ($season + 1) . "-05-31";
        $etichetta = "Ricerca partita conclusa (fine stagione $season)";
    } else {
        $da = $oggi->modify("-21 days")->format("Y-m-d");
        $a  = $oggi->format("Y-m-d");
        $etichetta = "Ricerca partita conclusa (ultime 3 settimane)";
    }
    $res = af_chiamata("fixtures", [
        "league" => $lega, "season" => $season, "status" => "FT", "from" => $da, "to" => $a,
    ], $stagione);
    $out["passi"][] = ["passo" => $etichetta, "ok" => $res["ok"], "errore" => $res["errore"], "trovate" => count($res["risposta"])];
    if (!$res["ok"]) { $out["ok"] = false; api_success($out); }
    if (!$res["risposta"]) {
        $out["ok"] = false;
        $out["avvisi"][] = "Nessuna partita conclusa nel periodo cercato per lega $lega / stagione $season (pausa del campionato, stagione non disponibile nel piano o season errata). Indicare un fixture_id o una stagione precedente.";
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
prova_riepilogo($righe, $out);
$out["richieste_oggi"] = af_richieste_oggi();
api_success($out);
