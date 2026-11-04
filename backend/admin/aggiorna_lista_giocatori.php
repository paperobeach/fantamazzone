<?php
// ============================================================
// api/admin/aggiorna_lista_giocatori.php
//
// Aggiornamento del listone della stagione in corso (menu Admin ->
// Avvio stagione -> "Aggiorna lista giocatori"). Logica in
// lib/ListaGiocatori.php.
//
// Solo POST multipart/form-data:
//   stagione, utenza, password, azione, file (.xlsx, foglio "Tutti")
// ACCESSO: utenza amministratore (password verificata lato server).
// La stagione deve essere l'ultima presente (la stagione in corso).
//
// azione = "anteprima"  (default)  legge il file e mostra cosa cambierebbe:
//                        nuovi, rientrati, fuori lista. Non scrive nulla.
// azione = "applica"    rilegge il file, rifa' il confronto con il DB di quel
//                        momento e applica:
//                          - nuovi            -> svincolati (e listone)
//                          - fuori lista      -> restano nella lista giocatori ma
//                                               non acquistabili / "giocatore ceduto"
//                                               se in rosa (non utilizzabili)
//                          - rientrati        -> di nuovo in lista
//
// Errori di validazione del file: 422 VALIDATION_ERROR con details
// [{riga, campo, messaggio}] (stesso formato di "Inserimento rose").
//
// Richiede Script DB/migrazione_aggiorna_lista_giocatori.sql.
// ============================================================
require_once __DIR__ . "/../connect.php";
require_once __DIR__ . "/../lib/BackupStagione.php";
require_once __DIR__ . "/../lib/ListaGiocatori.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    api_error("Metodo non consentito. Usare POST.", 405);
}
mysqli_set_charset($conn, "utf8mb4");

$stagione = (int) ($_POST["stagione"] ?? 0);
$utenza   = trim((string) ($_POST["utenza"] ?? ""));
$password = (string) ($_POST["password"] ?? "");
$azione   = strtolower(trim((string) ($_POST["azione"] ?? "anteprima")));

if ($stagione < 2000 || $stagione > 2100) {
    api_error("Parametro 'stagione' obbligatorio e deve essere un anno a 4 cifre plausibile (2000-2100)", 400);
}
if (!bks_verifica_admin($conn, $stagione, $utenza, $password)) {
    api_error("Credenziali non valide oppure utenza non amministratore", 403);
}
if ($stagione !== sg_ultima_stagione()) {
    api_error("L'aggiornamento della lista giocatori e' disponibile solo per la stagione in corso ("
              . sg_ultima_stagione() . ")", 403);
}
if (!lg_colonna_pronta()) {
    api_error("Colonna mancante: NEW_GIOCATORI_BASE_ASTA.in_lista. Eseguire Script DB/migrazione_aggiorna_lista_giocatori.sql", 500);
}
if (!in_array($azione, ["anteprima", "applica"], true)) {
    api_error("Azione non riconosciuta: $azione", 400);
}

if (empty($_FILES["file"]) || $_FILES["file"]["error"] !== UPLOAD_ERR_OK) {
    api_error("File Excel mancante o non caricato correttamente", 400);
}
if (!preg_match('/\.xlsx$/i', (string) $_FILES["file"]["name"])) {
    api_error("Il file deve essere in formato .xlsx", 400);
}

try {
    $letto = lg_leggi_listone($_FILES["file"]["tmp_name"], $stagione);
} catch (Throwable $e) {
    api_error("Impossibile leggere il file: " . $e->getMessage(), 400);
}

if (!empty($letto["errori"])) {
    http_response_code(422);
    echo json_encode([
        "error" => [
            "code"    => "VALIDATION_ERROR",
            "message" => "Il file contiene " . count($letto["errori"]) . " errore/i",
            "status"  => 422,
            "details" => $letto["errori"],
        ],
    ]);
    exit;
}

try {
    $diff = lg_confronta($stagione, $letto["giocatori"]);

    if ($azione === "anteprima") {
        api_success(["ok" => true, "applicato" => false, "titolo_file" => $letto["titolo"], "stagione" => $stagione] + $diff);
    }

    $esito = lg_applica($stagione, $letto["giocatori"], $diff);
    api_success(["ok" => true, "applicato" => true, "titolo_file" => $letto["titolo"], "stagione" => $stagione,
                 "esito" => $esito] + $diff);
} catch (Throwable $e) {
    api_error("Errore durante l'aggiornamento: " . $e->getMessage(), 500);
}
