<?php
// ============================================================
// backend/lib/ApiFootballSync.php
//
// Sincronizzazione delle statistiche dei giocatori a fine partita (provider
// API-Football; per TheSportsDB vedi TheSportsDbSync.php).
//
// REGOLE DEL CRON (chiamate periodiche, es. ogni 5 minuti: vedi cron_api_football.php)
//   Ad ogni esecuzione si guardano le partite di NEW_CALENDARIO_SERIE_A già
//   iniziate (e non oltre "limite_attesa_min"). Per ciascuna si decide, SENZA
//   contattare l'API, se interrogarla (af_decidi):
//     - non ancora iniziata ........................ no
//     - non disputata (rinviata, annullata...) ..... no
//     - statistiche finali già salvate ............. no  (è la regola "una chiamata
//       dopo la fine della partita basta": dopo il fischio finale la partita
//       viene interrogata una volta sola, finché l'API la dà per conclusa)
//     - in corso o terminata senza statistiche ..... sì
//   Se nessuna partita richiede una verifica, NON viene fatta alcuna chiamata.
//   Altrimenti: (1) se serve, un'unica chiamata di associazione degli id delle
//   partite del turno; (2) un'unica chiamata "fixtures?ids=..." per TUTTE le
//   partite da verificare, che restituisce stato, eventi e statistiche giocatore.
//   Le statistiche vengono salvate solo per le partite concluse.
//
// Il salvataggio avviene in NEW_API_STATISTICHE (grezze, per api_player_id);
// il collegamento al giocatore del progetto passa da NEW_API_MAPPING_GIOCATORI.
// NEW_VOTI_SERIE_A (fonte ufficiale, import xlsx) non viene mai toccata.
//
// Va incluso DOPO connect.php e ApiFootballClient.php.
// ============================================================

require_once __DIR__ . "/ApiFootballMapping.php";
require_once __DIR__ . "/TheSportsDbClient.php";
require_once __DIR__ . "/TheSportsDbSync.php";

const AF_STATI_FINALI   = ["FT", "AET", "PEN"];
const AF_STATI_ANNULLATI = ["PST", "CANC", "ABD", "AWD", "WO"];
// Partita in corso: le statistiche si salvano come dati PARZIALI a ogni controllo
const AF_STATI_IN_CORSO  = ["1H", "HT", "2H", "ET", "BT", "P", "LIVE", "INT"];

function af_fuso(): DateTimeZone
{
    return new DateTimeZone("Europe/Rome");
}

/** Le 5 tabelle NEW_API_* esistono? @return string[] nomi delle tabelle mancanti */
function af_tabelle_mancanti(): array
{
    global $conn;
    $mancanti = [];
    foreach (["NEW_API_LOG", "NEW_API_MAPPING_GIOCATORI", "NEW_API_MAPPING_SQUADRE", "NEW_API_PARTITE", "NEW_API_STATISTICHE"] as $t) {
        $r = @mysqli_query($conn, "SHOW TABLES LIKE '$t'");
        if (!$r || mysqli_num_rows($r) === 0) $mancanti[] = $t;
    }
    return $mancanti;
}

/** Calcio d'inizio (ora di Roma) di una riga di NEW_CALENDARIO_SERIE_A; null se orario da definire. */
function af_inizio_partita(array $cal): ?DateTimeImmutable
{
    if (empty($cal["data_partita"]) || empty($cal["ora_partita"])) return null;
    try {
        return new DateTimeImmutable($cal["data_partita"] . " " . $cal["ora_partita"], af_fuso());
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Decide se una partita va interrogata. Funzione PURA (nessun accesso a DB/API).
 *
 * @param array $m {
 *   inizio:            DateTimeImmutable  calcio d'inizio
 *   stato:             string             ultimo stato API noto ("" se mai controllata)
 *   dettaglio_finale:  string             "Y" se le statistiche finali sono già salvate
 *   ultimo_controllo:  ?string            ora di Roma dell'ultima chiamata su questa partita
 * }
 * @param array $cfg { durata_partita_min, limite_attesa_min, intervallo_min }
 * @return array{controlla:bool,motivo:string,fine_stimata:DateTimeImmutable}
 */
function af_decidi(array $m, DateTimeImmutable $ora, array $cfg): array
{
    $durata   = (int) ($cfg["durata_partita_min"] ?? 120);
    $limite   = (int) ($cfg["limite_attesa_min"] ?? 360);
    $interv   = (int) ($cfg["intervallo_min"] ?? 50);
    $inizio   = $m["inizio"];
    $fine     = $inizio->modify("+$durata minutes");
    $stato    = (string) ($m["stato"] ?? "");
    $risposta = fn(bool $c, string $motivo) => ["controlla" => $c, "motivo" => $motivo, "fine_stimata" => $fine];

    if (in_array($stato, AF_STATI_ANNULLATI, true)) return $risposta(false, "partita non disputata (stato $stato)");
    if (($m["dettaglio_finale"] ?? "N") === "Y")    return $risposta(false, "statistiche finali già salvate");
    if ($ora < $inizio)                              return $risposta(false, "non ancora iniziata");
    if ($ora >= $inizio->modify("+$limite minutes")) return $risposta(false, "oltre il limite di attesa senza esito definitivo");

    // Evita chiamate ravvicinate (cron + esecuzione manuale) mentre la partita è ancora in corso
    if (!empty($m["ultimo_controllo"]) && !in_array($stato, AF_STATI_FINALI, true)) {
        try {
            $ultimo = new DateTimeImmutable($m["ultimo_controllo"], af_fuso());
            if ($ultimo->modify("+$interv minutes") > $ora) return $risposta(false, "controllata da meno di $interv minuti");
        } catch (Exception $e) { /* data non leggibile: si procede */ }
    }

    if (in_array($stato, AF_STATI_FINALI, true)) return $risposta(true, "conclusa: statistiche da recuperare");
    return $risposta(true, $ora < $fine ? "in corso" : "oltre la fine stimata: verifica dello stato");
}

/** Posizione breve (G/D/M/F) o estesa → termine esteso usato da /players/squads. */
function af_posizione_estesa(?string $p): string
{
    $m = ["G" => "Goalkeeper", "D" => "Defender", "M" => "Midfielder", "F" => "Attacker", "A" => "Attacker"];
    $p = trim((string) $p);
    return $m[strtoupper($p)] ?? $p;
}

/**
 * Converte le statistiche di UN giocatore in una partita nei campi del progetto.
 * Funzione PURA.
 *
 * Regole di conversione (da validare su una giornata chiusa confrontando con l'xlsx):
 *   gf  = gol totali meno i rigori segnati      (i rigori segnati vanno in rf)
 *   rf  = rigori segnati · rs = rigori sbagliati · rp = rigori parati
 *   gs  = gol subiti, SOLO per i portieri
 *   au  = autogol (vengono dagli eventi, non dalle statistiche del giocatore)
 *   amm/esp = max 1 ciascuno; con doppia ammonizione + rosso vale solo l'espulsione
 *   ass = assist
 *   presente = minuti > 0
 *   voto_api = rating di API-Football: NON è il fantavoto
 *
 * @param array $s    primo elemento di "statistics" del giocatore
 * @param int   $autogol  autogol del giocatore ricavati dagli eventi
 */
function af_converti_giocatore(array $s, int $autogol = 0): array
{
    $g = $s["games"] ?? [];
    $minuti = (int) ($g["minutes"] ?? 0);
    $tot    = (int) ($s["goals"]["total"] ?? 0);
    $rigSeg = (int) ($s["penalty"]["scored"] ?? 0);
    $portiere = strtoupper((string) ($g["position"] ?? "")) === "G";
    $giallo = (int) ($s["cards"]["yellow"] ?? 0);
    $rosso  = (int) ($s["cards"]["red"] ?? 0);
    $esp    = $rosso > 0 ? 1 : 0;
    $amm    = ($giallo > 0 && !($esp === 1 && $giallo >= 2)) ? 1 : 0;
    $rating = $g["rating"] ?? null;

    return [
        "presente"  => $minuti > 0 ? "Y" : "N",
        "minuti"    => $minuti,
        "titolare"  => (($g["substitute"] ?? true) === false) ? "Y" : "N",
        "voto_api"  => (is_numeric($rating) && (float) $rating > 0) ? round((float) $rating, 1) : null,
        "gf"        => max(0, $tot - $rigSeg),
        "gs"        => $portiere ? (int) ($s["goals"]["conceded"] ?? 0) : 0,
        "rp"        => (int) ($s["penalty"]["saved"] ?? 0),
        "rs"        => (int) ($s["penalty"]["missed"] ?? 0),
        "rf"        => $rigSeg,
        "au"        => $autogol,
        "amm"       => $amm,
        "esp"       => $esp,
        "ass"       => (int) ($s["goals"]["assists"] ?? 0),
    ];
}

/**
 * Righe di statistiche di una partita, a partire dal dettaglio restituito da
 * "fixtures?ids=" (che contiene "players" ed "events") oppure dai fallback
 * "fixtures/players" ed "fixtures/events". Funzione PURA.
 *
 * @return array<int,array> una riga per giocatore: api_player_id, api_team_id, nome, posizione,
 *                          numero + campi di af_converti_giocatore
 */
function af_righe_statistiche(array $fx, ?array $giocatoriFallback = null, ?array $eventiFallback = null): array
{
    $blocchi = (isset($fx["players"]) && is_array($fx["players"]) && $fx["players"]) ? $fx["players"] : ($giocatoriFallback ?? []);
    $eventi  = (isset($fx["events"]) && is_array($fx["events"])) ? $fx["events"] : ($eventiFallback ?? []);

    $autogol = [];
    foreach ($eventi as $e) {
        if (($e["type"] ?? "") === "Goal" && ($e["detail"] ?? "") === "Own Goal" && !empty($e["player"]["id"])) {
            $id = (int) $e["player"]["id"];
            $autogol[$id] = ($autogol[$id] ?? 0) + 1;
        }
    }

    $righe = [];
    foreach ($blocchi as $b) {
        $teamId = (int) ($b["team"]["id"] ?? 0);
        foreach ($b["players"] ?? [] as $p) {
            $id = (int) ($p["player"]["id"] ?? 0);
            $s  = $p["statistics"][0] ?? null;
            if ($id <= 0 || !is_array($s)) continue;
            $riga = af_converti_giocatore($s, $autogol[$id] ?? 0);
            $riga["api_player_id"] = $id;
            $riga["api_team_id"]   = $teamId;
            $riga["nome"]          = (string) ($p["player"]["name"] ?? "");
            $riga["posizione"]     = af_posizione_estesa($s["games"]["position"] ?? "");
            $riga["numero"]        = isset($s["games"]["number"]) ? (int) $s["games"]["number"] : null;
            $righe[] = $riga;
        }
    }
    return af_correggi_gol_subiti($righe);
}

/**
 * Corregge i GOL SUBITI dei portieri. Il campo "goals.conceded" di API-Football a volte arriva a 0
 * anche se la squadra ha incassato (11 portieri su 100 nei dati verificati, 4 gol subiti compresi).
 * I gol subiti da una squadra si ricavano però dalle statistiche degli altri giocatori:
 *   subiti = gol su azione e rigori segnati dagli avversari + autogol della propria squadra
 * (coincide col risultato in tutte le partite verificate e con i gol subiti ufficiali di tutti i 100
 * portieri). Se per quella squadra ha giocato UN SOLO portiere, il valore è quello ricavato (o il valore
 * API se maggiore); con due portieri non si può attribuire con certezza e si lascia il dato API.
 * Funzione PURA: restituisce le righe corrette.
 */
function af_correggi_gol_subiti(array $righe): array
{
    $squadre = [];   // api_team_id => ['diretti' => gol su azione + rigori, 'au' => autogol]
    foreach ($righe as $r) {
        $t = (int) $r["api_team_id"];
        if (!isset($squadre[$t])) $squadre[$t] = ["diretti" => 0, "au" => 0];
        $squadre[$t]["diretti"] += (int) $r["gf"] + (int) $r["rf"];
        $squadre[$t]["au"]      += (int) $r["au"];
    }
    if (count($squadre) !== 2) return $righe;   // servono entrambe le squadre per ricavare i gol incassati

    $ids = array_keys($squadre);
    $subiti = [
        $ids[0] => $squadre[$ids[1]]["diretti"] + $squadre[$ids[0]]["au"],
        $ids[1] => $squadre[$ids[0]]["diretti"] + $squadre[$ids[1]]["au"],
    ];
    $portieri = [];   // api_team_id => indici delle righe dei portieri scesi in campo
    foreach ($righe as $i => $r) {
        if ($r["posizione"] === "Goalkeeper" && $r["presente"] === "Y") $portieri[(int) $r["api_team_id"]][] = $i;
    }
    foreach ($portieri as $t => $indici) {
        if (count($indici) !== 1) continue;
        $i = $indici[0];
        $righe[$i]["gs"] = max((int) $righe[$i]["gs"], (int) $subiti[$t]);
    }
    return $righe;
}

// ------------------------------------------------------------
// Persistenza
// ------------------------------------------------------------

function af_esc(string $s): string
{
    global $conn;
    return mysqli_real_escape_string($conn, $s);
}

/** Inserisce/aggiorna una riga di NEW_API_PARTITE (solo i campi ammessi). */
function af_partita_upsert(int $stagione, int $giornata, int $nPartita, array $campi): void
{
    global $conn;
    $tipi = ["api_fixture_id" => "i", "api_stato" => "s", "gol_casa" => "i", "gol_ospite" => "i",
             "ultimo_controllo" => "s", "ultimo_sync" => "s", "n_controlli" => "i",
             "dettaglio_finale" => "s", "n_giocatori" => "i", "n_non_mappati" => "i", "errore" => "s"];
    $cols = []; $vals = []; $upd = [];
    foreach ($campi as $c => $v) {
        if (!isset($tipi[$c])) continue;
        if ($v === null) $sql = "NULL";
        elseif ($tipi[$c] === "i") $sql = (string) (int) $v;
        else $sql = "'" . af_esc(mb_substr((string) $v, 0, 255)) . "'";
        $cols[] = "`$c`"; $vals[] = $sql; $upd[] = "`$c` = VALUES(`$c`)";
    }
    if (!$cols) return;
    mysqli_query($conn, "INSERT INTO NEW_API_PARTITE (stagione, giornata, n_partita, " . implode(", ", $cols) . ")
                         VALUES ($stagione, $giornata, $nPartita, " . implode(", ", $vals) . ")
                         ON DUPLICATE KEY UPDATE " . implode(", ", $upd));
}

/**
 * Salva (sostituendole) le statistiche di una partita e registra i giocatori API
 * non ancora noti in NEW_API_MAPPING_GIOCATORI (da associare dalla pagina Mappatura).
 * @return array{salvate:int,non_mappati:int}
 */
function af_salva_statistiche(int $stagione, int $fixtureId, array $righe, string $adesso): array
{
    global $conn;
    mysqli_begin_transaction($conn);
    try {
        mysqli_query($conn, "DELETE FROM NEW_API_STATISTICHE WHERE stagione = $stagione AND api_fixture_id = $fixtureId");
        foreach (array_chunk($righe, 100) as $blocco) {
            $vals = [];
            $mapv = [];
            foreach ($blocco as $r) {
                $voto = $r["voto_api"] === null ? "NULL" : number_format((float) $r["voto_api"], 1, ".", "");
                $team = $r["api_team_id"] > 0 ? (int) $r["api_team_id"] : "NULL";
                $vals[] = "($stagione, $fixtureId, " . (int) $r["api_player_id"] . ", $team, '" . $r["presente"] . "', "
                        . (int) $r["minuti"] . ", '" . $r["titolare"] . "', $voto, " . (int) $r["gf"] . ", " . (int) $r["gs"] . ", "
                        . (int) $r["rp"] . ", " . (int) $r["rs"] . ", " . (int) $r["rf"] . ", " . (int) $r["au"] . ", "
                        . (int) $r["amm"] . ", " . (int) $r["esp"] . ", " . (int) $r["ass"] . ", '$adesso')";
                $num = $r["numero"] === null ? "NULL" : (int) $r["numero"];
                $mapv[] = "($stagione, " . (int) $r["api_player_id"] . ", $team, '" . af_esc(mb_substr($r["nome"], 0, 80)) . "', '"
                        . af_esc($r["posizione"]) . "', $num, '$adesso')";
            }
            if ($vals) {
                mysqli_query($conn, "INSERT INTO NEW_API_STATISTICHE
                    (stagione, api_fixture_id, api_player_id, api_team_id, presente, minuti, titolare, voto_api,
                     gf, gs, rp, rs, rf, au, amm, esp, ass, aggiornato_il) VALUES " . implode(", ", $vals));
                mysqli_query($conn, "INSERT IGNORE INTO NEW_API_MAPPING_GIOCATORI
                    (stagione, api_player_id, api_team_id, api_nome, api_ruolo, numero, aggiornato_il) VALUES " . implode(", ", $mapv));
            }
        }
        mysqli_commit($conn);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        throw $e;
    }
    $nm = query_one("SELECT COUNT(*) AS n FROM NEW_API_STATISTICHE s
                     LEFT JOIN NEW_API_MAPPING_GIOCATORI m ON m.stagione = s.stagione AND m.api_player_id = s.api_player_id
                     WHERE s.stagione = $stagione AND s.api_fixture_id = $fixtureId AND m.id_giocatore IS NULL");
    return ["salvate" => count($righe), "non_mappati" => (int) ($nm["n"] ?? 0)];
}

// ------------------------------------------------------------
// Sincronizzazione
// ------------------------------------------------------------

/** Parametri delle regole del cron letti dalla configurazione. */
function af_regole(): array
{
    return [
        "durata_partita_min" => (int) af_cfg("durata_partita_min", 120),
        "limite_attesa_min"  => (int) af_cfg("limite_attesa_min", 360),
        // Pausa minima tra due controlli della stessa partita in corso (minuti): va tenuta
        // un po' sotto la cadenza del cron (es. cron ogni 5 minuti → 4).
        "intervallo_min"     => max(0, (int) af_cfg("intervallo_controllo_min", 4)),
    ];
}

/**
 * Dopo una sincronizzazione che ha salvato statistiche, ricalcola la SIMULAZIONE della
 * giornata in corso (come il pulsante "Simula" della pagina Live), così voti e risultati
 * simulati seguono i dati API (anche parziali) senza interventi manuali.
 *
 * Opzione "simulazione_automatica" di config_api_football.php:
 *   "sempre"    (default) ricalcola la giornata in corso
 *   "esistente" solo se per la giornata esiste già una simulazione
 *   "no"        non ricalcola mai
 * Usa lo stesso blocco applicativo delle scritture della pagina Live. Eventuali errori
 * vengono riportati negli avvisi senza far fallire la sincronizzazione.
 */
function af_aggiorna_simulazione(int $stagione, array &$rep): void
{
    global $conn;
    $modo = strtolower(trim((string) af_cfg("simulazione_automatica", "sempre")));
    if ($modo === "no" || empty($rep["giornate_aggiornate"])) return;

    try {
        require_once __DIR__ . "/StatoGiornata.php";
        require_once __DIR__ . "/SimulazioneGiornata.php";

        $st = sg_stato_corrente($stagione);
        if (!$st["in_corso"]) { $rep["simulazione"] = ["aggiornata" => false, "motivo" => "nessuna giornata in corso"]; return; }
        $giornata = (int) $st["giornata"];

        // Le partite sincronizzate appartengono alla giornata in corso?
        $gsa = sim_giornata_serie_a($stagione, $giornata);
        if (!isset($rep["giornate_aggiornate"][$gsa])) {
            $rep["simulazione"] = ["aggiornata" => false, "motivo" => "le partite sincronizzate non sono della giornata in corso"];
            return;
        }
        if ($modo === "esistente") {
            $e = query_one("SELECT 1 AS x FROM NEW_SIMULAZIONE_RISULTATI WHERE stagione = $stagione AND giornata = $giornata LIMIT 1");
            if (!$e) { $rep["simulazione"] = ["aggiornata" => false, "motivo" => "nessuna simulazione esistente (simulazione_automatica = esistente)"]; return; }
        }

        $lock = "fantamazzone_live_" . $stagione;
        $l = query_one("SELECT GET_LOCK('" . af_esc($lock) . "', 5) AS l");
        if (!$l || (int) $l["l"] !== 1) {
            $rep["avvisi"][] = "Simulazione non aggiornata: un'altra operazione sulla simulazione è in corso (verrà aggiornata alla prossima esecuzione)";
            $rep["simulazione"] = ["aggiornata" => false, "motivo" => "operazione in corso"];
            return;
        }
        try {
            $res = sim_esegui_giornata($conn, $stagione, $giornata, null, "Sync API (auto)");
            $rep["simulazione"] = [
                "aggiornata" => true, "giornata" => $giornata, "partite" => (int) $res["partite_elaborate"],
                "champions" => (int) ($res["champions_elaborate"] ?? 0), "api" => (int) ($res["api"] ?? 0),
                "parziali" => (int) ($res["parziali"] ?? 0), "provvisori" => (int) $res["provvisori"], "manuali" => (int) $res["manuali"],
            ];
            $rep["messaggio"] .= "; simulazione della giornata $giornata aggiornata";
        } finally {
            mysqli_query($conn, "DO RELEASE_LOCK('" . af_esc($lock) . "')");
        }
    } catch (Throwable $e) {
        $rep["avvisi"][] = "Simulazione non aggiornata: " . $e->getMessage();
        $rep["simulazione"] = ["aggiornata" => false, "motivo" => $e->getMessage()];
    }
}

/**
 * Esegue (o simula, con "dry") una sincronizzazione.
 *
 * @param array $opz {
 *   dry:    bool                  solo decisioni, nessuna chiamata API
 *   ora:    DateTimeImmutable     ora di riferimento (default: adesso, Roma)
 *   forza:  array{giornata:int,n_partita:int}  rilegge una partita anche se già finale
 * }
 * @return array report (vedi campi sotto)
 */
function af_sync_esegui(int $stagione, array $opz = []): array
{
    global $conn;
    $dry   = !empty($opz["dry"]);
    $ora   = $opz["ora"] ?? new DateTimeImmutable("now", af_fuso());
    $forza = $opz["forza"] ?? null;
    $regole = af_regole();
    $adesso = $ora->format("Y-m-d H:i:s");

    $rep = ["ok" => true, "stagione" => $stagione, "ora" => $adesso, "dry" => $dry, "partite" => [],
            "chiamate" => [], "salvate" => 0, "avvisi" => [], "messaggio" => "", "richieste_oggi" => af_richieste_oggi()];

    $manc = af_tabelle_mancanti();
    if ($manc) {
        return ["ok" => false, "messaggio" => "Tabelle mancanti: " . implode(", ", $manc) . ". Eseguire Script DB/NEW_API_FOOTBALL.sql"] + $rep;
    }

    $lock = "fantamazzone_apifootball_" . $stagione;
    $r = query_one("SELECT GET_LOCK('" . af_esc($lock) . "', 0) AS ok");
    if (!$r || (int) $r["ok"] !== 1) {
        return ["ok" => false, "messaggio" => "Un'altra sincronizzazione è già in corso"] + $rep;
    }

    try {
        // 1) Partite candidate dal calendario
        $da = $ora->modify("-" . $regole["limite_attesa_min"] . " minutes")->format("Y-m-d");
        $a  = $ora->format("Y-m-d");
        $sql = "SELECT c.giornata, c.n_partita, c.data_partita, c.ora_partita, c.squadra_casa, c.squadra_ospite,
                       p.api_fixture_id, p.api_stato, p.dettaglio_finale, p.ultimo_controllo, p.n_controlli
                FROM NEW_CALENDARIO_SERIE_A c
                LEFT JOIN NEW_API_PARTITE p ON p.stagione = c.stagione AND p.giornata = c.giornata AND p.n_partita = c.n_partita
                WHERE c.stagione = $stagione AND c.ora_partita IS NOT NULL ";
        if ($forza) {
            $sql .= "AND c.giornata = " . (int) $forza["giornata"] . " AND c.n_partita = " . (int) $forza["n_partita"];
        } else {
            $sql .= "AND c.data_partita BETWEEN '$da' AND '$a'";
        }
        $sql .= " ORDER BY c.data_partita, c.ora_partita, c.n_partita";

        $attive = [];
        foreach (query_all($sql) as $c) {
            $inizio = af_inizio_partita($c);
            if (!$inizio) continue;
            $d = af_decidi([
                "inizio" => $inizio, "stato" => (string) $c["api_stato"],
                "dettaglio_finale" => $c["dettaglio_finale"] ?? "N", "ultimo_controllo" => $c["ultimo_controllo"],
            ], $ora, $regole);
            if ($forza) $d["controlla"] = true;
            $riga = [
                "giornata" => (int) $c["giornata"], "n_partita" => (int) $c["n_partita"],
                "partita" => $c["squadra_casa"] . " - " . $c["squadra_ospite"],
                "inizio" => $inizio->format("Y-m-d H:i"), "fine_stimata" => $d["fine_stimata"]->format("Y-m-d H:i"),
                "decisione" => $d["controlla"] ? "controlla" : "salta", "motivo" => $forza ? "esecuzione forzata" : $d["motivo"],
            ];
            $rep["partite"][] = $riga;
            if ($d["controlla"]) $attive[] = $c + ["_idx" => count($rep["partite"]) - 1];
        }

        if (!$attive) {
            $rep["messaggio"] = "Nessuna partita da verificare: nessuna chiamata API effettuata";
            return $rep;
        }
        if ($dry) {
            $rep["messaggio"] = count($attive) . " partite da verificare (simulazione: nessuna chiamata API effettuata)";
            return $rep;
        }

        // Fornitore TheSportsDB: stato di ogni partita ed eventuali statistiche
        if (af_provider() === "thesportsdb") {
            tsdb_sync_attive($stagione, $attive, $rep, $adesso);
            af_aggiorna_simulazione($stagione, $rep);
            return $rep;
        }

        $chiama = function (string $ep, array $par) use (&$rep, $stagione): array {
            $res = af_chiamata($ep, $par, $stagione);
            $rep["chiamate"][] = ["endpoint" => $ep, "parametri" => http_build_query($par), "ok" => $res["ok"], "errore" => $res["errore"], "rimaste" => $res["rimaste"]];
            $rep["richieste_oggi"] = af_richieste_oggi();
            return $res;
        };
        $segnaErrore = function (array $m, string $msg) use (&$rep, $stagione) {
            af_partita_upsert($stagione, (int) $m["giornata"], (int) $m["n_partita"], ["errore" => $msg]);
            $rep["partite"][$m["_idx"]]["esito"] = "errore: " . $msg;
        };

        // 2) Associazione degli id partita (una chiamata per turno, solo se manca)
        $teamPer = afm_team_per_squadra($stagione);
        $senzaId = array_values(array_filter($attive, fn($m) => empty($m["api_fixture_id"])));
        if ($senzaId) {
            $turni = array_values(array_unique(array_map(fn($m) => (int) $m["giornata"], $senzaId)));
            $coppie = [];
            foreach ($turni as $giornata) {
                $res = $chiama("fixtures", ["league" => (int) af_cfg("league_id", 135), "season" => af_season($stagione),
                                            "round" => af_cfg("round_prefix", "Regular Season - ") . $giornata]);
                if (!$res["ok"]) { $rep["avvisi"][] = "Turno $giornata: " . $res["errore"]; continue; }
                foreach ($res["risposta"] as $fx) {
                    $h = (int) ($fx["teams"]["home"]["id"] ?? 0);
                    $v = (int) ($fx["teams"]["away"]["id"] ?? 0);
                    $coppie[$giornata][$h . "-" . $v] = (int) ($fx["fixture"]["id"] ?? 0);
                }
            }
            // Associa gli id a TUTTE le partite del turno (non solo a quelle attive): le
            // successive esecuzioni non dovranno ripetere questa chiamata.
            foreach ($turni as $giornata) {
                if (empty($coppie[$giornata])) continue;
                foreach (query_all("SELECT n_partita, squadra_casa, squadra_ospite FROM NEW_CALENDARIO_SERIE_A
                                    WHERE stagione = $stagione AND giornata = $giornata") as $c) {
                    $h = $teamPer[afm_chiave_squadra($c["squadra_casa"])] ?? null;
                    $v = $teamPer[afm_chiave_squadra($c["squadra_ospite"])] ?? null;
                    $id = ($h !== null && $v !== null) ? ($coppie[$giornata][$h . "-" . $v] ?? 0) : 0;
                    if ($id <= 0) continue;
                    af_partita_upsert($stagione, $giornata, (int) $c["n_partita"], ["api_fixture_id" => $id]);
                    foreach ($attive as &$x) {
                        if ((int) $x["giornata"] === $giornata && (int) $x["n_partita"] === (int) $c["n_partita"]) $x["api_fixture_id"] = $id;
                    }
                    unset($x);
                }
            }
            foreach ($senzaId as $m) {
                $h = $teamPer[afm_chiave_squadra($m["squadra_casa"])] ?? null;
                $v = $teamPer[afm_chiave_squadra($m["squadra_ospite"])] ?? null;
                if ($h === null || $v === null) {
                    $segnaErrore($m, "Squadra non associata a API-Football (" . ($h === null ? $m["squadra_casa"] : $m["squadra_ospite"]) . ")");
                    continue;
                }
                $trovata = false;
                foreach ($attive as $x) {
                    if ((int) $x["giornata"] === (int) $m["giornata"] && (int) $x["n_partita"] === (int) $m["n_partita"] && !empty($x["api_fixture_id"])) $trovata = true;
                }
                if (!$trovata) $segnaErrore($m, "Partita non trovata nel turno API");
            }
        }

        // 3) Una sola chiamata per tutte le partite da verificare
        $daVerificare = array_values(array_filter($attive, fn($m) => !empty($m["api_fixture_id"])));
        if (!$daVerificare) {
            $rep["ok"] = false;
            $rep["messaggio"] = "Nessuna partita verificabile (mancano associazioni): vedere gli errori per partita";
            return $rep;
        }
        $ids = array_values(array_unique(array_map(fn($m) => (int) $m["api_fixture_id"], $daVerificare)));
        $dettagli = [];
        foreach (array_chunk($ids, 20) as $gruppo) {
            $res = $chiama("fixtures", ["ids" => implode("-", $gruppo)]);
            if (!$res["ok"]) {
                $rep["ok"] = false;
                $rep["avvisi"][] = "Dettaglio partite: " . $res["errore"];
                foreach ($daVerificare as $m) if (in_array((int) $m["api_fixture_id"], $gruppo, true)) $segnaErrore($m, (string) $res["errore"]);
                continue;
            }
            foreach ($res["risposta"] as $fx) $dettagli[(int) ($fx["fixture"]["id"] ?? 0)] = $fx;
        }

        // 4) Stato e, a partita conclusa, statistiche
        foreach ($daVerificare as $m) {
            $id = (int) $m["api_fixture_id"];
            if (!isset($dettagli[$id])) {
                if (empty($rep["partite"][$m["_idx"]]["esito"])) $segnaErrore($m, "Partita assente nella risposta API");
                continue;
            }
            $fx = $dettagli[$id];
            $stato = (string) ($fx["fixture"]["status"]["short"] ?? "");
            $campi = [
                "api_stato" => $stato,
                "gol_casa" => isset($fx["goals"]["home"]) ? (int) $fx["goals"]["home"] : null,
                "gol_ospite" => isset($fx["goals"]["away"]) ? (int) $fx["goals"]["away"] : null,
                "ultimo_controllo" => $adesso,
                "n_controlli" => (int) $m["n_controlli"] + 1,
                "errore" => null,
            ];
            $esito = "stato $stato";

            if (in_array($stato, AF_STATI_IN_CORSO, true)) {
                // Partita in corso: statistiche PARZIALI dalla stessa chiamata (nessun costo aggiuntivo).
                // La partita resta "da verificare" (dettaglio_finale = N) finché non è conclusa.
                $righe = af_righe_statistiche($fx, null, null);
                if ($righe) {
                    $s = af_salva_statistiche($stagione, $id, $righe, $adesso);
                    $campi += ["ultimo_sync" => $adesso, "n_giocatori" => $s["salvate"], "n_non_mappati" => $s["non_mappati"]];
                    $rep["salvate"] += $s["salvate"];
                    $rep["giornate_aggiornate"][(int) $m["giornata"]] = true;
                    $esito = "in corso ($stato): " . $s["salvate"] . " giocatori (dato parziale)" . ($s["non_mappati"] ? ", " . $s["non_mappati"] . " non associati" : "");
                }
            } elseif (in_array($stato, AF_STATI_FINALI, true)) {
                $giocatoriFb = null; $eventiFb = null;
                if (empty($fx["players"])) {
                    $r2 = $chiama("fixtures/players", ["fixture" => $id]);
                    $giocatoriFb = $r2["ok"] ? $r2["risposta"] : null;
                }
                if (!isset($fx["events"])) {
                    $r3 = $chiama("fixtures/events", ["fixture" => $id]);
                    $eventiFb = $r3["ok"] ? $r3["risposta"] : null;
                }
                $righe = af_righe_statistiche($fx, $giocatoriFb, $eventiFb);
                if ($righe) {
                    $s = af_salva_statistiche($stagione, $id, $righe, $adesso);
                    $campi += ["dettaglio_finale" => "Y", "ultimo_sync" => $adesso,
                               "n_giocatori" => $s["salvate"], "n_non_mappati" => $s["non_mappati"]];
                    $rep["salvate"] += $s["salvate"];
                    $rep["giornate_aggiornate"][(int) $m["giornata"]] = true;
                    $esito = "conclusa: " . $s["salvate"] . " giocatori salvati" . ($s["non_mappati"] ? ", " . $s["non_mappati"] . " non associati" : "");
                } else {
                    $campi["errore"] = "Partita conclusa ma statistiche giocatori non disponibili";
                    $esito = "conclusa, statistiche non ancora disponibili (nuovo tentativo alla prossima esecuzione)";
                }
            }
            af_partita_upsert($stagione, (int) $m["giornata"], (int) $m["n_partita"], $campi);
            $rep["partite"][$m["_idx"]]["esito"] = $esito;
        }

        $rep["messaggio"] = count($rep["chiamate"]) . " chiamate API, " . $rep["salvate"] . " righe di statistiche salvate";
        af_aggiorna_simulazione($stagione, $rep);
        return $rep;
    } finally {
        mysqli_query($conn, "DO RELEASE_LOCK('" . af_esc($lock) . "')");
    }
}

/**
 * Stato di sincronizzazione di una giornata di Serie A (per il monitor in app):
 * ogni partita del calendario con l'eventuale riga di NEW_API_PARTITE e la decisione
 * che il cron prenderebbe adesso.
 */
function af_stato_giornata(int $stagione, int $giornataSerieA, ?DateTimeImmutable $ora = null): array
{
    $ora = $ora ?? new DateTimeImmutable("now", af_fuso());
    $regole = af_regole();
    $out = [];
    $rows = query_all("SELECT c.giornata, c.n_partita, c.data_partita, c.ora_partita, c.squadra_casa, c.squadra_ospite,
                              p.api_fixture_id, p.api_stato, p.gol_casa, p.gol_ospite, p.ultimo_controllo, p.ultimo_sync,
                              p.n_controlli, p.dettaglio_finale, p.n_giocatori, p.n_non_mappati, p.errore
                       FROM NEW_CALENDARIO_SERIE_A c
                       LEFT JOIN NEW_API_PARTITE p ON p.stagione = c.stagione AND p.giornata = c.giornata AND p.n_partita = c.n_partita
                       WHERE c.stagione = $stagione AND c.giornata = $giornataSerieA
                       ORDER BY c.data_partita, c.ora_partita, c.n_partita");
    foreach ($rows as $c) {
        $inizio = af_inizio_partita($c);
        $dec = $inizio ? af_decidi([
            "inizio" => $inizio, "stato" => (string) $c["api_stato"],
            "dettaglio_finale" => $c["dettaglio_finale"] ?? "N", "ultimo_controllo" => $c["ultimo_controllo"],
        ], $ora, $regole) : ["controlla" => false, "motivo" => "orario da definire", "fine_stimata" => null];
        $out[] = [
            "giornata" => (int) $c["giornata"], "n_partita" => (int) $c["n_partita"],
            "casa" => $c["squadra_casa"], "ospite" => $c["squadra_ospite"],
            "inizio" => $inizio ? $inizio->format("Y-m-d H:i") : null,
            "fine_stimata" => $dec["fine_stimata"] ? $dec["fine_stimata"]->format("H:i") : null,
            "api_fixture_id" => $c["api_fixture_id"] !== null ? (int) $c["api_fixture_id"] : null,
            "api_stato" => (string) $c["api_stato"],
            "gol_casa" => $c["gol_casa"] !== null ? (int) $c["gol_casa"] : null,
            "gol_ospite" => $c["gol_ospite"] !== null ? (int) $c["gol_ospite"] : null,
            "ultimo_controllo" => $c["ultimo_controllo"], "ultimo_sync" => $c["ultimo_sync"],
            "n_controlli" => (int) $c["n_controlli"], "dettaglio_finale" => $c["dettaglio_finale"] ?? "N",
            "n_giocatori" => (int) $c["n_giocatori"], "n_non_mappati" => (int) $c["n_non_mappati"],
            "errore" => $c["errore"],
            "prossima_azione" => $dec["controlla"] ? "verifica alla prossima esecuzione" : $dec["motivo"],
        ];
    }
    return $out;
}
