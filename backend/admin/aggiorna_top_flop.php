<?php
// ============================================================
// api/admin/aggiorna_top_flop.php
// POST { stagione }
//
// Ricalcola TOP11 e FLOP11 dalla tabella STATISTICHE.
// Equivale ai vecchi web_aggiorna_flop11.php
// ============================================================
require_once __DIR__ . "/../connect.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") api_error("Usare POST", 405);

$stagione = post_int("stagione");

// Soglia minima giocate (esclude chi ha giocato pochissimo)
$min_giocate = 5;

// ---- TOP11 ----
// Il migliore per ogni ruolo (1=P, 2=D, 3=C, 4=A)
// Per P e A prendo 1, per D e C prendo più elementi (storico 2+3)
$ruoli = [
    1 => 1,  // Portieri: top 1
    2 => 3,  // Difensori: top 3
    3 => 3,  // Centrocampisti: top 3
    4 => 3,  // Attaccanti: top 3 (a volte 4, adattare)
];

mysqli_query($conn, "DELETE FROM TOP11 WHERE stagione = $stagione");
mysqli_query($conn, "DELETE FROM FLOP11 WHERE stagione = $stagione");

foreach ($ruoli as $ruolo => $quanti) {
    // TOP
    $top = query_all("SELECT id_squadra, squadra, logo, id_giocatore, giocatore, media, ruolo, giocate
                      FROM STATISTICHE
                      WHERE stagione = $stagione
                        AND ruolo = $ruolo
                        AND giocate >= $min_giocate
                      ORDER BY media DESC
                      LIMIT $quanti");
    foreach ($top as $r) {
        $id_g  = (int)$r["id_giocatore"];
        $id_sq = (int)$r["id_squadra"];
        $media = (float)$r["media"];
        $gg    = (int)$r["giocate"];
        $nome  = mysqli_real_escape_string($conn, $r["giocatore"]);
        $sqn   = mysqli_real_escape_string($conn, $r["squadra"]);
        $logo  = mysqli_real_escape_string($conn, $r["logo"]);
        mysqli_query($conn, "INSERT INTO TOP11
            (stagione, id_giocatore, giocatore, media, ruolo, giocate, id_squadra, squadra, logo)
            VALUES ($stagione, $id_g, '$nome', $media, $ruolo, $gg, $id_sq, '$sqn', '$logo')");
    }

    // FLOP
    $flop = query_all("SELECT id_squadra, squadra, logo, id_giocatore, giocatore, media, ruolo, giocate
                       FROM STATISTICHE
                       WHERE stagione = $stagione
                         AND ruolo = $ruolo
                         AND giocate >= $min_giocate
                       ORDER BY media ASC
                       LIMIT $quanti");
    foreach ($flop as $r) {
        $id_g  = (int)$r["id_giocatore"];
        $id_sq = (int)$r["id_squadra"];
        $media = (float)$r["media"];
        $gg    = (int)$r["giocate"];
        $nome  = mysqli_real_escape_string($conn, $r["giocatore"]);
        $sqn   = mysqli_real_escape_string($conn, $r["squadra"]);
        $logo  = mysqli_real_escape_string($conn, $r["logo"]);
        mysqli_query($conn, "INSERT INTO FLOP11
            (stagione, id_giocatore, giocatore, media, ruolo, giocate, id_squadra, squadra, logo)
            VALUES ($stagione, $id_g, '$nome', $media, $ruolo, $gg, $id_sq, '$sqn', '$logo')");
    }
}

api_success(["ok" => true]);
