<?php
// ============================================================
// api/dashboard_metriche.php
// GET ?stagione=2026&competizione=CAMP|CHAMP
//
// Statistiche SELEZIONABILI della Dashboard squadra, per UNA competizione
// (campionato = default, Champions). Alimenta le card dell'area
// "Statistiche" e le aree "Confronta" e "Andamento".
//
//   competizione  "CAMP" | "CHAMP"
//   squadre       [{id, nome, logo}] tutte le squadre della stagione
//   giornate      giornate pubbliche (chiuse) giocate da almeno una squadra
//   metriche      catalogo: key, label, gruppo, agg, dir (up|down|neutral),
//                 unita ("" | "%"), dec (decimali), descr
//   valori        chiave metrica -> { id_squadra: valore di stagione | null }
//   serie         chiave metrica -> { id_squadra: [valore per ogni giornata
//                 di `giornate`, null se non applicabile] }
//   benchmark     chiave metrica -> { n, media, mediana, min, max } sulle
//                 squadre della lega (la squadra che guarda è inclusa)
//
// Come si ricavano valori e andamento: vedi lib/MetricheSquadra.php.
// Regole: i risultati sono pubblici solo a giornata chiusa (o stagioni
// storiche). In Champions i gol sono quelli dei 90'.
// Voto = voto base senza bonus/malus; fantavoto = voto con bonus/malus.
// ============================================================
require_once __DIR__ . "/connect.php";
require_once __DIR__ . "/lib/StatoGiornata.php";
require_once __DIR__ . "/lib/ChampionsCalendario.php";
require_once __DIR__ . "/lib/DashboardSquadra.php";
require_once __DIR__ . "/lib/MetricheSquadra.php";

$stagione = param_int("stagione");
$comp     = strtoupper(trim((string) (param_str("competizione", false) ?: "CAMP")));
if (!in_array($comp, ["CAMP", "CHAMP"], true)) api_error("Competizione non valida (CAMP o CHAMP)", 400);
$champ = $comp === "CHAMP";

$storica = $stagione !== sg_ultima_stagione();
$chiuse  = [];
foreach (query_all("SELECT giornata FROM NEW_CALENDARIO_CK
                    WHERE stagione = $stagione AND ck_giocata = 'S'") as $c) {
    $chiuse[(int) $c["giornata"]] = true;
}
$pub = fn(int $g): bool => $storica || isset($chiuse[$g]);

$squadre = query_all("SELECT id, nome, logo FROM NEW_SQUADRE WHERE stagione = $stagione ORDER BY nome");
if (!$squadre) api_error("Nessuna squadra per la stagione", 404);

$r = ms_calcola($stagione, $champ, $pub, $squadre);

api_success([
    "stagione"     => $stagione,
    "competizione" => $comp,
    "squadre"      => array_map(fn($s) => ["id" => (int) $s["id"], "nome" => $s["nome"], "logo" => $s["logo"]], $squadre),
] + $r);
