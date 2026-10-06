<?php
// ============================================================
// lib/BackupStagione.php
//
// Funzioni condivise da admin/reset_stagione.php e
// admin/ripristino_stagione.php: elenco delle tabelle, backup in
// tabelle BK_*, cancellazione e ripristino di una stagione, verifica
// delle credenziali amministratore, lock applicativo.
//
// Va incluso DOPO connect.php (usa query_one/query_all).
//
// GRUPPI DI TABELLE
//   backup       Tabelle copiate in BK_<nome> prima di una
//                cancellazione e ripristinabili: quasi tutta la stagione
//                (squadre, utenze, rose, import Serie A, regole,
//                calendari, formazioni, voti, risultati, classifiche,
//                statistiche...), così il ripristino riporta la stagione
//                esattamente com'era.
//   non_salvate  Solo cancellate: simulazioni LIVE (temporanee) e
//                registro accessi.
//   Non toccate: NEW_NAZIONI (senza stagione), NEW_FORMAZIONI_BCK2026 e
//                ogni altra tabella fuori dagli elenchi, tabelle BK_* e
//                NEW_BACKUP_STAGIONE.
//   Le tabelle non presenti sul database (o senza colonna stagione)
//   sono ignorate.
//
// STATI NEL REGISTRO (NEW_BACKUP_STAGIONE.stato)
//   BACKUP_IN_CORSO   backup avviato e non terminato (non ripristinabile)
//   RESET_PARZIALE    backup completo, cancellazione interrotta
//   RESET_COMPLETATO  backup completo e stagione cancellata
//   RESET_ANNULLATO   reset interrotto poi annullato da un ripristino
//   PRE_RIPRISTINO    backup di sicurezza creato prima di un ripristino
//                     che ha sostituito dati esistenti (annulla il ripristino)
// ============================================================

const BKS_PREFISSO = "BK_";
const BKS_REGISTRO = "NEW_BACKUP_STAGIONE";

/** Stati dei backup completi, quindi ripristinabili. */
function bks_stati_ripristinabili(): array
{
    return ["RESET_COMPLETATO", "RESET_PARZIALE", "RESET_ANNULLATO", "PRE_RIPRISTINO"];
}

// ------------------------------------------------------------
// Elenco tabelle
// ------------------------------------------------------------

/**
 * Tabelle salvate in backup e poi cancellate; l'ordine è quello di
 * backup e di visualizzazione. NEW_SQUADRE è la prima: la cancellazione
 * (ordine inverso) la fa per ultima e il ripristino la rimette per ultima,
 * perché da lì dipende l'elenco delle stagioni mostrato dall'app.
 */
function bks_tabelle_backup(): array
{
    return [
        // anagrafica e configurazione
        "NEW_SQUADRE"               => "Squadre",
        "NEW_ALLENATORI"            => "Allenatori",
        "NEW_UTENZE"                => "Utenze e credenziali",
        "NEW_PARAMETRI_STAGIONE"    => "Parametri di stagione",
        "NEW_REGOLE_BONUS"          => "Regole bonus/malus",
        "NEW_REGOLE_ALGORITMI"      => "Regole algoritmi modificatori",
        "NEW_SISTEMA"               => "Configurazione di sistema",
        "NEW_PENALITA"              => "Penalità",
        // rose
        "NEW_GIOCATORI"             => "Rose (giocatori in squadra)",
        "NEW_GIOCATORI_BASE_ASTA"   => "Rose (base asta)",
        "NEW_GIOCATORI_SVINCOLATI"  => "Giocatori svincolati",
        // import Serie A
        "NEW_CALENDARIO_SERIE_A"    => "Calendario Serie A importato",
        "NEW_VOTI_SERIE_A"          => "Voti Serie A importati",
        // calendari della lega
        "NEW_CALENDARIO"            => "Calendario di campionato",
        "NEW_CALENDARIO_CHAMP"      => "Calendario Champions",
        "NEW_NOTE_CHAMPIONS"        => "Note Champions",
        "NEW_CALENDARIO_CK"         => "Giornate chiuse",
        // formazioni
        "NEW_FORMAZIONI"            => "Formazioni inserite",
        "NEW_FORMAZIONI_CK"         => "Conferme formazioni",
        // risultati delle giornate chiuse
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
        // comunicazioni
        "NEW_MESSAGGI"              => "Messaggi",
    ];
}

/** Tabelle solo cancellate (dati temporanei o di log). */
function bks_tabelle_non_salvate(): array
{
    return [
        "NEW_SIMULAZIONE_VOTI"      => "Simulazione LIVE: voti",
        "NEW_SIMULAZIONE_RISULTATI" => "Simulazione LIVE: risultati",
        "NEW_SIMULAZIONE_EDIT"      => "Simulazione LIVE: modifiche manuali",
        "NEW_ACCESSI"               => "Registro accessi",
    ];
}

/** Ordine di cancellazione: prima le non salvate, poi le salvate in ordine inverso. */
function bks_ordine_cancellazione(): array
{
    $out = [];
    foreach (bks_tabelle_non_salvate() as $t => $d) $out[$t] = $d;
    foreach (array_reverse(bks_tabelle_backup(), true) as $t => $d) $out[$t] = $d;
    return $out;
}

/** Ordine di ripristino: come l'elenco di backup, con NEW_SQUADRE per ultima. */
function bks_ordine_ripristino(): array
{
    $tab = bks_tabelle_backup();
    $squadre = $tab["NEW_SQUADRE"];
    unset($tab["NEW_SQUADRE"]);
    $tab["NEW_SQUADRE"] = $squadre;
    return $tab;
}

// ------------------------------------------------------------
// Utilità DB
// ------------------------------------------------------------

/** Esegue una query e, se fallisce, solleva un'eccezione. */
function bks_esegui($conn, string $sql)
{
    $ok = mysqli_query($conn, $sql);
    if ($ok === false) {
        throw new RuntimeException(mysqli_error($conn));
    }
    return $ok;
}

/** La tabella esiste nel database corrente? */
function bks_tabella_esiste(string $tab): bool
{
    $r = query_one("SELECT COUNT(*) AS n FROM information_schema.tables
                    WHERE table_schema = DATABASE() AND table_name = '$tab'");
    return $r && (int) $r["n"] > 0;
}

/** La tabella esiste ed ha la colonna "stagione" (qualsiasi maiuscole/minuscole)? */
function bks_tabella_con_stagione(string $tab): bool
{
    if (!bks_tabella_esiste($tab)) return false;
    $r = query_one("SELECT COUNT(*) AS n FROM information_schema.columns
                    WHERE table_schema = DATABASE() AND table_name = '$tab'
                      AND LOWER(column_name) = 'stagione'");
    return $r && (int) $r["n"] > 0;
}

/** Colonne di una tabella: [nome => tipo]. */
function bks_colonne($conn, string $tab): array
{
    $res = bks_esegui($conn, "SHOW COLUMNS FROM `$tab`");
    $out = [];
    while ($r = mysqli_fetch_assoc($res)) {
        $out[$r["Field"]] = $r["Type"];
    }
    return $out;
}

/** Nomi degli indici di una tabella (distinti). */
function bks_indici($conn, string $tab): array
{
    $res = bks_esegui($conn, "SHOW INDEX FROM `$tab`");
    $out = [];
    while ($r = mysqli_fetch_assoc($res)) {
        $out[$r["Key_name"]] = true;
    }
    return array_keys($out);
}

function bks_righe_stagione(string $tab, int $stagione): int
{
    $r = query_one("SELECT COUNT(*) AS n FROM `$tab` WHERE stagione = $stagione");
    return (int) ($r["n"] ?? 0);
}

/** Righe di un backup in una tabella BK_* (0 se la tabella non esiste). */
function bks_righe_backup(string $tab, int $bkId): int
{
    $bk = BKS_PREFISSO . $tab;
    if (!bks_tabella_esiste($bk)) return 0;
    $r = query_one("SELECT COUNT(*) AS n FROM `$bk` WHERE bk_id = $bkId");
    return (int) ($r["n"] ?? 0);
}

/** Righe complessive presenti per la stagione in tutte le tabelle gestite. */
function bks_righe_totali(int $stagione): int
{
    $tot = 0;
    foreach (bks_ordine_cancellazione() as $tab => $d) {
        if (bks_tabella_con_stagione($tab)) $tot += bks_righe_stagione($tab, $stagione);
    }
    return $tot;
}

/** Ultima stagione presente (da NEW_SQUADRE), null se vuota. */
function bks_ultima_stagione(): ?int
{
    $u = query_one("SELECT MAX(stagione) AS s FROM NEW_SQUADRE");
    return ($u && $u["s"] !== null) ? (int) $u["s"] : null;
}

// ------------------------------------------------------------
// Registro dei backup
// ------------------------------------------------------------
function bks_assicura_registro($conn): void
{
    bks_esegui($conn, "CREATE TABLE IF NOT EXISTS `" . BKS_REGISTRO . "` (
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

/** Riga del registro con "dettaglio" già decodificato (array), null se assente. */
function bks_leggi_backup(int $bkId): ?array
{
    if (!bks_tabella_esiste(BKS_REGISTRO)) return null;
    $r = query_one("SELECT bk_id, stagione, creato_il, creato_da, stato, completato_il, dettaglio
                    FROM " . BKS_REGISTRO . " WHERE bk_id = $bkId");
    if (!$r) return null;
    $det = json_decode((string) $r["dettaglio"], true);
    $r["bk_id"]    = (int) $r["bk_id"];
    $r["stagione"] = (int) $r["stagione"];
    $r["dettaglio"] = is_array($det) ? $det : [];
    return $r;
}

/** Unisce $dati al JSON "dettaglio" del backup. */
function bks_aggiorna_dettaglio($conn, int $bkId, array $dati): void
{
    $bk  = bks_leggi_backup($bkId);
    $det = array_merge($bk["dettaglio"] ?? [], $dati);
    $js  = mysqli_real_escape_string($conn, json_encode($det, JSON_UNESCAPED_UNICODE));
    bks_esegui($conn, "UPDATE " . BKS_REGISTRO . " SET dettaglio = '$js' WHERE bk_id = $bkId");
}

function bks_imposta_stato($conn, int $bkId, string $stato, bool $completato = false): void
{
    $extra = $completato ? ", completato_il = NOW()" : "";
    bks_esegui($conn, "UPDATE " . BKS_REGISTRO . " SET stato = '$stato'$extra WHERE bk_id = $bkId");
}

// ------------------------------------------------------------
// Backup
// ------------------------------------------------------------

/**
 * Garantisce l'esistenza di BK_<tabella> con tutte le colonne della
 * sorgente + bk_id + bk_data. Senza chiavi, così più backup della
 * stessa stagione possono coesistere.
 */
function bks_assicura_tabella_backup($conn, string $tab, string $bk): void
{
    if (!bks_tabella_esiste($bk)) {
        try {
            bks_esegui($conn, "CREATE TABLE `$bk` ENGINE=InnoDB AS SELECT * FROM `$tab` WHERE 1 = 0");
        } catch (Throwable $e) {
            // Alcune configurazioni (es. GTID) vietano CREATE ... SELECT:
            // si copia la struttura e si rimuovono chiavi e indici.
            if (!bks_tabella_esiste($bk)) {
                bks_esegui($conn, "CREATE TABLE `$bk` LIKE `$tab`");
            }
            foreach (bks_indici($conn, $bk) as $idx) {
                if ($idx === "PRIMARY") {
                    bks_esegui($conn, "ALTER TABLE `$bk` DROP PRIMARY KEY");
                } else {
                    bks_esegui($conn, "ALTER TABLE `$bk` DROP INDEX `$idx`");
                }
            }
        }
    }

    // Allineamento colonne (la sorgente può aver acquisito colonne dopo
    // il primo backup, es. NEW_ALLENATORI.email).
    $colSrc = bks_colonne($conn, $tab);
    $colBk  = array_change_key_case(bks_colonne($conn, $bk), CASE_LOWER);
    foreach ($colSrc as $nome => $tipo) {
        if (!isset($colBk[strtolower($nome)])) {
            bks_esegui($conn, "ALTER TABLE `$bk` ADD COLUMN `$nome` $tipo NULL");
        }
    }
    if (!isset($colBk["bk_id"])) {
        bks_esegui($conn, "ALTER TABLE `$bk` ADD COLUMN `bk_id` int NOT NULL DEFAULT 0");
    }
    if (!isset($colBk["bk_data"])) {
        bks_esegui($conn, "ALTER TABLE `$bk` ADD COLUMN `bk_data` datetime NULL");
    }
    if (!in_array("idx_bk_id", bks_indici($conn, $bk), true)) {
        bks_esegui($conn, "ALTER TABLE `$bk` ADD INDEX `idx_bk_id` (`bk_id`)");
    }
}

/** Copia le righe della stagione in BK_<tabella> e verifica il conteggio. */
function bks_backup_tabella($conn, string $tab, int $bkId, int $stagione): int
{
    $bk = BKS_PREFISSO . $tab;
    bks_assicura_tabella_backup($conn, $tab, $bk);

    $lista = implode(", ", array_map(function ($c) { return "`$c`"; }, array_keys(bks_colonne($conn, $tab))));

    $attese = bks_righe_stagione($tab, $stagione);
    bks_esegui($conn, "INSERT INTO `$bk` ($lista, `bk_id`, `bk_data`)
                       SELECT $lista, $bkId, NOW() FROM `$tab` WHERE stagione = $stagione");

    $copiate = bks_righe_backup($tab, $bkId);
    if ($copiate !== $attese) {
        throw new RuntimeException("$tab: copiate $copiate righe su $attese");
    }
    return $copiate;
}

/** Rimuove un backup incompleto (righe nelle BK_* e voce di registro). */
function bks_scarta_backup($conn, int $bkId): void
{
    foreach (array_keys(bks_tabelle_backup()) as $tab) {
        $bk = BKS_PREFISSO . $tab;
        if (bks_tabella_esiste($bk)) {
            mysqli_query($conn, "DELETE FROM `$bk` WHERE bk_id = $bkId");
        }
    }
    mysqli_query($conn, "DELETE FROM " . BKS_REGISTRO . " WHERE bk_id = $bkId");
}

/**
 * Backup completo della stagione. Registra il backup, copia tutte le
 * tabelle del gruppo "backup" verificando i conteggi e lo porta allo
 * stato $statoFinale. Se qualcosa fallisce scarta il backup parziale e
 * rilancia l'eccezione: nessun dato della stagione è stato toccato.
 *
 * @return array [bk_id, righe per tabella]
 */
function bks_crea_backup($conn, int $stagione, string $creatoDaEsc, string $statoFinale): array
{
    $bkId = null;
    try {
        bks_assicura_registro($conn);
        bks_esegui($conn, "INSERT INTO " . BKS_REGISTRO . " (stagione, creato_il, creato_da, stato)
                           VALUES ($stagione, NOW(), '$creatoDaEsc', 'BACKUP_IN_CORSO')");
        $bkId = (int) mysqli_insert_id($conn);

        $righe   = [];
        $colonne = [];
        foreach (bks_tabelle_backup() as $tab => $descr) {
            if (!bks_tabella_con_stagione($tab)) continue;
            $colonne[$tab] = array_keys(bks_colonne($conn, $tab));
            $righe[$tab]   = bks_backup_tabella($conn, $tab, $bkId, $stagione);
        }

        // Le colonne servono al ripristino: BK_<tab> può averne acquisite altre
        // dopo (valorizzate NULL per i backup più vecchi), che non vanno rimesse.
        bks_aggiorna_dettaglio($conn, $bkId, ["righe_backup" => $righe, "colonne_backup" => $colonne]);
        bks_imposta_stato($conn, $bkId, $statoFinale);
        return [$bkId, $righe];
    } catch (Throwable $e) {
        if ($bkId) bks_scarta_backup($conn, $bkId);
        throw $e;
    }
}

// ------------------------------------------------------------
// Cancellazione
// ------------------------------------------------------------

/**
 * Cancella tutti i dati della stagione.
 * @return array [righe cancellate per tabella, messaggio di errore o null]
 */
function bks_cancella_stagione($conn, int $stagione): array
{
    $righe = [];
    foreach (bks_ordine_cancellazione() as $tab => $descr) {
        if (!bks_tabella_con_stagione($tab)) continue;
        $ok = mysqli_query($conn, "DELETE FROM `$tab` WHERE stagione = $stagione");
        if ($ok === false) {
            return [$righe, "$tab: " . mysqli_error($conn)];
        }
        $righe[$tab] = (int) mysqli_affected_rows($conn);
    }
    return [$righe, null];
}

// ------------------------------------------------------------
// Ripristino
// ------------------------------------------------------------

/**
 * Colonne in comune tra BK_<tab> e la tabella originale.
 * $colonneBackup: colonne che la tabella aveva al momento del backup
 * (registrate in dettaglio.colonne_backup); se null (backup precedenti
 * a questa registrazione) si usano tutte quelle presenti in BK_<tab>.
 * @return array [colonne originale, colonne backup (stesso ordine), colonne del backup non più nell'originale]
 */
function bks_colonne_ripristino($conn, string $tab, ?array $colonneBackup = null): array
{
    $bk     = BKS_PREFISSO . $tab;
    $colSrc = bks_colonne($conn, $tab);
    $colBk  = bks_colonne($conn, $bk);
    if ($colonneBackup !== null) {
        $ammesse = array_map("strtolower", $colonneBackup);
        $colBk = array_filter($colBk, function ($n) use ($ammesse) {
            return in_array(strtolower($n), $ammesse, true);
        }, ARRAY_FILTER_USE_KEY);
    }
    $bkLow  = [];
    foreach ($colBk as $n => $t) $bkLow[strtolower($n)] = $n;

    $src = [];
    $dst = [];
    foreach ($colSrc as $n => $t) {
        if (isset($bkLow[strtolower($n)])) {
            $src[] = $n;
            $dst[] = $bkLow[strtolower($n)];
        }
    }
    $srcLow = array_map("strtolower", $src);
    $ignorate = [];
    foreach ($colBk as $n => $t) {
        $l = strtolower($n);
        if ($l === "bk_id" || $l === "bk_data") continue;
        if (!in_array($l, $srcLow, true)) $ignorate[] = $n;
    }
    return [$src, $dst, $ignorate];
}

/** Rimette nella tabella originale le righe di un backup e verifica il conteggio. */
function bks_ripristina_tabella($conn, string $tab, int $bkId, int $stagione, ?array $colonneBackup = null): int
{
    $bk = BKS_PREFISSO . $tab;
    [$src, $dst] = bks_colonne_ripristino($conn, $tab, $colonneBackup);
    $attese = bks_righe_backup($tab, $bkId);
    if ($attese === 0) return 0;

    $lSrc = implode(", ", array_map(function ($c) { return "`$c`"; }, $src));
    $lBk  = implode(", ", array_map(function ($c) { return "`$c`"; }, $dst));
    bks_esegui($conn, "INSERT INTO `$tab` ($lSrc) SELECT $lBk FROM `$bk`
                       WHERE bk_id = $bkId AND stagione = $stagione");
    $inserite = (int) mysqli_affected_rows($conn);
    if ($inserite !== $attese) {
        throw new RuntimeException("$tab: ripristinate $inserite righe su $attese");
    }
    return $inserite;
}

// ------------------------------------------------------------
// Credenziali e lock
// ------------------------------------------------------------

/**
 * L'utenza è un amministratore della stagione? Si cerca in NEW_UTENZE
 * e, se la stagione è stata già svuotata da un reset, anche nel backup
 * indicato (bk_id) o, in mancanza, nell'ultimo reset interrotto.
 */
function bks_verifica_admin($conn, int $stagione, string $utenza, string $password, ?int $bkId = null): bool
{
    if ($utenza === "" || $password === "") return false;
    $esc = mysqli_real_escape_string($conn, $utenza);

    $candidati = query_all("SELECT PASSWORD, amministratore FROM NEW_UTENZE
                            WHERE stagione = $stagione AND utenza = '$esc'");

    if ($bkId === null && bks_tabella_esiste(BKS_REGISTRO)) {
        $p = query_one("SELECT bk_id FROM " . BKS_REGISTRO . "
                        WHERE stagione = $stagione AND stato = 'RESET_PARZIALE'
                        ORDER BY bk_id DESC LIMIT 1");
        $bkId = $p ? (int) $p["bk_id"] : null;
    }
    if ($bkId !== null && bks_tabella_esiste(BKS_PREFISSO . "NEW_UTENZE")) {
        $candidati = array_merge($candidati, query_all(
            "SELECT PASSWORD, amministratore FROM " . BKS_PREFISSO . "NEW_UTENZE
             WHERE bk_id = $bkId AND stagione = $stagione AND utenza = '$esc'"));
    }

    foreach ($candidati as $c) {
        if (hash_equals((string) $c["PASSWORD"], $password)
            && strtoupper(trim((string) $c["amministratore"])) === "Y") {
            return true;
        }
    }
    return false;
}

/**
 * Lock applicativo (lo stesso di chiusura/riapertura giornata).
 * @return callable|null funzione che rilascia il lock, null se occupato
 */
function bks_prendi_lock($conn, int $stagione): ?callable
{
    $nome = "fantamazzone_chiusura_$stagione";
    $res  = mysqli_query($conn, "SELECT GET_LOCK('$nome', 0) AS l");
    $row  = $res ? mysqli_fetch_assoc($res) : null;
    if (!$row || (int) $row["l"] !== 1) return null;
    return function () use ($conn, $nome) {
        mysqli_query($conn, "SELECT RELEASE_LOCK('$nome')");
    };
}
