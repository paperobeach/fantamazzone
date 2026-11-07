<?php
// ============================================================
// api/dashboard_lega.php
// GET ?stagione=2026
//
// Intestazione della "Dashboard lega": la PROSSIMA giornata (o quella in
// corso) a livello di lega, non di singola squadra.
//
// Risposta:
//   stagione, giornata, giornata_serie_a,
//   in_corso          false per stagioni storiche / stagione conclusa
//   motivo            spiegazione se non in corso
//   inizio_giornata   limite per l'inserimento delle formazioni
//                     (prima partita di Serie A della giornata)
//   live              { attiva, inizio, fine_stimata }: attiva dalla prima
//                     partita di Serie A alla conclusione dell'ultima (stessa
//                     finestra di dashboard_live.php); quando e' attiva il
//                     front end legge i risultati da live_giornata.php
//   partite           partite di campionato: casa/ospite + stato della
//                     formazione (inserita si/no, mai il contenuto)
//   champions         { turno, partite, riposa } oppure null
// ============================================================
require_once __DIR__ . "/connect.php";
require_once __DIR__ . "/lib/StatoGiornata.php";
require_once __DIR__ . "/lib/ChampionsCalendario.php";
require_once __DIR__ . "/lib/FormazioniChampions.php";
require_once __DIR__ . "/lib/DashboardSquadra.php";
require_once __DIR__ . "/lib/SimulazioneGiornata.php";

const DL_DURATA_PARTITA_MIN = 120;

$stagione = param_int("stagione");
$stato    = sg_stato_corrente($stagione);

$base = [
    "stagione" => $stagione, "giornata" => $stato["giornata"], "giornata_serie_a" => null,
    "in_corso" => $stato["in_corso"], "motivo" => $stato["motivo"], "inizio_giornata" => null,
    "live" => ["attiva" => false, "inizio" => null, "fine_stimata" => null],
    "partite" => [], "champions" => null,
];
if (!$stato["in_corso"]) api_success($base);

$giornata = $stato["giornata"];
[$giornataSerieA, $inizio] = ds_inizio_giornata($stagione, $giornata);
$base["giornata_serie_a"] = $giornataSerieA;
$base["inizio_giornata"]  = $inizio;

// ── Finestra LIVE ────────────────────────────────────────────
$serieA = query_all("SELECT data_partita, ora_partita, squadra_casa, squadra_ospite
                     FROM NEW_CALENDARIO_SERIE_A
                     WHERE stagione = $stagione AND giornata = $giornataSerieA");
if ($serieA) {
    $inizi = array_map(fn($p) => (int) strtotime($p["data_partita"] . " " . ($p["ora_partita"] ?: "00:00:00")), $serieA);
    $primo = min($inizi);
    $fine  = max($inizi) + DL_DURATA_PARTITA_MIN * 60;
    $ora   = time();

    $tutteConVoti = true;
    if ($ora >= $primo && $ora <= $fine) {
        $ctx = sim_contesto($stagione, $giornata);
        foreach ($serieA as $p) {
            if (!isset($ctx["giocate"][sim_norm($p["squadra_casa"])]) || !isset($ctx["giocate"][sim_norm($p["squadra_ospite"])])) {
                $tutteConVoti = false;
                break;
            }
        }
    }
    $base["live"] = [
        "attiva"       => $ora >= $primo && $ora <= $fine && !$tutteConVoti,
        "inizio"       => date("Y-m-d H:i:s", $primo),
        "fine_stimata" => date("Y-m-d H:i:s", $fine),
    ];
}

// ── Partite di campionato ────────────────────────────────────
$righe = query_all("SELECT c.posizione, c.squadra AS id_squadra, s.nome, s.logo
                    FROM NEW_CALENDARIO c
                    JOIN NEW_SQUADRE s ON s.id = c.squadra AND s.stagione = c.stagione
                    WHERE c.stagione = $stagione AND c.giornata = $giornata
                    ORDER BY c.posizione");
$tmp = [];
foreach ($righe as $r) {
    $i = (int) (($r["posizione"] - 1) / 2);
    $lato = $r["posizione"] % 2 === 1 ? "casa" : "ospite";
    $tmp[$i][$lato] = [
        "id" => (int) $r["id_squadra"], "nome" => $r["nome"], "logo" => $r["logo"],
        "formazione" => ds_stato_formazione(FORMAZIONE_TAB_CAMPIONATO, $stagione, $giornata, (int) $r["id_squadra"]),
    ];
}
foreach ($tmp as $p) {
    if (isset($p["casa"], $p["ospite"])) $base["partite"][] = $p;
}

// ── Champions ────────────────────────────────────────────────
$pc = champions_partite_giornata($stagione, $giornata);
if (!empty($pc["partite"]) || !empty($pc["riposa"])) {
    $nomi = [];
    foreach (query_all("SELECT s.id, s.nome, s.logo
                        FROM NEW_CALENDARIO_CHAMP cc
                        JOIN NEW_SQUADRE s ON s.id = cc.squadra AND s.stagione = cc.stagione
                        WHERE cc.stagione = $stagione AND cc.giornata_camp = $giornata") as $r) {
        $nomi[(int) $r["id"]] = ["id" => (int) $r["id"], "nome" => $r["nome"], "logo" => $r["logo"]];
    }
    $partiteC = [];
    foreach ($pc["partite"] as $p) {
        if (!isset($nomi[$p["casa"]], $nomi[$p["ospite"]])) continue;
        $tabC = formazione_tabella_champions($stagione, $giornata, $p["casa"]);
        $tabO = formazione_tabella_champions($stagione, $giornata, $p["ospite"]);
        $partiteC[] = [
            "girone" => champions_etichetta_gruppo($p["girone"]),
            "casa"   => $nomi[$p["casa"]]   + ["formazione" => ds_stato_formazione($tabC, $stagione, $giornata, $p["casa"])],
            "ospite" => $nomi[$p["ospite"]] + ["formazione" => ds_stato_formazione($tabO, $stagione, $giornata, $p["ospite"])],
        ];
    }
    $base["champions"] = [
        "partite" => $partiteC,
        "riposa"  => array_values(array_filter(array_map(fn($r) => $nomi[$r["id"]] ?? null, $pc["riposa"]))),
    ];
}

api_success($base);
