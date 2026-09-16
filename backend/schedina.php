<?php
// ============================================================
// api/schedina.php
//
// GET  ?stagione=2024&giornata=5         → tutte le schedine
// GET  ?stagione=2024&giornata=5&id=3   → schedina singola
// GET  ?stagione=2024&tipo=classifica   → classifica schedina
// POST { stagione, giornata, id, pronostici: [{squadra_1, squadra_2, pronostico}] }
// ============================================================
require_once __DIR__ . "/connect.php";

$stagione = param_int("stagione");
$tipo     = param_str("tipo", false);

if ($tipo === "classifica") {
    $data = query_all("SELECT
            sc.ID, sc.PUNTI,
            a.descrizione AS allenatore, a.logo
        FROM NEW_SCHEDINA_CLASSIFICA sc
        LEFT JOIN NEW_ALLENATORI a ON a.id = sc.ID AND a.stagione = sc.STAGIONE
        WHERE sc.STAGIONE = $stagione
        ORDER BY sc.PUNTI DESC");
    api_success($data);
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $input = json_decode(file_get_contents("php://input"), true) ?? $_POST;
    $giornata    = (int)($input["giornata"] ?? 0);
    $id          = (int)($input["id"] ?? 0);
    $pronostici  = $input["pronostici"] ?? [];

    if (!$giornata || !$id || !is_array($pronostici)) {
        api_error("Parametri mancanti: giornata, id, pronostici[]");
    }

    // Cancella e reinserisce
    mysqli_query($conn, "DELETE FROM NEW_SCHEDINA
                         WHERE STAGIONE = $stagione AND GIORNATA = $giornata AND ID = $id");

    $validi = ["1", "X", "2"];
    foreach ($pronostici as $p) {
        $s1 = (int)$p["squadra_1"];
        $s2 = (int)$p["squadra_2"];
        $pr = strtoupper(trim($p["pronostico"] ?? ""));
        if (!in_array($pr, $validi)) continue;
        mysqli_query($conn, "INSERT INTO NEW_SCHEDINA (STAGIONE, GIORNATA, ID, SQUADRA_1, SQUADRA_2, PRONOSTICO)
                             VALUES ($stagione, $giornata, $id, $s1, $s2, '$pr')");
    }

    api_success(["ok" => true]);

} else {

    $giornata = param_int("giornata");
    $id       = param_int("id", false);

    $where_id = $id !== null ? "AND s.ID = $id" : "";

    $data = query_all("SELECT
            s.ID, s.SQUADRA_1, s.SQUADRA_2, s.PRONOSTICO,
            sq1.nome AS nome_squadra_1, sq1.logo AS logo_squadra_1,
            sq2.nome AS nome_squadra_2, sq2.logo AS logo_squadra_2,
            a.descrizione AS allenatore
        FROM NEW_SCHEDINA s
        JOIN NEW_SQUADRE sq1 ON sq1.id = s.SQUADRA_1 AND sq1.stagione = s.STAGIONE
        JOIN NEW_SQUADRE sq2 ON sq2.id = s.SQUADRA_2 AND sq2.stagione = s.STAGIONE
        LEFT JOIN NEW_ALLENATORI a ON a.id = s.ID AND a.stagione = s.STAGIONE
        WHERE s.STAGIONE = $stagione AND s.GIORNATA = $giornata $where_id
        ORDER BY s.ID, s.SQUADRA_1");

    api_success($data);
}
