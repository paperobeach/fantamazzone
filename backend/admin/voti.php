<?php
// ============================================================
// api/admin/voti.php
//
// GET  ?stagione=2024&giornata=5&id_squadra=3  → voti inseriti
// POST { stagione, giornata, id_squadra, voti: [{id_giocatore, voto, reti, ...}] }
// ============================================================
require_once __DIR__ . "/../connect.php";

$stagione = param_int("stagione");

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $input      = json_decode(file_get_contents("php://input"), true) ?? $_POST;
    $giornata   = (int)($input["giornata"]   ?? 0);
    $id_squadra = (int)($input["id_squadra"] ?? 0);
    $voti       = $input["voti"] ?? [];

    if (!$giornata || !$id_squadra || !is_array($voti)) {
        api_error("Parametri mancanti: giornata, id_squadra, voti[]");
    }

    // Verifica giornata aperta
    $ck = query_one("SELECT ck_giocata FROM CALENDARIO_CK
                     WHERE stagione = $stagione AND giornata = $giornata");
    if ($ck && $ck["ck_giocata"] === "S") {
        api_error("Giornata già chiusa, impossibile modificare i voti", 403);
    }

    // Cancella e reinserisce
    mysqli_query($conn, "DELETE FROM VOTI
                         WHERE stagione = $stagione
                           AND giornata = $giornata
                           AND id_squadra = $id_squadra");

    $inseriti = 0;
    foreach ($voti as $v) {
        $id_g      = (int)$v["id_giocatore"];
        $voto      = (float)$v["voto"];
        $giocata   = isset($v["giocata"])   ? (int)$v["giocata"]   : ($voto > 0 ? 1 : 0);
        $reti      = (int)($v["reti"]      ?? 0);
        $ammon     = (int)($v["ammonizioni"] ?? 0);
        $espuls    = (int)($v["espulsioni"] ?? 0);
        $autogol   = (int)($v["autogol"]   ?? 0);
        $retis     = (int)($v["retis"]     ?? 0);  // reti subite (portieri)
        $rigores   = (int)($v["rigores"]   ?? 0);  // rigore segnato
        $rigorep   = (int)($v["rigorep"]   ?? 0);  // rigore parato
        $rufficio  = (int)($v["rufficio"]  ?? 0);  // voto d'ufficio
        $assist    = (int)($v["assist"]    ?? 0);

        // Calcolo totale con bonus/malus standard fanta
        $totale = $voto;
        $totale += $reti   * 3;
        $totale += $assist * 1;
        $totale -= $ammon  * 0.5;
        $totale -= $espuls * 1;
        $totale -= $autogol * 2;
        $totale += $retis  * (-1);  // gol subiti portiere
        $totale += $rigores * 3;
        $totale -= $rigorep * (-3); // rigore parato = bonus
        $totale  = round($totale, 2);

        if ($id_g <= 0) continue;

        mysqli_query($conn, "INSERT INTO VOTI
            (id_squadra, id_giocatore, stagione, voto, giornata,
             reti, ammonizioni, espulsioni, autogol, retis,
             rigores, rigorep, rufficio, giocata, totale, assist)
            VALUES ($id_squadra, $id_g, $stagione, $voto, $giornata,
                    $reti, $ammon, $espuls, $autogol, $retis,
                    $rigores, $rigorep, $rufficio, $giocata, $totale, $assist)");
        $inseriti++;
    }

    api_success(["ok" => true, "voti_inseriti" => $inseriti]);

} else {

    $giornata   = param_int("giornata");
    $id_squadra = param_int("id_squadra");

    $data = query_all("SELECT
            v.id_giocatore, g.descrizione AS giocatore, g.ruolo,
            v.voto, v.totale, v.giocata,
            v.reti, v.ammonizioni, v.espulsioni, v.autogol,
            v.retis, v.rigores, v.rigorep, v.assist
        FROM VOTI v
        JOIN GIOCATORI g ON g.id = v.id_giocatore AND g.stagione = v.stagione
        WHERE v.stagione    = $stagione
          AND v.giornata    = $giornata
          AND v.id_squadra  = $id_squadra
        ORDER BY g.ruolo, v.totale DESC");

    api_success($data);
}
