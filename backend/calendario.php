<?php
// ============================================================
// api/calendario.php
// GET ?stagione=2024
//
// Risposta: array di giornate, ciascuna con le partite e
//           i risultati SOLO se la giornata è chiusa
//           (NEW_CALENDARIO_CK.ck_giocata = 'S'); i risultati della
//           giornata in corso sono nella pagina "LIVE Giornata in
//           corso" (live_giornata.php). Le stagioni storiche (non
//           l'ultima) mostrano sempre tutti i risultati.
// ============================================================
require_once __DIR__ . "/connect.php";
require_once __DIR__ . "/lib/StatoGiornata.php";

$stagione = param_int("stagione");

// Tutte le giornate del calendario con i nomi delle squadre
$sql_cal = "SELECT
                c.giornata,
                c.posizione,
                c.squadra  AS id_squadra,
                s.nome     AS nome_squadra,
                s.logo
            FROM NEW_CALENDARIO c
            JOIN NEW_SQUADRE s ON s.id = c.squadra AND s.stagione = c.stagione
            WHERE c.stagione = $stagione
            ORDER BY c.giornata, c.posizione";

$rows = query_all($sql_cal);

// Risultati già inseriti
$sql_ris = "SELECT
                giornata,
                id_squadra,
                id_squadra_a,
                ftotale,
                ftotale_a,
                golf,
                gols,
                punti,
                segno
            FROM NEW_RISULTATI
            WHERE stagione = $stagione";

$risultati_raw = query_all($sql_ris);

// Indicizzo i risultati per giornata e id_squadra (squadra di casa)
$risultati = [];
foreach ($risultati_raw as $r) {
    $risultati[$r["giornata"]][$r["id_squadra"]] = $r;
}

// Giornate chiuse: i risultati sono visibili solo per queste (per le
// stagioni storiche, non più gestite, sono sempre visibili)
$stagione_storica = $stagione !== sg_ultima_stagione();
$chiuse = [];
foreach (query_all("SELECT giornata FROM NEW_CALENDARIO_CK
                    WHERE stagione = $stagione AND ck_giocata = 'S'") as $c) {
    $chiuse[(int)$c["giornata"]] = true;
}

// Raggruppo il calendario per giornata e accoppio le squadre a 2 a 2
$giornate = [];
foreach ($rows as $row) {
    $g = $row["giornata"];
    $p = $row["posizione"];
    if (!isset($giornate[$g])) {
        $giornate[$g] = ["giornata" => $g, "partite" => []];
    }
    // posizioni dispari = squadra di casa, pari = squadra ospite
    $match_index = (int)(($p - 1) / 2);
    if (!isset($giornate[$g]["partite"][$match_index])) {
        $giornate[$g]["partite"][$match_index] = [];
    }
    if ($p % 2 === 1) {
        $giornate[$g]["partite"][$match_index]["casa"] = [
            "id"   => (int)$row["id_squadra"],
            "nome" => $row["nome_squadra"],
            "logo" => $row["logo"],
        ];
    } else {
        $giornate[$g]["partite"][$match_index]["ospite"] = [
            "id"   => (int)$row["id_squadra"],
            "nome" => $row["nome_squadra"],
            "logo" => $row["logo"],
        ];
    }
}

// Aggiungo i risultati alle partite
foreach ($giornate as &$giornata) {
    $g = $giornata["giornata"];
    $visibile = $stagione_storica || isset($chiuse[(int)$g]);
    $giornata["chiusa"] = $visibile;
    foreach ($giornata["partite"] as &$partita) {
        $id_casa = $partita["casa"]["id"] ?? null;
        if ($visibile && $id_casa && isset($risultati[$g][$id_casa])) {
            $r = $risultati[$g][$id_casa];
            $partita["risultato"] = [
                "ftotale_casa"   => (float)$r["ftotale"],
                "ftotale_ospite" => (float)$r["ftotale_a"],
                "golf"           => (int)$r["golf"],
                "gols"           => (int)$r["gols"],
                "punti_casa"     => (int)$r["punti"],
                "segno"          => $r["segno"],
            ];
        } else {
            $partita["risultato"] = null;
        }
    }
    unset($partita);
    $giornata["partite"] = array_values($giornata["partite"]);
}
unset($giornata);

api_success(array_values($giornate));
