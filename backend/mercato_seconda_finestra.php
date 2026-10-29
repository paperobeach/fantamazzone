<?php
// ============================================================
// api/mercato_seconda_finestra.php  -  Mercato di riparazione, seconda finestra (ad asta, in presenza)
//
// GET  ?stagione=2026[&id_richiedente=3]
//        → stato: finestra, ordine di chiamata, squadra di turno, budget di ogni squadra
// GET  ?stagione=2026&modo=svincolati
//        → svincolati (con squadra Serie A e squadre che non possono acquistarli)
// GET  ?stagione=2026&modo=storico
//        → aste/passi registrati
//
// POST { stagione, id_richiedente, azione: "avvia" }                       (solo admin)
// POST { stagione, id_richiedente, azione: "aggiudica",
//        id_giocatore, id_squadra_aggiudicataria, prezzo }                 (squadra di turno o admin)
// POST { stagione, id_richiedente, azione: "passa" }                       (squadra di turno o admin)
// POST { stagione, id_richiedente, azione: "annulla_ultima" }              (solo admin)
// POST { stagione, id_richiedente, azione: "azzera" }                      (solo admin, prima della 1a operazione)
//
// L'API non ha sessioni: l'utente e' indicato da id_richiedente (id utente = id squadra),
// come per mercato.php. Turno e regole di budget sono verificati lato server.
// ============================================================
require_once __DIR__ . "/connect.php";
require_once __DIR__ . "/lib/MercatoSecondaFinestra.php";

mysqli_set_charset($conn, "utf8mb4");

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $in = json_decode(file_get_contents("php://input"), true) ?? $_POST;
    $stagione = (int) ($in["stagione"] ?? 0);
    $rich     = (int) ($in["id_richiedente"] ?? 0);
    $azione   = (string) ($in["azione"] ?? "");
    if (!$stagione || !$rich) api_error("Parametri obbligatori mancanti: stagione, id_richiedente", 400);

    try {
        switch ($azione) {
            case "avvia":
                api_success(m2_avvia($stagione, $rich));
            case "aggiudica":
                $g = (int) ($in["id_giocatore"] ?? 0);
                $s = (int) ($in["id_squadra_aggiudicataria"] ?? 0);
                $p = (int) ($in["prezzo"] ?? 0);
                if (!$g || !$s) api_error("Indicare giocatore e squadra aggiudicataria", 400);
                api_success(m2_aggiudica($stagione, $rich, $g, $s, $p));
            case "passa":
                api_success(m2_passa($stagione, $rich));
            case "annulla_ultima":
                api_success(m2_annulla_ultima($stagione, $rich));
            case "azzera":
                api_success(m2_azzera($stagione, $rich));
            default:
                api_error("Azione non valida", 400);
        }
    } catch (MercatoErrore $e) {
        api_error($e->getMessage(), $e->http);
    }
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "GET") api_error("Metodo non consentito. Usare GET o POST.", 405);

$stagione = param_int("stagione");
if (!$stagione) api_error("Parametro obbligatorio mancante: stagione", 400);
$modo = param_str("modo", false) ?? "stato";

switch ($modo) {
    case "svincolati":
        api_success(m2_svincolati($stagione));
    case "storico":
        api_success(m2_storico($stagione));
    default:
        $rich = param_int("id_richiedente", false);
        api_success(m2_stato($stagione, $rich ?: null));
}
