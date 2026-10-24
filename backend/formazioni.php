<?php
// ============================================================
// api/formazioni.php
//
// GET  ?stagione=2024&giornata=5&id_squadra=3                    → formazione di campionato
// GET  ?stagione=2024&giornata=5&id_squadra=3&competizione=CHAMP → formazione Champions distinta
// GET  ?stagione=2024&giornata=5&id_squadra=3&modo=contesto      → { champions_in_giornata,
//        separata_attiva, separata_ammessa }: se separata_ammessa è true l'utente può
//        inserire una formazione Champions diversa da quella di campionato
// GET  ?stagione=2024&giornata=5&id_squadra=3&modo=rigoristi     → [{id_giocatore, ordine}] ordine dei
//        rigoristi salvato per la giornata (vuoto = nessuna lista: si parte dal portiere, per maglia)
// GET  ?stagione=2024&giornata=5&id_squadra=3&modo=avversarie    → { giornata_serie_a, giocatori:
//        [{id_giocatore, serie_a, avversaria, casa, data, ora}] } per ogni giocatore della rosa:
//        squadra di Serie A, avversaria della giornata e incontro in casa (true) o in
//        trasferta (false); avversaria/casa = null se la squadra non gioca nella giornata
//        o se calendario/squadra del giocatore non sono disponibili (vedi lib/AvversarieSerieA.php)
// GET  ?stagione=2024&giornata=5&id_squadra=3&modo=stato         → { blocco_attivo, bloccata, inizio_giornata,
//        limite_inserimento, nascondi_consentito, nascosta }: stato del blocco inserimento
//        (parametro BLOCCO_FORMAZIONE_ATTIVO) e dell'opzione "nascondi formazione"
//        (parametro NASCONDI_FORMAZIONE_CONSENTITO), vedi lib/FormazioniBlocco.php
//
// Tutte le GET accettano id_richiedente (id della squadra di chi guarda): se la formazione
// richiesta è nascosta e il richiedente non è la squadra stessa, la risposta è vuota ([]).
//
// POST { stagione, giornata, id_squadra, giocatori: [{id, maglia}],
//        competizione: "CAMP" (default) | "CHAMP", entrambe: bool (default false),
//        rigoristi?: [idGiocatore, ...], nascondi?: bool }
//      Con il parametro BLOCCO_FORMAZIONE_ATTIVO il salvataggio è rifiutato (403) da un minuto
//      prima della prima partita di Serie A della giornata fino alla chiusura della giornata.
//      "nascondi" (facoltativo, solo con NASCONDI_FORMAZIONE_CONSENTITO attivo) = true nasconde la
//      formazione agli altri fino all'inizio della prima partita di Serie A della giornata;
//      false la rende di nuovo visibile; se omesso la scelta già salvata resta invariata.
//      "rigoristi" (facoltativo) = ordine dei calci di rigore, solo per i turni di Champions
//      che prevedono supplementari e rigori (flag del calendario Champions): titolari da 1 a
//      11 poi panchinari. Viene salvato in NEW_RIGORISTI_CHAMP insieme alla formazione usata
//      in Champions (quella Champions distinta o, se non è distinta, quella di campionato).
//      Lista vuota/null = nessuna lista (cancella quella eventualmente salvata).
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
require_once __DIR__ . "/lib/AvversarieSerieA.php";
require_once __DIR__ . "/lib/FormazioniBlocco.php";

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

    // Blocco inserimento formazione (parametro di stagione): chiuso da un minuto
    // prima della prima partita di Serie A della giornata, fino alla chiusura della giornata.
    $blocco = fb_stato_blocco($stagione, $giornata);
    if ($blocco["bloccata"]) {
        api_error("Inserimento formazione chiuso: il limite era " . date("d/m/Y H:i", strtotime($blocco["limite"]))
                  . " (un minuto prima della prima partita di Serie A della giornata). "
                  . "Tornerà possibile a giornata chiusa.", 403);
    }

    // Opzione "nascondi formazione": va validata prima di scrivere qualunque cosa
    $nascondi = null;   // null = scelta invariata
    if (array_key_exists("nascondi", $body)) {
        $nascondi_raw = $body["nascondi"];
        $nascondi = !empty($nascondi_raw) && !in_array(strtolower((string)$nascondi_raw), ["false", "0", "no"], true);
        if ($nascondi && !fb_nascondi_consentito($stagione)) {
            api_error("Nascondere la formazione non è consentito in questa stagione", 403);
        }
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

    if ($nascondi !== null) {
        fb_imposta_nascosta($stagione, $giornata, $id_squadra, $nascondi);
    }

    // Ordine dei rigoristi: solo se il turno di Champions lo prevede e la formazione
    // salvata è quella che userà la Champions (distinta se ammessa, altrimenti l'unica).
    $rigoristi_salvati = null;
    if (array_key_exists("rigoristi", $body)
        && champions_rigori_previsti($stagione, $giornata, $id_squadra)
        && (!$ammessa || in_array("CHAMP", $destinazioni, true))) {
        $tab_rig = in_array("CHAMP", $destinazioni, true) ? formazione_tabella("CHAMP") : formazione_tabella("CAMP");
        $lista_rig = is_array($body["rigoristi"]) ? $body["rigoristi"] : [];
        $rigoristi_salvati = salva_rigoristi($stagione, $giornata, $id_squadra, $lista_rig, $tab_rig);
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
        "nascosta"      => fb_flag_nascosta($stagione, $giornata, $id_squadra) && fb_nascondi_consentito($stagione),
        "rigoristi_salvati" => $rigoristi_salvati, // null = non applicabile; altrimenti numero di rigoristi in lista
        "email_inviata" => $esito_email["ok"],
        "motivo_email"  => $esito_email["motivo"], // null se inviata; altrimenti spiega il perché
    ]);

} else {

    $giornata   = param_int("giornata");
    $id_squadra = param_int("id_squadra");
    $id_richiedente = param_int("id_richiedente", false);
    $modo_get   = param_str("modo", false) ?? "";

    // Formazione nascosta dall'allenatore: agli altri non si restituisce nulla
    // (modi "contesto", "stato" e "avversarie" non espongono la formazione).
    $nascosta_a_chi_guarda = !in_array($modo_get, ["contesto", "stato", "avversarie"], true)
        && fb_formazione_nascosta_a($stagione, $giornata, $id_squadra, $id_richiedente);

    if ($nascosta_a_chi_guarda) {
        api_success([]);
    } elseif ($modo_get === "stato") {
        $blocco = fb_stato_blocco($stagione, $giornata);
        $consentito = fb_nascondi_consentito($stagione);
        api_success([
            "blocco_attivo"       => $blocco["attivo"],
            "bloccata"            => $blocco["bloccata"],
            "inizio_giornata"     => $blocco["inizio"],
            "limite_inserimento"  => $blocco["limite"],
            "nascondi_consentito" => $consentito,
            "nascosta"            => $consentito && fb_flag_nascosta($stagione, $giornata, $id_squadra),
        ]);
    } elseif ((param_str("modo", false) ?? "") === "contesto") {
        // Contesto: l'utente può inserire una formazione Champions distinta?
        $gioca  = champions_squadra_gioca_in_giornata($stagione, $giornata, $id_squadra);
        $attiva = formazione_champions_separata_attiva($stagione);
        api_success([
            "champions_in_giornata" => $gioca,
            "separata_attiva"       => $attiva,
            "separata_ammessa"      => $gioca && $attiva,
            // Il turno di Champions prevede supplementari e rigori: l'allenatore può
            // stabilire l'ordine dei rigoristi (titolari 1-11, poi panchinari)
            "rigori_previsti"       => $gioca && champions_rigori_previsti($stagione, $giornata, $id_squadra),
        ]);
    } elseif ((param_str("modo", false) ?? "") === "avversarie") {
        // Avversaria di Serie A e casa/trasferta di ogni giocatore della rosa
        api_success(av_avversarie_rosa($stagione, $giornata, $id_squadra));
    } elseif ((param_str("modo", false) ?? "") === "rigoristi") {
        api_success(query_all("SELECT ID_GIOCATORE AS id_giocatore, ORDINE AS ordine
                               FROM NEW_RIGORISTI_CHAMP
                               WHERE STAGIONE = $stagione AND GIORNATA = $giornata AND ID_SQUADRA = $id_squadra
                               ORDER BY ORDINE"));
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

// ── Ordine dei rigoristi ────────────────────────────────────────
// Salva in NEW_RIGORISTI_CHAMP l'ordine di tiro dei rigori per squadra+giornata.
// L'ordine ricevuto viene riportato alla formazione realmente salvata ($tabella):
// si scartano i giocatori non in formazione e i doppioni, i titolari (maglia 1-11)
// vengono sempre prima dei panchinari (maglia > 11) e chi manca nella lista segue
// per numero di maglia. Lista vuota = cancella la lista salvata (si andrà per
// numero di maglia, dal portiere). Ritorna il numero di rigoristi salvati.
function salva_rigoristi($stagione, $giornata, $id_squadra, array $ids, string $tabella): int {
    global $conn;

    mysqli_query($conn, "DELETE FROM NEW_RIGORISTI_CHAMP
                         WHERE STAGIONE = $stagione AND GIORNATA = $giornata AND ID_SQUADRA = $id_squadra");
    if (count($ids) === 0) return 0;

    $formazione = query_all("SELECT ID_GIOCATORE AS id, MAGLIA AS maglia
                             FROM $tabella
                             WHERE STAGIONE = $stagione AND ID_SQUADRA = $id_squadra AND GIORNATA = $giornata
                             ORDER BY MAGLIA");
    $maglia = [];
    foreach ($formazione as $r) $maglia[(int)$r["id"]] = (int)$r["maglia"];

    $ricevuti = [];
    foreach ($ids as $id) {
        $id = (int)$id;
        if (isset($maglia[$id]) && !in_array($id, $ricevuti, true)) $ricevuti[] = $id;
    }

    $ordinati = [];
    foreach ([false, true] as $panchina) {
        $gruppo = array_values(array_filter(array_keys($maglia), fn($id) => ($maglia[$id] > 11) === $panchina));
        // prima quelli indicati dall'utente (nel suo ordine), poi i restanti per maglia
        foreach ($ricevuti as $id) if (in_array($id, $gruppo, true)) $ordinati[] = $id;
        $mancanti = array_values(array_filter($gruppo, fn($id) => !in_array($id, $ricevuti, true)));
        usort($mancanti, fn($a, $b) => $maglia[$a] <=> $maglia[$b]);
        foreach ($mancanti as $id) $ordinati[] = $id;
    }

    foreach ($ordinati as $i => $id) {
        $ordine = $i + 1;
        mysqli_query($conn, "INSERT INTO NEW_RIGORISTI_CHAMP (STAGIONE, GIORNATA, ID_SQUADRA, ID_GIOCATORE, ORDINE)
                             VALUES ($stagione, $giornata, $id_squadra, $id, $ordine)");
    }
    return count($ordinati);
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
