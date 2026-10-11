<?php
// ============================================================
// api/articoli.php   (PUBBLICO, sola lettura)
//
// "La Gazzetta del Fantamazzone": articoli AI di commento alla giornata
// già PUBBLICATI (NEW_ARTICOLI.stato = 'pubblicato'). Le bozze, il
// modello usato e i costi non sono mai esposti qui.
//
// GET ?stagione=2026            → elenco (più recente per primo)
//     [{ id, giornata, titolo, sommario, data_pubblicazione }]
// GET ?id=12                    → singolo articolo pubblicato
//     { id, stagione, giornata, titolo, sommario, testo, data_pubblicazione }
//
// Se la tabella NEW_ARTICOLI non esiste ancora (Script DB/NEW_AI_ARTICOLI.sql
// non eseguito) l'elenco è semplicemente vuoto.
// ============================================================
require_once __DIR__ . "/connect.php";

mysqli_set_charset($conn, "utf8mb4");

$esiste = mysqli_query($conn, "SHOW TABLES LIKE 'NEW_ARTICOLI'");
$tabella = $esiste && mysqli_num_rows($esiste) > 0;

$id = param_int("id", false);

if ($id !== null) {
    if (!$tabella) api_error("Articolo non trovato", 404);
    $r = query_one("SELECT id, stagione, giornata, titolo, sommario, testo, data_pubblicazione
                    FROM NEW_ARTICOLI
                    WHERE id = $id AND stato = 'pubblicato'");
    if (!$r) api_error("Articolo non trovato", 404);
    $r["id"]       = (int) $r["id"];
    $r["stagione"] = (int) $r["stagione"];
    $r["giornata"] = (int) $r["giornata"];
    api_success($r);
}

$stagione = param_int("stagione");
if (!$tabella) api_success([]);

$righe = query_all("SELECT id, giornata, titolo, sommario, data_pubblicazione
                    FROM NEW_ARTICOLI
                    WHERE stagione = $stagione AND stato = 'pubblicato'
                    ORDER BY giornata DESC, data_pubblicazione DESC, id DESC");
foreach ($righe as &$r) {
    $r["id"]       = (int) $r["id"];
    $r["giornata"] = (int) $r["giornata"];
}
unset($r);
api_success($righe);
