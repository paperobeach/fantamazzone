<?php
// ============================================================
// api/incontri.php
// GET ?stagione=2024&giornata=5
// GET ?stagione=2024&giornata=5&id_squadra=3  (singola partita)
//
// Risposta: dettaglio voti giocatori per ogni partita
// ============================================================
require_once __DIR__ . "/connect.php";

$stagione    = param_int("stagione");
$giornata    = param_int("giornata");
$id_squadra  = param_int("id_squadra", false);

$where_squadra = $id_squadra !== null
    ? "AND (r.id_squadra = $id_squadra OR r.id_squadra_a = $id_squadra)"
    : "";

// Risultati della giornata
$risultati = query_all("SELECT
        r.id_squadra, r.id_squadra_a,
        s1.nome AS nome_casa,  s1.logo AS logo_casa,
        s2.nome AS nome_ospite, s2.logo AS logo_ospite,
        r.ftotale, r.ftotale_a,
        r.golf, r.gols,
        r.modificatore, r.modificatore_a,
        r.punti, r.segno,
        r.mod_att, r.num_cc, r.tot_cc, r.mod_cc
    FROM RISULTATI r
    JOIN SQUADRE s1 ON s1.id = r.id_squadra   AND s1.stagione = r.stagione
    JOIN SQUADRE s2 ON s2.id = r.id_squadra_a AND s2.stagione = r.stagione
    WHERE r.stagione = $stagione AND r.giornata = $giornata
    $where_squadra
    ORDER BY r.id_squadra");

if (empty($risultati)) {
    api_success([]);
}

// Voti di tutte le squadre coinvolte
$ids = [];
foreach ($risultati as $r) {
    $ids[] = (int)$r["id_squadra"];
    $ids[] = (int)$r["id_squadra_a"];
}
$ids_str = implode(",", array_unique($ids));

$voti_raw = query_all("SELECT
        v.id_squadra, v.id_giocatore,
        g.descrizione AS giocatore, g.ruolo,
        f.MAGLIA AS maglia,
        v.voto, v.totale, v.giocata,
        v.reti, v.ammonizioni, v.espulsioni, v.autogol,
        v.retis, v.rigores, v.rigorep, v.assist
    FROM VOTI v
    JOIN GIOCATORI g  ON g.id = v.id_giocatore AND g.stagione = v.stagione
    LEFT JOIN FORMAZIONI f ON f.ID_GIOCATORE = v.id_giocatore
                           AND f.ID_SQUADRA  = v.id_squadra
                           AND f.STAGIONE    = v.stagione
                           AND f.GIORNATA    = v.giornata
    WHERE v.stagione = $stagione
      AND v.giornata = $giornata
      AND v.id_squadra IN ($ids_str)
    ORDER BY v.id_squadra, g.ruolo, v.totale DESC");

// Raggruppo voti per squadra
$voti_per_squadra = [];
foreach ($voti_raw as $v) {
    $voti_per_squadra[(int)$v["id_squadra"]][] = $v;
}

// Compongo risposta
$output = [];
foreach ($risultati as $r) {
    $id_casa   = (int)$r["id_squadra"];
    $id_ospite = (int)$r["id_squadra_a"];
    $output[] = [
        "casa"   => [
            "id"     => $id_casa,
            "nome"   => $r["nome_casa"],
            "logo"   => $r["logo_casa"],
            "ftotale"=> (float)$r["ftotale"],
            "mod"    => (int)$r["modificatore"],
            "giocatori" => $voti_per_squadra[$id_casa] ?? [],
        ],
        "ospite" => [
            "id"     => $id_ospite,
            "nome"   => $r["nome_ospite"],
            "logo"   => $r["logo_ospite"],
            "ftotale"=> (float)$r["ftotale_a"],
            "mod"    => (int)$r["modificatore_a"],
            "giocatori" => $voti_per_squadra[$id_ospite] ?? [],
        ],
        "golf"   => (int)$r["golf"],
        "gols"   => (int)$r["gols"],
        "punti_casa" => (int)$r["punti"],
        "segno"  => $r["segno"],
        "mod_att"=> (float)$r["mod_att"],
        "num_cc" => (int)$r["num_cc"],
        "tot_cc" => (float)$r["tot_cc"],
        "mod_cc" => (float)$r["mod_cc"],
    ];
}

api_success($output);
