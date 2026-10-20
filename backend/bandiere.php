<?php
// ============================================================
// api/bandiere.php
// GET ?stagione=2026
//
// Restituisce la mappa  id_giocatore => nome file della bandiera
// (file in frontend/public/flags/), per i giocatori in rosa nella
// stagione. Il frontend la carica una volta sola e la usa per
// mostrare la bandiera accanto al nome del giocatore in ogni vista,
// senza dover modificare le query di ciascun endpoint.
//
// La nazionalità arriva da NEW_GIOCATORI_BASE_ASTA.nazione (staging
// dell'asta); in mancanza, da NEW_GIOCATORI.nazione. La nazione viene
// poi tradotta nel file bandiera tramite NEW_NAZIONI.
// Un giocatore la cui nazione non è in NEW_NAZIONI resta senza bandiera.
//
// Risposta: { "123": "Italian.jpg", "456": "Brazilian.jpg", ... }
// ============================================================
require_once __DIR__ . "/connect.php";

$stagione = param_int("stagione");

$righe = query_all("SELECT g.id, n.bandiera
                    FROM NEW_GIOCATORI g
                    LEFT JOIN NEW_GIOCATORI_BASE_ASTA b
                           ON b.id_giocatore = g.id AND b.stagione = g.stagione
                    JOIN NEW_NAZIONI n
                           ON n.nazione = COALESCE(NULLIF(b.nazione, ''), g.nazione)
                    WHERE g.stagione = $stagione
                      AND n.bandiera <> ''");

$mappa = [];
foreach ($righe as $r) {
    $mappa[(int) $r["id"]] = $r["bandiera"];
}

// (object): con la mappa vuota si ottiene {} e non [] (il frontend legge
// comunque entrambe le forme)
api_success((object) $mappa);
