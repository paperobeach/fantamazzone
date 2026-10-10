<?php
// ============================================================
// api/admin/inizializza_stagione.php
//
// L'inizializzazione della stagione si svolge in DUE PASSI separati:
//
//   PASSO 1 — "Creazione utenze" (fase "utenze")
//     Crea squadre, allenatori e utenze (credenziali e ordine delle
//     squadre). Non tocca i calendari.
//   PASSO 2 — "Creazione calendari" (fase "calendari")
//     Da eseguire DOPO aver controllato i parametri della stagione in
//     "Gestisci regole di calcolo" → "Parametri stagione". Genera
//     NEW_CALENDARIO e NEW_CALENDARIO_CHAMP leggendo dalla
//     configurazione (NEW_PARAMETRI_STAGIONE): numero di giornate
//     (NUMERO_GIORNATE) e giornate Champions (CHAMP_*). Non ha
//     parametri di input oltre alla stagione.
//
// GET  ?stagione=2027
//   Restituisce, una riga per squadra, i dati per la stagione
//   indicata (join tra NEW_SQUADRE, NEW_ALLENATORI e NEW_UTENZE
//   sullo stesso id). La password non viene mai restituita.
//   - Se per la stagione richiesta non esiste ancora nessuna
//     squadra, la tabella viene precompilata con i dati
//     dell'ULTIMA stagione disponibile (MAX(stagione) presente in
//     NEW_SQUADRE): "fonte_stagione" nella risposta indica da quale
//     stagione provengono i dati mostrati (null se sono già quelli
//     della stagione richiesta).
//   - "formazione_presente" indica se per la stagione richiesta
//     esiste già almeno una formazione (NEW_FORMAZIONI): se true,
//     il passo 1 si limita ai soli campi utenza/password/abilitazione/
//     amministratore/email e il passo 2 non è eseguibile.
//
// GET  ?stagione=2027&fase=calendari
//   Stato del passo 2: { numero_giornate, squadre_presenti,
//   formazione_presente, calendario_righe, calendario_champ_righe,
//   errori: [messaggi], eseguibile }. "errori" elenca i motivi per cui
//   i calendari non sono generabili (squadre mancanti, formazioni già
//   presenti, NUMERO_GIORNATE non configurato, giornate Champions non
//   valide).
//
// POST { stagione, fase: "utenze", righe: [{ ordine, nome, logo, albo,
//                            allenatore, foto_allenatore, email,
//                            utenza, password, abilitazione,
//                            amministratore }] }
//
//   Se NON esiste ancora nessuna formazione per la stagione:
//     Consolida in un'unica chiamata le tre tabelle:
//       - NEW_SQUADRE     (id, nome, logo, stagione, albo)
//       - NEW_ALLENATORI  (id, descrizione, id_squadra, logo, stagione, email)
//       - NEW_UTENZE      (id, utenza, PASSWORD, descrizione, stagione, abilitazione, amministratore)
//     con cancellazione preventiva per stagione + reinserimento
//     (operazione ripetibile).
//   Se ESISTE già almeno una formazione per la stagione (controllo
//   ripetuto anche qui lato server, non ci si fida del frontend):
//     - vengono accettate in scrittura SOLO utenza/password/
//       abilitazione/amministratore e l'email dell'allenatore delle
//       righe già esistenti (aggiornate con UPDATE, non con
//       cancellazione+reinserimento). L'email è infatti SEMPRE
//       modificabile, in qualsiasi momento della stagione;
//     - non è possibile aggiungere/rimuovere/rinominare squadre né
//       cambiarne l'allenatore.
//   Se "fase" è omessa vale "utenze".
//
// POST { stagione, fase: "calendari" }
//   Rigenera i calendari della stagione (operazione ripetibile):
//     - NEW_CALENDARIO: copia le righe del "calendario modello"
//       (stagione 2011) con giornata <= NUMERO_GIORNATE, sostituendo
//       la stagione con quella indicata:
//         DELETE FROM NEW_CALENDARIO WHERE stagione = :stagione
//         INSERT INTO NEW_CALENDARIO (giornata, posizione, squadra, stagione)
//           SELECT giornata, posizione, squadra, :stagione
//           FROM NEW_CALENDARIO
//           WHERE giornata <= :giornate AND stagione = 2011
//     - NEW_CALENDARIO_CHAMP: copia la struttura della Champions
//       della stagione precedente (:stagione - 1). La Champions si
//       gioca in contemporanea al campionato; la giornata di
//       campionato di ogni turno e di ogni girone è configurata in
//       "Gestisci regole di calcolo" → "Parametri stagione" →
//       "Struttura stagione" (codici CHAMP_*, vedi
//       lib/ChampionsCalendario.php) e qui viene SOLO LETTA: le
//       giornate della struttura sorgente, ordinate
//       cronologicamente, sono associate per posizione ai turni
//       previsti e la colonna "giornata" è sostituita con quella
//       configurata per il girone della riga.
//   Richiede che esistano le squadre della stagione (passo 1) e NON
//   che esistano già formazioni. Se la configurazione manca o non è
//   valida (NUMERO_GIORNATE assente, giornate Champions fuori da
//   1..giornate, non crescenti) la richiesta è rifiutata PRIMA di
//   modificare qualsiasi dato.
//
// Mapping colonna riga → colonna DB (in lettura si usa sempre il
// valore su NEW_SQUADRE quando il dato è duplicato; in scrittura
// viene invece propagato su tutte le tabelle coinvolte):
//   nome            → NEW_SQUADRE.NOME
//   logo            → NEW_SQUADRE.LOGO
//   albo            → NEW_SQUADRE.ALBO
//   allenatore      → NEW_ALLENATORI.DESCRIZIONE
//   foto_allenatore → NEW_ALLENATORI.LOGO
//   email           → NEW_ALLENATORI.EMAIL (destinatario della mail di
//                      conferma formazione; sempre modificabile)
//   utenza          → NEW_UTENZE.UTENZA
//   password        → NEW_UTENZE.PASSWORD
//   abilitazione    → NEW_UTENZE.ABILITAZIONE
//   amministratore  → NEW_UTENZE.AMMINISTRATORE (flag Y/N: utente
//                      riconosciuto come amministratore dell'app,
//                      indipendentemente dall'abilitazione)
//   ordine          → NEW_SQUADRE.ID, NEW_ALLENATORI.ID,
//                      NEW_ALLENATORI.ID_SQUADRA, NEW_UTENZE.ID
//                      (stesso valore su tutte e 4 le colonne)
//
// NEW_UTENZE.DESCRIZIONE (il nome mostrato una volta loggati, vedi
// api/login.php) non è tra i campi indicati dall'utente: finché non
// arriva un'indicazione diversa, viene valorizzato automaticamente
// con "allenatore" (o, se vuoto, con "nome" della squadra), e non
// viene più toccato quando si è in modalità sola utenza (formazione
// già presente).
//
// NOTA TECNICA: come le altre tabelle NEW_* di questo DB, tutte le
// tabelle coinvolte sono MyISAM e quindi NON supportano transazioni
// reali (vedi anche la nota in admin/inserimento_rose.php per
// NEW_GIOCATORI). La chiamata a mysqli_begin_transaction/commit/
// rollback è mantenuta per uniformità di stile; la validazione
// completa preventiva riduce fortemente il rischio di scritture
// parziali.
//
// NOTA: le regole di valorizzazione dei campi e i controlli sui
// valori sono per ora quelli generici (obbligatorietà, lunghezza,
// univocità); verranno integrati con le regole di business
// specifiche in un secondo momento, come indicato dall'utente.
// ============================================================
require_once __DIR__ . "/../connect.php";
require_once __DIR__ . "/../lib/ChampionsCalendario.php";

const STAGIONE_MODELLO_CALENDARIO = 2011; // stagione "modello" da cui copiare gli accoppiamenti

function err_field($indice, $campo, $messaggio) {
    return ["indice" => $indice, "campo" => $campo, "messaggio" => $messaggio];
}

function validation_error(array $errors) {
    http_response_code(422);
    echo json_encode([
        "error" => [
            "code"    => "VALIDATION_ERROR",
            "message" => "Sono stati rilevati " . count($errors) . " errore/i di validazione",
            "status"  => 422,
            "details" => $errors,
        ]
    ]);
    exit;
}

// La colonna NEW_ALLENATORI.email è aggiunta da una migrazione
// (migrazione_email_allenatori.sql): finché non è stata eseguita, le
// scritture che la coinvolgono fallirebbero. Si controlla PRIMA di
// toccare qualsiasi dato, per non cancellare le righe di
// NEW_ALLENATORI senza poterle poi reinserire (tabelle MyISAM: nessun
// rollback possibile).
function colonna_email_presente($conn) {
    $r = mysqli_query($conn, "SHOW COLUMNS FROM NEW_ALLENATORI LIKE 'email'");
    return $r && mysqli_num_rows($r) > 0;
}

// Esegue una query di scrittura e, se fallisce, solleva un'eccezione
// (gestita dal try/catch del POST) invece di proseguire in silenzio
// rispondendo "ok" con dati non realmente salvati.
function esegui($conn, $sql) {
    $ok = mysqli_query($conn, $sql);
    if ($ok === false) {
        throw new RuntimeException(mysqli_error($conn));
    }
    return $ok;
}

// Righe (una per squadra) per una data stagione, con join su
// allenatori e utenze. Usata sia per la stagione richiesta sia,
// come fallback, per l'ultima stagione disponibile.
function righe_per_stagione($conn, $stagione) {
    // Se la migrazione non è ancora stata eseguita la pagina resta
    // utilizzabile in lettura (email vuote); è il salvataggio a
    // segnalare esplicitamente il problema.
    $colEmail = colonna_email_presente($conn) ? "a.email" : "'' AS email";
    $righe = query_all("
        SELECT s.id AS ordine, s.nome, s.logo, s.albo,
               a.descrizione AS allenatore, a.logo AS foto_allenatore, $colEmail,
               u.utenza, u.abilitazione, u.amministratore,
               IF(u.PASSWORD IS NOT NULL AND u.PASSWORD <> '', 1, 0) AS password_impostata
        FROM NEW_SQUADRE s
        LEFT JOIN NEW_ALLENATORI a ON a.id = s.id AND a.stagione = s.stagione
        LEFT JOIN NEW_UTENZE     u ON u.id = s.id AND u.stagione = s.stagione
        WHERE s.stagione = $stagione
        ORDER BY s.id
    ");
    foreach ($righe as &$r) {
        $r["allenatore"]      = $r["allenatore"] ?? "";
        $r["foto_allenatore"] = $r["foto_allenatore"] ?? "";
        $r["email"]           = $r["email"] ?? "";
        $r["utenza"]          = $r["utenza"] ?? "";
        $r["abilitazione"]    = $r["abilitazione"] ?? "N";
        $r["amministratore"]  = $r["amministratore"] ?? "N";
        $r["password"]        = ""; // mai restituita
        // Solo un indicatore (mai la password): è già valorizzata, quindi se il
        // campo resta vuoto viene mantenuta o copiata dalla stagione precedente
        $r["password_impostata"] = (bool) (int) ($r["password_impostata"] ?? 0);
    }
    unset($r);
    return $righe;
}

function formazione_presente_per_stagione($stagione) {
    $fc = query_one("SELECT COUNT(*) AS n FROM NEW_FORMAZIONI WHERE STAGIONE = $stagione");
    return $fc && (int) $fc["n"] > 0;
}


// ------------------------------------------------------------
// PASSO 2 — contesto e generazione dei calendari
// ------------------------------------------------------------

/** NUMERO_GIORNATE dalla configurazione di stagione (null se assente o non valido). */
function numero_giornate_configurato($stagione) {
    $r = query_one("SELECT valore FROM NEW_PARAMETRI_STAGIONE
                    WHERE stagione = $stagione AND codice = 'NUMERO_GIORNATE'");
    $v = $r ? trim((string) $r["valore"]) : "";
    if ($v === "" || !ctype_digit($v) || (int) $v < 1 || (int) $v > 99) return null;
    return (int) $v;
}

/**
 * Verifica se i calendari della stagione sono generabili.
 * @return array [ numero_giornate|null, giornate_champions (codice=>valore), errori (messaggi) ]
 */
function calendari_verifica($stagione) {
    $errori = [];
    $ns = query_one("SELECT COUNT(*) AS n FROM NEW_SQUADRE WHERE stagione = $stagione");
    if (!$ns || (int) $ns["n"] === 0) {
        $errori[] = "Nessuna squadra per la stagione $stagione: completare prima il passo 1 (Creazione utenze).";
    }
    if (formazione_presente_per_stagione($stagione)) {
        $errori[] = "Per la stagione $stagione esistono già formazioni inserite: i calendari non sono più modificabili.";
    }

    $giornate = numero_giornate_configurato($stagione);
    if ($giornate === null) {
        $errori[] = "Numero di giornate non configurato per la stagione $stagione: impostarlo in Gestisci regole di calcolo → Parametri stagione → Struttura stagione.";
    }

    $champGiornate = champions_parametri_stagione($stagione);
    if ($giornate !== null) {
        $turniCron   = champions_turni_cronologici();
        $sorgenteCal = champions_assegna_sorgente($stagione - 1); // giornata sorgente => indice turno
        $obbligatori = [];
        foreach ($sorgenteCal as $idx) {
            foreach ($turniCron[$idx]["celle"] ?? [] as $c) {
                if (!$c["opzionale"]) $obbligatori[] = $c["codice"];
            }
        }
        foreach (champions_valida_giornate($champGiornate, $giornate, $obbligatori) as [$cod, $msg]) {
            $errori[] = $msg . " (Gestisci regole di calcolo → Parametri stagione → Struttura stagione)";
        }
    }
    return [$giornate, $champGiornate, $errori];
}

/** Rigenera NEW_CALENDARIO e NEW_CALENDARIO_CHAMP (da chiamare dentro il try/transazione). */
function calendari_genera($conn, $stagione, $giornate, array $champGiornate) {
    $stagionePrecedente = $stagione - 1;

    esegui($conn, "DELETE FROM NEW_CALENDARIO WHERE stagione = $stagione");
    esegui($conn, "INSERT INTO NEW_CALENDARIO (giornata, posizione, squadra, stagione)
        SELECT giornata, posizione, squadra, $stagione
        FROM NEW_CALENDARIO
        WHERE giornata <= $giornate AND stagione = " . STAGIONE_MODELLO_CALENDARIO);

    // Ogni giornata sorgente corrisponde a un turno (per posizione
    // cronologica); per ogni riga si usa la cella del suo girone (i gironi
    // storici C/D della fase 2 corrispondono alle celle A/B).
    // Colonne scritte:
    //   giornata       progressivo Champions: copiato dalla sorgente
    //   giornata_camp  giornata di fantacampionato: quella CONFIGURATA per la cella
    //   girone         copiato com'è (il dominio storico A, B, C1..D3, S1, S2, FI, FR non cambia)
    $turniCron = champions_turni_cronologici();
    $sorgente  = champions_assegna_sorgente($stagionePrecedente);
    $righeSorg = query_all("SELECT giornata, giornata_camp, posizione, squadra, girone
                            FROM NEW_CALENDARIO_CHAMP WHERE stagione = $stagionePrecedente");

    esegui($conn, "DELETE FROM NEW_CALENDARIO_CHAMP WHERE stagione = $stagione");
    foreach ($righeSorg as $rs) {
        $gSorg = (int) $rs["giornata"];
        if (!isset($sorgente[$gSorg]) || !isset($turniCron[$sorgente[$gSorg]])) continue; // oltre i turni previsti
        $cella = champions_cella_turno($turniCron[$sorgente[$gSorg]], trim((string) $rs["girone"]));
        $gCamp = (int) ($champGiornate[$cella["codice"]] ?? 0);
        if ($gCamp < 1) continue; // turno non configurato (es. replay)
        $girone_esc = mysqli_real_escape_string($conn, $rs["girone"]);
        esegui($conn, "INSERT INTO NEW_CALENDARIO_CHAMP (stagione, giornata, giornata_camp, posizione, squadra, girone)
            VALUES ($stagione, $gSorg, $gCamp, " . (int) $rs["posizione"] . ", " . (int) $rs["squadra"] . ", '$girone_esc')");
    }
}

// ------------------------------------------------------------
// GET → dati esistenti (una riga per squadra) per la stagione
// ------------------------------------------------------------
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    $stagione = param_int("stagione");

    if (param_str("fase", false) === "calendari") {
        [$giornate, , $errori] = calendari_verifica($stagione);
        $ns  = query_one("SELECT COUNT(*) AS n FROM NEW_SQUADRE WHERE stagione = $stagione");
        $cal = query_one("SELECT COUNT(*) AS n FROM NEW_CALENDARIO WHERE stagione = $stagione");
        $chp = query_one("SELECT COUNT(*) AS n FROM NEW_CALENDARIO_CHAMP WHERE stagione = $stagione");
        api_success([
            "numero_giornate"        => $giornate,
            "squadre_presenti"       => (int) ($ns["n"] ?? 0),
            "formazione_presente"    => formazione_presente_per_stagione($stagione),
            "calendario_righe"       => (int) ($cal["n"] ?? 0),
            "calendario_champ_righe" => (int) ($chp["n"] ?? 0),
            "errori"                 => $errori,
            "eseguibile"             => empty($errori),
        ]);
    }

    $righe = righe_per_stagione($conn, $stagione);

    $fonteStagione = null;
    if (empty($righe)) {
        $ultima = query_one("SELECT MAX(stagione) AS s FROM NEW_SQUADRE");
        if ($ultima && $ultima["s"] !== null) {
            $fonteStagione = (int) $ultima["s"];
            $righe = righe_per_stagione($conn, $fonteStagione);
        }
    }

    api_success([
        "righe"               => $righe,
        "fonte_stagione"      => $fonteStagione,
        "formazione_presente" => formazione_presente_per_stagione($stagione),
    ]);
}

// ------------------------------------------------------------
// POST → validazione + salvataggio (completo oppure, se sono già
// presenti formazioni per la stagione, limitato a utenza/password/
// abilitazione)
// ------------------------------------------------------------
$input = json_decode(file_get_contents("php://input"), true) ?? $_POST;

$stagione = (int) ($input["stagione"] ?? 0);
$fase     = (string) ($input["fase"] ?? "utenze");

if ($stagione < 2000 || $stagione > 2100) {
    api_error("Parametro 'stagione' obbligatorio e deve essere un anno a 4 cifre plausibile (2000-2100)", 400);
}
if (!in_array($fase, ["utenze", "calendari"], true)) {
    api_error("Parametro 'fase' non valido: valori ammessi 'utenze' o 'calendari'", 400);
}

// ------------------------------------------------------------
// POST fase "calendari" → PASSO 2
// ------------------------------------------------------------
if ($fase === "calendari") {
    [$giornate, $champGiornate, $errori] = calendari_verifica($stagione);
    if (!empty($errori)) {
        validation_error(array_map(function ($m) { return err_field(null, "calendari", $m); }, $errori));
    }

    mysqli_begin_transaction($conn);
    try {
        calendari_genera($conn, $stagione, $giornate, $champGiornate);
        mysqli_commit($conn);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        api_error("Errore durante la creazione dei calendari: " . $e->getMessage(), 500);
    }

    $cal = query_one("SELECT COUNT(*) AS n FROM NEW_CALENDARIO WHERE stagione = $stagione");
    $chp = query_one("SELECT COUNT(*) AS n FROM NEW_CALENDARIO_CHAMP WHERE stagione = $stagione");
    api_success([
        "ok"                     => true,
        "stagione"               => $stagione,
        "fase"                   => "calendari",
        "numero_giornate"        => $giornate,
        "calendario_righe"       => (int) ($cal["n"] ?? 0),
        "calendario_champ_righe" => (int) ($chp["n"] ?? 0),
    ]);
}

// ------------------------------------------------------------
// POST fase "utenze" → PASSO 1
// ------------------------------------------------------------
$righe = $input["righe"] ?? [];

if (!is_array($righe)) {
    api_error("Formato dati non valido: 'righe' deve essere un array", 400);
}
if (empty($righe)) {
    api_error("Inserire almeno una riga (squadra)", 400);
}

// Ricontrollato sempre lato server: non ci si fida di un eventuale
// flag inviato dal frontend.
$formazionePresente = formazione_presente_per_stagione($stagione);

$errors   = [];
$idsVisti = [];      // ordine => indice riga (univocità)
$utenzeViste = [];   // nome utenza => indice riga (univocità, solo se valorizzata)
$rigaData = [];

foreach ($righe as $i => $r) {
    $ordine          = isset($r["ordine"]) ? (int) $r["ordine"] : 0;
    $nome            = trim((string) ($r["nome"] ?? ""));
    $logo            = trim((string) ($r["logo"] ?? ""));
    $albo            = trim((string) ($r["albo"] ?? ""));
    $allenatore      = trim((string) ($r["allenatore"] ?? ""));
    $foto_allenatore = trim((string) ($r["foto_allenatore"] ?? ""));
    $email           = trim((string) ($r["email"] ?? ""));
    $utenza          = trim((string) ($r["utenza"] ?? ""));
    $password        = (string) ($r["password"] ?? "");
    $abilitazione    = trim((string) ($r["abilitazione"] ?? ""));
    $amministratore  = trim((string) ($r["amministratore"] ?? ""));

    // ordine: sempre richiesto per individuare la riga; con
    // formazione già presente deve corrispondere a una squadra già
    // esistente (non è possibile aggiungerne/rimuoverne).
    if ($ordine <= 0) {
        $errors[] = err_field($i, "ordine", "Deve essere un numero intero positivo");
    } elseif (isset($idsVisti[$ordine])) {
        $errors[] = err_field($i, "ordine", "Valore duplicato (già usato alla riga {$idsVisti[$ordine]})");
    } else {
        $idsVisti[$ordine] = $i;
    }

    if ($formazionePresente) {
        if ($ordine > 0 && !isset($idsVisti["esiste:$ordine"])) {
            $esisteSquadra = query_one("SELECT id FROM NEW_SQUADRE WHERE id = $ordine AND stagione = $stagione LIMIT 1");
            if (!$esisteSquadra) {
                $errors[] = err_field($i, "ordine", "Per questa stagione sono già presenti formazioni: non è possibile aggiungere nuove squadre");
            }
            $idsVisti["esiste:$ordine"] = true;
        }
    } else {
        // nome squadra
        if ($nome === "")          $errors[] = err_field($i, "nome", "Campo obbligatorio");
        if (mb_strlen($nome) > 50) $errors[] = err_field($i, "nome", "Massimo 50 caratteri");

        // logo squadra / albo
        if (mb_strlen($logo) > 20) $errors[] = err_field($i, "logo", "Massimo 20 caratteri");
        if (mb_strlen($albo) > 2)  $errors[] = err_field($i, "albo", "Massimo 2 caratteri");

        // allenatore
        if (mb_strlen($allenatore) > 50)      $errors[] = err_field($i, "allenatore", "Massimo 50 caratteri");
        if (mb_strlen($foto_allenatore) > 20) $errors[] = err_field($i, "foto_allenatore", "Massimo 20 caratteri");
    }

    // email allenatore: facoltativa, ma se indicata deve essere valida.
    // Sempre validata (e sempre modificabile), anche in modalità
    // "solo utenze".
    if ($email !== "") {
        if (mb_strlen($email) > 120) {
            $errors[] = err_field($i, "email", "Massimo 120 caratteri");
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = err_field($i, "email", "Indirizzo email non valido");
        }
    }

    // utenza / password / abilitazione: sempre validati, anche in
    // modalità "solo utenze".
    if ($utenza !== "") {
        if (mb_strlen($utenza) > 50) $errors[] = err_field($i, "utenza", "Massimo 50 caratteri");
        if (isset($utenzeViste[$utenza])) {
            $errors[] = err_field($i, "utenza", "Nome utenza duplicato (già usato alla riga {$utenzeViste[$utenza]})");
        } else {
            $utenzeViste[$utenza] = $i;
        }
    }

    if (mb_strlen($password) > 8) $errors[] = err_field($i, "password", "Massimo 8 caratteri (limite colonna PASSWORD)");

    if ($abilitazione === "") {
        $abilitazione = "N";
    } elseif (!in_array($abilitazione, ["Y", "N"], true)) {
        $errors[] = err_field($i, "abilitazione", "Deve essere 'Y' o 'N'");
    }

    if ($amministratore === "") {
        $amministratore = "N";
    } elseif (!in_array($amministratore, ["Y", "N"], true)) {
        $errors[] = err_field($i, "amministratore", "Deve essere 'Y' o 'N'");
    }

    $rigaData[] = [
        "ordine" => $ordine, "nome" => $nome, "logo" => $logo, "albo" => $albo,
        "allenatore" => $allenatore, "foto_allenatore" => $foto_allenatore,
        "email" => $email,
        "utenza" => $utenza, "password" => $password, "abilitazione" => $abilitazione,
        "amministratore" => $amministratore,
    ];
}

// ---- Password mancante ----
// Se la password è vuota, la si ricava (senza mai passarla al frontend):
//   1. da quella già presente su DB per lo stesso ordine/stagione;
//   2. se la stagione non ha ancora utenze (stagione copiata dalla
//      precedente), da quella della stagione precedente per lo stesso
//      ordine, ma SOLO se il nome utenza è rimasto lo stesso (se l'utenza
//      è cambiata si tratta di un'altra persona: la password va indicata).
// Se non si trova nulla e viene indicato un nome utenza, la password è
// obbligatoria.
$stagionePrec = query_one("SELECT MAX(stagione) AS s FROM NEW_UTENZE WHERE stagione < $stagione");
$stagionePrec = ($stagionePrec && $stagionePrec["s"] !== null) ? (int) $stagionePrec["s"] : null;

foreach ($rigaData as $i => &$r) {
    if ($r["utenza"] === "" || $r["password"] !== "") continue;

    $esistente = query_one("SELECT PASSWORD FROM NEW_UTENZE
                             WHERE id = {$r['ordine']} AND stagione = $stagione LIMIT 1");

    if ($esistente && $esistente["PASSWORD"] !== "") {
        $r["password"] = $esistente["PASSWORD"]; // mantiene quella attuale
        continue;
    }

    if ($stagionePrec !== null) {
        $utenza_esc = mysqli_real_escape_string($conn, $r["utenza"]);
        $prec = query_one("SELECT PASSWORD FROM NEW_UTENZE
                           WHERE id = {$r['ordine']} AND stagione = $stagionePrec
                             AND utenza = '$utenza_esc' AND PASSWORD <> '' LIMIT 1");
        if ($prec) {
            $r["password"] = $prec["PASSWORD"]; // copiata dalla stagione precedente
            continue;
        }
    }

    $errors[] = err_field($i, "password", "Campo obbligatorio per una nuova utenza (o utenza senza password attuale)");
}
unset($r);

if (!empty($errors)) {
    validation_error($errors);
}

if (!colonna_email_presente($conn)) {
    api_error("La colonna 'email' non esiste ancora su NEW_ALLENATORI: eseguire prima la migrazione migrazione_email_allenatori.sql. Nessun dato è stato modificato.", 500);
}

mysqli_begin_transaction($conn);
try {
    if ($formazionePresente) {
        // ---- Modalità limitata: solo utenza/password/abilitazione/
        //      amministratore + email allenatore ----
        foreach ($rigaData as $r) {
            $utenza_esc = mysqli_real_escape_string($conn, $r["utenza"]);
            $pass_esc   = mysqli_real_escape_string($conn, $r["password"]);
            $abil_esc   = mysqli_real_escape_string($conn, $r["abilitazione"]);
            $admin_esc  = mysqli_real_escape_string($conn, $r["amministratore"]);
            $email_esc  = mysqli_real_escape_string($conn, $r["email"]);

            esegui($conn, "UPDATE NEW_UTENZE
                SET utenza = '$utenza_esc', PASSWORD = '$pass_esc', abilitazione = '$abil_esc', amministratore = '$admin_esc'
                WHERE id = {$r['ordine']} AND stagione = $stagione");

            // L'email dell'allenatore è modificabile anche a formazioni
            // già presenti (non altera squadra/allenatore).
            // Upsert: se per la squadra non esiste ancora la riga
            // allenatore, viene creata (vuota) così l'email non va persa.
            esegui($conn, "INSERT INTO NEW_ALLENATORI (id, descrizione, id_squadra, logo, stagione, email)
                VALUES ({$r['ordine']}, '', {$r['ordine']}, '', $stagione, '$email_esc')
                ON DUPLICATE KEY UPDATE email = VALUES(email)");
        }

        mysqli_commit($conn);

        api_success([
            "ok"                  => true,
            "stagione"            => $stagione,
            "righe"               => count($rigaData),
            "modalita"            => "solo_utenze",
            "fase"                => "utenze",
        ]);
    }

    // ---- Modalità completa: squadre + allenatori + utenze ----
    esegui($conn, "DELETE FROM NEW_SQUADRE    WHERE stagione = $stagione");
    esegui($conn, "DELETE FROM NEW_ALLENATORI WHERE stagione = $stagione");
    esegui($conn, "DELETE FROM NEW_UTENZE     WHERE stagione = $stagione");

    foreach ($rigaData as $r) {
        $nome_esc       = mysqli_real_escape_string($conn, $r["nome"]);
        $logo_esc       = mysqli_real_escape_string($conn, $r["logo"]);
        $albo_esc       = mysqli_real_escape_string($conn, $r["albo"]);
        $allenatore_esc = mysqli_real_escape_string($conn, $r["allenatore"]);
        $foto_all_esc   = mysqli_real_escape_string($conn, $r["foto_allenatore"]);
        $email_esc      = mysqli_real_escape_string($conn, $r["email"]);
        $utenza_esc     = mysqli_real_escape_string($conn, $r["utenza"]);
        $pass_esc       = mysqli_real_escape_string($conn, $r["password"]);
        $abil_esc       = mysqli_real_escape_string($conn, $r["abilitazione"]);
        $admin_esc      = mysqli_real_escape_string($conn, $r["amministratore"]);

        // NEW_UTENZE.descrizione non è tra i campi mappati esplicitamente:
        // di default usa il nome dell'allenatore, con fallback al nome
        // squadra (vedi nota in testa al file).
        $utenza_descrizione     = $r["allenatore"] !== "" ? $r["allenatore"] : $r["nome"];
        $utenza_descrizione_esc = mysqli_real_escape_string($conn, $utenza_descrizione);

        esegui($conn, "INSERT INTO NEW_SQUADRE (id, nome, logo, stagione, albo)
            VALUES ({$r['ordine']}, '$nome_esc', '$logo_esc', $stagione, '$albo_esc')");

        esegui($conn, "INSERT INTO NEW_ALLENATORI (id, descrizione, id_squadra, logo, stagione, email)
            VALUES ({$r['ordine']}, '$allenatore_esc', {$r['ordine']}, '$foto_all_esc', $stagione, '$email_esc')");

        esegui($conn, "INSERT INTO NEW_UTENZE (id, utenza, PASSWORD, descrizione, stagione, abilitazione, amministratore)
            VALUES ({$r['ordine']}, '$utenza_esc', '$pass_esc', '$utenza_descrizione_esc', $stagione, '$abil_esc', '$admin_esc')");
    }

    mysqli_commit($conn);

    api_success([
        "ok"                     => true,
        "stagione"               => $stagione,
        "fase"                   => "utenze",
        "righe"                  => count($rigaData),
        "modalita"               => "completa",
    ]);
} catch (Throwable $e) {
    mysqli_rollback($conn);
    api_error("Errore durante il salvataggio: " . $e->getMessage(), 500);
}
