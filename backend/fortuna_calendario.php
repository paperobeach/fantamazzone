<?php
// ============================================================
// api/fortuna_calendario.php
// GET ?stagione=2025
// GET ?stagione=2025&simulazioni=5000     (200-10000, default 3000)
//
// Misura l'incidenza della fortuna legata al calendario: i punteggi
// realmente ottenuti da ogni squadra (con le formazioni realmente
// schierate, giornate chiuse) vengono rigiocati contro tutte le
// possibili avversarie e in calendari alternativi. Dettaglio del
// metodo in lib/FortunaCalendario.php.
//
// Risposta: vedi fortuna_calendario() (squadre ordinate per posizione
// reale; per ciascuna punti reali/attesi, fortuna = reali - attesi,
// z-score, percentile, distribuzione delle posizioni, dettaglio per
// giornata).
//
// Sola lettura: nessuna scrittura su DB.
// ============================================================
require_once __DIR__ . "/connect.php";
require_once __DIR__ . "/lib/FortunaCalendario.php";

$stagione    = param_int("stagione");
$simulazioni = param_int("simulazioni", false) ?? 3000;

try {
    api_success(fortuna_calendario($conn, $stagione, $simulazioni));
} catch (Throwable $e) {
    api_error($e->getMessage(), 400);
}
