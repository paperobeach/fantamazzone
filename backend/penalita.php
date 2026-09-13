<?php
// ============================================================
// api/penalita.php
// GET  ?stagione=2024
// POST { stagione, id_squadra, punti, motivo }
// DELETE ?stagione=2024&id_squadra=3&id=7
// ============================================================
require_once __DIR__ . "/connect.php";

$stagione = param_int("stagione");
$method   = $_SERVER["REQUEST_METHOD"];

if ($method === "POST") {
    $id_squadra = post_int("id_squadra");
    $punti      = post_int("punti");
    $motivo     = post_str("motivo", false) ?? "";

    mysqli_query($conn, "INSERT INTO PENALITA (stagione, id_squadra, punti, motivo)
                         VALUES ($stagione, $id_squadra, $punti, '$motivo')");
    if (mysqli_affected_rows($conn) === 0) api_error("Inserimento fallito", 500);
    api_success(["ok" => true, "id" => mysqli_insert_id($conn)]);

} elseif ($method === "DELETE") {
    $id = param_int("id");
    mysqli_query($conn, "DELETE FROM PENALITA WHERE stagione = $stagione AND id = $id");
    api_success(["ok" => true, "deleted" => mysqli_affected_rows($conn)]);

} else {
    // GET - legge con JOIN su SQUADRE per avere il nome
    $sql = "SELECT p.id, p.id_squadra, s.nome AS squadra, s.logo, p.punti, p.motivo
            FROM PENALITA p
            JOIN SQUADRE s ON s.id = p.id_squadra AND s.stagione = p.stagione
            WHERE p.stagione = $stagione
            ORDER BY s.nome";
    api_success(query_all($sql));
}
