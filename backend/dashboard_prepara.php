<?php
// ============================================================
// api/dashboard_prepara.php
// GET ?stagione=2026&id_squadra=3&competizione=CAMP|CHAMP
//
// Area "Prepara la prossima partita" della Dashboard squadra, per UNA
// competizione (campionato = default, Champions).
//
//   match              prossima partita della competizione (giornata,
//                      casa/trasferta, avversario, scadenza formazione);
//                      null se non c'è
//   forma_avversario   ultime 10 partite dell'avversario nella stessa
//                      competizione (la UI mostra le ultime 5 o 10)
//   moduli_avversario  moduli schierati dall'avversario nella stagione
//   formazioni         formazione propria e dell'avversario per questo match,
//                      se inserite (null altrimenti)
//   rosa               ogni giocatore della rosa con: squadra di Serie A,
//                      avversaria di Serie A della giornata, casa/trasferta,
//                      presenze e medie voto netto/lordo (per il Top 11).
//                      presenze/media_* contano solo le giornate in cui il
//                      giocatore ha concorso al punteggio (schierato);
//                      tutta_rosa {presenze, media_netto, media_lordo} conta
//                      le giornate in cui era in rosa con un voto reale,
//                      schierato o no (lib/StatisticheRosa.php); null se non
//                      calcolabile (vedi rosa_non_disponibile)
//   rosa_non_disponibile  messaggio se la vista "tutta la rosa" non è
//                      calcolabile per la stagione (voti Serie A o regole
//                      mancanti), altrimenti null
//   incontri_serie_a   partite di Serie A della giornata, con i giocatori
//                      della rosa schierabili da ciascun lato
//
// Giornata di Serie A = giornata di fantacampionato + GIORNATA_SERIE_A_INIZIO
// - 1 (vedi ds_inizio_giornata). Per la Champions si usa la giornata del match.
// Voto NETTO = voto base senza bonus/malus, LORDO = fantavoto con bonus/malus.
// Le giornate non chiuse non entrano mai nelle medie, nella forma e nei moduli.
//
// ATTENZIONE: FORMAZIONE_AVVERSARIO_VISIBILE decide se la formazione già
// inserita dall'avversario è mostrata anche PRIMA del limite di inserimento
// (comportamento della dashboard esistente). Con false resta nascosta fino
// alla scadenza.
// FORMAZIONE NASCOSTA: se l'avversario ha scelto "nascondi formazione"
// (parametro NASCONDI_FORMAZIONE_CONSENTITO, lib/FormazioniBlocco.php), la sua
// formazione resta nascosta (avversario_nascosta = true) fino all'inizio della
// prima partita di Serie A della giornata. Lo stesso vale per la formazione
// "mia" se la dashboard è guardata da un'altra squadra (id_richiedente, che
// se assente coincide con id_squadra): mia_nascosta = true.
// ============================================================
require_once __DIR__ . "/connect.php";
require_once __DIR__ . "/lib/StatoGiornata.php";
require_once __DIR__ . "/lib/ChampionsCalendario.php";
require_once __DIR__ . "/lib/FormazioniChampions.php";
require_once __DIR__ . "/lib/DashboardSquadra.php";
require_once __DIR__ . "/lib/FormazioniBlocco.php";
require_once __DIR__ . "/lib/StatisticheRosa.php";

const FORMAZIONE_AVVERSARIO_VISIBILE = true;

$stagione  = param_int("stagione");
$idSquadra = param_int("id_squadra");
$idRichiedente = param_int("id_richiedente", false) ?? $idSquadra;
$comp      = strtoupper(trim((string) (param_str("competizione", false) ?: "CAMP")));
if (!in_array($comp, ["CAMP", "CHAMP"], true)) api_error("Competizione non valida (CAMP o CHAMP)", 400);
$champ = $comp === "CHAMP";

// ── Giornate pubbliche e giornata corrente ───────────────────

$storica = $stagione !== sg_ultima_stagione();
$chiuse  = [];
foreach (query_all("SELECT giornata FROM NEW_CALENDARIO_CK
                    WHERE stagione = $stagione AND ck_giocata = 'S'") as $c) {
    $chiuse[(int) $c["giornata"]] = true;
}
$pub = fn(int $g): bool => $storica || isset($chiuse[$g]);

$ultimaChiusa = $chiuse ? max(array_keys($chiuse)) : 0;
$ultCal = query_one("SELECT MAX(giornata) AS u FROM NEW_CALENDARIO WHERE stagione = $stagione");
$ultimaCalendario = (int) ($ultCal["u"] ?? 0);
$conclusa  = $ultimaCalendario === 0 || $ultimaChiusa >= $ultimaCalendario;
$giornataF = $conclusa ? $ultimaCalendario : $ultimaChiusa + 1;

if (!query_one("SELECT id FROM NEW_SQUADRE WHERE id = $idSquadra AND stagione = $stagione")) {
    api_error("Squadra non trovata", 404);
}

$vuoto = [
    "stagione" => $stagione, "competizione" => $comp, "conclusa" => $conclusa,
    "match" => null, "forma_avversario" => [], "moduli_avversario" => null,
    "formazioni" => ["mia" => null, "mia_nascosta" => false, "avversario" => null, "avversario_nascosta" => false],
    "giornata_serie_a" => null, "rosa" => [], "incontri_serie_a" => [],
];
if ($conclusa) api_success($vuoto);

// ── Helper ───────────────────────────────────────────────────

function dp_norm(?string $s): string
{
    $s = trim((string) $s);
    return function_exists("mb_strtoupper") ? mb_strtoupper($s, "UTF-8") : strtoupper($s);
}

/**
 * id_giocatore => nome della squadra di Serie A. Base: staging dell'asta;
 * l'ultima squadra con cui il giocatore compare nei voti Serie A prevale
 * (gestisce i trasferimenti). Stessa regola di lib/SimulazioneGiornata.php.
 */
function dp_squadre_serie_a(int $stagione): array
{
    $mappa = [];
    foreach (query_all("SELECT id_giocatore, squadra_serie_a AS squadra
                        FROM NEW_GIOCATORI_BASE_ASTA WHERE stagione = $stagione") as $r) {
        $mappa[(int) $r["id_giocatore"]] = $r["squadra"];
    }
    foreach (query_all("SELECT id_giocatore, squadra FROM NEW_VOTI_SERIE_A
                        WHERE stagione = $stagione ORDER BY giornata") as $r) {
        $mappa[(int) $r["id_giocatore"]] = $r["squadra"];
    }
    return $mappa;
}

/** Modulo ("4-3-3") dai conteggi dei titolari per ruolo; null se non sono 11. */
function dp_modulo(array $perRuolo): ?string
{
    $d = $perRuolo[2] ?? 0; $c = $perRuolo[3] ?? 0; $a = $perRuolo[4] ?? 0; $p = $perRuolo[1] ?? 0;
    if ($p !== 1 || $d + $c + $a !== 10) return null;
    return "$d-$c-$a";
}

// ── Prossimo match della competizione ────────────────────────

$match = null;
$tabMia = FORMAZIONE_TAB_CAMPIONATO;
$tabAvv = FORMAZIONE_TAB_CAMPIONATO;
$giornataMatch = $giornataF;

if (!$champ) {
    $ac   = ds_avversario_camp($stagione, $giornataF, $idSquadra);
    $avvC = $ac ? ds_avversario($stagione, $ac["id_avv"]) : null;
    if ($avvC) {
        $match = ["giornata" => $giornataF, "casa" => $ac["casa"], "turno" => null,
                  "rigori" => false, "avversario" => $avvC];
    }
} else {
    $pc   = ds_prossima_champions($stagione, $idSquadra, $giornataF);
    $avvH = $pc ? ds_avversario($stagione, $pc["id_avv"]) : null;
    if ($pc && $avvH) {
        $giornataMatch = $pc["giornata"];
        $match = ["giornata" => $pc["giornata"], "casa" => $pc["casa"], "turno" => $pc["turno"],
                  "rigori" => $pc["rigori"], "avversario" => $avvH];
        $tabMia = formazione_tabella_champions($stagione, $giornataMatch, $idSquadra);
        $tabAvv = formazione_tabella_champions($stagione, $giornataMatch, $avvH["id"]);
    }
}

[$giornataSerieA, $scadenza] = ds_inizio_giornata($stagione, $giornataMatch);
if ($match) $match["scadenza"] = $scadenza;
$scaduta = $scadenza !== null && strtotime($scadenza) <= time();

// ── Avversario: forma, moduli, formazioni ────────────────────

$forma = [];
$moduli = null;
$formazioni = ["mia" => null, "avversario" => null, "avversario_nascosta" => false];

if ($match) {
    $idAvv = $match["avversario"]["id"];

    $partiteAvv = $champ
        ? ds_partite_champ($stagione, $idAvv, $pub)
        : ds_partite_camp($stagione, $idAvv, $pub);
    $forma = array_slice(array_reverse($partiteAvv), 0, 10);

    // Moduli schierati: una formazione completa (11 titolari) per giornata chiusa
    $perGiornata = [];   // giornata => [ruolo => n]
    if (!$champ) {
        foreach (query_all("SELECT f.GIORNATA AS g, gg.ruolo AS r, COUNT(*) AS n
                            FROM NEW_FORMAZIONI f
                            JOIN " . rs_sql_anagrafica($stagione) . " gg ON gg.id = f.ID_GIOCATORE AND gg.stagione = f.STAGIONE
                            WHERE f.STAGIONE = $stagione AND f.ID_SQUADRA = $idAvv
                              AND f.MAGLIA BETWEEN 1 AND 11
                            GROUP BY f.GIORNATA, gg.ruolo") as $r) {
            if (!$pub((int) $r["g"])) continue;
            $perGiornata[(int) $r["g"]][(int) $r["r"]] = (int) $r["n"];
        }
    } else {
        // Formazione Champions effettiva: dipende dalla giornata (può essere quella di campionato)
        foreach ($partiteAvv as $p) {
            $g   = (int) $p["giornata"];
            $tab = formazione_tabella_champions($stagione, $g, $idAvv);
            foreach (query_all("SELECT gg.ruolo AS r, COUNT(*) AS n
                                FROM $tab f
                                JOIN " . rs_sql_anagrafica($stagione) . " gg ON gg.id = f.ID_GIOCATORE AND gg.stagione = f.STAGIONE
                                WHERE f.STAGIONE = $stagione AND f.ID_SQUADRA = $idAvv AND f.GIORNATA = $g
                                  AND f.MAGLIA BETWEEN 1 AND 11
                                GROUP BY gg.ruolo") as $r) {
                $perGiornata[$g][(int) $r["r"]] = (int) $r["n"];
            }
        }
    }
    $conteggio = [];
    foreach ($perGiornata as $perRuolo) {
        $m = dp_modulo($perRuolo);
        if ($m !== null) $conteggio[$m] = ($conteggio[$m] ?? 0) + 1;
    }
    arsort($conteggio);
    $tot = array_sum($conteggio);
    $moduli = [
        "campione"     => $tot,
        "distribuzione" => array_map(fn($m, $n) => [
            "modulo" => $m, "n" => $n, "perc" => round($n / $tot * 100, 1),
        ], array_keys($conteggio), array_values($conteggio)),
    ];

    // Formazioni per questo scontro (se inserite)
    $mia = ds_formazione($stagione, $giornataMatch, $idSquadra, $tabMia);
    $avv = ds_formazione($stagione, $giornataMatch, $idAvv, $tabAvv);
    $formazioni["mia_nascosta"] = false;
    if ($mia && fb_formazione_nascosta_a($stagione, $giornataMatch, $idSquadra, $idRichiedente)) {
        $mia = [];
        $formazioni["mia_nascosta"] = true;
    }
    $formazioni["mia"] = $mia ?: null;
    $avvNascosta = $avv && fb_formazione_nascosta_a($stagione, $giornataMatch, $idAvv, $idRichiedente);
    if ($avv && (($avvNascosta) || (!FORMAZIONE_AVVERSARIO_VISIBILE && !$scaduta))) {
        $formazioni["avversario_nascosta"] = true;   // inserita, ma non ancora visibile
    } else {
        $formazioni["avversario"] = $avv ?: null;
    }
}

// ── Rosa: Serie A, avversarie, medie voto ────────────────────

$tabVoti = $champ ? "NEW_VOTI_CHAMP" : "NEW_VOTI";
$condPub = $storica ? "1 = 1"
         : ($chiuse ? "v.giornata IN (" . implode(",", array_keys($chiuse)) . ")" : "1 = 0");

$medie = [];
foreach (query_all("SELECT v.id_giocatore, COUNT(*) AS presenze, AVG(v.voto) AS netto, AVG(v.totale) AS lordo
                    FROM $tabVoti v
                    WHERE v.stagione = $stagione AND v.id_squadra = $idSquadra AND v.giocata = 1 AND $condPub
                    GROUP BY v.id_giocatore") as $r) {
    $medie[(int) $r["id_giocatore"]] = $r;
}

// Vista "tutta la rosa": presenze e medie indipendenti dallo schieramento
// (giornate giocate dalla squadra nella competizione, giocatore in rosa con voto)
$mieiRisultati = $champ ? ds_partite_champ($stagione, $idSquadra, $pub) : ds_partite_camp($stagione, $idSquadra, $pub);
$mediePerRosa = null;
$rosaNonDisponibile = null;
try {
    $mediePerRosa = sr_medie_per_giocatore(
        sr_voti_rosa($stagione, $idSquadra, $champ, array_column($mieiRisultati, "giornata"))
    );
} catch (StatisticheRosaNonDisponibili $e) {
    $rosaNonDisponibile = $e->getMessage();
}

$partiteSerieA = query_all("SELECT n_partita, data_partita, ora_partita, squadra_casa, squadra_ospite
                            FROM NEW_CALENDARIO_SERIE_A
                            WHERE stagione = $stagione AND giornata = $giornataSerieA
                            ORDER BY data_partita, COALESCE(ora_partita, '23:59:59'), n_partita");
$perSquadraSerieA = [];   // nome normalizzato => dati della sua partita
foreach ($partiteSerieA as $p) {
    $base = ["data" => $p["data_partita"], "ora" => $p["ora_partita"]];
    $perSquadraSerieA[dp_norm($p["squadra_casa"])]   = $base + ["avversaria" => $p["squadra_ospite"], "casa" => true];
    $perSquadraSerieA[dp_norm($p["squadra_ospite"])] = $base + ["avversaria" => $p["squadra_casa"],   "casa" => false];
}

$squadreSerieA = dp_squadre_serie_a($stagione);
$rosa = [];
foreach (query_all("SELECT id, descrizione, ruolo FROM NEW_GIOCATORI
                    WHERE stagione = $stagione AND squadra = $idSquadra
                    ORDER BY ruolo, descrizione") as $g) {
    $id  = (int) $g["id"];
    $sa  = $squadreSerieA[$id] ?? null;
    $inc = $sa !== null ? ($perSquadraSerieA[dp_norm($sa)] ?? null) : null;
    $m   = $medie[$id] ?? null;
    $rosa[] = [
        "id_giocatore" => $id,
        "nome"         => $g["descrizione"],
        "ruolo"        => (int) $g["ruolo"],
        "serie_a"      => $sa !== null && trim($sa) !== "" ? $sa : null,
        "avversaria"   => $inc["avversaria"] ?? null,
        "casa"         => $inc["casa"] ?? null,
        "data"         => $inc["data"] ?? null,
        "ora"          => $inc["ora"] ?? null,
        "presenze"     => $m ? (int) $m["presenze"] : 0,
        "media_netto"  => $m ? round((float) $m["netto"], 2) : null,
        "media_lordo"  => $m ? round((float) $m["lordo"], 2) : null,
        "tutta_rosa"   => $mediePerRosa === null ? null
                          : ($mediePerRosa[$id] ?? ["presenze" => 0, "media_netto" => null, "media_lordo" => null]),
    ];
}

// ── Incontri di Serie A con i giocatori della rosa ───────────

$perNome = [];   // nome Serie A normalizzato => giocatori della rosa
foreach ($rosa as $g) {
    if ($g["serie_a"] === null) continue;
    $perNome[dp_norm($g["serie_a"])][] = [
        "id_giocatore" => $g["id_giocatore"], "nome" => $g["nome"], "ruolo" => $g["ruolo"],
    ];
}
$incontri = array_map(fn($p) => [
    "n_partita"      => (int) $p["n_partita"],
    "data"           => $p["data_partita"],
    "ora"            => $p["ora_partita"],
    "casa"           => $p["squadra_casa"],
    "ospite"         => $p["squadra_ospite"],
    "giocatori_casa"   => $perNome[dp_norm($p["squadra_casa"])] ?? [],
    "giocatori_ospite" => $perNome[dp_norm($p["squadra_ospite"])] ?? [],
], $partiteSerieA);

api_success([
    "stagione"          => $stagione,
    "competizione"      => $comp,
    "conclusa"          => false,
    "match"             => $match,
    "forma_avversario"  => $forma,
    "moduli_avversario" => $moduli,
    "formazioni"        => $formazioni,
    "giornata_serie_a"  => $giornataSerieA,
    "rosa"              => $rosa,
    "rosa_non_disponibile" => $rosaNonDisponibile,
    "incontri_serie_a"  => $incontri,
]);
