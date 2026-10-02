<?php
// ============================================================
// api/marcatori.php
// GET ?stagione=2024&tipo=marcatori|migliori|peggiori|assist
// GET ?stagione=2024&tipo=marcatori&limit=20
// ============================================================
require_once __DIR__ . "/connect.php";
require_once __DIR__ . "/lib/AggiornamentiGiornata.php";

$stagione = param_int("stagione");
$tipo     = param_str("tipo", false) ?? "marcatori";
$limit    = param_int("limit", false) ?? 20;
if ($limit < 1 || $limit > 200) $limit = 20;

$campi_base = "id_squadra, squadra, logo, id_giocatore, giocatore, ruolo, giocate, media";

// Giocate minime per "migliori"/"peggiori" (parametro di stagione, default 5)
$min_giocate = (int) agg_parametro_stagione($conn, $stagione, "MARCATORI_MIN_GIOCATE", MARCATORI_MIN_GIOCATE_DEFAULT);
if ($min_giocate < 0) $min_giocate = 0;

switch ($tipo) {
    case "marcatori":
        $sql = "SELECT $campi_base, golf, autogol
                FROM NEW_STATISTICHE
                WHERE stagione = $stagione AND golf > 0
                ORDER BY golf DESC, media DESC
                LIMIT $limit";
        break;

    case "assist":
        $sql = "SELECT $campi_base, assist
                FROM NEW_STATISTICHE
                WHERE stagione = $stagione AND assist > 0
                ORDER BY assist DESC, media DESC
                LIMIT $limit";
        break;

    case "migliori":
        $sql = "SELECT $campi_base
                FROM NEW_STATISTICHE
                WHERE stagione = $stagione AND giocate >= $min_giocate
                ORDER BY media DESC
                LIMIT $limit";
        break;

    case "peggiori":
        $sql = "SELECT $campi_base
                FROM NEW_STATISTICHE
                WHERE stagione = $stagione AND giocate >= $min_giocate
                ORDER BY media ASC
                LIMIT $limit";
        break;

    default:
        api_error("Tipo non valido. Valori accettati: marcatori, assist, migliori, peggiori");
}

api_success(query_all($sql));
