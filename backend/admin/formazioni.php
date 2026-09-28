<?php
// ============================================================
// api/formazioni.php
//
// GET  ?stagione=2024&giornata=5&id_squadra=3  → formazione
// POST { stagione, giornata, id_squadra, giocatori: [{id, maglia}] }
//
// Codifica del campo MAGLIA (nessun'altra colonna disponibile per la
// posizione): 1..11 = titolare, 12+ = panchina (nell'ordine ricevuto).
// Chi non compare affatto non viene salvato (è "in tribuna" lato
// frontend) e non fa parte di questa tabella.
//
// Quando la formazione salvata è completa (tutti gli 11 titolari
// presenti, maglie 1..11), viene inviata una email di conferma alla
// squadra: oggetto = nome squadra, corpo = formazione appena inserita.
// L'indirizzo del destinatario si legge dalla colonna
// NEW_ALLENATORI.email dell'allenatore della squadra (aggiunta con una
// migrazione dedicata, vedi ALTER TABLE fornita a parte; modificabile
// in qualsiasi momento da "Inizializzazione stagione"): se assente o
// non valido, l'invio viene semplicemente saltato e il salvataggio
// della formazione non ne risente in alcun modo.
// ============================================================
require_once __DIR__ . "/connect.php";
require_once __DIR__ . "/mailer.php";

$stagione = param_int("stagione");

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $giornata   = post_int("giornata");
    $id_squadra = post_int("id_squadra");

    // Accetta JSON nel body oppure form data
    $input = file_get_contents("php://input");
    $body  = json_decode($input, true);
    if ($body === null) $body = $_POST;

    $giocatori = $body["giocatori"] ?? null;
    if (!is_array($giocatori) || count($giocatori) === 0) {
        api_error("Campo 'giocatori' obbligatorio (array di {id, maglia})");
    }

    // Verifica che la giornata non sia già giocata
    $ck = query_one("SELECT COUNT(*) AS n FROM NEW_CALENDARIO_CK
                     WHERE stagione = $stagione
                       AND giornata <= $giornata
                       AND ck_giocata = 'S'");
    if ((int)($ck["n"] ?? 0) >= $giornata) {
        api_error("Giornata già chiusa, impossibile modificare la formazione", 403);
    }

    // Cancella formazione precedente e reinserisce
    mysqli_query($conn, "DELETE FROM NEW_FORMAZIONI
                         WHERE STAGIONE = $stagione
                           AND ID_SQUADRA = $id_squadra
                           AND GIORNATA = $giornata");

    foreach ($giocatori as $g) {
        $id_g   = (int)$g["id"];
        $maglia = (int)$g["maglia"];
        if ($id_g <= 0) continue;
        mysqli_query($conn, "INSERT INTO NEW_FORMAZIONI (STAGIONE, ID_SQUADRA, ID_GIOCATORE, GIORNATA, MAGLIA)
                             VALUES ($stagione, $id_squadra, $id_g, $giornata, $maglia)");
    }

    // Rilegge la formazione appena salvata (fonte di verità: quello che
    // è realmente su DB, non l'input grezzo ricevuto) per capire se è
    // completa e per comporre il corpo della mail.
    $salvata = query_all("SELECT
            f.ID_GIOCATORE AS id_giocatore,
            g.descrizione  AS giocatore,
            g.ruolo,
            f.MAGLIA       AS maglia
        FROM NEW_FORMAZIONI f
        JOIN NEW_GIOCATORI g ON g.id = f.ID_GIOCATORE AND g.stagione = f.STAGIONE
        WHERE f.STAGIONE    = $stagione
          AND f.ID_SQUADRA  = $id_squadra
          AND f.GIORNATA    = $giornata
        ORDER BY f.MAGLIA");

    $email_inviata = invia_mail_formazione_se_completa($stagione, $giornata, $id_squadra, $salvata);

    api_success(["ok" => true, "email_inviata" => $email_inviata]);

} else {

    $giornata   = param_int("giornata");
    $id_squadra = param_int("id_squadra");

    $data = query_all("SELECT
            f.ID_GIOCATORE AS id_giocatore,
            g.descrizione  AS giocatore,
            g.ruolo,
            f.MAGLIA       AS maglia
        FROM NEW_FORMAZIONI f
        JOIN NEW_GIOCATORI g ON g.id = f.ID_GIOCATORE AND g.stagione = f.STAGIONE
        WHERE f.STAGIONE    = $stagione
          AND f.ID_SQUADRA  = $id_squadra
          AND f.GIORNATA    = $giornata
        ORDER BY f.MAGLIA");

    api_success($data);
}

// ── Invio email di conferma ─────────────────────────────────────
// Restituisce true/false (mai un errore): un fallimento dell'invio non
// deve mai far fallire il salvataggio della formazione che lo precede.
function invia_mail_formazione_se_completa($stagione, $giornata, $id_squadra, $righe) {
    $ruolo_label = [1 => "Portiere", 2 => "Difensore", 3 => "Centrocampista", 4 => "Attaccante"];

    $titolari = array_values(array_filter($righe, fn($r) => (int)$r["maglia"] >= 1 && (int)$r["maglia"] <= 11));
    $panchina = array_values(array_filter($righe, fn($r) => (int)$r["maglia"] >= 12));

    // "Completa" = tutte e 11 le maglie da 1 a 11 presenti una sola volta.
    $maglie_titolari = array_map(fn($r) => (int)$r["maglia"], $titolari);
    sort($maglie_titolari);
    if ($maglie_titolari !== range(1, 11)) {
        return false; // formazione ancora incompleta: nessuna mail
    }

    // Nome dalla squadra, email dall'allenatore associato (stesso join
    // usato in squadre.php: NEW_ALLENATORI.id_squadra = NEW_SQUADRE.id).
    $squadra = query_one("SELECT s.nome, a.email
                          FROM NEW_SQUADRE s
                          LEFT JOIN NEW_ALLENATORI a ON a.id_squadra = s.id AND a.stagione = s.stagione
                          WHERE s.id = $id_squadra AND s.stagione = $stagione");
    if (!$squadra) return false;

    $nome_squadra = $squadra["nome"] ?? "";

    $conteggio = [2 => 0, 3 => 0, 4 => 0];
    foreach ($titolari as $r) {
        $ruolo = (int)$r["ruolo"];
        if (isset($conteggio[$ruolo])) $conteggio[$ruolo]++;
    }
    $modulo = "{$conteggio[2]}-{$conteggio[3]}-{$conteggio[4]}";

    $righe_testo = [];
    $righe_testo[] = "Formazione salvata — " . $nome_squadra;
    $righe_testo[] = "Giornata " . $giornata . " · Stagione " . $stagione . "/" . ($stagione + 1);
    $righe_testo[] = "Modulo " . $modulo;
    $righe_testo[] = "";
    $righe_testo[] = "TITOLARI";
    foreach ($titolari as $r) {
        $label = $ruolo_label[(int)$r["ruolo"]] ?? ("Ruolo " . $r["ruolo"]);
        $righe_testo[] = $r["maglia"] . ". " . $label . " — " . $r["giocatore"];
    }
    if (count($panchina) > 0) {
        $righe_testo[] = "";
        $righe_testo[] = "PANCHINA";
        foreach ($panchina as $r) {
            $label = $ruolo_label[(int)$r["ruolo"]] ?? ("Ruolo " . $r["ruolo"]);
            $righe_testo[] = $r["maglia"] . ". " . $label . " — " . $r["giocatore"];
        }
    }

    $corpo = implode("\n", $righe_testo);

    return invia_email($squadra["email"] ?? "", $nome_squadra, $corpo);
}
