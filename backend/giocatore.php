<?php
// ============================================================
// api/giocatore.php
// GET ?stagione=2026&id=123   (scheda di un singolo giocatore)
//
// Pubblica (nessun login richiesto). L'id e' valido SOLO nella stagione
// indicata; lo storico delle altre stagioni passa dal raccordo per id (ripiego: nome)
// (vedi lib/SchedaGiocatore.php).
//
// Risposta:
//   anagrafica        { id, stagione, nome, ruolo, nazione, squadra_serie_a,
//                       crediti, in_rosa, id_squadra, squadra, logo }
//   stagione_corrente { stagione, totali|null, giornate[], percorso[] }
//   storico           { stagioni[], carriera, collegamento: "raccordo"|"nome", da_verificare }
// ============================================================
require_once __DIR__ . "/connect.php";
require_once __DIR__ . "/lib/SchedaGiocatore.php";

$stagione = param_int("stagione");
$id       = param_int("id");

$scheda = sg_scheda($stagione, $id);
if (!$scheda) api_error("Giocatore non trovato", 404);

api_success($scheda);
