<?php
// ============================================================
// api/login.php  —  POST
// Body: stagione, utenza, password
//
// Risposta: { id, utenza, descrizione, abilitazione, stagione }
// oppure 401 se credenziali errate
//
// NOTA SICUREZZA: le password nel DB sono attualmente in chiaro.
// Quando aggiorni le password con password_hash(), cambia il
// controllo con password_verify() come indicato nei commenti.
// ============================================================
require_once __DIR__ . "/connect.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    api_error("Metodo non consentito. Usare POST.", 405);
}

$stagione = post_int("stagione");
$utenza   = post_str("utenza");
$pass     = post_str("password");

// Recupera l'utente (non filtriamo per password in query per
// evitare timing attack; il controllo avviene in PHP)
$sql = "SELECT id, utenza, PASSWORD, descrizione, abilitazione
        FROM NEW_UTENZE
        WHERE stagione = $stagione AND utenza = '$utenza'
        LIMIT 1";

$utente = query_one($sql);

if (!$utente) {
    http_response_code(401);
    api_success(["error" => "Credenziali non valide"]);
}

// ----- Controllo password -----
// ATTUALE: password in chiaro nel DB
$ok = ($utente["PASSWORD"] === $pass);

// FUTURO (dopo migrazione con password_hash):
// $ok = password_verify($pass, $utente["PASSWORD"]);

if (!$ok) {
    http_response_code(401);
    api_success(["error" => "Credenziali non valide"]);
}

// Registra accesso
$now = date("l d F H:i");
$days_it   = ["Monday"=>"Lunedi","Tuesday"=>"Martedi","Wednesday"=>"Mercoledi",
               "Thursday"=>"Giovedi","Friday"=>"Venerdi","Saturday"=>"Sabato","Sunday"=>"Domenica"];
$months_it = ["January"=>"Gennaio","February"=>"Febbraio","March"=>"Marzo","April"=>"Aprile",
               "May"=>"Maggio","June"=>"Giugno","July"=>"Luglio","August"=>"Agosto",
               "September"=>"Settembre","October"=>"Ottobre","November"=>"Novembre","December"=>"Dicembre"];
foreach ($days_it as $en => $it)   $now = str_replace($en, $it, $now);
foreach ($months_it as $en => $it) $now = str_replace($en, $it, $now);

$desc_esc = mysqli_real_escape_string($conn, $utente["descrizione"]);
mysqli_query($conn, "INSERT INTO NEW_ACCESSI (stagione, allenatore, time)
                     VALUES ($stagione, '$desc_esc', '$now')");

// Non restituiamo mai la password
unset($utente["PASSWORD"]);
$utente["stagione"] = $stagione;

api_success($utente);
