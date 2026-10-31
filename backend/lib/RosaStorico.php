<?php
// ============================================================
// backend/lib/RosaStorico.php
//
// STORICO DELLE ROSE e ANAGRAFICA STORICA dei giocatori.
//
// 1) NEW_ROSA_STORICO
//    Le rose cambiano SOLO durante il mercato (riparazione, sessioni e
//    seconda finestra), quindi lo storico non è uno snapshot per giornata
//    ma una tabella di PERIODI: un giocatore è nella rosa di una squadra
//    dalla giornata `da_giornata` alla giornata `a_giornata` inclusa
//    (NULL = ancora in rosa). Un'operazione di mercato fatta dopo la
//    chiusura della giornata G vale così:
//      cessione   → a_giornata  = G      (ultima giornata in rosa)
//      acquisto   → da_giornata = G + 1  (prima giornata in rosa)
//    Un giocatore ceduto resta quindi nella rosa della squadra precedente
//    per tutte le giornate in cui c'era, e compare nella nuova solo da lì.
//
//    Giocatori attualmente in rosa SENZA alcuna riga nello storico (rose
//    inserite dopo l'introduzione della tabella, o tabella non ancora
//    popolata) valgono "in rosa per tutta la stagione": la lettura non
//    dipende quindi dal fatto che ogni flusso abbia già scritto lo storico.
//
//    Le scritture (rs_registra_*) non devono MAI far fallire un'operazione
//    di mercato: gli errori sono intercettati e scritti nel log. Se la
//    tabella non esiste ancora (migrazione non eseguita) vengono ignorate.
//
// 2) ANAGRAFICA STORICA (rs_sql_anagrafica)
//    Quando un giocatore viene ceduto, il mercato lo toglie da NEW_GIOCATORI
//    e lo mette in NEW_GIOCATORI_SVINCOLATI. Le query che leggono dati
//    storici (formazioni, voti, dettaglio partite) NON devono perdere quel
//    giocatore: per nome, ruolo e nazione si usa questa sotto-query, che
//    cerca prima in NEW_GIOCATORI e poi tra gli svincolati.
//
// Va incluso DOPO connect.php (usa query_one / query_all / $conn).
// ============================================================

// ── Anagrafica storica ───────────────────────────────────────

/**
 * Sotto-query (da usare come tabella derivata, es. `JOIN <sql> g ON ...`)
 * con id, stagione, descrizione, ruolo, nazione di TUTTI i giocatori della
 * stagione: in rosa (NEW_GIOCATORI) oppure svincolati (ceduti).
 * $stagione è un intero: nessun rischio di injection.
 */
function rs_sql_anagrafica(int $stagione): string
{
    return "(SELECT a.id, a.stagione, a.descrizione, a.ruolo, a.nazione
               FROM NEW_GIOCATORI a
              WHERE a.stagione = $stagione
             UNION ALL
             SELECT v.id, v.stagione, v.descrizione, v.ruolo, v.nazione
               FROM NEW_GIOCATORI_SVINCOLATI v
              WHERE v.stagione = $stagione
                AND NOT EXISTS (SELECT 1 FROM NEW_GIOCATORI x
                                 WHERE x.stagione = v.stagione AND x.id = v.id))";
}

// ── Storico rosa: utilità ────────────────────────────────────

function rs_tabella_esiste(): bool
{
    static $esiste = null;
    if ($esiste === null) {
        try {
            $r = query_one("SELECT 1 AS x FROM information_schema.tables
                            WHERE table_schema = DATABASE() AND table_name = 'NEW_ROSA_STORICO'");
            $esiste = $r !== null && $r !== false;
        } catch (Throwable $e) {
            $esiste = false;
        }
    }
    return $esiste;
}

/** Ultima giornata chiusa della stagione (0 se nessuna). */
function rs_ultima_giornata_chiusa(int $stagione): int
{
    $r = query_one("SELECT COALESCE(MAX(giornata), 0) AS g FROM NEW_CALENDARIO_CK
                    WHERE stagione = $stagione AND ck_giocata = 'S'");
    return (int) ($r["g"] ?? 0);
}

// ── Storico rosa: scritture (chiamate dal mercato) ───────────

/**
 * Il giocatore esce dalla rosa della squadra: l'ultima giornata in cui
 * ne faceva parte è $ultimaChiusa (la giornata chiusa prima del mercato).
 */
function rs_registra_cessione(int $stagione, int $idSquadra, int $idGiocatore, int $ultimaChiusa): void
{
    global $conn;
    if (!rs_tabella_esiste()) return;
    try {
        mysqli_query($conn, "UPDATE NEW_ROSA_STORICO SET a_giornata = $ultimaChiusa
                             WHERE stagione = $stagione AND id_giocatore = $idGiocatore
                               AND id_squadra = $idSquadra AND a_giornata IS NULL");
        if (mysqli_affected_rows($conn) < 1) {
            // Nessun periodo aperto (rosa mai registrata): in rosa dall'inizio fino alla cessione
            mysqli_query($conn, "INSERT INTO NEW_ROSA_STORICO (stagione, id_giocatore, id_squadra, da_giornata, a_giornata)
                                 VALUES ($stagione, $idGiocatore, $idSquadra, 1, $ultimaChiusa)
                                 ON DUPLICATE KEY UPDATE a_giornata = VALUES(a_giornata)");
        }
    } catch (Throwable $e) {
        error_log("RosaStorico: cessione non registrata ($stagione/$idSquadra/$idGiocatore): " . $e->getMessage());
    }
}

/**
 * Il giocatore entra nella rosa della squadra a partire dalla giornata
 * successiva a $ultimaChiusa.
 */
function rs_registra_acquisto(int $stagione, int $idSquadra, int $idGiocatore, int $ultimaChiusa): void
{
    global $conn;
    if (!rs_tabella_esiste()) return;
    try {
        // Coerenza: un giocatore non può essere in rosa a due squadre
        mysqli_query($conn, "UPDATE NEW_ROSA_STORICO SET a_giornata = $ultimaChiusa
                             WHERE stagione = $stagione AND id_giocatore = $idGiocatore
                               AND id_squadra <> $idSquadra AND a_giornata IS NULL");
        $da = $ultimaChiusa + 1;
        mysqli_query($conn, "INSERT INTO NEW_ROSA_STORICO (stagione, id_giocatore, id_squadra, da_giornata, a_giornata)
                             VALUES ($stagione, $idGiocatore, $idSquadra, $da, NULL)
                             ON DUPLICATE KEY UPDATE a_giornata = NULL");
    } catch (Throwable $e) {
        error_log("RosaStorico: acquisto non registrato ($stagione/$idSquadra/$idGiocatore): " . $e->getMessage());
    }
}

/** Annullamento di un acquisto (seconda finestra): toglie il periodo aperto. */
function rs_annulla_acquisto(int $stagione, int $idSquadra, int $idGiocatore): void
{
    global $conn;
    if (!rs_tabella_esiste()) return;
    try {
        mysqli_query($conn, "DELETE FROM NEW_ROSA_STORICO
                             WHERE stagione = $stagione AND id_giocatore = $idGiocatore
                               AND id_squadra = $idSquadra AND a_giornata IS NULL");
    } catch (Throwable $e) {
        error_log("RosaStorico: annullamento acquisto non registrato ($stagione/$idSquadra/$idGiocatore): " . $e->getMessage());
    }
}

/** Cancella lo storico della stagione (es. quando le rose vengono reinserite da zero). */
function rs_azzera_stagione(int $stagione): void
{
    global $conn;
    if (!rs_tabella_esiste()) return;
    try {
        mysqli_query($conn, "DELETE FROM NEW_ROSA_STORICO WHERE stagione = $stagione");
    } catch (Throwable $e) {
        error_log("RosaStorico: azzeramento stagione $stagione non riuscito: " . $e->getMessage());
    }
}

// ── Storico rosa: lettura ────────────────────────────────────

/**
 * Periodi in rosa di ogni giocatore della squadra nella stagione:
 *   id_giocatore => [ [da, a|null], ... ]
 * Comprende i giocatori ceduti (per le giornate in cui c'erano) e quelli
 * attualmente in rosa privi di storico (in rosa per tutta la stagione).
 */
function rs_rosa_squadra(int $stagione, int $idSquadra): array
{
    $per = [];
    if (rs_tabella_esiste()) {
        foreach (query_all("SELECT id_giocatore, da_giornata, a_giornata FROM NEW_ROSA_STORICO
                            WHERE stagione = $stagione AND id_squadra = $idSquadra") as $r) {
            $per[(int) $r["id_giocatore"]][] = [
                (int) $r["da_giornata"],
                $r["a_giornata"] === null ? null : (int) $r["a_giornata"],
            ];
        }
        $senzaStorico = query_all("SELECT g.id FROM NEW_GIOCATORI g
                                   WHERE g.stagione = $stagione AND g.squadra = $idSquadra
                                     AND NOT EXISTS (SELECT 1 FROM NEW_ROSA_STORICO r
                                                      WHERE r.stagione = g.stagione AND r.id_giocatore = g.id)");
    } else {
        $senzaStorico = query_all("SELECT id FROM NEW_GIOCATORI
                                   WHERE stagione = $stagione AND squadra = $idSquadra");
    }
    foreach ($senzaStorico as $r) $per[(int) $r["id"]][] = [1, null];
    return $per;
}

/** Il giocatore, con questi periodi, è in rosa alla giornata $giornata? */
function rs_in_rosa(array $periodi, int $giornata): bool
{
    foreach ($periodi as [$da, $a]) {
        if ($giornata >= $da && ($a === null || $giornata <= $a)) return true;
    }
    return false;
}
