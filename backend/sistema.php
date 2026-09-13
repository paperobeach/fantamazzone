<?php
// ============================================================
// api/sistema.php
// GET  ?stagione=2024          → parametri configurazione
// GET  ?tipo=stagioni          → lista stagioni disponibili
// POST { stagione, label, valore }  → aggiorna parametro
// ============================================================
require_once __DIR__ . "/connect.php";

$tipo     = param_str("tipo", false);
$stagione = param_int("stagione", false);

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $stagione = post_int("stagione");
    $label    = post_str("label");
    $valore   = post_str("valore");

    // Upsert
    $exists = query_one("SELECT COUNT(*) AS n FROM SISTEMA
                         WHERE stagione = $stagione AND label = '$label'");
    if ((int)$exists["n"] > 0) {
        mysqli_query($conn, "UPDATE SISTEMA SET valore = '$valore'
                             WHERE stagione = $stagione AND label = '$label'");
    } else {
        mysqli_query($conn, "INSERT INTO SISTEMA (stagione, label, valore)
                             VALUES ($stagione, '$label', '$valore')");
    }
    api_success(["ok" => true]);
}

if ($tipo === "stagioni") {
    // Tutte le stagioni disponibili (da SQUADRE, che è sempre popolata)
    $rows = query_all("SELECT DISTINCT stagione FROM SQUADRE ORDER BY stagione DESC");
    $stagioni = array_column($rows, "stagione");
    api_success($stagioni);
}

if ($stagione === null) api_error("Parametro 'stagione' obbligatorio");

$params = query_all("SELECT label, valore FROM SISTEMA WHERE stagione = $stagione");
// Trasforma in oggetto chiave/valore per comodità del frontend
$result = [];
foreach ($params as $p) {
    $result[$p["label"]] = $p["valore"];
}
api_success($result);
