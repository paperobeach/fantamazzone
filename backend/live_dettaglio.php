<?php
// ============================================================
// api/live_dettaglio.php
// GET ?stagione=2026&id_squadra=3[&fonte=auto|reale|simulazione][&competizione=CAMP|CHAMP]
// (default auto: NEW_RISULTATI se presente, altrimenti simulazione;
//  la risposta riporta la fonte effettivamente usata)
//
// Dettaglio voti di UNA partita della giornata in corso (id_squadra =
// squadra di casa). Stessa struttura di dettaglio_partita.php, ma:
//   - fonte=reale        legge NEW_VOTI / NEW_RISULTATI
//   - fonte=simulazione  legge NEW_SIMULAZIONE_VOTI / _RISULTATI e
//                        aggiunge ai giocatori i flag "provvisorio"
//                        (6 provvisorio) e "manuale" (editing manuale).
// Con competizione=CHAMP il dettaglio è quello della partita di Champions
// della giornata (id_squadra = squadra di casa): reale = NEW_RISULTATI_CHAMP /
// NEW_VOTI_CHAMP, simulazione = NEW_SIMULAZIONE_*_CHAMP; le formazioni sono
// quelle effettive di Champions (distinta se ammessa e salvata).
// Disponibile solo per la giornata in corso (non chiusa).
// ============================================================
require_once __DIR__ . "/connect.php";
require_once __DIR__ . "/lib/StatoGiornata.php";
require_once __DIR__ . "/lib/FormazioniChampions.php";

$stagione   = param_int("stagione");
$id_squadra = param_int("id_squadra");
$fonte      = param_str("fonte", false) ?: "auto";
if (!in_array($fonte, ["auto", "reale", "simulazione"], true)) api_error("Parametro fonte non valido", 400);
$comp = strtoupper(trim((string) (param_str("competizione", false) ?: "CAMP")));
if (!in_array($comp, ["CAMP", "CHAMP"], true)) api_error("Parametro competizione non valido (CAMP o CHAMP)", 400);
$champ = $comp === "CHAMP";

$stato = sg_stato_corrente($stagione);
if (!$stato["in_corso"]) api_success(null);
$giornata = $stato["giornata"];

// auto: se la partita ha dati su NEW_RISULTATI si usano quelli,
// altrimenti la simulazione
if ($fonte === "auto") {
    $n = $champ
        ? query_one("SELECT COUNT(*) AS n FROM NEW_RISULTATI_CHAMP
                     WHERE stagione = $stagione AND giornata = $giornata AND squadra = $id_squadra")
        : query_one("SELECT COUNT(*) AS n FROM NEW_RISULTATI
                     WHERE stagione = $stagione AND giornata = $giornata AND id_squadra = $id_squadra");
    $fonte = (int) ($n["n"] ?? 0) > 0 ? "reale" : "simulazione";
}

// Tabelle (e formazioni) della competizione richiesta
if ($champ) {
    // Avversaria nel turno di Champions della giornata
    $avversaria = null;
    foreach (champions_partite_giornata($stagione, $giornata)["partite"] as $pc) {
        if ($pc["casa"] === $id_squadra) { $avversaria = $pc["ospite"]; break; }
    }
    if ($avversaria === null) api_success(null);

    // NEW_RISULTATI_CHAMP ha la squadra in "squadra": la si espone come id_squadra
    $tRis  = $fonte === "reale"
        ? "(SELECT stagione, giornata, squadra AS id_squadra, id_squadra_a, ftotale, ftotale_a, golf, gols,
                   modificatore, modificatore_a, punti, segno, mod_att, num_cc, tot_cc, mod_cc
            FROM NEW_RISULTATI_CHAMP)"
        : "NEW_SIMULAZIONE_RISULTATI_CHAMP";
    $tVoti = $fonte === "reale" ? "NEW_VOTI_CHAMP" : "NEW_SIMULAZIONE_VOTI_CHAMP";
    $tForm = formazione_champions_sql_effettiva($stagione, $giornata, [$id_squadra, $avversaria]);
} else {
    $tRis  = $fonte === "reale" ? "NEW_RISULTATI" : "NEW_SIMULAZIONE_RISULTATI";
    $tVoti = $fonte === "reale" ? "NEW_VOTI"      : "NEW_SIMULAZIONE_VOTI";
    $tForm = "NEW_FORMAZIONI";
}
$extraRis = $fonte === "reale" ? "NULL AS calcolato_il, NULL AS simulato_da" : "r.calcolato_il, r.simulato_da";
$extra = $fonte === "reale" ? "0 AS provvisorio, 0 AS manuale" : "v.provvisorio, v.manuale";

$r = query_one("SELECT
        r.id_squadra, r.id_squadra_a,
        s1.nome AS nome_casa,  s1.logo AS logo_casa,
        s2.nome AS nome_ospite, s2.logo AS logo_ospite,
        r.ftotale, r.ftotale_a, r.golf, r.gols, r.modificatore, r.modificatore_a, r.punti, r.segno,
        $extraRis,
        r.mod_att AS mod_att_casa, r.mod_cc AS mod_cc_casa, r.num_cc AS num_cc_casa, r.tot_cc AS tot_cc_casa,
        r2.mod_att AS mod_att_ospite, r2.mod_cc AS mod_cc_ospite,
        r2.num_cc AS num_cc_ospite, r2.tot_cc AS tot_cc_ospite,
        r2.modificatore_a AS modificatore_a_ospite
    FROM $tRis r
    JOIN NEW_SQUADRE s1 ON s1.id = r.id_squadra   AND s1.stagione = r.stagione
    JOIN NEW_SQUADRE s2 ON s2.id = r.id_squadra_a AND s2.stagione = r.stagione
    LEFT JOIN $tRis r2 ON r2.stagione = r.stagione AND r2.giornata = r.giornata
                      AND r2.id_squadra = r.id_squadra_a AND r2.id_squadra_a = r.id_squadra
    WHERE r.stagione = $stagione AND r.giornata = $giornata AND r.id_squadra = $id_squadra");

if ($r === null) api_success(null);

$id_casa   = (int) $r["id_squadra"];
$id_ospite = (int) $r["id_squadra_a"];

$voti_raw = query_all("SELECT
        v.id_squadra, v.id_giocatore,
        COALESCE(g.descrizione, 'Riserva d''ufficio') AS giocatore,
        COALESCE(g.ruolo, FLOOR(ABS(v.id_giocatore) / 1000000)) AS ruolo,
        f.MAGLIA AS maglia, v.rufficio, (g.id IS NULL) AS ufficio_fittizio,
        v.voto, v.totale, v.giocata,
        v.reti, v.ammonizioni, v.espulsioni, v.autogol,
        v.retis, v.rigores, v.rigorep, v.assist,
        $extra
    FROM $tVoti v
    LEFT JOIN NEW_GIOCATORI g  ON g.id = v.id_giocatore AND g.stagione = v.stagione
    LEFT JOIN $tForm f ON f.ID_GIOCATORE = v.id_giocatore AND f.ID_SQUADRA = v.id_squadra
                              AND f.STAGIONE = v.stagione AND f.GIORNATA = v.giornata
    WHERE v.stagione = $stagione AND v.giornata = $giornata
      AND v.id_squadra IN ($id_casa, $id_ospite)
    ORDER BY v.id_squadra, ruolo, v.totale DESC");

$per_squadra = [];
foreach ($voti_raw as $v) {
    $v["ruolo"]            = (int) $v["ruolo"];
    $v["riserva_ufficio"]  = (int) $v["rufficio"] === 1;
    $v["ufficio_fittizio"] = (int) $v["ufficio_fittizio"] === 1;
    $v["provvisorio"]      = (int) $v["provvisorio"] === 1;
    $v["manuale"]          = (int) $v["manuale"] === 1;
    // Origine del voto, mostrata come icona nel dettaglio per giocatore
    $v["origine"] = $v["riserva_ufficio"] ? "ufficio"
                  : ($v["provvisorio"] ? "provvisorio"
                  : ($v["manuale"] ? "manuale"
                  : ((int) $v["giocata"] === 1 ? "reale" : "senza_voto")));
    $per_squadra[(int) $v["id_squadra"]][] = $v;
}

$num = fn($x) => $x !== null ? (float) $x : null;
$int = fn($x) => $x !== null ? (int) $x : null;

api_success([
    "fonte"    => $fonte,
    "competizione" => $comp,
    "giornata" => $giornata,
    "casa" => [
        "id" => $id_casa, "nome" => $r["nome_casa"], "logo" => $r["logo_casa"],
        "ftotale" => (float) $r["ftotale"],
        "mod_dif" => 0 - (int) $r["modificatore_a"],
        "mod_cc"  => $num($r["mod_cc_casa"]),  "mod_att" => $num($r["mod_att_casa"]),
        "num_cc"  => $int($r["num_cc_casa"]),  "tot_cc"  => $num($r["tot_cc_casa"]),
        "giocatori" => $per_squadra[$id_casa] ?? [],
    ],
    "ospite" => [
        "id" => $id_ospite, "nome" => $r["nome_ospite"], "logo" => $r["logo_ospite"],
        "ftotale" => (float) $r["ftotale_a"],
        "mod_dif" => $r["modificatore_a_ospite"] !== null ? 0 - (int) $r["modificatore_a_ospite"] : 0 - (int) $r["modificatore"],
        "mod_cc"  => $num($r["mod_cc_ospite"]),  "mod_att" => $num($r["mod_att_ospite"]),
        "num_cc"  => $int($r["num_cc_ospite"]),  "tot_cc"  => $num($r["tot_cc_ospite"]),
        "giocatori" => $per_squadra[$id_ospite] ?? [],
    ],
    "calcolato_il" => $r["calcolato_il"], "simulato_da" => $r["simulato_da"],
    "golf" => (int) $r["golf"], "gols" => (int) $r["gols"],
    "punti_casa" => (int) $r["punti"], "segno" => $r["segno"],
]);
