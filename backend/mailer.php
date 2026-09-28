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
//   invia_email("destinatario@example.com", "Oggetto", "Corpo del messaggio");
//
// NOTA: su alcuni hosting (incluso il piano gratuito di Altervista) la
// funzione mail() può essere limitata o disabilitata dal provider.
// invia_email() non solleva mai un errore bloccante: restituisce
// semplicemente true/false, così un eventuale fallimento dell'invio
// non deve mai impedire il salvataggio dei dati che lo ha generato.
// ============================================================

define("MAIL_MITTENTE", "no-reply@fantamazzone.altervista.org");
define("MAIL_NOME_MITTENTE", "FantaMazzone");

/**
 * Invia una email di solo testo. Restituisce true/false, non lancia mai
 * eccezioni: un destinatario mancante o non valido è semplicemente un
 * invio "non effettuato", non un errore dell'operazione chiamante.
 */
function invia_email($destinatario, $oggetto, $corpo) {
    $destinatario = trim((string)$destinatario);
    if ($destinatario === "" || !filter_var($destinatario, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    // Oggetto codificato in MIME per supportare correttamente accenti
    // ed eventuali caratteri speciali nel nome della squadra.
    $oggetto_mime = "=?UTF-8?B?" . base64_encode($oggetto) . "?=";

    $headers  = "From: " . MAIL_NOME_MITTENTE . " <" . MAIL_MITTENTE . ">\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $headers .= "Content-Transfer-Encoding: 8bit\r\n";

    return @mail($destinatario, $oggetto_mime, $corpo, $headers);
}
