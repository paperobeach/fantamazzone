<?php
// ============================================================
// api/admin/api_football_mapping.php
//
// FASE 1 - Associazione tra squadre/giocatori di API-Football e quelli del
// progetto (vedi lib/ApiFootballMapping.php). Usato dalla pagina
// "Mappatura API-Football" (menu Admin → Avvio stagione).
//
// Solo POST. Body: { stagione, utenza, password, azione, ... }
// ACCESSO: utenza amministratore (password verificata lato server).
//
// azione = "stato"               contatori di avanzamento
// azione = "squadre_carica"      elenco squadre del fornitore (1 richiesta) + abbinamento automatico
// azione = "squadre_elenco"      squadre API con associazione + nomi del progetto
// azione = "squadra_associa"     { api_team_id, squadra_serie_a|null }
// azione = "giocatori_carica"    { api_team_id }: rosa della squadra (1 richiesta; TheSportsDB gratuito: max 10 giocatori)
// azione = "giocatori_automatch" abbinamento automatico locale (nessuna richiesta)
// azione = "giocatori_elenco"    { filtro: da_associare|associati, squadra?, q?, limite? }
// azione = "giocatori_cerca_api" { q, api_team_id? }: giocatori API non associati
// azione = "giocatore_associa"   { id_giocatore, api_player_id|null }
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
if (!bks_verifica_admin($conn, $stagione, $utenza, $password)) {
    api_error("Credenziali non valide oppure utenza non amministratore", 403);
}
$manc = af_tabelle_mancanti();
if ($manc) {
    api_error("Tabelle mancanti: " . implode(", ", $manc) . ". Eseguire Script DB/NEW_API_FOOTBALL.sql", 500);
}

$adesso = (new DateTimeImmutable("now", af_fuso()))->format("Y-m-d H:i:s");

/** Elenco delle squadre API della stagione con la relativa associazione. */
function map_squadre_elenco(int $stagione): array
{
    $api = query_all("SELECT api_team_id, api_nome, squadra_serie_a, metodo FROM NEW_API_MAPPING_SQUADRE
                      WHERE stagione = $stagione ORDER BY api_nome");
    foreach ($api as &$r) {
        $r["api_team_id"] = (int) $r["api_team_id"];
        $n = query_one("SELECT COUNT(*) AS n, SUM(id_giocatore IS NOT NULL) AS m FROM NEW_API_MAPPING_GIOCATORI
                        WHERE stagione = $stagione AND api_team_id = " . $r["api_team_id"]);
        $r["giocatori_api"] = (int) ($n["n"] ?? 0);
        $r["giocatori_associati"] = (int) ($n["m"] ?? 0);
    }
    unset($r);
    return ["squadre_api" => $api, "squadre_progetto" => afm_squadre_db($stagione)];
}

/** Inserisce o aggiorna un giocatore del fornitore nell'elenco da associare (non tocca l'associazione). */
function map_upsert_giocatore(int $stagione, int $teamId, int $pid, string $nome, string $ruolo, ?int $numero, string $adesso): void
{
    global $conn;
    mysqli_query($conn, "INSERT INTO NEW_API_MAPPING_GIOCATORI (stagione, api_player_id, api_team_id, api_nome, api_ruolo, numero, aggiornato_il)
                         VALUES ($stagione, $pid, $teamId, '" . af_esc(mb_substr($nome, 0, 80)) . "', '" . af_esc($ruolo) . "', "
                         . ($numero === null ? "NULL" : (int) $numero) . ", '$adesso')
                         ON DUPLICATE KEY UPDATE api_team_id = VALUES(api_team_id), api_nome = VALUES(api_nome),
                             api_ruolo = VALUES(api_ruolo), numero = VALUES(numero), aggiornato_il = VALUES(aggiornato_il)");
}

// ------------------------------------------------------------
if ($azione === "stato") {
    $s = query_one("SELECT COUNT(*) AS n, SUM(squadra_serie_a IS NOT NULL) AS m FROM NEW_API_MAPPING_SQUADRE WHERE stagione = $stagione");
    $g = query_one("SELECT COUNT(*) AS n, SUM(id_giocatore IS NOT NULL) AS m FROM NEW_API_MAPPING_GIOCATORI WHERE stagione = $stagione");
    $db = query_one("SELECT COUNT(*) AS n FROM NEW_GIOCATORI_BASE_ASTA WHERE stagione = $stagione");
    $dbm = query_one("SELECT COUNT(DISTINCT id_giocatore) AS n FROM NEW_API_MAPPING_GIOCATORI WHERE stagione = $stagione AND id_giocatore IS NOT NULL");
    api_success([
        "squadre_api" => (int) $s["n"], "squadre_associate" => (int) $s["m"], "squadre_progetto" => count(afm_squadre_db($stagione)),
        "giocatori_api" => (int) $g["n"], "giocatori_api_associati" => (int) $g["m"],
        "giocatori_progetto" => (int) $db["n"], "giocatori_progetto_associati" => (int) $dbm["n"],
        "richieste_oggi" => af_richieste_oggi(), "quota_max_locale" => (int) af_cfg("quota_giornaliera_max", 90),
    ]);
}

if ($azione === "squadre_elenco") {
    api_success(map_squadre_elenco($stagione));
}

if ($azione === "squadre_carica") {
    $elenco = [];   // [ [id, nome, rosa[]] ]
    $avviso = null;
    if (af_provider() === "thesportsdb") {
        $res = tsdb_chiamata("search_all_teams.php", ["l" => (string) af_cfg("tsdb_league_name", "Italian Serie A")], $stagione);
        if (!$res["ok"]) api_error("TheSportsDB: " . $res["errore"], 502);
        foreach ($res["dati"]["teams"] ?? [] as $t) {
            $elenco[] = [(int) ($t["idTeam"] ?? 0), (string) ($t["strTeam"] ?? ""), []];
        }
        if (tsdb_chiave_gratuita() && count($elenco) <= 10) {
            $avviso = "Chiave gratuita di TheSportsDB: l'elenco squadre è limitato a 10 (la Serie A ne ha 20). Serve una chiave personale.";
        }
    } else {
        $res = af_chiamata("teams", ["league" => (int) af_cfg("league_id", 135), "season" => af_season($stagione)], $stagione);
        if (!$res["ok"]) api_error("API-Football: " . $res["errore"], 502);
        foreach ($res["risposta"] as $t) $elenco[] = [(int) ($t["team"]["id"] ?? 0), (string) ($t["team"]["name"] ?? ""), []];
    }
    if (!$elenco) api_error("Nessuna squadra restituita per competizione e stagione configurate", 404);
    $rosterCaricati = 0;
    foreach ($elenco as [$id, $nomeSq, $rosa]) {
        if ($id <= 0) continue;
        $nome = af_esc(mb_substr($nomeSq, 0, 80));
        mysqli_query($conn, "INSERT INTO NEW_API_MAPPING_SQUADRE (stagione, api_team_id, api_nome, aggiornato_il)
                             VALUES ($stagione, $id, '$nome', '$adesso')
                             ON DUPLICATE KEY UPDATE api_nome = VALUES(api_nome)");
        foreach ($rosa as $p) {
            if ($p["id"] > 0) { map_upsert_giocatore($stagione, $id, $p["id"], $p["nome"], $p["ruolo"], $p["numero"], $adesso); $rosterCaricati++; }
        }
    }
    // Abbinamento automatico delle sole squadre ancora libere
    $libereApi = [];
    foreach (query_all("SELECT api_team_id, api_nome FROM NEW_API_MAPPING_SQUADRE WHERE stagione = $stagione AND squadra_serie_a IS NULL") as $r) {
        $libereApi[(int) $r["api_team_id"]] = $r["api_nome"];
    }
    $usate = array_column(query_all("SELECT squadra_serie_a FROM NEW_API_MAPPING_SQUADRE WHERE stagione = $stagione AND squadra_serie_a IS NOT NULL"), "squadra_serie_a");
    $libereDb = array_values(array_diff(afm_squadre_db($stagione), $usate));
    $auto = 0;
    foreach (afm_proponi_squadre($libereApi, $libereDb) as $idApi => $p) {
        mysqli_query($conn, "UPDATE NEW_API_MAPPING_SQUADRE SET squadra_serie_a = '" . af_esc($p["squadra"]) . "', metodo = 'AUTO', aggiornato_il = '$adesso'
                             WHERE stagione = $stagione AND api_team_id = " . (int) $idApi);
        $auto++;
    }
    api_success(["caricate" => count($elenco), "associate_automaticamente" => $auto, "giocatori_caricati" => $rosterCaricati,
                 "provider" => af_provider(), "avviso" => $avviso] + map_squadre_elenco($stagione));
}

if ($azione === "squadra_associa") {
    $idApi = (int) ($input["api_team_id"] ?? 0);
    $nome = isset($input["squadra_serie_a"]) ? trim((string) $input["squadra_serie_a"]) : "";
    $r = query_one("SELECT api_team_id FROM NEW_API_MAPPING_SQUADRE WHERE stagione = $stagione AND api_team_id = $idApi");
    if (!$r) api_error("Squadra API non trovata: caricare prima l'elenco delle squadre", 404);
    if ($nome === "") {
        mysqli_query($conn, "UPDATE NEW_API_MAPPING_SQUADRE SET squadra_serie_a = NULL, metodo = NULL, aggiornato_il = '$adesso'
                             WHERE stagione = $stagione AND api_team_id = $idApi");
    } else {
        if (!in_array($nome, afm_squadre_db($stagione), true)) api_error("Squadra del progetto non valida", 400);
        $e = af_esc($nome);
        // Un nome del progetto può essere associato a una sola squadra API
        mysqli_query($conn, "UPDATE NEW_API_MAPPING_SQUADRE SET squadra_serie_a = NULL, metodo = NULL, aggiornato_il = '$adesso'
                             WHERE stagione = $stagione AND squadra_serie_a = '$e' AND api_team_id <> $idApi");
        mysqli_query($conn, "UPDATE NEW_API_MAPPING_SQUADRE SET squadra_serie_a = '$e', metodo = 'MANUALE', aggiornato_il = '$adesso'
                             WHERE stagione = $stagione AND api_team_id = $idApi");
    }
    api_success(map_squadre_elenco($stagione));
}

if ($azione === "giocatori_carica") {
    $idApi = (int) ($input["api_team_id"] ?? 0);
    $t = query_one("SELECT api_nome FROM NEW_API_MAPPING_SQUADRE WHERE stagione = $stagione AND api_team_id = $idApi");
    if (!$t) api_error("Squadra non trovata", 404);
    $n = 0;
    $nota = null;
    if (af_provider() === "thesportsdb") {
        $res = tsdb_chiamata("lookup_all_players.php", ["id" => $idApi], $stagione);
        if (!$res["ok"]) api_error("TheSportsDB: " . $res["errore"], 502);
        foreach ($res["dati"]["player"] ?? [] as $p) {
            $pid = (int) ($p["idPlayer"] ?? 0);
            if ($pid <= 0) continue;
            map_upsert_giocatore($stagione, $idApi, $pid, (string) ($p["strPlayer"] ?? ""), tsdb_posizione($p["strPosition"] ?? ""),
                                 isset($p["strNumber"]) && $p["strNumber"] !== "" ? (int) $p["strNumber"] : null, $adesso);
            $n++;
        }
        if ($n === 0) $nota = "TheSportsDB non ha restituito giocatori per questa squadra.";
        elseif (tsdb_chiave_gratuita() && $n <= 10) $nota = "Chiave gratuita di TheSportsDB: rosa limitata a 10 giocatori. Serve una chiave personale.";
    } else {
        $res = af_chiamata("players/squads", ["team" => $idApi], $stagione);
        if (!$res["ok"]) api_error("API-Football: " . $res["errore"], 502);
        foreach ($res["risposta"][0]["players"] ?? [] as $p) {
            $pid = (int) ($p["id"] ?? 0);
            if ($pid <= 0) continue;
            map_upsert_giocatore($stagione, $idApi, $pid, (string) ($p["name"] ?? ""), af_posizione_estesa($p["position"] ?? ""),
                                 isset($p["number"]) && $p["number"] !== null ? (int) $p["number"] : null, $adesso);
            $n++;
        }
    }
    api_success(["api_team_id" => $idApi, "squadra" => $t["api_nome"], "giocatori_caricati" => $n, "nota" => $nota,
                 "richieste_oggi" => af_richieste_oggi()]);
}

if ($azione === "giocatori_automatch") {
    $soglia = max(70, min(100, (int) ($input["soglia"] ?? 85)));
    $db = afm_giocatori_db($stagione);
    $assegnati = [];
    foreach (query_all("SELECT id_giocatore FROM NEW_API_MAPPING_GIOCATORI WHERE stagione = $stagione AND id_giocatore IS NOT NULL") as $r) {
        $assegnati[(int) $r["id_giocatore"]] = true;
    }
    $daAbbinare = array_diff_key($db, $assegnati);
    $api = [];
    foreach (query_all("SELECT api_player_id, api_team_id, api_nome, api_ruolo FROM NEW_API_MAPPING_GIOCATORI
                        WHERE stagione = $stagione AND id_giocatore IS NULL") as $r) {
        $api[(int) $r["api_player_id"]] = ["nome" => $r["api_nome"], "team_id" => (int) $r["api_team_id"], "ruolo" => $r["api_ruolo"]];
    }
    $prop = afm_proponi_giocatori($daAbbinare, $api, afm_team_per_squadra($stagione), $soglia);
    $auto = 0; $daRivedere = 0; $senza = 0;
    foreach ($prop as $idDb => $p) {
        if ($p["auto"]) {
            mysqli_query($conn, "UPDATE NEW_API_MAPPING_GIOCATORI SET id_giocatore = " . (int) $idDb . ", metodo = 'AUTO',
                                 punteggio = " . (int) $p["punteggio"] . ", aggiornato_il = '$adesso'
                                 WHERE stagione = $stagione AND api_player_id = " . (int) $p["api_player_id"] . " AND id_giocatore IS NULL");
            $auto++;
        } elseif ($p["candidati"]) {
            $daRivedere++;
        } else {
            $senza++;
        }
    }
    api_success([
        "giocatori_progetto" => count($db), "gia_associati" => count($assegnati), "associati_ora" => $auto,
        "da_rivedere" => $daRivedere, "senza_candidati" => $senza, "soglia" => $soglia,
        "giocatori_api_liberi" => count($api) - $auto,
    ]);
}

if ($azione === "giocatori_elenco") {
    $filtro = (string) ($input["filtro"] ?? "da_associare");
    $q = afm_norm((string) ($input["q"] ?? ""));
    $squadra = trim((string) ($input["squadra"] ?? ""));
    $limite = max(10, min(300, (int) ($input["limite"] ?? 100)));
    $db = afm_giocatori_db($stagione);

    $mappati = [];
    foreach (query_all("SELECT id_giocatore, api_player_id, api_nome, api_team_id, metodo, punteggio FROM NEW_API_MAPPING_GIOCATORI
                        WHERE stagione = $stagione AND id_giocatore IS NOT NULL") as $r) {
        $mappati[(int) $r["id_giocatore"]] = $r;
    }
    $filtra = function (array $g) use ($q, $squadra) {
        if ($squadra !== "" && afm_chiave_squadra($g["squadra"]) !== afm_chiave_squadra($squadra)) return false;
        if ($q !== "" && strpos(afm_norm($g["nome"]), $q) === false) return false;
        return true;
    };

    if ($filtro === "associati") {
        $righe = [];
        foreach ($mappati as $idDb => $m) {
            if (!isset($db[$idDb]) || !$filtra($db[$idDb])) continue;
            $righe[] = ["id_giocatore" => $idDb, "nome" => $db[$idDb]["nome"], "squadra" => $db[$idDb]["squadra"], "ruolo" => $db[$idDb]["ruolo"],
                        "api_player_id" => (int) $m["api_player_id"], "api_nome" => $m["api_nome"], "metodo" => $m["metodo"], "punteggio" => $m["punteggio"] !== null ? (int) $m["punteggio"] : null];
        }
        usort($righe, fn($a, $b) => strcmp($a["squadra"] . $a["nome"], $b["squadra"] . $b["nome"]));
        api_success(["totale" => count($righe), "righe" => array_slice($righe, 0, $limite)]);
    }

    // da_associare: giocatori del progetto senza associazione, con candidati suggeriti
    $daAbbinare = array_filter(array_diff_key($db, $mappati), $filtra);
    $api = [];
    foreach (query_all("SELECT api_player_id, api_team_id, api_nome, api_ruolo FROM NEW_API_MAPPING_GIOCATORI
                        WHERE stagione = $stagione AND id_giocatore IS NULL") as $r) {
        $api[(int) $r["api_player_id"]] = ["nome" => $r["api_nome"], "team_id" => (int) $r["api_team_id"], "ruolo" => $r["api_ruolo"]];
    }
    $prop = afm_proponi_giocatori($daAbbinare, $api, afm_team_per_squadra($stagione));
    $righe = [];
    foreach ($daAbbinare as $idDb => $g) {
        $cand = [];
        foreach ($prop[$idDb]["candidati"] ?? [] as $c) {
            $cand[] = ["api_player_id" => $c["api_player_id"], "api_nome" => $api[$c["api_player_id"]]["nome"], "punteggio" => $c["punteggio"]];
        }
        $righe[] = ["id_giocatore" => $idDb, "nome" => $g["nome"], "squadra" => $g["squadra"], "ruolo" => $g["ruolo"], "candidati" => $cand];
    }
    usort($righe, fn($a, $b) => strcmp($a["squadra"] . $a["nome"], $b["squadra"] . $b["nome"]));
    api_success(["totale" => count($righe), "righe" => array_slice($righe, 0, $limite)]);
}

if ($azione === "giocatori_cerca_api") {
    $q = trim((string) ($input["q"] ?? ""));
    $team = (int) ($input["api_team_id"] ?? 0);
    if (mb_strlen($q) < 2) api_error("Indicare almeno 2 caratteri", 400);
    $sql = "SELECT m.api_player_id, m.api_nome, m.api_ruolo, m.api_team_id, s.api_nome AS squadra_api
            FROM NEW_API_MAPPING_GIOCATORI m
            LEFT JOIN NEW_API_MAPPING_SQUADRE s ON s.stagione = m.stagione AND s.api_team_id = m.api_team_id
            WHERE m.stagione = $stagione AND m.id_giocatore IS NULL" . ($team > 0 ? " AND m.api_team_id = $team" : "");
    $trovati = [];
    foreach (query_all($sql) as $r) {
        $p = afm_punteggio_giocatore($q, $r["api_nome"]);
        if ($p === 0 && strpos(afm_norm($r["api_nome"]), afm_norm($q)) !== false) $p = 60;
        if ($p >= 60) $trovati[] = ["api_player_id" => (int) $r["api_player_id"], "api_nome" => $r["api_nome"], "api_ruolo" => $r["api_ruolo"],
                                    "squadra_api" => $r["squadra_api"], "punteggio" => $p];
    }
    usort($trovati, fn($a, $b) => $b["punteggio"] <=> $a["punteggio"]);
    api_success(["righe" => array_slice($trovati, 0, 30)]);
}

if ($azione === "giocatore_associa") {
    $idDb = (int) ($input["id_giocatore"] ?? 0);
    $idApi = isset($input["api_player_id"]) && $input["api_player_id"] !== null ? (int) $input["api_player_id"] : 0;
    $g = query_one("SELECT id_giocatore FROM NEW_GIOCATORI_BASE_ASTA WHERE stagione = $stagione AND id_giocatore = $idDb");
    if (!$g) api_error("Giocatore non trovato nel listone della stagione", 404);
    // Sgancia l'eventuale associazione precedente del giocatore
    mysqli_query($conn, "UPDATE NEW_API_MAPPING_GIOCATORI SET id_giocatore = NULL, metodo = NULL, punteggio = NULL, aggiornato_il = '$adesso'
                         WHERE stagione = $stagione AND id_giocatore = $idDb");
    if ($idApi > 0) {
        $a = query_one("SELECT api_player_id FROM NEW_API_MAPPING_GIOCATORI WHERE stagione = $stagione AND api_player_id = $idApi");
        if (!$a) api_error("Giocatore API non presente: caricare prima la rosa della sua squadra", 404);
        mysqli_query($conn, "UPDATE NEW_API_MAPPING_GIOCATORI SET id_giocatore = $idDb, metodo = 'MANUALE', punteggio = 100, aggiornato_il = '$adesso'
                             WHERE stagione = $stagione AND api_player_id = $idApi");
    }
    api_success(["id_giocatore" => $idDb, "api_player_id" => $idApi > 0 ? $idApi : null]);
}

api_error("Azione non valida", 400);
