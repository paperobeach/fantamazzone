<?php
// ============================================================
// api/dashboard_ruoli.php
// GET ?stagione=2026&id_squadra=3&ruolo=1..4&competizione=CAMP|CHAMP&vista=SCHIERATI|ROSA
//
// Area "Statistiche" della Dashboard squadra, sezione "Analisi per ruolo",
// per UN reparto (1 = portieri, 2 = difensori, 3 = centrocampisti,
// 4 = attaccanti) e UNA competizione (campionato = default, Champions).
//
// VISTA (parametro `vista`, default SCHIERATI)
//   SCHIERATI  fonte: NEW_VOTI / NEW_VOTI_CHAMP (righe con giocata = 1: i
//              giocatori che hanno concorso al punteggio della giornata,
//              sostituti entrati inclusi) e NEW_RISULTATI / NEW_RISULTATI_CHAMP
//              per i modificatori di squadra.
//   ROSA       tutti i giocatori della rosa nella giornata, schierati o no
//              (panchina, sostituiti, tribuna) purché con un voto reale; un
//              giocatore conta solo nelle giornate in cui era nella rosa
//              della squadra (storico: NEW_ROSA_STORICO, lib/RosaStorico.php;
//              un ceduto resta nella squadra precedente). Voti da
//              NEW_VOTI_SERIE_A, fantavoto con le regole bonus/malus della
//              stagione (lib/StatisticheRosa.php). Il modificatore di reparto
//              dipende dallo schieramento e in questa vista non è calcolato
//              (modificatore = null). Le giornate considerate sono quelle
//              giocate dalla squadra nella competizione.
//   Con `vista` = ROSA, se mancano i voti Serie A o le regole di calcolo
//   della stagione la risposta contiene `non_disponibile` (messaggio).
//   Nella vista ROSA il campo `schierati` indica i giocatori CON VOTO.
//
// Definizioni (le stesse della UI):
//   voto NETTO  = voto base del giocatore, senza bonus/malus (NEW_VOTI.voto)
//   voto LORDO  = voto con bonus/malus, cioè il fantavoto (NEW_VOTI.totale)
//   media di una giornata = media del reparto in quella partita; la media
//                 di reparto della stagione è la media di queste medie
//                 (ogni partita pesa uguale, a prescindere da quanti
//                 giocatori del reparto hanno giocato)
//   gol         = gol segnati dal giocatore (NEW_VOTI.reti); per i portieri
//                 i gol SUBITI dal portiere (NEW_VOTI.retis), cioè quelli del
//                 malus "gol subito", NON il punteggio fanta della partita
//   modificatore del reparto, dal punto di vista della squadra
//                 (positivo = favorevole):
//                 difensori      NEW_RISULTATI.modificatore
//                 centrocampisti NEW_RISULTATI.mod_cc
//                 attaccanti     NEW_RISULTATI.mod_att
//                 i portieri non hanno un modificatore proprio
//   riserva d'ufficio: ha ID negativo (-(ruolo*1000000 + ...)), da cui si
//                 ricava il reparto; compare come "Riserva d'ufficio".
//
// Le giornate non ancora chiuse non vengono mai considerate.
// ============================================================
require_once __DIR__ . "/connect.php";
require_once __DIR__ . "/lib/StatoGiornata.php";
require_once __DIR__ . "/lib/ChampionsCalendario.php";
require_once __DIR__ . "/lib/DashboardSquadra.php";
require_once __DIR__ . "/lib/StatisticheRosa.php";

$stagione  = param_int("stagione");
$idSquadra = param_int("id_squadra");
$ruolo     = param_int("ruolo");
$comp      = strtoupper(trim((string) (param_str("competizione", false) ?: "CAMP")));
if (!in_array($comp, ["CAMP", "CHAMP"], true)) api_error("Competizione non valida (CAMP o CHAMP)", 400);
if ($ruolo < 1 || $ruolo > 4) api_error("Ruolo non valido (1 = P, 2 = D, 3 = C, 4 = A)", 400);
$champ = $comp === "CHAMP";
$vista = strtoupper(trim((string) (param_str("vista", false) ?: "SCHIERATI")));
if (!in_array($vista, ["SCHIERATI", "ROSA"], true)) api_error("Vista non valida (SCHIERATI o ROSA)", 400);
$rosa = $vista === "ROSA";

$tabVoti  = $champ ? "NEW_VOTI_CHAMP" : "NEW_VOTI";
$tabRis   = $champ ? "NEW_RISULTATI_CHAMP" : "NEW_RISULTATI";
$colSqRis = $champ ? "squadra" : "id_squadra";
// Il modificatore dipende dallo schieramento: non ha senso sulla rosa intera
$colMod   = $rosa ? null : ([2 => "modificatore", 3 => "mod_cc", 4 => "mod_att"][$ruolo] ?? null);

// ── Giornate pubbliche ───────────────────────────────────────

$storica = $stagione !== sg_ultima_stagione();
$chiuse  = [];
foreach (query_all("SELECT giornata FROM NEW_CALENDARIO_CK
                    WHERE stagione = $stagione AND ck_giocata = 'S'") as $c) {
    $chiuse[(int) $c["giornata"]] = true;
}
$pub = fn(int $g): bool => $storica || isset($chiuse[$g]);

if (!query_one("SELECT id FROM NEW_SQUADRE WHERE id = $idSquadra AND stagione = $stagione")) {
    api_error("Squadra non trovata", 404);
}

// ── Helper ───────────────────────────────────────────────────

function dr_media(array $v): ?float
{
    return $v ? round(array_sum($v) / count($v), 2) : null;
}

/**
 * Distribuzione dei valori per fasce di ampiezza $passo ([da, a), a partire
 * dal multiplo di $passo che contiene il minimo). Include le fasce vuote
 * intermedie, così il grafico non ha "buchi".
 */
function dr_fasce(array $valori, float $passo = 0.5): array
{
    if (!$valori) return [];
    $da  = floor(min($valori) / $passo) * $passo;
    $max = max($valori);
    $out = [];
    for ($a = $da; $a <= $max + 1e-9; $a += $passo) {
        $n = 0;
        foreach ($valori as $v) if ($v >= $a - 1e-9 && $v < $a + $passo - 1e-9) $n++;
        $out[] = ["da" => round($a, 2), "a" => round($a + $passo, 2), "n" => $n];
    }
    return $out;
}

/** Distribuzione di valori discreti: valore => numero di partite (ordinata per valore). */
function dr_distribuzione(array $valori): array
{
    $per = [];
    foreach ($valori as $v) {
        $k = (string) round((float) $v, 2);
        $per[$k] = ($per[$k] ?? 0) + 1;
    }
    uksort($per, fn($a, $b) => (float) $a <=> (float) $b);
    $out = [];
    foreach ($per as $k => $n) $out[] = ["valore" => (float) $k, "n" => $n];
    return $out;
}

// ── Partite e modificatori per giornata ──────────────────────

$partite = $champ
    ? ds_partite_champ($stagione, $idSquadra, $pub)
    : ds_partite_camp($stagione, $idSquadra, $pub);
$partitaPer = [];
foreach ($partite as $p) $partitaPer[$p["giornata"]] = $p;

$modPer = [];
if ($colMod !== null) {
    foreach (query_all("SELECT giornata, $colMod AS mod_val FROM $tabRis
                        WHERE stagione = $stagione AND $colSqRis = $idSquadra") as $r) {
        $modPer[(int) $r["giornata"]] = (float) $r["mod_val"];
    }
}

// ── Voti del reparto ─────────────────────────────────────────

if ($rosa) {
    try {
        $righe = sr_voti_rosa($stagione, $idSquadra, $champ, array_keys($partitaPer));
    } catch (StatisticheRosaNonDisponibili $e) {
        api_success([
            "stagione" => $stagione, "competizione" => $comp, "ruolo" => $ruolo, "vista" => $vista,
            "partite" => 0, "non_disponibile" => $e->getMessage(),
        ]);
    }
    usort($righe, fn($a, $b) => $a["giornata"] <=> $b["giornata"]);
} else {
    $righe = query_all("SELECT v.giornata, v.id_giocatore, v.voto, v.totale, v.reti, v.retis,
                               g.descrizione AS nome, g.ruolo AS ruolo_g
                        FROM $tabVoti v
                        LEFT JOIN " . rs_sql_anagrafica($stagione) . " g ON g.id = v.id_giocatore AND g.stagione = v.stagione
                        WHERE v.stagione = $stagione AND v.id_squadra = $idSquadra AND v.giocata = 1
                        ORDER BY v.giornata");
}

$portiere = $ruolo === 1;
$perGiornata = [];   // giornata => [giocatori del reparto]
$giocatori   = [];   // chiave giocatore => anagrafica + risultati

foreach ($righe as $r) {
    $g = (int) $r["giornata"];
    if (!$pub($g)) continue;

    $id = (int) $r["id_giocatore"];
    if ($r["ruolo_g"] !== null) {
        $ruoloRiga = (int) $r["ruolo_g"];
    } elseif ($id < 0) {
        $ruoloRiga = intdiv(-$id, 1000000);   // riserva d'ufficio: il reparto è nell'ID
    } else {
        continue;                             // giocatore non in anagrafica
    }
    if ($ruoloRiga !== $ruolo) continue;

    $chiave = $id < 0 ? "U" : (string) $id;
    $nome   = $id < 0 ? "Riserva d'ufficio" : ($r["nome"] ?? "#$id");
    $gol    = (int) ($portiere ? $r["retis"] : $r["reti"]);

    $perGiornata[$g][] = [
        "chiave" => $chiave, "voto" => (float) $r["voto"], "totale" => (float) $r["totale"], "gol" => $gol,
    ];
    if (!isset($giocatori[$chiave])) {
        $giocatori[$chiave] = [
            "id_giocatore" => $id < 0 ? null : $id, "nome" => $nome,
            "presenze" => 0, "gol" => 0, "voti" => [], "totali" => [], "per_giornata" => [],
        ];
    }
    $gp = &$giocatori[$chiave];
    $gp["presenze"]++;
    $gp["gol"] += $gol;
    $gp["voti"][]   = (float) $r["voto"];
    $gp["totali"][] = (float) $r["totale"];
    $gp["per_giornata"][$g] = ($gp["per_giornata"][$g] ?? 0) + $gol;
    unset($gp);
}
ksort($perGiornata);

// ── Aggregazione per giornata ────────────────────────────────

$giornate = [];
$mediePartitaNetto = [];
$mediePartitaLordo = [];
$perN = [];          // numero giocatori schierati => medie
$golPartita = [];
$modValori = [];
$golTot = 0;

foreach ($perGiornata as $g => $lista) {
    $mn = dr_media(array_column($lista, "voto"));
    $ml = dr_media(array_column($lista, "totale"));
    $gol = array_sum(array_column($lista, "gol"));
    $n = count($lista);
    $mod = $colMod !== null && array_key_exists($g, $modPer) ? $modPer[$g] : null;
    $p = $partitaPer[$g] ?? null;

    $giornate[] = [
        "giornata"    => $g,
        "avversario"  => $p["avversario"] ?? null,
        "logo_avv"    => $p["logo_avv"] ?? null,
        "segno"       => $p["segno"] ?? null,
        "golf"        => $p["golf"] ?? null,
        "gols"        => $p["gols"] ?? null,
        "schierati"   => $n,
        "media_netto" => $mn,
        "media_lordo" => $ml,
        "gol"         => $gol,
        "modificatore" => $mod,
    ];

    $mediePartitaNetto[] = $mn;
    $mediePartitaLordo[] = $ml;
    $golPartita[] = $gol;
    $golTot += $gol;
    if ($mod !== null) $modValori[] = $mod;
    $perN[$n]["netto"][] = $mn;
    $perN[$n]["lordo"][] = $ml;
}

$partiteN = count($giornate);

// ── Giocatori: medie e matrice per giornata ──────────────────

$elencoGiocatori = [];
foreach ($giocatori as $gp) {
    $elencoGiocatori[] = [
        "id_giocatore" => $gp["id_giocatore"],
        "nome"         => $gp["nome"],
        "presenze"     => $gp["presenze"],
        "gol"          => $gp["gol"],
        "media_netto"  => dr_media($gp["voti"]),
        "media_lordo"  => dr_media($gp["totali"]),
        // giornata => gol (la chiave c'è solo se il giocatore ha concorso al punteggio)
        "per_giornata" => (object) $gp["per_giornata"],
    ];
}
usort($elencoGiocatori, fn($a, $b) => [$b["presenze"], $b["gol"]] <=> [$a["presenze"], $a["gol"]]);

// ── Numero di giocatori schierati (vista ROSA: con voto) ─────

ksort($perN);
$distribuzioneN = [];
foreach ($perN as $n => $m) {
    $distribuzioneN[] = [
        "schierati"   => $n,
        "partite"     => count($m["netto"]),
        "media_netto" => dr_media($m["netto"]),
        "media_lordo" => dr_media($m["lordo"]),
    ];
}

// ── Modificatore ─────────────────────────────────────────────

$modificatore = null;
if ($colMod !== null && $modValori) {
    $nm = count($modValori);
    $pos = count(array_filter($modValori, fn($v) => $v > 0));
    $neg = count(array_filter($modValori, fn($v) => $v < 0));
    $modificatore = [
        "campione"     => $nm,
        "medio"        => round(array_sum($modValori) / $nm, 2),
        "totale"       => round(array_sum($modValori), 2),
        "positivi"     => $pos,
        "negativi"     => $neg,
        "nulli"        => $nm - $pos - $neg,
        "perc_positivi" => round($pos / $nm * 100, 1),
        "perc_negativi" => round($neg / $nm * 100, 1),
        "distribuzione" => dr_distribuzione($modValori),
    ];
}

api_success([
    "stagione"     => $stagione,
    "competizione" => $comp,
    "ruolo"        => $ruolo,
    "vista"        => $vista,
    "partite"      => $partiteN,
    "riepilogo"    => [
        "media_netto"    => dr_media($mediePartitaNetto),
        "media_lordo"    => dr_media($mediePartitaLordo),
        "gol_totali"     => $golTot,
        "media_gol"      => $partiteN ? round($golTot / $partiteN, 2) : null,
        "media_schierati" => $partiteN ? round(array_sum(array_column($giornate, "schierati")) / $partiteN, 2) : null,
    ],
    "giornate"     => $giornate,
    "giocatori"    => $elencoGiocatori,
    "schierati"    => $distribuzioneN,
    "fasce_netto"  => dr_fasce($mediePartitaNetto),
    "fasce_lordo"  => dr_fasce($mediePartitaLordo),
    "distribuzione_gol" => dr_distribuzione($golPartita),
    "modificatore" => $modificatore,
]);
