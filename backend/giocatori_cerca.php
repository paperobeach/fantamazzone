<?php
// ============================================================
// api/giocatori_cerca.php
// GET ?stagione=2026&q=lau   (ricerca rapida giocatori)
//
// Pubblica. Cerca per nome tra i giocatori della stagione (in rosa e
// svincolati); servono almeno 2 caratteri. Massimo 10 risultati.
//
// Risposta: [ { id, descrizione, ruolo, squadra_serie_a,
//               id_squadra, squadra, logo }, ... ]
// ============================================================
require_once __DIR__ . "/connect.php";
require_once __DIR__ . "/lib/SchedaGiocatore.php";

$stagione = param_int("stagione");
$q        = param_str("q", false) ?? "";

api_success(sg_cerca($stagione, $q, 10));
