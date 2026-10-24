<?php
// ============================================================
// api/dashboard_live.php
// GET ?stagione=2026&id_squadra=3
//
// Richiamo LIVE dell'intestazione della Dashboard squadra. È "attivo" dalla
// partenza della prima partita di Serie A della giornata in corso fino alla
// conclusione dell'ultima; fuori da questa finestra risponde solo
// { attiva: false, ... } (nessun calcolo ulteriore).
//
// Finestra:
//   inizio       = data/ora della prima partita di Serie A della giornata
//   fine_stimata = inizio dell'ultima partita + DURATA_PARTITA_MIN
//   si chiude prima se TUTTE le squadre di Serie A della giornata hanno già
//   i voti in NEW_VOTI_SERIE_A (giornata di fatto conclusa).
//
// Risposta (con attiva = true):
//   partite     le partite fanta della squadra nella giornata (campionato e,
//               se c'è, Champions) con il risultato dal punto di vista della
//               squadra: "reale" (NEW_RISULTATI*) se presente, altrimenti
//               "simulazione" (NEW_SIMULAZIONE_RISULTATI*). Il dettaglio si
//               apre con live_dettaglio.php (id_squadra = id_casa).
//   oggi        partite di Serie A di OGGI non ancora concluse, con i
//               titolari della squadra coinvolti (stato: in_corso | da_giocare)
//               e, in "avversari", i titolari delle squadre avversarie di
//               giornata (campionato e/o Champions) che giocano in quella
//               partita: [{ id_squadra, nome, competizioni, giocatori }]
//   senza_voto  titolari la cui partita è finita senza voto (sarà sostituito)
//   panchinari  per ruolo, i primi panchinari che entrerebbero se i titolari
//               ancora da giocare (o senza voto) non prendessero voto
//
// Regole di sostituzione (come sim_carica_formazione): per ogni titolare
// senza voto si provano i panchinari dello STESSO ruolo in ordine di maglia
// e entra il primo che ha voto. I panchinari già senza voto (s.v.) non
// possono entrare e quindi non vengono proposti. I limiti di sostituzioni
// e di riserve d'ufficio non sono considerati: la lista è indicativa.
// ============================================================
require_once __DIR__ . "/connect.php";
require_once __DIR__ . "/lib/StatoGiornata.php";
require_once __DIR__ . "/lib/ChampionsCalendario.php";
require_once __DIR__ . "/lib/FormazioniChampions.php";
require_once __DIR__ . "/lib/DashboardSquadra.php";
require_once __DIR__ . "/lib/SimulazioneGiornata.php";
require_once __DIR__ . "/lib/PanchinaDettaglio.php";

// Durata convenzionale di una partita di Serie A (90' + recupero + intervallo)
const DURATA_PARTITA_MIN = 120;

$stagione  = param_int("stagione");
$idSquadra = param_int("id_squadra");

$stato = sg_stato_corrente($stagione);
if (!$stato["in_corso"]) {
    api_success(["attiva" => false, "motivo" => $stato["motivo"]]);
}
$giornata = $stato["giornata"];
[$giornataSerieA] = ds_inizio_giornata($stagione, $giornata);

// ── Finestra della giornata ──────────────────────────────────

$partiteSerieA = query_all("SELECT n_partita, data_partita, ora_partita, squadra_casa, squadra_ospite
                            FROM NEW_CALENDARIO_SERIE_A
                            WHERE stagione = $stagione AND giornata = $giornataSerieA
                            ORDER BY data_partita, COALESCE(ora_partita, '23:59:59'), n_partita");
if (!$partiteSerieA) api_success(["attiva" => false, "motivo" => "Calendario Serie A non disponibile"]);

function dl_inizio(array $p): int
{
    return (int) strtotime($p["data_partita"] . " " . ($p["ora_partita"] ?: "00:00:00"));
}
$primo   = dl_inizio($partiteSerieA[0]);
$ultimo  = max(array_map("dl_inizio", $partiteSerieA));
$fine    = $ultimo + DURATA_PARTITA_MIN * 60;
$ora     = time();
$fmt     = fn(int $t) => date("Y-m-d H:i:s", $t);

$ctx = sim_contesto($stagione, $giornata);   // voti Serie A, squadre già scese in campo, squadra di ogni giocatore

$tutteConVoti = true;
foreach ($partiteSerieA as $p) {
    if (!isset($ctx["giocate"][sim_norm($p["squadra_casa"])]) || !isset($ctx["giocate"][sim_norm($p["squadra_ospite"])])) {
        $tutteConVoti = false;
        break;
    }
}

$finestra = ["inizio" => $fmt($primo), "fine_stimata" => $fmt($fine)];
if ($ora < $primo || $ora > $fine || $tutteConVoti) {
    api_success([
        "attiva" => false, "giornata" => $giornata, "finestra" => $finestra,
        "motivo" => $ora < $primo ? "La giornata non è ancora iniziata" : "La giornata di Serie A è conclusa",
    ]);
}

// ── Partite fanta della squadra con il risultato ─────────────

/** Risultato dal punto di vista della squadra: reale se presente, altrimenti simulazione. */
function dl_risultato(string $tabReale, string $colReale, string $tabSim, int $stagione, int $giornata, int $id): array
{
    $cols = "ftotale, ftotale_a, golf, gols, punti, segno";
    $reale = query_one("SELECT $cols FROM $tabReale
                        WHERE stagione = $stagione AND giornata = $giornata AND $colReale = $id");
    $sim = $reale ? null : query_one("SELECT $cols, calcolato_il, simulato_da FROM $tabSim
                        WHERE stagione = $stagione AND giornata = $giornata AND id_squadra = $id");
    $r = $reale ?: $sim;
    if (!$r) return ["fonte" => null, "mio" => null, "calcolato_il" => null, "simulato_da" => null];
    return [
        "fonte" => $reale ? "reale" : "simulazione",
        "mio" => [
            "ftotale"   => (float) $r["ftotale"],  "ftotale_a" => (float) $r["ftotale_a"],
            "golf"      => (int) $r["golf"],       "gols"      => (int) $r["gols"],
            "punti"     => (int) $r["punti"],      "segno"     => $r["segno"],
        ],
        "calcolato_il" => $sim["calcolato_il"] ?? null,
        "simulato_da"  => $sim["simulato_da"] ?? null,
    ];
}

/** Righe di formazione di UNA squadra (titolari maglia 1-11, panchina > 11). */
function dl_formazione_squadra(int $stagione, int $giornata, string $tab, int $idSquadra): array
{
    return pd_carica_formazione($stagione, $giornata, $tab, [$idSquadra])[$idSquadra] ?? [];
}

/** Registra l'avversario di una competizione con la sua formazione (la stessa squadra può comparire in entrambe). */
function dl_registra_avversario(array &$avversari, int $idAvv, string $nome, string $comp, array $righe): void
{
    if (!isset($avversari[$idAvv])) $avversari[$idAvv] = ["nome" => $nome, "competizioni" => [], "righe" => []];
    $avversari[$idAvv]["competizioni"][] = $comp;
    // la formazione è per competizione: si tiene quella di ogni competizione, i titolari si uniscono dopo
    $avversari[$idAvv]["righe"][$comp] = $righe;
}

$partite = [];
$formazioni = [];   // competizione => righe di formazione (titolari + panchina)
$avversari  = [];   // id squadra avversaria => [nome, competizioni, righe di formazione]

// Campionato
$ac = ds_avversario_camp($stagione, $giornata, $idSquadra);
if ($ac) {
    $io  = ds_avversario($stagione, $idSquadra);
    $avv = ds_avversario($stagione, $ac["id_avv"]);
    if ($io && $avv) {
        $partite[] = [
            "competizione" => "CAMP", "turno" => null,
            "id_casa" => $ac["casa"] ? $idSquadra : $ac["id_avv"],
            "casa" => $ac["casa"] ? $io : $avv, "ospite" => $ac["casa"] ? $avv : $io,
            "io_casa" => $ac["casa"],
        ] + dl_risultato("NEW_RISULTATI", "id_squadra", "NEW_SIMULAZIONE_RISULTATI", $stagione, $giornata, $idSquadra);
        $formazioni["CAMP"] = dl_formazione_squadra($stagione, $giornata, FORMAZIONE_TAB_CAMPIONATO, $idSquadra);
        dl_registra_avversario($avversari, $ac["id_avv"], $avv["nome"], "CAMP",
            dl_formazione_squadra($stagione, $giornata, FORMAZIONE_TAB_CAMPIONATO, $ac["id_avv"]));
    }
}

// Champions (se la squadra gioca in questa giornata)
foreach (champions_partite_giornata($stagione, $giornata)["partite"] as $p) {
    if ($p["casa"] !== $idSquadra && $p["ospite"] !== $idSquadra) continue;
    $casa  = $p["casa"] === $idSquadra;
    $idAvv = $casa ? $p["ospite"] : $p["casa"];
    $io  = ds_avversario($stagione, $idSquadra);
    $avv = ds_avversario($stagione, $idAvv);
    if (!$io || !$avv) break;
    $partite[] = [
        "competizione" => "CHAMP",
        "turno" => champions_etichetta_gruppo(champions_gruppo_girone($p["girone"])),
        "id_casa" => $p["casa"],
        "casa" => $casa ? $io : $avv, "ospite" => $casa ? $avv : $io,
        "io_casa" => $casa,
    ] + dl_risultato("NEW_RISULTATI_CHAMP", "squadra", "NEW_SIMULAZIONE_RISULTATI_CHAMP", $stagione, $giornata, $idSquadra);
    $formazioni["CHAMP"] = dl_formazione_squadra(
        $stagione, $giornata, formazione_tabella_champions($stagione, $giornata, $idSquadra), $idSquadra);
    dl_registra_avversario($avversari, $idAvv, $avv["nome"], "CHAMP", dl_formazione_squadra(
        $stagione, $giornata, formazione_tabella_champions($stagione, $giornata, $idAvv), $idAvv));
    break;
}

// ── Giocatori: titolari e panchinari, con la loro partita ────────

// Calendario Serie A per squadra (nome normalizzato)
$perSquadra = [];
foreach ($partiteSerieA as $p) {
    $inizio = dl_inizio($p);
    $base = ["n_partita" => (int) $p["n_partita"], "data" => $p["data_partita"], "ora" => $p["ora_partita"], "inizio" => $inizio];
    $perSquadra[sim_norm($p["squadra_casa"])]   = $base + ["lato" => "casa"];
    $perSquadra[sim_norm($p["squadra_ospite"])] = $base + ["lato" => "ospite"];
}

/** Stato della partita di Serie A di una squadra: conclusa | in_corso | da_giocare | nessuna. */
function dl_stato_partita(?array $partita, string $squadraNorm, array $ctx, int $ora): string
{
    if ($partita === null) return "nessuna";
    if (isset($ctx["giocate"][$squadraNorm]) || $ora > $partita["inizio"] + DURATA_PARTITA_MIN * 60) return "conclusa";
    return $ora >= $partita["inizio"] ? "in_corso" : "da_giocare";
}

$titolari = [];   // id => giocatore (unione delle competizioni)
$bench    = [];   // ruolo => [id => giocatore] in ordine di maglia
foreach ($formazioni as $comp => $righe) {
    foreach ($righe as $r) {
        $id = $r["id_giocatore"];
        $sa = $ctx["squadre"][$id] ?? null;
        $sn = $sa !== null ? sim_norm($sa) : "";
        $partita = $sn !== "" ? ($perSquadra[$sn] ?? null) : null;
        $g = [
            "id_giocatore" => $id, "nome" => $r["giocatore"], "ruolo" => $r["ruolo"],
            "squadra_serie_a" => $sa !== null && trim($sa) !== "" ? $sa : null,
            "stato_partita" => dl_stato_partita($partita, $sn, $ctx, $ora),
            "data" => $partita["data"] ?? null, "ora" => $partita["ora"] ?? null,
            "voto_reale" => isset($ctx["voti"][$id]) && $ctx["voti"][$id]["voto"] !== null && $ctx["voti"][$id]["sv"] !== "Y"
                            ? (float) $ctx["voti"][$id]["voto"] : null,
            "senza_voto" => isset($ctx["voti"][$id]) && ($ctx["voti"][$id]["sv"] === "Y" || $ctx["voti"][$id]["voto"] === null),
            "_norm" => $sn, "_partita" => $partita,
        ];
        if ($r["maglia"] >= 1 && $r["maglia"] <= 11) {
            if (!isset($titolari[$id])) $titolari[$id] = $g + ["competizioni" => []];
            $titolari[$id]["competizioni"][] = $comp;
        } elseif ($r["maglia"] > 11) {
            if (!isset($bench[$g["ruolo"]][$id])) $bench[$g["ruolo"]][$id] = $g + ["competizioni" => []];
            $bench[$g["ruolo"]][$id]["competizioni"][] = $comp;
        }
    }
}

// Titolari delle squadre avversarie di giornata (maglia 1-11), con la loro partita di Serie A
$titolariAvv = [];   // id squadra avversaria => [id giocatore => giocatore]
foreach ($avversari as $idAvv => $a) {
    foreach ($a["righe"] as $comp => $righe) {
        foreach ($righe as $r) {
            if ($r["maglia"] < 1 || $r["maglia"] > 11) continue;
            $id = $r["id_giocatore"];
            if (isset($titolariAvv[$idAvv][$id])) { $titolariAvv[$idAvv][$id]["competizioni"][] = $comp; continue; }
            $sa = $ctx["squadre"][$id] ?? null;
            $sn = $sa !== null ? sim_norm($sa) : "";
            $partita = $sn !== "" ? ($perSquadra[$sn] ?? null) : null;
            $titolariAvv[$idAvv][$id] = [
                "id_giocatore" => $id, "nome" => $r["giocatore"], "ruolo" => $r["ruolo"],
                "squadra_serie_a" => $sa !== null && trim($sa) !== "" ? $sa : null,
                "stato_partita" => dl_stato_partita($partita, $sn, $ctx, $ora),
                "senza_voto" => isset($ctx["voti"][$id]) && ($ctx["voti"][$id]["sv"] === "Y" || $ctx["voti"][$id]["voto"] === null),
                "competizioni" => [$comp], "_partita" => $partita,
            ];
        }
    }
}

// ── Partite di Serie A di oggi con i titolari coinvolti ──────────

$oggi = date("Y-m-d", $ora);
$perPartita = [];   // n_partita => giocatori
$senzaVoto = [];
$aRischio  = [];    // ruolo => numero di titolari non ancora "sicuri"

foreach ($titolari as $t) {
    $p = $t["_partita"];
    if ($p === null) continue;   // squadra non determinata o a riposo
    if ($t["senza_voto"]) {      // partita finita, nessun voto: sarà sostituito
        $senzaVoto[] = $t;
        $aRischio[$t["ruolo"]] = ($aRischio[$t["ruolo"]] ?? 0) + 1;
        continue;
    }
    if ($t["stato_partita"] === "conclusa") continue;     // ha già (o avrà) il suo voto
    // da giocare o in corso
    $aRischio[$t["ruolo"]] = ($aRischio[$t["ruolo"]] ?? 0) + 1;
    if ($p["data"] === $oggi) $perPartita[$p["n_partita"]][] = $t;
}

// Titolari avversari ancora da giocare / in corso, per partita di Serie A di oggi
$perPartitaAvv = [];   // n_partita => id squadra avversaria => giocatori
foreach ($titolariAvv as $idAvv => $gioc) {
    foreach ($gioc as $t) {
        $p = $t["_partita"];
        if ($p === null || $p["data"] !== $oggi) continue;
        if ($t["senza_voto"] || $t["stato_partita"] === "conclusa") continue;
        $perPartitaAvv[$p["n_partita"]][$idAvv][] = $t;
    }
}

$outOggi = [];
foreach ($partiteSerieA as $p) {
    $n = (int) $p["n_partita"];
    if ($p["data_partita"] !== $oggi) continue;
    $stCasa = dl_stato_partita($perSquadra[sim_norm($p["squadra_casa"])] ?? null, sim_norm($p["squadra_casa"]), $ctx, $ora);
    $stOsp  = dl_stato_partita($perSquadra[sim_norm($p["squadra_ospite"])] ?? null, sim_norm($p["squadra_ospite"]), $ctx, $ora);
    if ($stCasa === "conclusa" && $stOsp === "conclusa") continue;      // partita finita
    $outOggi[] = [
        "n_partita" => $n, "data" => $p["data_partita"], "ora" => $p["ora_partita"],
        "stato" => $stCasa === "in_corso" || $stOsp === "in_corso" ? "in_corso" : "da_giocare",
        "casa" => $p["squadra_casa"], "ospite" => $p["squadra_ospite"],
        "giocatori" => array_map(fn($g) => [
            "id_giocatore" => $g["id_giocatore"], "nome" => $g["nome"], "ruolo" => $g["ruolo"],
            "squadra_serie_a" => $g["squadra_serie_a"], "competizioni" => $g["competizioni"],
        ], $perPartita[$n] ?? []),
        "avversari" => array_values(array_map(fn($idAvv, $gioc) => [
            "id_squadra" => $idAvv, "nome" => $avversari[$idAvv]["nome"],
            "competizioni" => $avversari[$idAvv]["competizioni"],
            "giocatori" => array_map(fn($g) => [
                "id_giocatore" => $g["id_giocatore"], "nome" => $g["nome"], "ruolo" => $g["ruolo"],
                "squadra_serie_a" => $g["squadra_serie_a"], "competizioni" => $g["competizioni"],
            ], $gioc),
        ], array_keys($perPartitaAvv[$n] ?? []), array_values($perPartitaAvv[$n] ?? []))),
    ];
}

// ── Panchinari: i primi candidati per ruolo ──────────────────────

$panchinari = [];
foreach ([1, 2, 3, 4] as $ruolo) {
    $nRischio = $aRischio[$ruolo] ?? 0;
    if ($nRischio === 0) continue;
    $candidati = [];
    foreach ($bench[$ruolo] ?? [] as $b) {
        if ($b["senza_voto"]) continue;                       // già s.v.: non può entrare
        $candidati[] = [
            "id_giocatore" => $b["id_giocatore"], "nome" => $b["nome"], "ruolo" => $ruolo,
            "squadra_serie_a" => $b["squadra_serie_a"], "competizioni" => $b["competizioni"],
            "stato_partita" => $b["stato_partita"], "voto" => $b["voto_reale"],
            "data" => $b["data"], "ora" => $b["ora"],
        ];
        if (count($candidati) >= min($nRischio, 3)) break;
    }
    $panchinari[] = ["ruolo" => $ruolo, "a_rischio" => $nRischio, "candidati" => $candidati];
}

api_success([
    "attiva"           => true,
    "stagione"         => $stagione,
    "giornata"         => $giornata,
    "giornata_serie_a" => $giornataSerieA,
    "finestra"         => $finestra,
    "partite"          => $partite,
    "oggi"             => $outOggi,
    "senza_voto"       => array_map(fn($g) => [
        "id_giocatore" => $g["id_giocatore"], "nome" => $g["nome"], "ruolo" => $g["ruolo"],
        "squadra_serie_a" => $g["squadra_serie_a"],
    ], $senzaVoto),
    "panchinari"       => $panchinari,
]);
