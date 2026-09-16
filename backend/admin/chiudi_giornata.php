<?php
// ============================================================
// api/admin/chiudi_giornata.php
// POST { stagione, giornata }
//
// Marca la giornata come giocata in CALENDARIO_CK.
// Da chiamare DOPO aver inserito tutti i voti e risultati.
// Poi il frontend chiamerà in sequenza:
//   1. aggiorna_statistiche
//   2. aggiorna_generale
//   3. aggiorna_top_flop
// ============================================================
require_once __DIR__ . "/../connect.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") api_error("Usare POST", 405);

$stagione = post_int("stagione");
$giornata = post_int("giornata");

// Verifica che non sia già chiusa
$ck = query_one("SELECT ck_giocata FROM NEW_CALENDARIO_CK
                 WHERE stagione = $stagione AND giornata = $giornata");

if ($ck && $ck["ck_giocata"] === "S") {
    api_error("Giornata $giornata già chiusa", 409);
}

if ($ck) {
    mysqli_query($conn, "UPDATE NEW_CALENDARIO_CK SET ck_giocata = 'S'
                         WHERE stagione = $stagione AND giornata = $giornata");
} else {
    mysqli_query($conn, "INSERT INTO NEW_CALENDARIO_CK (stagione, giornata, ck_giocata)
                         VALUES ($stagione, $giornata, 'S')");
}

if (mysqli_affected_rows($conn) === 0) {
    api_error("Chiusura giornata fallita", 500);
}

api_success(["ok" => true, "giornata_chiusa" => $giornata]);
