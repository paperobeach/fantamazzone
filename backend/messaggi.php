<?php
// ============================================================
// api/messaggi.php
//
// GET  ?stagione=2024&id=5          → messaggi ricevuti
// GET  ?stagione=2024&id=5&tipo=inviati
// POST { stagione, mittente, destinatario, messaggio }
// ============================================================
require_once __DIR__ . "/connect.php";

$stagione = param_int("stagione");

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    // --- Invio messaggio ---
    $mittente     = post_int("mittente");
    $destinatario = post_int("destinatario");
    $messaggio    = post_str("messaggio");

    if (strlen($messaggio) > 100) {
        api_error("Messaggio troppo lungo (max 100 caratteri)");
    }

    $max = query_one("SELECT COALESCE(MAX(id), 0) + 1 AS next_id FROM NEW_MESSAGGI WHERE stagione = $stagione");
    $next_id = (int)$max["next_id"];

    mysqli_query($conn, "INSERT INTO NEW_MESSAGGI (id, stagione, mittente, destinatario, messaggio)
                         VALUES ($next_id, $stagione, $mittente, $destinatario, '$messaggio')");

    if (mysqli_affected_rows($conn) === 0) {
        api_error("Invio messaggio fallito", 500);
    }
    api_success(["ok" => true, "id" => $next_id]);

} else {
    // --- Lettura messaggi ---
    $id   = param_int("id");
    $tipo = param_str("tipo", false) ?? "ricevuti";

    if ($tipo === "inviati") {
        $where_msg = "m.mittente = $id";
    } else {
        $where_msg = "m.destinatario = $id";
    }

    $sql = "SELECT
                m.id, m.mittente, m.destinatario, m.messaggio,
                a1.descrizione AS nome_mittente,
                a2.descrizione AS nome_destinatario
            FROM NEW_MESSAGGI m
            LEFT JOIN NEW_ALLENATORI a1 ON a1.id = m.mittente     AND a1.stagione = m.stagione
            LEFT JOIN NEW_ALLENATORI a2 ON a2.id = m.destinatario AND a2.stagione = m.stagione
            WHERE m.stagione = $stagione AND $where_msg
            ORDER BY m.id DESC";

    api_success(query_all($sql));
}
