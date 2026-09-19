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
                          FROM NEW_SQUADRE s
                          LEFT JOIN NEW_ALLENATORI a ON a.id_squadra = s.id AND a.stagione = s.stagione
                          WHERE s.id = $id AND s.stagione = $stagione");
    if (!$squadra) api_error("Squadra non trovata", 404);

    $rosa = query_all("SELECT g.id, g.descrizione, g.ruolo, g.nazione, g.crediti,
                              COALESCE(ns.giocate, 0)      AS presenze,
                              COALESCE(ns.media, 0)        AS media,
                              CASE WHEN g.ruolo = 1
                                   THEN COALESCE(ns.gols, 0)
                                   ELSE COALESCE(ns.golf, 0)
                              END                          AS gol,
                              COALESCE(ns.assist, 0)       AS assist,
                              COALESCE(ns.ammonizioni, 0)  AS ammonizioni,
                              COALESCE(ns.espulsioni, 0)   AS espulsioni,
                              COALESCE(ns.autogol, 0)      AS autogol,
                              COALESCE(ns.rigorep, 0)      AS rigori_parati,
                              COALESCE(ns.rigores, 0)      AS rigori_sbagliati
                       FROM NEW_GIOCATORI g
                       LEFT JOIN NEW_STATISTICHE ns
                              ON ns.id_giocatore = g.id AND ns.stagione = g.stagione
                       WHERE g.squadra = $id AND g.stagione = $stagione
                       ORDER BY g.ruolo, g.descrizione");

    $squadra["rosa"] = $rosa;
    api_success($squadra);
} else {
    $sql = "SELECT s.id, s.nome, s.logo, s.albo,
                   a.descrizione AS allenatore, a.logo AS logo_allenatore
            FROM NEW_SQUADRE s
            LEFT JOIN NEW_ALLENATORI a ON a.id_squadra = s.id AND a.stagione = s.stagione
            WHERE s.stagione = $stagione
            ORDER BY s.nome";
    api_success(query_all($sql));
}
