<?php
// ============================================================
// api/squadre.php
// GET ?stagione=2024
// GET ?stagione=2024&id=3   (singola squadra con rosa)
// ============================================================
require_once __DIR__ . "/connect.php";

$stagione = param_int("stagione");
$id       = param_int("id", false);

if ($id !== null) {
    $squadra = query_one("SELECT s.id, s.nome, s.logo, s.albo,
                                 a.descrizione AS allenatore, a.logo AS logo_allenatore
                          FROM SQUADRE s
                          LEFT JOIN ALLENATORI a ON a.id_squadra = s.id AND a.stagione = s.stagione
                          WHERE s.id = $id AND s.stagione = $stagione");
    if (!$squadra) api_error("Squadra non trovata", 404);

    $rosa = query_all("SELECT g.id, g.descrizione, g.ruolo, g.nazione, g.crediti
                       FROM GIOCATORI g
                       WHERE g.squadra = $id AND g.stagione = $stagione
                       ORDER BY g.ruolo, g.descrizione");

    $squadra["rosa"] = $rosa;
    api_success($squadra);
} else {
    $sql = "SELECT s.id, s.nome, s.logo, s.albo,
                   a.descrizione AS allenatore, a.logo AS logo_allenatore
            FROM SQUADRE s
            LEFT JOIN ALLENATORI a ON a.id_squadra = s.id AND a.stagione = s.stagione
            WHERE s.stagione = $stagione
            ORDER BY s.nome";
    api_success(query_all($sql));
}
