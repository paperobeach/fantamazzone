<?php
// ============================================================
// api/top_flop.php
// GET ?stagione=2024&tipo=top   → top 11
// GET ?stagione=2024&tipo=flop  → flop 11
// GET ?stagione=2024            → entrambi
// GET ...&competizione=CHAMP    → Top/Flop 11 della sola Champions
//                                 (NEW_TOP11_CHAMP / NEW_FLOP11_CHAMP);
//                                 senza il parametro (o CAMP) restano
//                                 quelle del campionato
//                                 (NEW_TOP11 / NEW_FLOP11)
//
// Tutte le tabelle vengono ricostruite alla chiusura della giornata
// (admin/chiusura_giornata.php → lib/AggiornamentiGiornata.php).
// ============================================================
require_once __DIR__ . "/connect.php";

$stagione     = param_int("stagione");
$tipo         = param_str("tipo", false);
$competizione = strtoupper((string) param_str("competizione", false));

$champ    = $competizione === "CHAMP";
$tabTop   = $champ ? "NEW_TOP11_CHAMP"  : "NEW_TOP11";
$tabFlop  = $champ ? "NEW_FLOP11_CHAMP" : "NEW_FLOP11";

$result = [];

// Il frontend verifica questo campo per non mostrare per errore dati del
// campionato se il server non gestisce ancora la Champions.
if ($champ) $result["competizione"] = "CHAMP";

if ($tipo === null || $tipo === "top") {
    $result["top11"] = query_all("SELECT
            id_giocatore, giocatore, media, ruolo,
            giocate, id_squadra, squadra, logo
        FROM $tabTop
        WHERE stagione = $stagione
        ORDER BY ruolo, media DESC");
}

if ($tipo === null || $tipo === "flop") {
    $result["flop11"] = query_all("SELECT
            id_giocatore, giocatore, media, ruolo,
            giocate, id_squadra, squadra, logo
        FROM $tabFlop
        WHERE stagione = $stagione
        ORDER BY ruolo, media ASC");
}

api_success($result);
