<?php
// ============================================================
// api/top_flop.php
// GET ?stagione=2024&tipo=top   → top 11
// GET ?stagione=2024&tipo=flop  → flop 11
// GET ?stagione=2024            → entrambi
// ============================================================
require_once __DIR__ . "/connect.php";

$stagione = param_int("stagione");
$tipo     = param_str("tipo", false);

$result = [];

if ($tipo === null || $tipo === "top") {
    $result["top11"] = query_all("SELECT
            id_giocatore, giocatore, media, ruolo,
            giocate, id_squadra, squadra, logo
        FROM NEW_TOP11
        WHERE stagione = $stagione
        ORDER BY ruolo, media DESC");
}

if ($tipo === null || $tipo === "flop") {
    $result["flop11"] = query_all("SELECT
            id_giocatore, giocatore, media, ruolo,
            giocate, id_squadra, squadra, logo
        FROM NEW_FLOP11
        WHERE stagione = $stagione
        ORDER BY ruolo, media ASC");
}

api_success($result);
