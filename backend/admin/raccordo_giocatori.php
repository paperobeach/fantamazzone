<?php
// ============================================================
// api/admin/raccordo_giocatori.php
//
// Raccordo per id dei giocatori tra le stagioni (vedi
// lib/RaccordoGiocatori.php). Usato dalla pagina "Raccordo giocatori"
// (menu Admin → Avvio stagione).
//
// Solo POST. Body: { stagione, utenza, password, azione, ... }
// ACCESSO: utenza amministratore (password verificata lato server).
//
// azione = "stato"        contatori per stagione + numero di persone
// azione = "ricostruisci" { azzera_auto?: true, riesamina_manuali?: true }
//                         rifa' i collegamenti AUTO di tutte le stagioni (i MANUALE
//                         restano invariati); con riesamina_manuali segna da verificare
//                         solo i MANUALE per cui l'automatico propone una persona diversa
// azione = "allinea"      { stagione_da_allineare }  collega i giocatori nuovi
// azione = "verifica"     { limite? }  collegamenti da verificare + candidati
// azione = "cerca"        { q }  persone per nome
// azione = "persona"      { id_anagrafica }  persona con le sue edizioni
// azione = "conferma"     { stagione_giocatore, id_giocatore }
// azione = "collega"      { stagione_giocatore, id_giocatore, id_anagrafica }
//                         collega UN solo giocatore (una stagione) a una persona
// azione = "unisci"       { id_tieni, id_assorbi }  unisce TUTTE le stagioni di due persone
// azione = "unisci_molti" { id_tieni, id_assorbi[] }  unisce TUTTE le stagioni di piu' persone in una
// azione = "separa"       { stagione_giocatore, id_giocatore }
// ============================================================
require_once __DIR__ . "/../connect.php";
require_once __DIR__ . "/../lib/BackupStagione.php";
require_once __DIR__ . "/../lib/RaccordoGiocatori.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    api_error("Metodo non consentito. Usare POST.", 405);
}

$input    = json_decode(file_get_contents("php://input"), true) ?? $_POST;
$stagione = (int) ($input["stagione"] ?? 0);
$utenza   = trim((string) ($input["utenza"] ?? ""));
$password = (string) ($input["password"] ?? "");
$azione   = strtolower(trim((string) ($input["azione"] ?? "stato")));

if ($stagione < 2000 || $stagione > 2100) {
    api_error("Parametro 'stagione' obbligatorio e deve essere un anno a 4 cifre plausibile (2000-2100)", 400);
}
if (!bks_verifica_admin($conn, $stagione, $utenza, $password)) {
    api_error("Credenziali non valide oppure utenza non amministratore", 403);
}
if (!rg_tabelle_esistono()) {
    api_error("Tabelle mancanti: NEW_GIOCATORI_ANAGRAFICA, NEW_GIOCATORI_RACCORDO. Eseguire Script DB/migrazione_raccordo_giocatori.sql", 500);
}

try {
    switch ($azione) {
        case "stato":
            api_success(rg_stato());
            break;

        case "ricostruisci":
            $esitoManuali = null;
            $risultato = rg_ricostruisci(
                ($input["azzera_auto"] ?? true) !== false,
                ($input["riesamina_manuali"] ?? true) !== false,
                $esitoManuali
            );
            api_success(["ok" => true, "stagioni" => $risultato, "manuali" => $esitoManuali, "stato" => rg_stato()]);
            break;

        case "allinea":
            $s = (int) ($input["stagione_da_allineare"] ?? 0);
            if ($s < 2000 || $s > 2100) api_error("Parametro 'stagione_da_allineare' non valido", 400);
            api_success(["ok" => true, "esito" => rg_allinea($s), "stato" => rg_stato()]);
            break;

        case "verifica":
            api_success(["righe" => rg_elenco_da_verificare((int) ($input["limite"] ?? 100)), "stato" => rg_stato()]);
            break;

        case "cerca":
            api_success(rg_cerca_persone((string) ($input["q"] ?? "")));
            break;

        case "persona":
            $p = rg_persona_dettaglio((int) ($input["id_anagrafica"] ?? 0));
            if (!$p) api_error("Persona non trovata", 404);
            api_success($p);
            break;

        case "conferma":
            rg_conferma((int) ($input["stagione_giocatore"] ?? 0), (int) ($input["id_giocatore"] ?? 0));
            api_success(["ok" => true, "stato" => rg_stato()]);
            break;

        case "collega":
            rg_collega((int) ($input["stagione_giocatore"] ?? 0), (int) ($input["id_giocatore"] ?? 0),
                       (int) ($input["id_anagrafica"] ?? 0));
            api_success(["ok" => true, "stato" => rg_stato()]);
            break;

        case "unisci":
            rg_unisci((int) ($input["id_tieni"] ?? 0), (int) ($input["id_assorbi"] ?? 0));
            api_success(["ok" => true, "stato" => rg_stato()]);
            break;

        case "unisci_molti":
            $n = rg_unisci_molti((int) ($input["id_tieni"] ?? 0), (array) ($input["id_assorbi"] ?? []));
            api_success(["ok" => true, "unite" => $n, "stato" => rg_stato()]);
            break;

        case "separa":
            $nuova = rg_separa((int) ($input["stagione_giocatore"] ?? 0), (int) ($input["id_giocatore"] ?? 0));
            api_success(["ok" => true, "id_anagrafica" => $nuova, "stato" => rg_stato()]);
            break;

        default:
            api_error("Azione non riconosciuta: $azione", 400);
    }
} catch (InvalidArgumentException $e) {
    api_error($e->getMessage(), 400);
} catch (Throwable $e) {
    api_error("Errore: " . $e->getMessage(), 500);
}
