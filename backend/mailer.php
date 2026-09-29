<?php
// ============================================================
// api/mailer.php
//
// Piccolo helper per l'invio di email tramite la funzione nativa
// mail() di PHP: nessuna libreria esterna richiesta, per restare
// compatibile con l'hosting Altervista usato per il deploy.
//
// Uso:
//   require_once __DIR__ . "/mailer.php";
//   $esito = invia_email("destinatario@example.com", "Oggetto", "Corpo");
//   // $esito = ["ok" => true|false, "motivo" => null|stringa]
//
// invia_email() non solleva mai un'eccezione: un eventuale fallimento
// (indirizzo mancante/non valido, o invio rifiutato dal server) non
// deve mai impedire il salvataggio dei dati che lo ha generato. Ma il
// motivo del fallimento viene sempre restituito esplicitamente, invece
// di un generico true/false: altrimenti "indirizzo non configurato" e
// "il server non riesce a spedire la mail" risultano indistinguibili,
// rendendo impossibile capire dove intervenire.
// ============================================================

define("MAIL_MITTENTE", "no-reply@fantamazzone.altervista.org");
define("MAIL_NOME_MITTENTE", "FantaMazzone");

/**
 * Invia una email di solo testo.
 * Ritorna ["ok" => bool, "motivo" => null|"email_non_configurata"|"email_non_valida"|"invio_fallito"].
 */
function invia_email($destinatario, $oggetto, $corpo) {
    $destinatario = trim((string)$destinatario);

    if ($destinatario === "") {
        return ["ok" => false, "motivo" => "email_non_configurata"];
    }
    if (!filter_var($destinatario, FILTER_VALIDATE_EMAIL)) {
        return ["ok" => false, "motivo" => "email_non_valida"];
    }

    // Oggetto codificato in MIME per supportare correttamente accenti
    // ed eventuali caratteri speciali nel nome della squadra.
    $oggetto_mime = "=?UTF-8?B?" . base64_encode($oggetto) . "?=";

    $headers  = "From: " . MAIL_NOME_MITTENTE . " <" . MAIL_MITTENTE . ">\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $headers .= "Content-Transfer-Encoding: 8bit\r\n";

    // Niente "@" davanti a mail(): l'eventuale warning va nel log del
    // server (error_log), utile per capire perché l'invio fallisce
    // (spesso: mail() non configurata o bloccata dall'hosting), senza
    // però interrompere la richiesta né esporre dettagli al client.
    $ok = mail($destinatario, $oggetto_mime, $corpo, $headers);

    if (!$ok) {
        $errore = error_get_last();
        error_log("invia_email: invio a '$destinatario' fallito — " .
                   ($errore["message"] ?? "mail() ha restituito false senza ulteriori dettagli"));
        return ["ok" => false, "motivo" => "invio_fallito"];
    }

    return ["ok" => true, "motivo" => null];
}
