<?php
// ============================================================
// api/admin/inizializza_stagione.php
//
// GET  ?stagione=2027
//   Restituisce, una riga per squadra, i dati per la stagione
//   indicata (join tra NEW_SQUADRE, NEW_ALLENATORI e NEW_UTENZE
//   sullo stesso id). La password non viene mai restituita.
//
//   - Se per la stagione richiesta non esiste ancora nessuna
//     squadra, la tabella viene precompilata con i dati
//     dell'ULTIMA stagione disponibile (MAX(stagione) presente in
//     NEW_SQUADRE): "fonte_stagione" nella risposta indica da quale
//     stagione provengono i dati mostrati (null se sono già quelli
//     della stagione richiesta).
//   - "formazione_presente" indica se per la stagione richiesta
//     esiste già almeno una formazione (NEW_FORMAZIONI): se true,
//     il frontend deve limitare la modifica ai soli campi
//     utenza/password/abilitazione (vedi POST).
//
// POST { stagione, giornate, righe: [{ ordine, nome, logo, albo,
//                            allenatore, foto_allenatore,
//                            utenza, password, abilitazione }] }
//
//   Se NON esiste ancora nessuna formazione per la stagione:
//     Consolida in un'unica chiamata le tre tabelle:
//       - NEW_SQUADRE     (id, nome, logo, stagione, albo)
//       - NEW_ALLENATORI  (id, descrizione, id_squadra, logo, stagione)
//       - NEW_UTENZE      (id, utenza, PASSWORD, descrizione, stagione, abilitazione)
//     con cancellazione preventiva per stagione + reinserimento
//     (operazione ripetibile).
//
//     Se inoltre è stato indicato "giornate" (1-99), rigenera anche
//     il calendario:
//       - NEW_CALENDARIO: copia le righe del "calendario modello"
//         (stagione 2011) con giornata <= giornate, sostituendo la
//         stagione con quella indicata:
//           DELETE FROM NEW_CALENDARIO WHERE stagione = :stagione
//           INSERT INTO NEW_CALENDARIO (giornata, posizione, squadra, stagione)
//             SELECT giornata, posizione, squadra, :stagione
//             FROM NEW_CALENDARIO
//             WHERE giornata <= :giornate AND stagione = 2011
//       - NEW_CALENDARIO_CHAMP: copia la struttura della Champions
//         della stagione precedente (:stagione - 1):
//           DELETE FROM NEW_CALENDARIO_CHAMP WHERE stagione = :stagione
//           INSERT INTO NEW_CALENDARIO_CHAMP (stagione, giornata, giornata_camp, posizione, squadra, girone)
//             SELECT :stagione, giornata, giornata_camp, posizione, squadra, girone
//             FROM NEW_CALENDARIO_CHAMP
//             WHERE stagione = :stagione - 1
//     Se "giornate" non è indicato, il calendario non viene toccato.
//
//   Se ESISTE già almeno una formazione per la stagione (controllo
//   ripetuto anche qui lato server, non ci si fida del frontend):
//     - vengono accettate in scrittura SOLO utenza/password/
//       abilitazione delle righe già esistenti (aggiornate con
//       UPDATE, non con cancellazione+reinserimento);
//     - non è possibile aggiungere/rimuovere/rinominare squadre né
//       cambiarne l'allenatore;
//     - NON vengono toccati né NEW_CALENDARIO né NEW_CALENDARIO_CHAMP,
//       indipendentemente dal valore di "giornate".
//
// Mapping colonna riga → colonna DB (in lettura si usa sempre il
// valore su NEW_SQUADRE quando il dato è duplicato; in scrittura
// viene invece propagato su tutte le tabelle coinvolte):
//   nome            → NEW_SQUADRE.NOME
//   logo            → NEW_SQUADRE.LOGO
//   albo            → NEW_SQUADRE.ALBO
//   allenatore      → NEW_ALLENATORI.DESCRIZIONE
//   foto_allenatore → NEW_ALLENATORI.LOGO
//   utenza          → NEW_UTENZE.UTENZA
//   password        → NEW_UTENZE.PASSWORD
//   abilitazione    → NEW_UTENZE.ABILITAZIONE
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

// Righe (una per squadra) per una data stagione, con join su
// allenatori e utenze. Usata sia per la stagione richiesta sia,
// come fallback, per l'ultima stagione disponibile.
function righe_per_stagione($conn, $stagione) {
    $righe = query_all("
        SELECT s.id AS ordine, s.nome, s.logo, s.albo,
               a.descrizione AS allenatore, a.logo AS foto_allenatore,
               u.utenza, u.abilitazione
        FROM NEW_SQUADRE s
        LEFT JOIN NEW_ALLENATORI a ON a.id = s.id AND a.stagione = s.stagione
        LEFT JOIN NEW_UTENZE     u ON u.id = s.id AND u.stagione = s.stagione
        WHERE s.stagione = $stagione
        ORDER BY s.id
    ");
    foreach ($righe as &$r) {
        $r["allenatore"]      = $r["allenatore"] ?? "";
        $r["foto_allenatore"] = $r["foto_allenatore"] ?? "";
        $r["utenza"]          = $r["utenza"] ?? "";
        $r["abilitazione"]    = $r["abilitazione"] ?? "N";
        $r["password"]        = ""; // mai restituita
    }
    unset($r);
    return $righe;
}

function formazione_presente_per_stagione($stagione) {
    $fc = query_one("SELECT COUNT(*) AS n FROM NEW_FORMAZIONI WHERE STAGIONE = $stagione");
    return $fc && (int) $fc["n"] > 0;
}

// ------------------------------------------------------------
// GET → dati esistenti (una riga per squadra) per la stagione
// ------------------------------------------------------------
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    $stagione = param_int("stagione");

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

$stagione     = (int) ($input["stagione"] ?? 0);
$righe        = $input["righe"] ?? [];
$giornateRaw  = $input["giornate"] ?? null;

if ($stagione < 2000 || $stagione > 2100) {
    api_error("Parametro 'stagione' obbligatorio e deve essere un anno a 4 cifre plausibile (2000-2100)", 400);
}
if (!is_array($righe)) {
    api_error("Formato dati non valido: 'righe' deve essere un array", 400);
}
if (empty($righe)) {
    api_error("Inserire almeno una riga (squadra)", 400);
}

// "giornate": numero di giornate del campionato, opzionale, al
// massimo 2 cifre (1-99). Se assente/vuoto, il calendario non viene
// toccato in questo salvataggio.
$giornate = null;
if ($giornateRaw !== null && $giornateRaw !== "") {
    if (!ctype_digit((string) $giornateRaw) || (int) $giornateRaw < 1 || (int) $giornateRaw > 99) {
        api_error("Il numero di giornate deve essere un intero di al massimo 2 cifre (1-99)", 400);
    }
    $giornate = (int) $giornateRaw;
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
    $utenza          = trim((string) ($r["utenza"] ?? ""));
    $password        = (string) ($r["password"] ?? "");
    $abilitazione    = trim((string) ($r["abilitazione"] ?? ""));

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

    $rigaData[] = [
        "ordine" => $ordine, "nome" => $nome, "logo" => $logo, "albo" => $albo,
        "allenatore" => $allenatore, "foto_allenatore" => $foto_allenatore,
        "utenza" => $utenza, "password" => $password, "abilitazione" => $abilitazione,
    ];
}

// ---- Password mancante su una utenza nuova ----
// Se la password è vuota, si mantiene quella già presente su DB per
// lo stesso ordine/stagione (permette di lasciarla invariata). Se
// però non esiste ancora nessuna utenza per quell'ordine/stagione
// (o esiste ma priva di password), e viene indicato un nome utenza,
// la password è obbligatoria.
foreach ($rigaData as $i => &$r) {
    if ($r["utenza"] === "" || $r["password"] !== "") continue;

    $esistente = query_one("SELECT PASSWORD FROM NEW_UTENZE
                             WHERE id = {$r['ordine']} AND stagione = $stagione LIMIT 1");

    if ($esistente && $esistente["PASSWORD"] !== "") {
        $r["password"] = $esistente["PASSWORD"]; // mantiene quella attuale
    } else {
        $errors[] = err_field($i, "password", "Campo obbligatorio per una nuova utenza (o utenza senza password attuale)");
    }
}
unset($r);

if (!empty($errors)) {
    validation_error($errors);
}

mysqli_begin_transaction($conn);
try {
    if ($formazionePresente) {
        // ---- Modalità limitata: solo utenza/password/abilitazione ----
        foreach ($rigaData as $r) {
            $utenza_esc = mysqli_real_escape_string($conn, $r["utenza"]);
            $pass_esc   = mysqli_real_escape_string($conn, $r["password"]);
            $abil_esc   = mysqli_real_escape_string($conn, $r["abilitazione"]);

            mysqli_query($conn, "UPDATE NEW_UTENZE
                SET utenza = '$utenza_esc', PASSWORD = '$pass_esc', abilitazione = '$abil_esc'
                WHERE id = {$r['ordine']} AND stagione = $stagione");
        }

        mysqli_commit($conn);

        api_success([
            "ok"                  => true,
            "stagione"            => $stagione,
            "righe"               => count($rigaData),
            "modalita"            => "solo_utenze",
            "calendario_aggiornato" => false,
        ]);
    }

    // ---- Modalità completa: squadre + allenatori + utenze ----
    mysqli_query($conn, "DELETE FROM NEW_SQUADRE    WHERE stagione = $stagione");
    mysqli_query($conn, "DELETE FROM NEW_ALLENATORI WHERE stagione = $stagione");
    mysqli_query($conn, "DELETE FROM NEW_UTENZE     WHERE stagione = $stagione");

    foreach ($rigaData as $r) {
        $nome_esc       = mysqli_real_escape_string($conn, $r["nome"]);
        $logo_esc       = mysqli_real_escape_string($conn, $r["logo"]);
        $albo_esc       = mysqli_real_escape_string($conn, $r["albo"]);
        $allenatore_esc = mysqli_real_escape_string($conn, $r["allenatore"]);
        $foto_all_esc   = mysqli_real_escape_string($conn, $r["foto_allenatore"]);
        $utenza_esc     = mysqli_real_escape_string($conn, $r["utenza"]);
        $pass_esc       = mysqli_real_escape_string($conn, $r["password"]);
        $abil_esc       = mysqli_real_escape_string($conn, $r["abilitazione"]);

        // NEW_UTENZE.descrizione non è tra i campi mappati esplicitamente:
        // di default usa il nome dell'allenatore, con fallback al nome
        // squadra (vedi nota in testa al file).
        $utenza_descrizione     = $r["allenatore"] !== "" ? $r["allenatore"] : $r["nome"];
        $utenza_descrizione_esc = mysqli_real_escape_string($conn, $utenza_descrizione);

        mysqli_query($conn, "INSERT INTO NEW_SQUADRE (id, nome, logo, stagione, albo)
            VALUES ({$r['ordine']}, '$nome_esc', '$logo_esc', $stagione, '$albo_esc')");

        mysqli_query($conn, "INSERT INTO NEW_ALLENATORI (id, descrizione, id_squadra, logo, stagione)
            VALUES ({$r['ordine']}, '$allenatore_esc', {$r['ordine']}, '$foto_all_esc', $stagione)");

        mysqli_query($conn, "INSERT INTO NEW_UTENZE (id, utenza, PASSWORD, descrizione, stagione, abilitazione)
            VALUES ({$r['ordine']}, '$utenza_esc', '$pass_esc', '$utenza_descrizione_esc', $stagione, '$abil_esc')");
    }

    // ---- Calendario (solo se è stato indicato il numero di giornate) ----
    $calendarioAggiornato = false;
    if ($giornate !== null) {
        $stagionePrecedente = $stagione - 1;

        mysqli_query($conn, "DELETE FROM NEW_CALENDARIO WHERE stagione = $stagione");
        mysqli_query($conn, "INSERT INTO NEW_CALENDARIO (giornata, posizione, squadra, stagione)
            SELECT giornata, posizione, squadra, $stagione
            FROM NEW_CALENDARIO
            WHERE giornata <= $giornate AND stagione = " . STAGIONE_MODELLO_CALENDARIO);

        mysqli_query($conn, "DELETE FROM NEW_CALENDARIO_CHAMP WHERE stagione = $stagione");
        mysqli_query($conn, "INSERT INTO NEW_CALENDARIO_CHAMP (stagione, giornata, giornata_camp, posizione, squadra, girone)
            SELECT $stagione, giornata, giornata_camp, posizione, squadra, girone
            FROM NEW_CALENDARIO_CHAMP
            WHERE stagione = $stagionePrecedente");

        $calendarioAggiornato = true;
    }

    mysqli_commit($conn);

    api_success([
        "ok"                     => true,
        "stagione"               => $stagione,
        "righe"                  => count($rigaData),
        "modalita"               => "completa",
        "calendario_aggiornato"  => $calendarioAggiornato,
    ]);
} catch (Throwable $e) {
    mysqli_rollback($conn);
    api_error("Errore durante il salvataggio: " . $e->getMessage(), 500);
}
