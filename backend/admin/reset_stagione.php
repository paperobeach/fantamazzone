<?php
// ============================================================
// api/admin/reset_stagione.php
//
// Reset della stagione: elimina TUTTI i dati della stagione indicata
// per ripartire da zero, dopo averli salvati nelle tabelle di backup
// BK_* (vedi lib/BackupStagione.php per gruppi di tabelle, stati e
// funzionamento del backup). I backup si ripristinano da
// admin/ripristino_stagione.php.
//
// GET  ?stagione=2026
//      Stato del reset: stagione resettabile o meno (con i motivi di
//      blocco), numero di righe per tabella (gruppo "backup" = salvate
//      e poi cancellate, "non_salvate" = solo cancellate), avvisi,
//      backup già eseguiti e eventuale reset rimasto a metà. Non
//      modifica nulla.
//
// POST { stagione, utenza, password, conferma }
//      - utenza/password: credenziali di un amministratore della
//        stagione (NEW_UTENZE.amministratore = 'Y'; se il reset è stato
//        interrotto dopo la cancellazione delle utenze, vengono
//        verificate sul backup di quel reset). Gli altri endpoint admin
//        si affidano al controllo lato frontend; per un'operazione
//        distruttiva le credenziali sono invece ricontrollate qui.
//      - conferma: frase da digitare, "RESET <stagione>" (es. "RESET 2026").
//      In sequenza:
//        1. backup: copia delle righe della stagione nelle BK_*, con
//           verifica dei conteggi PRIMA di cancellare qualsiasi cosa: se
//           fallisce, il backup parziale viene rimosso e nessun dato è
//           toccato.
//        2. cancellazione di tutte le righe della stagione, per ultima
//           NEW_SQUADRE.
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
//
// Risposta POST:
//   { ok, stagione, bk_id, passi: [{codice, etichetta, ok, dettaglio?, errore?}],
//     tabelle: [{tabella, descrizione, gruppo, righe_backup, righe_cancellate}],
//     avvisi: [] }     ok=false => un passo è fallito (vedi passi[].errore)
// ============================================================
require_once __DIR__ . "/../connect.php";
require_once __DIR__ . "/../lib/BackupStagione.php";

/** Ultimi backup della stagione (per l'elenco nella pagina di reset). */
function reset_elenco_backup(int $stagione): array
{
    if (!bks_tabella_esiste(BKS_REGISTRO)) return [];
    $rows = query_all("SELECT bk_id, creato_il, creato_da, stato, completato_il, dettaglio
                       FROM " . BKS_REGISTRO . "
                       WHERE stagione = $stagione ORDER BY bk_id DESC LIMIT 10");
    foreach ($rows as &$r) {
        $det = json_decode((string) $r["dettaglio"], true);
        $r["bk_id"]        = (int) $r["bk_id"];
        $r["righe_backup"] = is_array($det) ? array_sum($det["righe_backup"] ?? []) : 0;
        unset($r["dettaglio"]);
    }
    unset($r);
    return $rows;
}

/** Stato del reset per la stagione. */
function reset_stato(int $stagione): array
{
    $tabelle = [];
    $totale  = 0;
    foreach ([["backup", bks_tabelle_backup()], ["non_salvate", bks_tabelle_non_salvate()]] as [$gruppo, $elenco]) {
        foreach ($elenco as $tab => $descr) {
            $presente = bks_tabella_con_stagione($tab);
            $righe    = $presente ? bks_righe_stagione($tab, $stagione) : 0;
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

    $ultima = bks_ultima_stagione();

    if ($totale === 0) {
        $blocchi[] = "Per la stagione $stagione non ci sono dati da eliminare";
    }
    if ($ultima !== null && $stagione < $ultima) {
        $blocchi[] = "Si può resettare solo la stagione più recente ($ultima): la $stagione è una stagione passata";
    }

    $chiuse = 0;
    if (bks_tabella_con_stagione("NEW_CALENDARIO_CK")) {
        $r = query_one("SELECT COUNT(*) AS n FROM NEW_CALENDARIO_CK WHERE stagione = $stagione AND ck_giocata = 'S'");
        $chiuse = (int) ($r["n"] ?? 0);
    }
    if ($chiuse > 0) {
        $avvisi[] = "La stagione ha $chiuse giornate già chiuse: risultati, classifiche e statistiche "
                  . "verranno salvati nel backup, ma spariranno dall'app finché non li ripristini";
    }

    $parziale = null;
    if (bks_tabella_esiste(BKS_REGISTRO)) {
        $p = query_one("SELECT bk_id FROM " . BKS_REGISTRO . "
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

// ============================================================
// GET
// ============================================================
if ($_SERVER["REQUEST_METHOD"] === "GET") {
    $stagione = param_int("stagione");
    if (!$stagione) api_error("Parametro obbligatorio mancante: stagione", 400);
    api_success(reset_stato($stagione));
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
if (!bks_verifica_admin($conn, $stagione, $utenza, $password)) {
    api_error("Credenziali amministratore non valide", 403);
}
$utenzaEsc = mysqli_real_escape_string($conn, $utenza);

// Una sola operazione alla volta sulla stagione
$rilascia = bks_prendi_lock($conn, $stagione);
if ($rilascia === null) {
    api_error("Un'altra operazione sulla stagione $stagione è già in corso", 409);
}

// Stato rivalutato DENTRO il lock
$stato = reset_stato($stagione);
if (!$stato["resettabile"]) {
    $rilascia();
    api_error(implode(". ", $stato["motivi_blocco"]), 409);
}

// Un'operazione interrotta a metà lascerebbe il reset incompleto
ignore_user_abort(true);
@set_time_limit(300);

$passi  = [];
$avvisi = $stato["avvisi"];
$bkId   = $stato["reset_parziale"];     // se presente si riprende da quel backup
$righeBk = [];                          // tabella => righe salvate

// ---------------- Passo 1: backup ----------------
if ($bkId === null) {
    try {
        [$bkId, $righeBk] = bks_crea_backup($conn, $stagione, $utenzaEsc, "RESET_PARZIALE");
        $passi[] = ["codice" => "backup", "etichetta" => "Backup dati", "ok" => true,
                    "dettaglio" => array_sum($righeBk) . " righe salvate in " . count($righeBk) . " tabelle (backup n. $bkId)"];
    } catch (Throwable $e) {
        $passi[] = ["codice" => "backup", "etichetta" => "Backup dati", "ok" => false, "errore" => $e->getMessage()];
        $rilascia();
        api_success([
            "ok"       => false,
            "stagione" => $stagione,
            "bk_id"    => null,
            "errore"   => "Backup non riuscito: " . $e->getMessage() . " — nessun dato è stato cancellato.",
            "passi"    => $passi,
            "tabelle"  => [],
            "avvisi"   => $avvisi,
        ]);
    }
} else {
    // Ripresa: si rilegge cosa era stato salvato
    $bk = bks_leggi_backup($bkId);
    $righeBk = $bk["dettaglio"]["righe_backup"] ?? [];
    $passi[] = ["codice" => "backup", "etichetta" => "Backup dati", "ok" => true,
                "dettaglio" => "già eseguito in precedenza (backup n. $bkId): non viene ripetuto"];
}

// ---------------- Passo 2: cancellazione ----------------
[$righeDel, $errore] = bks_cancella_stagione($conn, $stagione);

if ($errore !== null) {
    $passi[] = ["codice" => "cancellazione", "etichetta" => "Cancellazione dati", "ok" => false, "errore" => $errore];
} else {
    $passi[] = ["codice" => "cancellazione", "etichetta" => "Cancellazione dati", "ok" => true,
                "dettaglio" => array_sum($righeDel) . " righe eliminate da " . count($righeDel) . " tabelle"];
    bks_aggiorna_dettaglio($conn, $bkId, ["righe_cancellate" => $righeDel]);
    bks_imposta_stato($conn, $bkId, "RESET_COMPLETATO", true);
}

$rilascia();

$tabelleOut = [];
foreach (bks_tabelle_backup() as $tab => $descr) {
    if (isset($righeBk[$tab]) || isset($righeDel[$tab])) {
        $tabelleOut[] = ["tabella" => $tab, "descrizione" => $descr, "gruppo" => "backup",
                         "righe_backup" => $righeBk[$tab] ?? 0, "righe_cancellate" => $righeDel[$tab] ?? 0];
    }
}
foreach (bks_tabelle_non_salvate() as $tab => $descr) {
    if (isset($righeDel[$tab])) {
        $tabelleOut[] = ["tabella" => $tab, "descrizione" => $descr, "gruppo" => "non_salvate",
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
