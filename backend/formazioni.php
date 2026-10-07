<?php
// ============================================================
// api/formazioni.php
//
// GET  ?stagione=2024&giornata=5&id_squadra=3                    → formazione di campionato
// GET  ?stagione=2024&giornata=5&id_squadra=3&competizione=CHAMP → formazione Champions distinta
// GET  ?stagione=2024&giornata=5&id_squadra=3&modo=contesto      → { champions_in_giornata,
//        separata_attiva, separata_ammessa }: se separata_ammessa è true l'utente può
//        inserire una formazione Champions diversa da quella di campionato
// POST { stagione, giornata, id_squadra, giocatori: [{id, maglia}],
//        competizione: "CAMP" (default) | "CHAMP", entrambe: bool (default false) }
//      "entrambe" = true salva la stessa formazione sia in campionato sia in
//      Champions (solo se la formazione distinta è ammessa, altrimenti
//      viene salvata solo quella di campionato).
//
// Formazione distinta di Champions (vedi lib/FormazioniChampions.php): ammessa
// solo se il parametro di stagione FORMAZIONE_CHAMPIONS_DIVERSA = '1' e la
// squadra gioca un turno di Champions nella giornata. Sta in
// NEW_FORMAZIONI_CHAMP; se non salvata, per la Champions vale quella di campionato.
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
require_once __DIR__ . "/lib/FormazioniChampions.php";

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

    // Competizione di destinazione e scelta "salva per entrambe"
    $competizione = strtoupper(trim((string)($body["competizione"] ?? "CAMP")));
    if (!in_array($competizione, ["CAMP", "CHAMP"], true)) {
        api_error("Competizione non valida (CAMP o CHAMP)", 400);
    }
    $entrambe_raw = $body["entrambe"] ?? false;
    $entrambe = !empty($entrambe_raw) && !in_array(strtolower((string)$entrambe_raw), ["false", "0", "no"], true);

    // Verifica che la giornata non sia già giocata
    $ck = query_one("SELECT COUNT(*) AS n FROM NEW_CALENDARIO_CK
                     WHERE stagione = $stagione
                       AND giornata <= $giornata
                       AND ck_giocata = 'S'");
    if ((int)($ck["n"] ?? 0) >= $giornata) {
        api_error("Giornata già chiusa, impossibile modificare la formazione", 403);
    }

    // La formazione Champions distinta è ammessa solo se previsto dal
    // parametro di stagione e se la squadra gioca la Champions nella giornata.
    $ammessa = formazione_champions_separata_ammessa($stagione, $giornata, $id_squadra);
    if ($competizione === "CHAMP" && !$ammessa) {
        api_error("Per questa giornata non è prevista una formazione Champions distinta da quella di campionato", 403);
    }
    $destinazioni = ($entrambe && $ammessa) ? ["CAMP", "CHAMP"] : [$competizione];

    foreach ($destinazioni as $dest) {
        $tabella = formazione_tabella($dest);

        // Cancella formazione precedente e reinserisce
        mysqli_query($conn, "DELETE FROM $tabella
                             WHERE STAGIONE = $stagione
                               AND ID_SQUADRA = $id_squadra
                               AND GIORNATA = $giornata");

        foreach ($giocatori as $g) {
            $id_g   = (int)$g["id"];
            $maglia = (int)$g["maglia"];
            if ($id_g <= 0) continue;
            mysqli_query($conn, "INSERT INTO $tabella (STAGIONE, ID_SQUADRA, ID_GIOCATORE, GIORNATA, MAGLIA)
                                 VALUES ($stagione, $id_squadra, $id_g, $giornata, $maglia)");
        }
    }

    // Rilegge la formazione appena salvata (fonte di verità: quello che
    // è realmente su DB, non l'input grezzo ricevuto) per capire se è
    // completa e per comporre il corpo della mail.
    $tabella_lettura = formazione_tabella($destinazioni[0]);
    $salvata = query_all("SELECT
            f.ID_GIOCATORE AS id_giocatore,
            g.descrizione  AS giocatore,
            g.ruolo,
            f.MAGLIA       AS maglia
        FROM $tabella_lettura f
        JOIN NEW_GIOCATORI g ON g.id = f.ID_GIOCATORE AND g.stagione = f.STAGIONE
        WHERE f.STAGIONE    = $stagione
          AND f.ID_SQUADRA  = $id_squadra
          AND f.GIORNATA    = $giornata
        ORDER BY f.MAGLIA");

    $esito_email = invia_mail_formazione_se_completa($stagione, $giornata, $id_squadra, $salvata, $destinazioni);

    api_success([
        "ok"            => true,
        "salvata_per"   => $destinazioni,
        "email_inviata" => $esito_email["ok"],
        "motivo_email"  => $esito_email["motivo"], // null se inviata; altrimenti spiega il perché
    ]);

} else {

    $giornata   = param_int("giornata");
    $id_squadra = param_int("id_squadra");

    if ((param_str("modo", false) ?? "") === "contesto") {
        // Contesto: l'utente può inserire una formazione Champions distinta?
        $gioca  = champions_squadra_gioca_in_giornata($stagione, $giornata, $id_squadra);
        $attiva = formazione_champions_separata_attiva($stagione);
        api_success([
            "champions_in_giornata" => $gioca,
            "separata_attiva"       => $attiva,
            "separata_ammessa"      => $gioca && $attiva,
        ]);
    } else {
        $competizione = strtoupper(trim((string)(param_str("competizione", false) ?? "CAMP")));
        if (!in_array($competizione, ["CAMP", "CHAMP"], true)) {
            api_error("Competizione non valida (CAMP o CHAMP)", 400);
        }
        $tabella = formazione_tabella($competizione);

        $data = query_all("SELECT
                f.ID_GIOCATORE AS id_giocatore,
                g.descrizione  AS giocatore,
                g.ruolo,
                f.MAGLIA       AS maglia
            FROM $tabella f
            JOIN NEW_GIOCATORI g ON g.id = f.ID_GIOCATORE AND g.stagione = f.STAGIONE
            WHERE f.STAGIONE    = $stagione
              AND f.ID_SQUADRA  = $id_squadra
              AND f.GIORNATA    = $giornata
            ORDER BY f.MAGLIA");

        api_success($data);
    }
}

// ── Invio email di conferma ─────────────────────────────────────
// Restituisce ["ok" => bool, "motivo" => null|stringa] (mai un errore):
// un fallimento dell'invio non deve mai far fallire il salvataggio
// della formazione che lo precede, ma il motivo va sempre riportato
// esplicitamente (vedi mailer.php per l'elenco dei motivi possibili).
function invia_mail_formazione_se_completa($stagione, $giornata, $id_squadra, $righe, $destinazioni = ["CAMP"]) {
    $ruolo_label = [1 => "Portiere", 2 => "Difensore", 3 => "Centrocampista", 4 => "Attaccante"];

    $titolari = array_values(array_filter($righe, fn($r) => (int)$r["maglia"] >= 1 && (int)$r["maglia"] <= 11));
    $panchina = array_values(array_filter($righe, fn($r) => (int)$r["maglia"] >= 12));

    // "Completa" = tutte e 11 le maglie da 1 a 11 presenti una sola volta.
    $maglie_titolari = array_map(fn($r) => (int)$r["maglia"], $titolari);
    sort($maglie_titolari);
    if ($maglie_titolari !== range(1, 11)) {
        return ["ok" => false, "motivo" => "formazione_incompleta"];
    }

    // Nome dalla squadra, email dall'allenatore associato (stesso join
    // usato in squadre.php: NEW_ALLENATORI.id_squadra = NEW_SQUADRE.id).
    $squadra = query_one("SELECT s.nome, a.email
                          FROM NEW_SQUADRE s
                          LEFT JOIN NEW_ALLENATORI a ON a.id_squadra = s.id AND a.stagione = s.stagione
                          WHERE s.id = $id_squadra AND s.stagione = $stagione");
    if (!$squadra) {
        return ["ok" => false, "motivo" => "squadra_non_trovata"];
    }

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
    $etichette = ["CAMP" => "Campionato", "CHAMP" => "Champions"];
    $righe_testo[] = "Competizione: " . implode(" e ", array_map(fn($d) => $etichette[$d] ?? $d, $destinazioni));
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
