<?php
// ============================================================
// api/admin/ripristino_stagione.php
//
// Ripristino di una stagione da uno dei backup creati dal "Reset
// stagione" (tabelle BK_* e registro NEW_BACKUP_STAGIONE: vedi
// lib/BackupStagione.php).
//
// GET
//      Elenco dei backup (ultimi 50, tutte le stagioni): stagione, data,
//      autore, stato, righe salvate, se sono ripristinabili e perché no.
//
// GET  ?bk_id=3
//      Dettaglio di un backup: per ogni tabella le righe nel backup e
//      quelle oggi presenti per la stagione, avvisi, motivi di blocco,
//      frase di conferma. Non modifica nulla.
//
// POST { bk_id, utenza, password, conferma }
//      - utenza/password: amministratore, verificato lato server su
//        NEW_UTENZE della stagione o, se la stagione è stata svuotata,
//        sul backup stesso.
//      - conferma: frase da digitare, "RIPRISTINA <stagione>".
//      In sequenza:
//        1. se la stagione contiene già dati (es. è stata reinizializzata
//           dopo il reset) viene creato un backup di sicurezza di quei
//           dati (stato PRE_RIPRISTINO, a sua volta ripristinabile: è
//           l'"annulla" del ripristino) e poi i dati vengono cancellati.
//           Se il backup di sicurezza fallisce non viene toccato nulla;
//        2. le righe del backup sono rimesse nelle tabelle originali,
//           ognuna verificata (righe inserite = righe del backup),
//           NEW_SQUADRE per ultima.
//
// BLOCCHI
//   - backup non completo (BACKUP_IN_CORSO) o vuoto;
//   - esistono stagioni più recenti: si ripristina solo la più recente;
//   - le righe di una BK_* non corrispondono a quanto registrato al
//     momento del backup (backup alterato).
//
// NOTE
//   - Colonne presenti nel backup ma non più nella tabella originale
//     sono ignorate (avviso); colonne nuove ricevono il valore di default.
//   - Dopo un ripristino i reset interrotti (RESET_PARZIALE) della stagione
//     passano a RESET_ANNULLATO, così un nuovo reset rifà il backup.
//   - Se il ripristino si interrompe, rilanciarlo: i dati parziali
//     vengono messi in un backup di sicurezza e sostituiti.
//   - Stesso lock applicativo di chiusura/riapertura giornata.
//
// Risposta POST:
//   { ok, stagione, bk_id, bk_sicurezza, passi: [{codice, etichetta, ok, dettaglio?, errore?}],
//     tabelle: [{tabella, descrizione, righe_ripristinate}], avvisi: [], errore }
// ============================================================
require_once __DIR__ . "/../connect.php";
require_once __DIR__ . "/../lib/BackupStagione.php";

/**
 * Analisi di un backup in vista del ripristino (usata da GET e, dentro
 * il lock, da POST). $bk è la riga di bks_leggi_backup().
 */
function ripr_analizza(array $bk): array
{
    $bkId     = $bk["bk_id"];
    $stagione = $bk["stagione"];
    $salvate  = $bk["dettaglio"]["righe_backup"] ?? [];
    $colonneBk = $bk["dettaglio"]["colonne_backup"] ?? [];

    $blocchi = [];
    $avvisi  = [];
    $tabelle = [];
    $totBk   = 0;

    foreach (bks_tabelle_backup() as $tab => $descr) {
        $presente  = bks_tabella_con_stagione($tab);
        $righeBk   = bks_righe_backup($tab, $bkId);
        $righeOra  = $presente ? bks_righe_stagione($tab, $stagione) : 0;
        $totBk    += $righeBk;
        $tabelle[] = [
            "tabella"      => $tab,
            "descrizione"  => $descr,
            "presente"     => $presente,
            "righe_backup" => $righeBk,
            "righe_attuali" => $righeOra,
        ];

        // Integrità: quanto c'è oggi nel backup = quanto registrato allora
        if (isset($salvate[$tab]) && (int) $salvate[$tab] !== $righeBk) {
            $blocchi[] = "Backup alterato: $tab contiene $righeBk righe invece di " . (int) $salvate[$tab];
        }
        if ($righeBk > 0 && !$presente) {
            $avvisi[] = "$tab non esiste più sul database: le sue $righeBk righe non possono essere ripristinate";
        }
        if ($righeBk > 0 && $presente && bks_tabella_esiste(BKS_PREFISSO . $tab)) {
            [, , $ignorate] = bks_colonne_ripristino($GLOBALS["conn"], $tab, $colonneBk[$tab] ?? null);
            if ($ignorate) {
                $avvisi[] = "$tab: colonne non più presenti, ignorate nel ripristino (" . implode(", ", $ignorate) . ")";
            }
        }
    }

    if (!in_array($bk["stato"], bks_stati_ripristinabili(), true)) {
        $blocchi[] = "Il backup n. $bkId non è completo (stato " . $bk["stato"] . ") e non può essere ripristinato";
    }
    if ($totBk === 0) {
        $blocchi[] = "Il backup n. $bkId non contiene dati";
    }
    $ultima = bks_ultima_stagione();
    if ($ultima !== null && $ultima > $stagione) {
        $blocchi[] = "Esistono stagioni più recenti ($ultima): si può ripristinare solo la stagione più recente";
    }

    $righeOggi = bks_righe_totali($stagione);
    if ($righeOggi > 0) {
        $avvisi[] = "La stagione $stagione contiene già " . number_format($righeOggi, 0, ",", ".") . " righe: "
                  . "verranno sostituite dal backup. Prima viene creato un backup di sicurezza dei dati attuali, "
                  . "ripristinabile a sua volta";
    }

    return [
        "stagione"       => $stagione,
        "ripristinabile" => empty($blocchi),
        "motivi_blocco"  => $blocchi,
        "avvisi"         => $avvisi,
        "sostituisce"    => $righeOggi > 0,
        "righe_attuali"  => $righeOggi,
        "righe_backup"   => $totBk,
        "frase_conferma" => "RIPRISTINA $stagione",
        "tabelle"        => $tabelle,
    ];
}

/** Dati comuni di un backup per l'elenco/dettaglio. */
function ripr_scheda(array $bk): array
{
    $det = $bk["dettaglio"];
    return [
        "bk_id"         => $bk["bk_id"],
        "stagione"      => $bk["stagione"],
        "creato_il"     => $bk["creato_il"],
        "creato_da"     => $bk["creato_da"],
        "stato"         => $bk["stato"],
        "completato_il" => $bk["completato_il"],
        "righe_backup"  => array_sum($det["righe_backup"] ?? []),
        "tabelle_backup" => count($det["righe_backup"] ?? []),
        "ripristini"    => $det["ripristini"] ?? [],
    ];
}

// ============================================================
// GET
// ============================================================
if ($_SERVER["REQUEST_METHOD"] === "GET") {
    $bkId = param_int("bk_id");

    // ---- dettaglio ----
    if ($bkId) {
        $bk = bks_leggi_backup($bkId);
        if (!$bk) api_error("Backup n. $bkId non trovato", 404);
        api_success(array_merge(["backup" => ripr_scheda($bk)], ripr_analizza($bk)));
    }

    // ---- elenco ----
    $out = [];
    if (bks_tabella_esiste(BKS_REGISTRO)) {
        $ultima = bks_ultima_stagione();
        $ids = query_all("SELECT bk_id FROM " . BKS_REGISTRO . " ORDER BY bk_id DESC LIMIT 50");
        foreach ($ids as $i) {
            $bk = bks_leggi_backup((int) $i["bk_id"]);
            $s  = ripr_scheda($bk);
            $motivo = null;
            if (!in_array($bk["stato"], bks_stati_ripristinabili(), true)) {
                $motivo = "backup non completo";
            } elseif ($s["righe_backup"] === 0) {
                $motivo = "backup vuoto";
            } elseif ($ultima !== null && $ultima > $bk["stagione"]) {
                $motivo = "esiste una stagione più recente ($ultima)";
            }
            $s["ripristinabile"] = $motivo === null;
            $s["motivo_blocco"]  = $motivo;
            $out[] = $s;
        }
    }
    api_success(["backup" => $out, "ultima_stagione" => bks_ultima_stagione()]);
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    api_error("Metodo non consentito. Usare GET o POST.", 405);
}

// ============================================================
// POST - ripristino
// ============================================================
$input    = json_decode(file_get_contents("php://input"), true) ?? $_POST;
$bkId     = (int) ($input["bk_id"] ?? 0);
$utenza   = trim((string) ($input["utenza"] ?? ""));
$password = (string) ($input["password"] ?? "");
$conferma = strtoupper(trim((string) ($input["conferma"] ?? "")));

if ($bkId <= 0) api_error("Parametro 'bk_id' obbligatorio", 400);
$bk = bks_leggi_backup($bkId);
if (!$bk) api_error("Backup n. $bkId non trovato", 404);
$stagione = $bk["stagione"];

if ($conferma !== "RIPRISTINA $stagione") {
    api_error("Frase di conferma errata: digitare esattamente \"RIPRISTINA $stagione\"", 400);
}
if (!bks_verifica_admin($conn, $stagione, $utenza, $password, $bkId)) {
    api_error("Credenziali amministratore non valide", 403);
}
$utenzaEsc = mysqli_real_escape_string($conn, $utenza);

$rilascia = bks_prendi_lock($conn, $stagione);
if ($rilascia === null) {
    api_error("Un'altra operazione sulla stagione $stagione è già in corso", 409);
}

// Analisi rivalutata DENTRO il lock
$analisi = ripr_analizza($bk);
if (!$analisi["ripristinabile"]) {
    $rilascia();
    api_error(implode(". ", $analisi["motivi_blocco"]), 409);
}

ignore_user_abort(true);
@set_time_limit(300);

$passi     = [];
$avvisi    = $analisi["avvisi"];
$bkSicurezza = null;
$righeRip  = [];

$esito = function (?string $errore) use (&$passi, &$bkSicurezza, &$righeRip, $conn, $stagione, $bkId, $rilascia, &$avvisi) {
    $rilascia();
    $tabelleOut = [];
    foreach (bks_tabelle_backup() as $tab => $descr) {
        if (isset($righeRip[$tab])) {
            $tabelleOut[] = ["tabella" => $tab, "descrizione" => $descr, "righe_ripristinate" => $righeRip[$tab]];
        }
    }
    api_success([
        "ok"           => $errore === null,
        "stagione"     => $stagione,
        "bk_id"        => $bkId,
        "bk_sicurezza" => $bkSicurezza,
        "errore"       => $errore,
        "passi"        => $passi,
        "tabelle"      => $tabelleOut,
        "avvisi"       => $avvisi,
    ]);
};

// ---------------- Passo 1: sicurezza e svuotamento ----------------
if ($analisi["sostituisce"]) {
    try {
        [$bkSicurezza, $righeSic] = bks_crea_backup($conn, $stagione, $utenzaEsc, "PRE_RIPRISTINO");
        $passi[] = ["codice" => "sicurezza", "etichetta" => "Backup di sicurezza dei dati attuali", "ok" => true,
                    "dettaglio" => array_sum($righeSic) . " righe salvate (backup n. $bkSicurezza)"];
    } catch (Throwable $e) {
        $passi[] = ["codice" => "sicurezza", "etichetta" => "Backup di sicurezza dei dati attuali", "ok" => false,
                    "errore" => $e->getMessage()];
        $esito("Backup di sicurezza non riuscito: " . $e->getMessage() . " — nessun dato è stato modificato.");
    }

    [$righeDel, $errDel] = bks_cancella_stagione($conn, $stagione);
    if ($errDel !== null) {
        $passi[] = ["codice" => "svuotamento", "etichetta" => "Svuotamento stagione", "ok" => false, "errore" => $errDel];
        $esito("Svuotamento non riuscito: $errDel — i dati attuali sono nel backup n. $bkSicurezza; "
             . "rilanciare il ripristino per completare.");
    }
    $passi[] = ["codice" => "svuotamento", "etichetta" => "Svuotamento stagione", "ok" => true,
                "dettaglio" => array_sum($righeDel) . " righe eliminate"];
}

// ---------------- Passo 2: ripristino ----------------
$errore = null;
foreach (bks_ordine_ripristino() as $tab => $descr) {
    if (!bks_tabella_con_stagione($tab)) continue;
    if (bks_righe_backup($tab, $bkId) === 0) continue;
    try {
        $righeRip[$tab] = bks_ripristina_tabella($conn, $tab, $bkId, $stagione,
                              $bk["dettaglio"]["colonne_backup"][$tab] ?? null);
    } catch (Throwable $e) {
        $errore = $e->getMessage();
        break;
    }
}

if ($errore !== null) {
    $passi[] = ["codice" => "ripristino", "etichetta" => "Ripristino dati", "ok" => false, "errore" => $errore];
    $esito("Ripristino interrotto: $errore — il backup n. $bkId è intatto: rilanciare il ripristino "
         . "(i dati parziali vengono sostituiti).");
}

$passi[] = ["codice" => "ripristino", "etichetta" => "Ripristino dati", "ok" => true,
            "dettaglio" => array_sum($righeRip) . " righe ripristinate in " . count($righeRip) . " tabelle"];

// ---------------- Registro ----------------
$ripristini = $bk["dettaglio"]["ripristini"] ?? [];
$ripristini[] = ["data" => date("Y-m-d H:i:s"), "da" => $utenza, "bk_sicurezza" => $bkSicurezza];
bks_aggiorna_dettaglio($conn, $bkId, ["ripristini" => $ripristini]);
// I reset rimasti a metà non hanno più senso: un nuovo reset rifarà il backup
bks_esegui($conn, "UPDATE " . BKS_REGISTRO . " SET stato = 'RESET_ANNULLATO'
                   WHERE stagione = $stagione AND stato = 'RESET_PARZIALE'");

$esito(null);
