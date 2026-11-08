<?php
// ============================================================
// api/giocatori_cerca.php
// GET ?q=lau   (ricerca rapida giocatori, in TUTTE le stagioni)
//
// Pubblica. Cerca per nome tra i giocatori di tutte le stagioni (in rosa e
// svincolati); servono almeno 2 caratteri. Massimo 10 risultati, uno per
// persona (edizione piu' recente). Il parametro "stagione" non serve piu':
// se presente viene ignorato (compatibilita' con i client precedenti).
//
// Risposta: [ { id, stagione, stagioni[], descrizione, ruolo, squadra_serie_a,
//               id_squadra, squadra, logo }, ... ]
// "id" e "stagione" identificano l'edizione piu' recente: servono ad aprire
// la scheda (api/giocatore.php?stagione=..&id=..).
// ============================================================
require_once __DIR__ . "/connect.php";
require_once __DIR__ . "/lib/SchedaGiocatore.php";

$q = param_str("q", false) ?? "";

api_success(sg_cerca($q, 10));
