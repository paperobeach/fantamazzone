<?php
// ============================================================
// api/giornata_corrente.php
// GET ?stagione=2024
//
// Restituisce la giornata di riferimento per l'inserimento delle
// formazioni: la prima giornata successiva all'ultima giornata già
// chiusa della stagione (NEW_CALENDARIO_CK.ck_giocata = 'S').
//
// - Se nessuna giornata è ancora stata chiusa -> giornata 1.
// - Se tutte le giornate del calendario risultano già chiuse -> resta
//   sull'ultima giornata del calendario (non esiste una giornata
//   successiva su cui inserire la formazione).
// ============================================================
require_once __DIR__ . "/connect.php";

$stagione = param_int("stagione");

$row = query_one("SELECT MAX(giornata) AS ultima_chiusa
                   FROM NEW_CALENDARIO_CK
                   WHERE stagione = $stagione AND ck_giocata = 'S'");

$ultima_chiusa = (int)($row["ultima_chiusa"] ?? 0);
$giornata      = $ultima_chiusa + 1;

// La giornata di riferimento non può superare l'ultima prevista dal
// calendario della stagione.
$max_row = query_one("SELECT MAX(giornata) AS ultima
                       FROM NEW_CALENDARIO
                       WHERE stagione = $stagione");

$ultima_calendario = (int)($max_row["ultima"] ?? 0);

if ($ultima_calendario > 0 && $giornata > $ultima_calendario) {
    $giornata = $ultima_calendario;
}

api_success([
    "giornata"          => $giornata,
    "ultima_chiusa"      => $ultima_chiusa,
    "ultima_calendario"  => $ultima_calendario,
]);
