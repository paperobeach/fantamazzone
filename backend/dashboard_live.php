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
//   avversari   per ciascuna squadra avversaria di giornata: stessi dati di
//               senza_voto e panchinari calcolati sulla sua formazione
//               [{ id_squadra, nome, competizioni, senza_voto, panchinari }]
//   senza_voto  titolari senza voto secondo la SIMULAZIONE: SV ufficiale (voto
//               assente / squadra di Serie A già scesa in campo senza di lui)
//               o SV inserito a mano nell'editing (voto 0); "manuale" lo distingue
//               Tra i giocatori di "oggi" compaiono anche i panchinari GIÀ SICURI di
//               giocare ("subentra": true): quelli che entrano perché un titolare
//               (o un panchinaro precedente) ha già giocato con la sua squadra di
//               Serie A senza prendere voto (SV ufficiale; gli editing manuali non
//               contano). Sono calcolati con la stessa logica e gli stessi limiti
//               di sim_carica_formazione.
//   panchinari  per ruolo (con titolari da verificare), i 3 panchinari successivi
//               all'ultimo giocatore del ruolo considerato titolare, cioè il titolare
//               o l'ultimo panchinaro già sicuro di giocare; i panchinari con SV
//               ufficiale sono saltati. "entra_sim": true se la simulazione (con gli
//               editing manuali) lo fa comunque entrare.
//
// Ogni giocatore riporta stato_dato / voto / modificabile come in
// live_simulazione.php (GET): il front end ne permette l'editing con
// live_simulazione.php (azioni salva_edit / elimina_edit), che ricalcola la
// simulazione della sola partita interessata.
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

$ctx = sim_contesto($stagione, $giornata);   // voti Serie A, squadre già scese in campo, editing manuale, squadra di ogni giocatore

// Stessi limiti della simulazione (sostituzioni e riserve d'ufficio)
$config = caricaConfigurazioneCalcolo($conn, $stagione);
$cfg = [
    "max_movimento" => (int) ($config["parametri"]["SOSTITUZIONI_MAX_MOVIMENTO"] ?? 5),
    "max_portiere"  => (int) ($config["parametri"]["SOSTITUZIONI_MAX_PORTIERE"] ?? 1),
    "ufficio" => [
        "max"            => max(0, (int) sim_parametro_numerico($config, "RISERVE_UFFICIO_MAX", 1)),
        "voto_portiere"  => sim_parametro_numerico($config, "VOTO_UFFICIO_PORTIERE", 3),
        "voto_movimento" => sim_parametro_numerico($config, "VOTO_UFFICIO_MOVIMENTO", 4),
        "voto_ammonito"  => sim_parametro_numerico($config, "VOTO_UFFICIO_AMMONITO", 5),
    ],
];

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
function dl_registra_avversario(array &$avversari, int $idAvv, string $nome, string $comp, array $righe, string $tab): void
{
    if (!isset($avversari[$idAvv])) $avversari[$idAvv] = ["nome" => $nome, "competizioni" => [], "righe" => [], "tab" => []];
    $avversari[$idAvv]["competizioni"][] = $comp;
    $avversari[$idAvv]["tab"][$comp] = $tab;
    // la formazione è per competizione: si tiene quella di ogni competizione, i titolari si uniscono dopo
    $avversari[$idAvv]["righe"][$comp] = $righe;
}

$partite = [];
$formazioni = [];   // competizione => righe di formazione (titolari + panchina)
$tabMie     = [];   // competizione => tabella di formazione della squadra
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
        $tabMie["CAMP"] = FORMAZIONE_TAB_CAMPIONATO;
        dl_registra_avversario($avversari, $ac["id_avv"], $avv["nome"], "CAMP",
            dl_formazione_squadra($stagione, $giornata, FORMAZIONE_TAB_CAMPIONATO, $ac["id_avv"]), FORMAZIONE_TAB_CAMPIONATO);
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
    $tabMie["CHAMP"] = formazione_tabella_champions($stagione, $giornata, $idSquadra);
    $tabAvv = formazione_tabella_champions($stagione, $giornata, $idAvv);
    dl_registra_avversario($avversari, $idAvv, $avv["nome"], "CHAMP",
        dl_formazione_squadra($stagione, $giornata, $tabAvv, $idAvv), $tabAvv);
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

/** Giocatore di una riga di formazione, con la sua partita di Serie A e il suo stato nella SIMULAZIONE. */
function dl_giocatore(array $r, array $ctx, array $perSquadra, int $ora): array
{
    $id = $r["id_giocatore"];
    $sa = $ctx["squadre"][$id] ?? null;
    $sn = $sa !== null ? sim_norm($sa) : "";
    $partita = $sn !== "" ? ($perSquadra[$sn] ?? null) : null;

    // Dato usato dalla simulazione: reale | senza voto | editing manuale | 6 provvisorio
    $warn = [];
    $stat = sim_stat_giocatore($ctx, $id, $warn, (string) $r["giocatore"]);
    $sv   = (bool) $stat["sv"];
    $stato = isset($ctx["voti"][$id]) ? ($sv ? "senza_voto" : "reale")
           : (!empty($stat["manuale"]) ? ($sv ? "senza_voto" : "manuale")
           : ($sv ? "senza_voto" : "provvisorio"));
    [$modificabile] = sim_giocatore_modificabile($ctx, $id);

    return [
        "id_giocatore" => $id, "nome" => $r["giocatore"], "ruolo" => $r["ruolo"],
        "squadra_serie_a" => $sa !== null && trim($sa) !== "" ? $sa : null,
        "stato_partita" => dl_stato_partita($partita, $sn, $ctx, $ora),
        "data" => $partita["data"] ?? null, "ora" => $partita["ora"] ?? null,
        "stato_dato" => $stato,
        "voto" => !$sv && $stat["voto"] !== null ? (float) $stat["voto"] : null,
        "senza_voto" => $sv,
        "sv_manuale" => $sv && !empty($stat["manuale"]),
        "modificabile" => $modificabile,
        "_norm" => $sn, "_partita" => $partita,
    ];
}

/**
 * Titolari (maglia 1-11) e panchinari (maglia > 11) di una squadra, uniti sulle
 * competizioni. $formazioni: competizione => righe di formazione.
 * @return array{0:array,1:array} [titolari id => giocatore, panchina ruolo => [id => giocatore]]
 */
function dl_carica_squadra(array $formazioni, array $ctx, array $perSquadra, int $ora): array
{
    $titolari = [];
    $bench    = [];
    foreach ($formazioni as $comp => $righe) {
        foreach ($righe as $r) {
            $id = $r["id_giocatore"];
            if ($r["maglia"] >= 1 && $r["maglia"] <= 11) {
                if (!isset($titolari[$id])) $titolari[$id] = dl_giocatore($r, $ctx, $perSquadra, $ora) + ["competizioni" => []];
                $titolari[$id]["competizioni"][] = $comp;
            } elseif ($r["maglia"] > 11) {
                $ruolo = $r["ruolo"];
                if (!isset($bench[$ruolo][$id])) $bench[$ruolo][$id] = dl_giocatore($r, $ctx, $perSquadra, $ora) + ["competizioni" => []];
                $bench[$ruolo][$id]["competizioni"][] = $comp;
            }
        }
    }
    return [$titolari, $bench];
}

/** Versione sintetica di un giocatore per la risposta. */
function dl_out_giocatore(array $g): array
{
    return [
        "id_giocatore" => $g["id_giocatore"], "nome" => $g["nome"], "ruolo" => $g["ruolo"],
        "squadra_serie_a" => $g["squadra_serie_a"], "competizioni" => $g["competizioni"] ?? [],
        "stato_dato" => $g["stato_dato"], "voto" => $g["voto"],
        "modificabile" => $g["modificabile"], "sv_manuale" => $g["sv_manuale"],
        "subentra" => (bool) ($g["subentra"] ?? false),
    ];
}

/**
 * Panchinari che entrano (al posto dei titolari senza voto) nel contesto passato:
 * con $ctx completo è la simulazione, con gli editing svuotati sono i soli SV ufficiali.
 * con la stessa logica e gli stessi limiti di sim_carica_formazione, per ogni
 * formazione (campionato / Champions) della squadra.
 * @return array id panchinaro => true
 */
function dl_subentranti(array $tabelle, int $idSquadra, array $ctx, int $stagione, int $giornata, array $cfg): array
{
    $out = [];
    foreach ($tabelle as $tab) {
        try {
            $w = []; $ng = [];
            $f = sim_carica_formazione($ctx, $stagione, $giornata, $idSquadra, $w, $ng,
                $cfg["max_movimento"], $cfg["max_portiere"], $cfg["ufficio"], $tab);
            foreach ($f["sostituti_entrati"] as $id) $out[(int) $id] = true;
        } catch (Throwable $e) {
            // formazione assente: nessun subentrante
        }
    }
    return $out;
}

/**
 * Analisi LIVE di una squadra, nella situazione aggiornata della simulazione.
 * @param array $sicuri id panchinaro => true: entra per un SV UFFICIALE (senza editing manuali)
 * @param array $sim    id panchinaro => true: entra nella simulazione (con gli editing manuali)
 * @return array{per_partita:array, senza_voto:array, panchinari:array}
 *   per_partita: n_partita => titolari ancora da giocare/in corso nelle partite di OGGI,
 *                più i panchinari già sicuri di giocare ("subentra")
 *   senza_voto : titolari senza voto (ufficiale o manuale): saranno sostituiti
 *   panchinari : per ruolo, i 3 panchinari successivi all'ultimo giocatore considerato titolare
 */
function dl_analizza_squadra(array $titolari, array $bench, array $sicuri, array $sim, string $oggi): array
{
    $perPartita = [];
    $senzaVoto  = [];
    $aRischio   = [];   // ruolo => numero di titolari non ancora "sicuri"
    $svPerRuolo = [];   // ruolo => titolari senza voto

    foreach ($titolari as $t) {
        $p = $t["_partita"];
        if ($p === null) continue;   // squadra non determinata o a riposo
        if ($t["senza_voto"]) {      // SV ufficiale o manuale: sarà sostituito
            $senzaVoto[] = $t;
            $svPerRuolo[$t["ruolo"]] = ($svPerRuolo[$t["ruolo"]] ?? 0) + 1;
            $aRischio[$t["ruolo"]] = ($aRischio[$t["ruolo"]] ?? 0) + 1;
            continue;
        }
        if ($t["stato_partita"] === "conclusa") continue;     // ha già (o avrà) il suo voto
        // da giocare o in corso
        $aRischio[$t["ruolo"]] = ($aRischio[$t["ruolo"]] ?? 0) + 1;
        if ($p["data"] === $oggi) $perPartita[$p["n_partita"]][] = $t;
    }

    // Panchinari già sicuri di giocare, con la partita di Serie A di oggi ancora da giocare
    foreach ($bench as $lista) {
        foreach ($lista as $b) {
            if (!isset($sicuri[$b["id_giocatore"]])) continue;
            $p = $b["_partita"];
            if ($p === null || $p["data"] !== $oggi || $b["stato_partita"] === "conclusa") continue;
            $perPartita[$p["n_partita"]][] = $b + ["subentra" => true];
        }
    }

    $panchinari = [];
    foreach ([1, 2, 3, 4] as $ruolo) {
        if (($aRischio[$ruolo] ?? 0) === 0) continue;
        $lista = array_values($bench[$ruolo] ?? []);

        // Si riparte dopo l'ultimo panchinaro già sicuro di giocare (o dai titolari se non ce n'è)
        $ultimo = -1;
        $nSicuri = 0;
        foreach ($lista as $i => $b) {
            if (isset($sicuri[$b["id_giocatore"]])) { $ultimo = $i; $nSicuri++; }
        }

        $candidati = [];
        foreach (array_slice($lista, $ultimo + 1) as $b) {
            if ($b["senza_voto"] && !$b["sv_manuale"]) continue;   // SV ufficiale: non può entrare
            $candidati[] = dl_out_giocatore($b) + [
                "stato_partita" => $b["stato_partita"], "data" => $b["data"], "ora" => $b["ora"],
                "entra_sim" => isset($sim[$b["id_giocatore"]]),
            ];
            if (count($candidati) >= 3) break;
        }
        $panchinari[] = [
            "ruolo" => $ruolo, "a_rischio" => $aRischio[$ruolo],
            "titolari_sv" => $svPerRuolo[$ruolo] ?? 0, "sicuri" => $nSicuri,
            "candidati" => $candidati,
        ];
    }

    return [
        "per_partita" => $perPartita,
        "senza_voto"  => array_map("dl_out_giocatore", $senzaVoto),
        "panchinari"  => $panchinari,
    ];
}

$oggi = date("Y-m-d", $ora);

// Contesto "ufficiale": senza gli editing manuali della simulazione. Serve a stabilire chi è
// SICURO di giocare (SV ufficiale di una squadra di Serie A già scesa in campo).
$ctxUff = $ctx;
$ctxUff["edit"] = [];

// La squadra
[$titolari, $bench] = dl_carica_squadra($formazioni, $ctx, $perSquadra, $ora);
$mia = dl_analizza_squadra($titolari, $bench,
    dl_subentranti($tabMie, $idSquadra, $ctxUff, $stagione, $giornata, $cfg),
    dl_subentranti($tabMie, $idSquadra, $ctx, $stagione, $giornata, $cfg), $oggi);

// Le squadre avversarie di giornata (campionato e/o Champions): stessa analisi
$outAvversari = [];
$perPartitaAvv = [];   // n_partita => id squadra avversaria => giocatori
foreach ($avversari as $idAvv => $a) {
    [$tAvv, $bAvv] = dl_carica_squadra($a["righe"], $ctx, $perSquadra, $ora);
    $an = dl_analizza_squadra($tAvv, $bAvv,
        dl_subentranti($a["tab"], $idAvv, $ctxUff, $stagione, $giornata, $cfg),
        dl_subentranti($a["tab"], $idAvv, $ctx, $stagione, $giornata, $cfg), $oggi);
    foreach ($an["per_partita"] as $n => $gioc) $perPartitaAvv[$n][$idAvv] = $gioc;
    $outAvversari[] = [
        "id_squadra" => $idAvv, "nome" => $a["nome"], "competizioni" => $a["competizioni"],
        "senza_voto" => $an["senza_voto"], "panchinari" => $an["panchinari"],
    ];
}

// ── Partite di Serie A di oggi con i titolari coinvolti ──────────

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
        "giocatori" => array_map("dl_out_giocatore", $mia["per_partita"][$n] ?? []),
        "avversari" => array_values(array_map(fn($idAvv, $gioc) => [
            "id_squadra" => $idAvv, "nome" => $avversari[$idAvv]["nome"],
            "competizioni" => $avversari[$idAvv]["competizioni"],
            "giocatori" => array_map("dl_out_giocatore", $gioc),
        ], array_keys($perPartitaAvv[$n] ?? []), array_values($perPartitaAvv[$n] ?? []))),
    ];
}

api_success([
    "attiva"           => true,
    "stagione"         => $stagione,
    "giornata"         => $giornata,
    "giornata_serie_a" => $giornataSerieA,
    "finestra"         => $finestra,
    "partite"          => $partite,
    "oggi"             => $outOggi,
    "senza_voto"       => $mia["senza_voto"],
    "panchinari"       => $mia["panchinari"],
    "avversari"        => $outAvversari,   // per ogni avversario: senza_voto e panchinari (stessa struttura)
]);
