<?php
// ============================================================
// api/dashboard_analisi_match.php
// GET ?stagione=2026&id_squadra=3&competizione=CAMP|CHAMP[&giornata=12]
//
// Area "Analizza match passati" della Dashboard squadra.
//
//   partite        partite giocate (e pubbliche) dalla squadra nella competizione,
//                  per il selettore (la più recente è l'ultima dell'elenco)
//   giornata       partita analizzata: quella richiesta, altrimenti l'ULTIMA giocata
//   partita        risultato della partita analizzata (prospettiva della squadra)
//   avversario     dati essenziali della squadra avversaria
//   performance    metriche della giornata a confronto con la media della stagione
//   performance_avv  idem per la formazione dell'avversario
//   scelte         formazione schierata vs migliore possibile, bonus persi, gol
//                  possibili, percentile tra tutte le formazioni possibili, esiti
//   scelte_avv     bonus persi, percentile ed esiti dell'avversario
//
// Le definizioni (formazioni possibili, fantamedia, percentile, esiti) sono
// documentate in lib/AnalisiMatch.php.
//
// Regole:
// - i risultati sono pubblici solo a giornata chiusa (o per le stagioni
//   storiche), come in dashboard_squadra.php: nessuna formazione di una
//   giornata aperta viene mai esposta;
// - in NEW_RISULTATI / NEW_RISULTATI_CHAMP ogni partita ha DUE righe, una
//   per ciascuna prospettiva.
// ============================================================
require_once __DIR__ . "/connect.php";
require_once __DIR__ . "/lib/StatoGiornata.php";
require_once __DIR__ . "/lib/ChampionsCalendario.php";
require_once __DIR__ . "/lib/FormazioniChampions.php";
require_once __DIR__ . "/lib/DashboardSquadra.php";
require_once __DIR__ . "/lib/AnalisiMatch.php";

$stagione  = param_int("stagione");
$idSquadra = param_int("id_squadra");
$giornata  = param_int("giornata", false);
$comp      = strtoupper(trim((string) (param_str("competizione", false) ?: "CAMP")));
if (!in_array($comp, ["CAMP", "CHAMP"], true)) api_error("Competizione non valida (CAMP o CHAMP)", 400);
$champ = $comp === "CHAMP";

// ── Giornate pubbliche ───────────────────────────────────────

$storica = $stagione !== sg_ultima_stagione();
$chiuse = [];
foreach (query_all("SELECT giornata FROM NEW_CALENDARIO_CK
                    WHERE stagione = $stagione AND ck_giocata = 'S'") as $c) {
    $chiuse[(int) $c["giornata"]] = true;
}
$pub = fn(int $g): bool => $storica || isset($chiuse[$g]);

$sq = query_one("SELECT id FROM NEW_SQUADRE WHERE id = $idSquadra AND stagione = $stagione");
if (!$sq) api_error("Squadra non trovata", 404);

api_success(am_analisi($stagione, $idSquadra, $champ, $giornata, $pub));
