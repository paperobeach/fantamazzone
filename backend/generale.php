<?php
// ============================================================
// api/generale.php
// GET ?stagione=2024
//
// Risposta: array di squadre con classifica generale
// ============================================================
// Commento per testare il deploy
// ULTERIORE COMMENTO 
require_once __DIR__ . "/connect.php";

$stagione = param_int("stagione");

$sql = "SELECT
            id_squadra,
            squadra,
            logo,
            punti,
            partiteg,
            vinte,
            nulle,
            perse,
            golf,
            gols,
            maxp,
            minp,
            media,
            media_a,
            segno,
            media_mod_dif,
            media_mod_cc,
            media_mod_att
        FROM NEW_GENERALE
        WHERE stagione = $stagione
        ORDER BY punti DESC, golf DESC, gols DESC";

api_success(query_all($sql));
