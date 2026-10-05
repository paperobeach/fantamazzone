<?php
// ============================================================
// api/admin/reset_stagione.php
//
// Reset della stagione: elimina TUTTI i dati della stagione indicata
// per ripartire da zero, dopo aver salvato in tabelle di backup i dati
// più onerosi da inserire lato utente.
//
// GET  ?stagione=2026
//      Stato del reset: stagione resettabile o meno (con i motivi di
//      blocco), numero di righe per tabella (divise tra "salvate nel
//      backup" e "solo cancellate"), avvisi, backup già eseguiti e
//      eventuale reset rimasto a metà. Non modifica nulla.
//
// POST { stagione, utenza, password, conferma }
//      - utenza/password: credenziali di un amministratore della
//        stagione (NEW_UTENZE.amministratore = 'Y'; se il reset è stato
//        interrotto dopo la cancellazione delle utenze, vengono
//        verificate sul backup di quel reset). Gli altri endpoint
//        admin si affidano al controllo lato frontend; per
//        un'operazione distruttiva le credenziali sono invece
//        ricontrollate qui, lato server.
//      - conferma: frase da digitare, "RESET <stagione>" (es. "RESET 2026").
//      In sequenza:
//        1. backup: per ogni tabella del gruppo "backup" le righe della
//           stagione sono copiate nella tabella BK_<nome> (es.
//           BK_NEW_SQUADRE) con un identificativo di backup (bk_id) e
//           la data. Il backup è verificato (righe copiate = righe
//           sorgente) PRIMA di cancellare qualsiasi cosa: se fallisce,
//           il backup parziale viene rimosso e nessun dato è toccato.
//        2. cancellazione: tutte le righe della stagione, prima dalle
//           tabelle derivate/rigenerabili e dalle tabelle "backup",
//           per ultima NEW_SQUADRE (da cui dipende l'elenco delle
//           stagioni mostrato dall'app).
//
// GRUPPI DI TABELLE
//   backup  (copiate in BK_* e poi cancellate): squadre, allenatori e
//           utenze, rose (giocatori, base asta, svincolati), calendario
//           e voti Serie A (import Excel), parametri e regole di
//           calcolo, configurazione di sistema, penalità, formazioni.
//   derivati (solo cancellate, si rigenerano): voti, risultati,
//           classifiche, statistiche, Top/Flop 11, calendari lega e
//           Champions, simulazioni LIVE, schedine, messaggi, accessi, ecc.
//   NON toccate: NEW_NAZIONI (anagrafica senza stagione) e le tabelle
//           di backup stesse.
//
// TABELLE DI BACKUP
//   - NEW_BACKUP_STAGIONE: registro dei backup (Script DB/NEW_BACKUP_STAGIONE.sql;
//     creata automaticamente se assente).
//   - BK_<tabella>: create automaticamente al primo backup, con le
//     stesse colonne della sorgente (nessuna chiave: più backup della
//     stessa stagione possono convivere) + bk_id + bk_data. Se la
//     tabella sorgente cambia struttura, le colonne mancanti vengono
//     aggiunte al backup.
//   Servono quindi i privilegi CREATE/ALTER sul database.
//   NB: il backup di NEW_UTENZE contiene le password come sono su DB.
//
// SICUREZZA / ROBUSTEZZA
//   - Si può resettare solo la stagione più recente.
//   - Stesso lock applicativo di chiusura/riapertura giornata.
//   - Tabelle MyISAM (niente rollback) e DDL che confermano
//     implicitamente: niente transazioni. Se la cancellazione si
//     interrompe, il backup resta e il registro lo segna come
//     RESET_PARZIALE: rilanciando il reset NON viene fatto un nuovo
//     backup (sarebbe di dati già in parte cancellati) ma si riprende
//     la cancellazione usando quello esistente.
//   - Le tabelle non presenti sul database (o senza colonna stagione)
//     vengono ignorate e segnalate come "non presente".
//
// Risposta POST:
//   { ok, stagione, bk_id, passi: [{codice, etichetta, ok, dettaglio?, errore?}],
//     tabelle: [{tabella, descrizione, gruppo, righe_backup, righe_cancellate}],
//     avvisi: [] }     ok=false => un passo è fallito (vedi passi[].errore)
// ============================================================
require_once __DIR__ . "/../connect.php";

const RESET_PREFISSO_BACKUP  = "BK_";
const RESET_TABELLA_REGISTRO = "NEW_BACKUP_STAGIONE";

// ------------------------------------------------------------
// Elenco tabelle
// ------------------------------------------------------------

/** Tabelle copiate in backup e poi cancellate (ordine di visualizzazione/backup). */
function reset_tabelle_backup(): array
{
    return [
        "NEW_SQUADRE"               => "Squadre",
        "NEW_ALLENATORI"            => "Allenatori",
        "NEW_UTENZE"                => "Utenze e credenziali",
        "NEW_GIOCATORI"             => "Rose (giocatori in squadra)",
        "NEW_GIOCATORI_BASE_ASTA"   => "Rose (base asta)",
        "NEW_GIOCATORI_SVINCOLATI"  => "Giocatori svincolati",
        "NEW_CALENDARIO_SERIE_A"    => "Calendario Serie A importato",
        "NEW_VOTI_SERIE_A"          => "Voti Serie A importati",
        "NEW_PARAMETRI_STAGIONE"    => "Parametri di stagione",
        "NEW_REGOLE_BONUS"          => "Regole bonus/malus",
        "NEW_REGOLE_ALGORITMI"      => "Regole algoritmi modificatori",
        "NEW_SISTEMA"               => "Configurazione di sistema",
        "NEW_PENALITA"              => "Penalità",
        "NEW_FORMAZIONI"            => "Formazioni inserite",
        "NEW_FORMAZIONI_CK"         => "Conferme formazioni",
    ];
}

/** Tabelle solo cancellate (dati derivati o rigenerabili). */
function reset_tabelle_derivate(): array
{
    return [
        "NEW_VOTI"                  => "Voti dei giocatori",
        "NEW_RISULTATI"             => "Risultati partite",
        "NEW_RISULTATI_CHAMP"       => "Risultati Champions",
        "NEW_GENERALE"              => "Classifica generale",
        "NEW_GENERALE_CHAMP"        => "Classifica Champions",
        "NEW_STATISTICHE"           => "Statistiche giocatori",
        "NEW_TOP11"                 => "Top 11",
        "NEW_FLOP11"                => "Flop 11",
        "NEW_STAT_RANGE"            => "Statistiche per fasce (giornata)",
        "NEW_KULOVIC"               => "Statistiche per fasce (stagione)",
        "NEW_CALENDARIO"            => "Calendario di campionato",
        "NEW_CALENDARIO_CHAMP"      => "Calendario Champions",
        "NEW_CALENDARIO_CK"         => "Giornate chiuse",
        "NEW_SIMULAZIONE_VOTI"      => "Simulazione LIVE: voti",
        "NEW_SIMULAZIONE_RISULTATI" => "Simulazione LIVE: risultati",
        "NEW_SIMULAZIONE_EDIT"      => "Simulazione LIVE: modifiche manuali",
        "NEW_SCHEDINA"              => "Schedina",
        "NEW_SCHEDINA_CK"           => "Schedina: conferme",
        "NEW_SCHEDINA_CLASSIFICA"   => "Schedina: classifica",
        "NEW_SCHEDINA_RISULTATI"    => "Schedina: risultati",
        "NEW_NOTE_CHAMPIONS"        => "Note Champions",
        "NEW_MESSAGGI"              => "Messaggi",
        "NEW_ACCESSI"               => "Registro accessi",
    ];
}

// ------------------------------------------------------------
// Utilità DB
// ------------------------------------------------------------

/** Esegue una query e, se fallisce, solleva un'eccezione. */
function reset_esegui($conn, string $sql)
{
    $ok = mysqli_query($conn, $sql);
    if ($ok === false) {
        throw new RuntimeException(mysqli_error($conn));
    }
    return $ok;
}

/** La tabella esiste nel database corrente? */
function reset_tabella_esiste(string $tab): bool
{
    $r = query_one("SELECT COUNT(*) AS n FROM information_schema.tables
                    WHERE table_schema = DATABASE() AND table_name = '$tab'");
    return $r && (int) $r["n"] > 0;
}

/** La tabella esiste ed ha la colonna "stagione" (qualsiasi maiuscole/minuscole)? */
function reset_tabella_con_stagione(string $tab): bool
{
    if (!reset_tabella_esiste($tab)) return false;
    $r = query_one("SELECT COUNT(*) AS n FROM information_schema.columns
                    WHERE table_schema = DATABASE() AND table_name = '$tab'
                      AND LOWER(column_name) = 'stagione'");
    return $r && (int) $r["n"] > 0;
}

/** Colonne di una tabella: [nome => tipo]. */
function reset_colonne($conn, string $tab): array
{
    $res = reset_esegui($conn, "SHOW COLUMNS FROM `$tab`");
    $out = [];
    while ($r = mysqli_fetch_assoc($res)) {
        $out[$r["Field"]] = $r["Type"];
    }
    return $out;
}

/** Nomi degli indici di una tabella (distinti). */
function reset_indici($conn, string $tab): array
{
    $res = reset_esegui($conn, "SHOW INDEX FROM `$tab`");
    $out = [];
    while ($r = mysqli_fetch_assoc($res)) {
        $out[$r["Key_name"]] = true;
    }
    return array_keys($out);
}

function reset_righe_stagione(string $tab, int $stagione): int
{
    $r = query_one("SELECT COUNT(*) AS n FROM `$tab` WHERE stagione = $stagione");
    return (int) ($r["n"] ?? 0);
}

// ------------------------------------------------------------
// Registro dei backup
// ------------------------------------------------------------
function reset_assicura_registro($conn): void
{
    reset_esegui($conn, "CREATE TABLE IF NOT EXISTS `" . RESET_TABELLA_REGISTRO . "` (
        `bk_id`        int          NOT NULL AUTO_INCREMENT,
        `stagione`     int          NOT NULL,
        `creato_il`    datetime     NOT NULL,
        `creato_da`    varchar(50)  NOT NULL DEFAULT '',
        `stato`        varchar(20)  NOT NULL,
        `completato_il` datetime    NULL,
        `dettaglio`    text         NULL,
        PRIMARY KEY (`bk_id`),
        KEY `idx_stagione` (`stagione`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function reset_elenco_backup(int $stagione): array
{
    if (!reset_tabella_esiste(RESET_TABELLA_REGISTRO)) return [];
    $rows = query_all("SELECT bk_id, creato_il, creato_da, stato, completato_il, dettaglio
                       FROM " . RESET_TABELLA_REGISTRO . "
                       WHERE stagione = $stagione ORDER BY bk_id DESC LIMIT 10");
    foreach ($rows as &$r) {
        $det = json_decode((string) $r["dettaglio"], true);
        $r["bk_id"]       = (int) $r["bk_id"];
        $r["righe_backup"] = is_array($det) ? array_sum($det["righe_backup"] ?? []) : 0;
        unset($r["dettaglio"]);
    }
    unset($r);
    return $rows;
}

// ------------------------------------------------------------
// Stato del reset
// ------------------------------------------------------------
function reset_stato($conn, int $stagione): array
{
    $tabelle = [];
    $totale  = 0;
    foreach ([["backup", reset_tabelle_backup()], ["derivati", reset_tabelle_derivate()]] as [$gruppo, $elenco]) {
        foreach ($elenco as $tab => $descr) {
            $presente = reset_tabella_con_stagione($tab);
            $righe    = $presente ? reset_righe_stagione($tab, $stagione) : 0;
            $totale  += $righe;
            $tabelle[] = [
                "tabella"     => $tab,
                "descrizione" => $descr,
                "gruppo"      => $gruppo,
                "presente"    => $presente,
                "righe"       => $righe,
            ];
        }
    }

    $blocchi = [];
    $avvisi  = [];

    $u = query_one("SELECT MAX(stagione) AS s FROM NEW_SQUADRE");
    $ultima = ($u && $u["s"] !== null) ? (int) $u["s"] : null;

    if ($totale === 0) {
        $blocchi[] = "Per la stagione $stagione non ci sono dati da eliminare";
    }
    if ($ultima !== null && $stagione < $ultima) {
        $blocchi[] = "Si può resettare solo la stagione più recente ($ultima): la $stagione è una stagione passata";
    }

    $chiuse = 0;
    if (reset_tabella_con_stagione("NEW_CALENDARIO_CK")) {
        $r = query_one("SELECT COUNT(*) AS n FROM NEW_CALENDARIO_CK WHERE stagione = $stagione AND ck_giocata = 'S'");
        $chiuse = (int) ($r["n"] ?? 0);
    }
    if ($chiuse > 0) {
        $avvisi[] = "La stagione ha $chiuse giornate già chiuse: classifiche, risultati e statistiche andranno persi";
    }

    $parziale = null;
    if (reset_tabella_esiste(RESET_TABELLA_REGISTRO)) {
        $p = query_one("SELECT bk_id FROM " . RESET_TABELLA_REGISTRO . "
                        WHERE stagione = $stagione AND stato = 'RESET_PARZIALE'
                        ORDER BY bk_id DESC LIMIT 1");
        $parziale = $p ? (int) $p["bk_id"] : null;
    }
    if ($parziale !== null) {
        $avvisi[] = "Un reset precedente si è interrotto a metà (backup n. $parziale): rilanciandolo "
                  . "si riprende la cancellazione senza rifare il backup";
    }

    return [
        "stagione"        => $stagione,
        "ultima_stagione" => $ultima,
        "resettabile"     => empty($blocchi),
        "motivi_blocco"   => $blocchi,
        "avvisi"          => $avvisi,
        "frase_conferma"  => "RESET $stagione",
        "tabelle"         => $tabelle,
        "totale_righe"    => $totale,
        "reset_parziale"  => $parziale,
        "backup"          => reset_elenco_backup($stagione),
    ];
}

// ------------------------------------------------------------
// Backup
// ------------------------------------------------------------

/**
 * Garantisce l'esistenza di BK_<tabella> con tutte le colonne della
 * sorgente + bk_id + bk_data. Senza chiavi, così più backup della
 * stessa stagione possono coesistere.
 */
function reset_assicura_tabella_backup($conn, string $tab, string $bk): void
{
    if (!reset_tabella_esiste($bk)) {
        try {
            reset_esegui($conn, "CREATE TABLE `$bk` ENGINE=InnoDB AS SELECT * FROM `$tab` WHERE 1 = 0");
        } catch (Throwable $e) {
            // Alcune configurazioni (es. GTID) vietano CREATE ... SELECT:
            // si copia la struttura e si rimuovono chiavi e indici.
            if (!reset_tabella_esiste($bk)) {
                reset_esegui($conn, "CREATE TABLE `$bk` LIKE `$tab`");
            }
            foreach (reset_indici($conn, $bk) as $idx) {
                if ($idx === "PRIMARY") {
                    reset_esegui($conn, "ALTER TABLE `$bk` DROP PRIMARY KEY");
                } else {
                    reset_esegui($conn, "ALTER TABLE `$bk` DROP INDEX `$idx`");
                }
            }
        }
    }

    // Allineamento colonne (la sorgente può aver acquisito colonne dopo
    // il primo backup, es. NEW_ALLENATORI.email).
    $colSrc = reset_colonne($conn, $tab);
    $colBk  = array_change_key_case(reset_colonne($conn, $bk), CASE_LOWER);
    foreach ($colSrc as $nome => $tipo) {
        if (!isset($colBk[strtolower($nome)])) {
            reset_esegui($conn, "ALTER TABLE `$bk` ADD COLUMN `$nome` $tipo NULL");
        }
    }
    if (!isset($colBk["bk_id"])) {
        reset_esegui($conn, "ALTER TABLE `$bk` ADD COLUMN `bk_id` int NOT NULL DEFAULT 0");
    }
    if (!isset($colBk["bk_data"])) {
        reset_esegui($conn, "ALTER TABLE `$bk` ADD COLUMN `bk_data` datetime NULL");
    }
    if (!in_array("idx_bk_id", reset_indici($conn, $bk), true)) {
        reset_esegui($conn, "ALTER TABLE `$bk` ADD INDEX `idx_bk_id` (`bk_id`)");
    }
}

/** Copia le righe della stagione in BK_<tabella> e verifica il conteggio. */
function reset_backup_tabella($conn, string $tab, int $bkId, int $stagione): int
{
    $bk = RESET_PREFISSO_BACKUP . $tab;
    reset_assicura_tabella_backup($conn, $tab, $bk);

    $lista = implode(", ", array_map(function ($c) { return "`$c`"; }, array_keys(reset_colonne($conn, $tab))));

    $attese = reset_righe_stagione($tab, $stagione);
    reset_esegui($conn, "INSERT INTO `$bk` ($lista, `bk_id`, `bk_data`)
                         SELECT $lista, $bkId, NOW() FROM `$tab` WHERE stagione = $stagione");

    $r = query_one("SELECT COUNT(*) AS n FROM `$bk` WHERE bk_id = $bkId");
    $copiate = (int) ($r["n"] ?? 0);
    if ($copiate !== $attese) {
        throw new RuntimeException("$tab: copiate $copiate righe su $attese");
    }
    return $copiate;
}

/** Rimuove un backup incompleto (righe nelle BK_* e voce di registro). */
function reset_scarta_backup($conn, int $bkId): void
{
    foreach (array_keys(reset_tabelle_backup()) as $tab) {
        $bk = RESET_PREFISSO_BACKUP . $tab;
        if (reset_tabella_esiste($bk)) {
            mysqli_query($conn, "DELETE FROM `$bk` WHERE bk_id = $bkId");
        }
    }
    mysqli_query($conn, "DELETE FROM " . RESET_TABELLA_REGISTRO . " WHERE bk_id = $bkId");
}

// ============================================================
// GET
// ============================================================
if ($_SERVER["REQUEST_METHOD"] === "GET") {
    $stagione = param_int("stagione");
    if (!$stagione) api_error("Parametro obbligatorio mancante: stagione", 400);
    api_success(reset_stato($conn, $stagione));
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    api_error("Metodo non consentito. Usare GET o POST.", 405);
}

// ============================================================
// POST - reset
// ============================================================
$input    = json_decode(file_get_contents("php://input"), true) ?? $_POST;
$stagione = (int) ($input["stagione"] ?? 0);
$utenza   = trim((string) ($input["utenza"] ?? ""));
$password = (string) ($input["password"] ?? "");
$conferma = strtoupper(trim((string) ($input["conferma"] ?? "")));

if ($stagione < 2000 || $stagione > 2100) {
    api_error("Parametro 'stagione' obbligatorio e deve essere un anno a 4 cifre plausibile (2000-2100)", 400);
}
if ($conferma !== "RESET $stagione") {
    api_error("Frase di conferma errata: digitare esattamente \"RESET $stagione\"", 400);
}

// Credenziali amministratore, verificate lato server
$utenzaEsc = mysqli_real_escape_string($conn, $utenza);
$admin = null;
if ($utenza !== "" && $password !== "") {
    $admin = query_one("SELECT PASSWORD, amministratore FROM NEW_UTENZE
                        WHERE stagione = $stagione AND utenza = '$utenzaEsc' LIMIT 1");
    // Reset interrotto a metà: NEW_UTENZE può essere già stata svuotata.
    // In quel caso le credenziali si verificano sul backup di quel reset
    // (BK_NEW_UTENZE), altrimenti non sarebbe più possibile completarlo.
    if (!$admin && reset_tabella_esiste(RESET_TABELLA_REGISTRO) && reset_tabella_esiste(RESET_PREFISSO_BACKUP . "NEW_UTENZE")) {
        $p = query_one("SELECT bk_id FROM " . RESET_TABELLA_REGISTRO . "
                        WHERE stagione = $stagione AND stato = 'RESET_PARZIALE'
                        ORDER BY bk_id DESC LIMIT 1");
        if ($p) {
            $admin = query_one("SELECT PASSWORD, amministratore FROM " . RESET_PREFISSO_BACKUP . "NEW_UTENZE
                                WHERE bk_id = " . (int) $p["bk_id"] . " AND stagione = $stagione
                                  AND utenza = '$utenzaEsc' LIMIT 1");
        }
    }
}
if (!$admin
    || !hash_equals((string) $admin["PASSWORD"], $password)
    || strtoupper(trim((string) $admin["amministratore"])) !== "Y") {
    api_error("Credenziali amministratore non valide", 403);
}

// Stesso lock di chiusura/riapertura: una sola operazione alla volta
$lockName = "fantamazzone_chiusura_$stagione";
$lockRes  = mysqli_query($conn, "SELECT GET_LOCK('$lockName', 0) AS l");
$lockRow  = $lockRes ? mysqli_fetch_assoc($lockRes) : null;
if (!$lockRow || (int) $lockRow["l"] !== 1) {
    api_error("Un'altra operazione sulla stagione $stagione è già in corso", 409);
}
$rilascia = function () use ($conn, $lockName) {
    mysqli_query($conn, "SELECT RELEASE_LOCK('$lockName')");
};

// Stato rivalutato DENTRO il lock
$stato = reset_stato($conn, $stagione);
if (!$stato["resettabile"]) {
    $rilascia();
    api_error(implode(". ", $stato["motivi_blocco"]), 409);
}

// Un'operazione interrotta a metà lascerebbe il reset incompleto
ignore_user_abort(true);
@set_time_limit(300);

$passi    = [];
$avvisi   = $stato["avvisi"];
$bkId     = $stato["reset_parziale"];     // se presente si riprende da quel backup
$righeBk  = [];                           // tabella => righe salvate
$righeDel = [];                           // tabella => righe cancellate
$errore   = null;

// ---------------- Passo 1: backup ----------------
if ($bkId === null) {
    try {
        reset_assicura_registro($conn);
        reset_esegui($conn, "INSERT INTO " . RESET_TABELLA_REGISTRO . " (stagione, creato_il, creato_da, stato)
                             VALUES ($stagione, NOW(), '$utenzaEsc', 'BACKUP_IN_CORSO')");
        $bkId = (int) mysqli_insert_id($conn);

        foreach (reset_tabelle_backup() as $tab => $descr) {
            if (!reset_tabella_con_stagione($tab)) continue;
            $righeBk[$tab] = reset_backup_tabella($conn, $tab, $bkId, $stagione);
        }

        $det = mysqli_real_escape_string($conn, json_encode(["righe_backup" => $righeBk], JSON_UNESCAPED_UNICODE));
        reset_esegui($conn, "UPDATE " . RESET_TABELLA_REGISTRO . "
                             SET stato = 'RESET_PARZIALE', dettaglio = '$det' WHERE bk_id = $bkId");
        $passi[] = ["codice" => "backup", "etichetta" => "Backup dati", "ok" => true,
                    "dettaglio" => array_sum($righeBk) . " righe salvate in " . count($righeBk) . " tabelle (backup n. $bkId)"];
    } catch (Throwable $e) {
        if ($bkId) reset_scarta_backup($conn, $bkId);
        $passi[] = ["codice" => "backup", "etichetta" => "Backup dati", "ok" => false, "errore" => $e->getMessage()];
        $rilascia();
        api_success([
            "ok"      => false,
            "stagione" => $stagione,
            "bk_id"   => null,
            "errore"  => "Backup non riuscito: " . $e->getMessage() . " — nessun dato è stato cancellato.",
            "passi"   => $passi,
            "tabelle" => [],
            "avvisi"  => $avvisi,
        ]);
    }
} else {
    // Ripresa: si rilegge cosa era stato salvato
    $r   = query_one("SELECT dettaglio FROM " . RESET_TABELLA_REGISTRO . " WHERE bk_id = $bkId");
    $det = json_decode((string) ($r["dettaglio"] ?? ""), true);
    $righeBk = is_array($det) ? ($det["righe_backup"] ?? []) : [];
    $passi[] = ["codice" => "backup", "etichetta" => "Backup dati", "ok" => true,
                "dettaglio" => "già eseguito in precedenza (backup n. $bkId): non viene ripetuto"];
}

// ---------------- Passo 2: cancellazione ----------------
// Prima derivate e tabelle di backup (la più "centrale", NEW_SQUADRE, per ultima)
$ordine = [];
foreach (reset_tabelle_derivate() as $tab => $descr) $ordine[$tab] = $descr;
foreach (array_reverse(reset_tabelle_backup(), true) as $tab => $descr) $ordine[$tab] = $descr;

foreach ($ordine as $tab => $descr) {
    if (!reset_tabella_con_stagione($tab)) continue;
    $ok = mysqli_query($conn, "DELETE FROM `$tab` WHERE stagione = $stagione");
    if ($ok === false) {
        $errore = "$tab: " . mysqli_error($conn);
        break;
    }
    $righeDel[$tab] = (int) mysqli_affected_rows($conn);
}

if ($errore !== null) {
    $passi[] = ["codice" => "cancellazione", "etichetta" => "Cancellazione dati", "ok" => false, "errore" => $errore];
} else {
    $passi[] = ["codice" => "cancellazione", "etichetta" => "Cancellazione dati", "ok" => true,
                "dettaglio" => array_sum($righeDel) . " righe eliminate da " . count($righeDel) . " tabelle"];
    $detFinale = mysqli_real_escape_string($conn, json_encode(
        ["righe_backup" => $righeBk, "righe_cancellate" => $righeDel], JSON_UNESCAPED_UNICODE));
    mysqli_query($conn, "UPDATE " . RESET_TABELLA_REGISTRO . "
                         SET stato = 'RESET_COMPLETATO', completato_il = NOW(), dettaglio = '$detFinale'
                         WHERE bk_id = $bkId");
}

$rilascia();

$tabelleOut = [];
foreach (reset_tabelle_backup() as $tab => $descr) {
    if (isset($righeBk[$tab]) || isset($righeDel[$tab])) {
        $tabelleOut[] = ["tabella" => $tab, "descrizione" => $descr, "gruppo" => "backup",
                         "righe_backup" => $righeBk[$tab] ?? 0, "righe_cancellate" => $righeDel[$tab] ?? 0];
    }
}
foreach (reset_tabelle_derivate() as $tab => $descr) {
    if (isset($righeDel[$tab])) {
        $tabelleOut[] = ["tabella" => $tab, "descrizione" => $descr, "gruppo" => "derivati",
                         "righe_backup" => 0, "righe_cancellate" => $righeDel[$tab]];
    }
}

api_success([
    "ok"       => $errore === null,
    "stagione" => $stagione,
    "bk_id"    => $bkId,
    "errore"   => $errore === null ? null
        : "$errore — il backup n. $bkId è al sicuro: rilanciare il reset per completare la cancellazione.",
    "passi"    => $passi,
    "tabelle"  => $tabelleOut,
    "avvisi"   => $avvisi,
]);
