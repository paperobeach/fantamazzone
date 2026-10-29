<?php
// ============================================================
// api/mercato.php  -  Mercato di riparazione (prima finestra)
//
// GET  ?stagione=2026[&id_richiedente=3]
//        → stato: configurazione, sessioni, sessione corrente, ordine di
//          chiamata, squadra di turno, scadenza, classifica salvata.
//          (la GET esegue anche mr_tick: apre/chiude sessioni, solleciti)
// GET  ?stagione=2026&modo=svincolati[&ruolo=2][&q=rossi]
//        → svincolati con flag "acquistabile" (false se ceduti nella finestra)
// GET  ?stagione=2026&modo=analisi_svincolati[&id_richiedente=3]
//        → pagina "Mercato > Svincolati": tutti gli svincolati con statistiche
//          (fantamedia, forma, presenze, gol, assist...), indice di valore,
//          prezzo e, se indicato id_richiedente, budget residuo e sostenibilita'.
// GET  ?stagione=2026&modo=rosa&id_squadra=3
//        → rosa della squadra (id, descrizione, ruolo, nazione, crediti)
// GET  ?stagione=2026&modo=storico[&sessione=2]
//        → transazioni salvate
//
// POST { stagione, azione: "acquista", id_squadra,
//        id_giocatore_acquistato, id_giocatore_ceduto }
// POST { stagione, azione: "passa", id_squadra }
// POST { stagione, azione: "salta", id_squadra, id_richiedente }
//        (solo amministratore: salta la squadra di turno)
//
// L'API non ha sessioni: la squadra che opera e' indicata da id_squadra
// (id utente = id squadra), come per formazioni.php. Il turno e' verificato
// lato server: agisce solo la squadra attualmente chiamata.
// ============================================================
require_once __DIR__ . "/connect.php";
require_once __DIR__ . "/lib/MercatoRiparazione.php";

mysqli_set_charset($conn, "utf8mb4");

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $in = json_decode(file_get_contents("php://input"), true) ?? $_POST;
    $stagione = (int) ($in["stagione"] ?? 0);
    $azione   = (string) ($in["azione"] ?? "");
    $idSq     = (int) ($in["id_squadra"] ?? 0);
    if (!$stagione || !$idSq) api_error("Parametri obbligatori mancanti: stagione, id_squadra", 400);

    try {
        if ($azione === "acquista") {
            $acq = (int) ($in["id_giocatore_acquistato"] ?? 0);
            $ced = (int) ($in["id_giocatore_ceduto"] ?? 0);
            if (!$acq || !$ced) api_error("Indicare il giocatore da acquistare e quello da cedere", 400);
            api_success(mr_acquista($stagione, MR_FINESTRA_1, $idSq, $acq, $ced));
        } elseif ($azione === "passa") {
            api_success(mr_passa($stagione, MR_FINESTRA_1, $idSq));
        } elseif ($azione === "salta") {
            $rich = (int) ($in["id_richiedente"] ?? 0);
            $adm = $rich ? query_one("SELECT amministratore FROM NEW_UTENZE WHERE stagione = $stagione AND id = $rich LIMIT 1") : null;
            if (!$adm || strtoupper(trim((string) $adm["amministratore"])) !== "Y") api_error("Operazione riservata all'amministratore", 403);
            api_success(mr_passa($stagione, MR_FINESTRA_1, $idSq, true));
        } else {
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
$finestra = MR_FINESTRA_1;

switch ($modo) {
    case "svincolati":
        $ruolo = param_int("ruolo", false);
        api_success(mr_svincolati($stagione, $finestra, $ruolo ?: null, trim((string) (param_str("q", false) ?? ""))));
    case "analisi_svincolati":
        $rich = param_int("id_richiedente", false);
        api_success(mr_analisi_svincolati($stagione, $finestra, $rich ?: null));
    case "rosa":
        $id = param_int("id_squadra");
        api_success(query_all("SELECT id, descrizione, ruolo, nazione, crediti FROM NEW_GIOCATORI
                               WHERE stagione = $stagione AND squadra = $id ORDER BY ruolo, descrizione"));
    case "storico":
        $s = param_int("sessione", false);
        api_success(mr_storico($stagione, $finestra, $s ?: null));
    default:
        try { mr_tick($stagione, $finestra); } catch (Throwable $e) { error_log("mercato tick: " . $e->getMessage()); }
        api_success(mr_stato($stagione, $finestra, param_int("id_richiedente", false)));
}
