<?php
// ============================================================
// backend/lib/MercatoSecondaFinestra.php
//
// Mercato di riparazione - SECONDA FINESTRA (in presenza, ad asta).
//
// REGOLE
//  - La finestra si tiene di persona in un giorno prestabilito. L'amministratore
//    la avvia dalla pagina: si salva la classifica del campionato e l'ordine di
//    chiamata e' l'INVERSO della classifica (ultimo = primo a chiamare).
//  - Turni consecutivi, a giri: la squadra di turno fa UN'operazione, cioe'
//    sottopone ad asta uno svincolato oppure PASSA. Chi passa termina la propria
//    finestra: non e' piu' chiamata e non puo' aggiudicarsi giocatori.
//    Quando tutte le squadre hanno passato la finestra si chiude.
//  - Squadra aggiudicataria e prezzo sono inseriti a mano (dalla squadra di turno
//    o dall'amministratore). All'aggiudicazione il giocatore passa da
//    NEW_GIOCATORI_SVINCOLATI a NEW_GIOCATORI con crediti = prezzo: il budget
//    residuo (budget - somma crediti della rosa) si aggiorna di conseguenza.
//  - BUDGET: si acquista solo con budget gia' disponibile (prezzo <= residuo).
//    Con residuo 0 si puo' acquistare solo ad 1 credito; con residuo negativo
//    non si puo' acquistare. Prezzo minimo 1.
//  - Una squadra non puo' aggiudicarsi un giocatore da lei ceduto (in qualunque
//    finestra: NEW_MERCATO_TRANSAZIONI, tipo SCAMBIO).
//  - Ogni operazione e' salvata in NEW_MERCATO2_TRANSAZIONI. L'amministratore
//    puo' annullare l'ultima operazione.
//
// Va incluso DOPO connect.php.
// ============================================================

require_once __DIR__ . "/MercatoRiparazione.php";   // mr_config, mr_nomi_squadre, mr_esc, MercatoErrore...

function m2_now(): string { return date("Y-m-d H:i:s"); }

function m2_lock(int $stagione, int $timeout = 5): bool
{
    global $conn;
    $r = mysqli_query($conn, "SELECT GET_LOCK('fantamazzone_mercato2_$stagione', $timeout) AS l");
    $row = $r ? mysqli_fetch_assoc($r) : null;
    return $row && (int) $row["l"] === 1;
}

function m2_unlock(int $stagione): void
{
    global $conn;
    mysqli_query($conn, "SELECT RELEASE_LOCK('fantamazzone_mercato2_$stagione')");
}

function m2_is_admin(int $stagione, ?int $idUtente): bool
{
    if (!$idUtente) return false;
    $r = query_one("SELECT amministratore FROM NEW_UTENZE WHERE stagione = $stagione AND id = $idUtente LIMIT 1");
    return $r && strtoupper(trim((string) $r["amministratore"])) === "Y";
}

function m2_finestra(int $stagione): ?array
{
    return query_one("SELECT * FROM NEW_MERCATO2_FINESTRA WHERE stagione = $stagione") ?: null;
}

function m2_turno(int $stagione, ?array $fin = null): ?array
{
    $fin ??= m2_finestra($stagione);
    if (!$fin || $fin["stato"] !== "ATTIVA" || $fin["turno_posizione"] === null) return null;
    return query_one("SELECT * FROM NEW_MERCATO2_ORDINE
                      WHERE stagione = $stagione AND posizione = " . (int) $fin["turno_posizione"]
                      . " AND stato = 'ATTIVA'") ?: null;
}

/** Budget di tutte le squadre: [id => totale, speso, residuo, giocatori, max_offerta (null = non puo' acquistare)]. */
function m2_budget_squadre(int $stagione): array
{
    $cfg = mr_config($stagione);
    $out = [];
    foreach (query_all("SELECT id FROM NEW_SQUADRE WHERE stagione = $stagione") as $s) {
        $out[(int) $s["id"]] = ["totale" => $cfg["budget"], "speso" => 0, "giocatori" => 0];
    }
    foreach (query_all("SELECT squadra, COALESCE(SUM(crediti), 0) AS speso, COUNT(*) AS n
                        FROM NEW_GIOCATORI WHERE stagione = $stagione GROUP BY squadra") as $r) {
        $id = (int) $r["squadra"];
        if (isset($out[$id])) { $out[$id]["speso"] = (int) $r["speso"]; $out[$id]["giocatori"] = (int) $r["n"]; }
    }
    foreach ($out as $id => &$b) {
        $b["residuo"] = $b["totale"] - $b["speso"];
        $b["max_offerta"] = m2_offerta_max($b["residuo"]);
    }
    return $out;
}

/** Massima offerta consentita: residuo, ma con residuo 0 solo 1 credito; negativo = non puo' acquistare. */
function m2_offerta_max(int $residuo): ?int
{
    if ($residuo < 0) return null;
    return max(1, $residuo);
}

/** Giocatori ceduti da ogni squadra nel mercato di riparazione (tutte le finestre): [id_giocatore => [id_squadra...]]. */
function m2_ceduti(int $stagione): array
{
    $out = [];
    foreach (query_all("SELECT id_giocatore_ceduto AS g, id_squadra AS s FROM NEW_MERCATO_TRANSAZIONI
                        WHERE stagione = $stagione AND tipo = 'SCAMBIO' AND id_giocatore_ceduto IS NOT NULL") as $r) {
        $out[(int) $r["g"]][] = (int) $r["s"];
    }
    return $out;
}

/** Ordine di chiamata a partire dalla classifica attuale (ultimo = primo). */
function m2_ordine_da_classifica(int $stagione): array
{
    $rows = query_all("SELECT id_squadra, punti FROM NEW_GENERALE WHERE stagione = $stagione
                       ORDER BY punti DESC, golf DESC, gols DESC, id_squadra ASC");
    $nomi = mr_nomi_squadre($stagione);
    $out = []; $pos = 1;
    foreach ($rows as $r) {
        $id = (int) $r["id_squadra"];
        $out[] = ["pos_classifica" => $pos++, "id_squadra" => $id, "punti" => (int) $r["punti"],
                  "nome" => $nomi[$id]["nome"] ?? "Squadra $id", "allenatore" => $nomi[$id]["allenatore"] ?? ""];
    }
    $out = array_reverse($out);
    foreach ($out as $i => &$o) $o["posizione"] = $i + 1;
    return $out;
}

// ------------------------------------------------------------
// Operazioni
// ------------------------------------------------------------
function m2_verifica_ultima(int $stagione): void
{
    if ($stagione !== sg_ultima_stagione()) throw new MercatoErrore("Il mercato e' disponibile solo per l'ultima stagione", 403);
}

function m2_avvia(int $stagione, int $idRich): array
{
    global $conn;
    m2_verifica_ultima($stagione);
    if (!m2_is_admin($stagione, $idRich)) throw new MercatoErrore("Solo l'amministratore puo' avviare la finestra", 403);
    if (!m2_lock($stagione)) throw new MercatoErrore("Un'altra operazione e' in corso: riprova tra qualche secondo", 409);
    try {
        if (m2_finestra($stagione)) throw new MercatoErrore("La seconda finestra e' gia' stata avviata", 409);
        $ordine = m2_ordine_da_classifica($stagione);
        if (!$ordine) throw new MercatoErrore("Classifica del campionato non disponibile", 500);
        $now = m2_now();
        mysqli_query($conn, "INSERT INTO NEW_MERCATO2_FINESTRA (stagione, stato, avviata_il, avviata_da, turno_posizione, giro)
                             VALUES ($stagione, 'ATTIVA', '$now', $idRich, 1, 1)");
        foreach ($ordine as $o) {
            mysqli_query($conn, "INSERT INTO NEW_MERCATO2_ORDINE (stagione, posizione, id_squadra, pos_classifica, punti, stato)
                                 VALUES ($stagione, " . (int) $o["posizione"] . ", " . (int) $o["id_squadra"] . ", "
                                 . (int) $o["pos_classifica"] . ", " . (int) $o["punti"] . ", 'ATTIVA')");
        }
        return ["ok" => true, "squadre" => count($ordine)];
    } finally {
        m2_unlock($stagione);
    }
}

/** Annulla l'avvio (solo admin, solo se non e' ancora stata registrata nessuna operazione). */
function m2_azzera(int $stagione, int $idRich): array
{
    global $conn;
    m2_verifica_ultima($stagione);
    if (!m2_is_admin($stagione, $idRich)) throw new MercatoErrore("Operazione riservata all'amministratore", 403);
    if (!m2_lock($stagione)) throw new MercatoErrore("Un'altra operazione e' in corso: riprova tra qualche secondo", 409);
    try {
        $n = query_one("SELECT COUNT(*) AS n FROM NEW_MERCATO2_TRANSAZIONI WHERE stagione = $stagione");
        if ((int) $n["n"] > 0) throw new MercatoErrore("Sono gia' state registrate operazioni: annullale prima una alla volta", 409);
        mysqli_query($conn, "DELETE FROM NEW_MERCATO2_ORDINE WHERE stagione = $stagione");
        mysqli_query($conn, "DELETE FROM NEW_MERCATO2_FINESTRA WHERE stagione = $stagione");
        return ["ok" => true];
    } finally {
        m2_unlock($stagione);
    }
}

/** Passa il turno al prossimo attivo; se non ce ne sono chiude la finestra. */
function m2_avanza(int $stagione, int $posCorrente, int $giro): void
{
    global $conn;
    $n = query_one("SELECT MIN(posizione) AS p FROM NEW_MERCATO2_ORDINE
                    WHERE stagione = $stagione AND stato = 'ATTIVA' AND posizione > $posCorrente");
    if ($n && $n["p"] !== null) {
        mysqli_query($conn, "UPDATE NEW_MERCATO2_FINESTRA SET turno_posizione = " . (int) $n["p"] . ", giro = $giro WHERE stagione = $stagione");
        return;
    }
    $n = query_one("SELECT MIN(posizione) AS p FROM NEW_MERCATO2_ORDINE WHERE stagione = $stagione AND stato = 'ATTIVA'");
    if ($n && $n["p"] !== null) {
        mysqli_query($conn, "UPDATE NEW_MERCATO2_FINESTRA SET turno_posizione = " . (int) $n["p"] . ", giro = " . ($giro + 1) . " WHERE stagione = $stagione");
        return;
    }
    mysqli_query($conn, "UPDATE NEW_MERCATO2_FINESTRA SET stato = 'CHIUSA', turno_posizione = NULL, chiusa_il = '" . m2_now() . "' WHERE stagione = $stagione");
}

/** Controlli comuni: finestra attiva, squadra di turno, utente autorizzato. */
function m2_verifica_turno(int $stagione, int $idRich): array
{
    m2_verifica_ultima($stagione);
    $fin = m2_finestra($stagione);
    if (!$fin) throw new MercatoErrore("La seconda finestra non e' stata ancora avviata", 409);
    if ($fin["stato"] !== "ATTIVA") throw new MercatoErrore("La seconda finestra e' conclusa", 409);
    $turno = m2_turno($stagione, $fin);
    if (!$turno) throw new MercatoErrore("Nessuna squadra di turno", 409);
    if (!m2_is_admin($stagione, $idRich) && $idRich !== (int) $turno["id_squadra"]) {
        throw new MercatoErrore("Non e' il tuo turno: e' chiamata " . mr_nome_squadra($stagione, (int) $turno["id_squadra"]), 403);
    }
    return [$fin, $turno];
}

function m2_aggiudica(int $stagione, int $idRich, int $idGiocatore, int $idAgg, int $prezzo): array
{
    global $conn;
    if (!m2_lock($stagione)) throw new MercatoErrore("Un'altra operazione e' in corso: riprova tra qualche secondo", 409);
    try {
        [$fin, $turno] = m2_verifica_turno($stagione, $idRich);

        $g = query_one("SELECT id, descrizione, ruolo, nazione FROM NEW_GIOCATORI_SVINCOLATI WHERE stagione = $stagione AND id = $idGiocatore");
        if (!$g) throw new MercatoErrore("Il giocatore non e' piu' tra gli svincolati", 400);

        $agg = query_one("SELECT * FROM NEW_MERCATO2_ORDINE WHERE stagione = $stagione AND id_squadra = $idAgg");
        if (!$agg) throw new MercatoErrore("Squadra aggiudicataria non valida", 400);
        $nomeAgg = mr_nome_squadra($stagione, $idAgg);
        if ($agg["stato"] !== "ATTIVA") throw new MercatoErrore("$nomeAgg ha gia' terminato la propria finestra di mercato", 400);

        $ceduti = m2_ceduti($stagione);
        if (in_array($idAgg, $ceduti[$idGiocatore] ?? [], true)) {
            throw new MercatoErrore("$nomeAgg non puo' acquistare {$g['descrizione']}: lo ha ceduto nel mercato di riparazione", 400);
        }

        if ($prezzo < 1) throw new MercatoErrore("Il prezzo minimo e' 1 credito", 400);
        $bud = m2_budget_squadre($stagione)[$idAgg] ?? null;
        if (!$bud) throw new MercatoErrore("Squadra aggiudicataria non valida", 400);
        if ($bud["max_offerta"] === null) {
            throw new MercatoErrore("$nomeAgg ha il budget esaurito (residuo {$bud['residuo']}): non puo' acquistare", 400);
        }
        if ($prezzo > $bud["max_offerta"]) {
            throw new MercatoErrore($bud["residuo"] <= 0
                ? "$nomeAgg ha budget 0: puo' acquistare solo un giocatore a 1 credito"
                : "$nomeAgg ha un budget residuo di {$bud['residuo']} crediti: prezzo massimo {$bud['max_offerta']}", 400);
        }

        $ruolo = (int) $g["ruolo"];
        $nome = mr_esc((string) $g["descrizione"]);
        $naz  = mr_esc((string) $g["nazione"]);

        // NEW_GIOCATORI e' MyISAM: nessun rollback, ogni passo e' verificato e ripristinato in caso di errore.
        mysqli_query($conn, "DELETE FROM NEW_GIOCATORI_SVINCOLATI WHERE stagione = $stagione AND id = $idGiocatore");
        if (mysqli_affected_rows($conn) < 1) throw new MercatoErrore("Operazione non riuscita: il giocatore non e' piu' svincolato", 409);
        $ok = mysqli_query($conn, "INSERT INTO NEW_GIOCATORI (id, stagione, descrizione, squadra, flag, ruolo, nazione, crediti)
                                   VALUES ($idGiocatore, $stagione, '$nome', $idAgg, 0, $ruolo, '$naz', $prezzo)");
        if (!$ok || mysqli_affected_rows($conn) < 1) {
            mysqli_query($conn, "INSERT INTO NEW_GIOCATORI_SVINCOLATI (id, stagione, descrizione, ruolo, nazione) VALUES ($idGiocatore, $stagione, '$nome', $ruolo, '$naz')");
            throw new MercatoErrore("Operazione non riuscita: impossibile inserire il giocatore in rosa", 500);
        }

        $pos = (int) $turno["posizione"]; $giro = (int) $fin["giro"];
        mysqli_query($conn, "INSERT INTO NEW_MERCATO2_TRANSAZIONI
            (stagione, giro, posizione, id_squadra_chiamante, tipo, id_squadra_aggiudicataria, id_giocatore, giocatore, ruolo, nazione, crediti, creato_il, creato_da)
            VALUES ($stagione, $giro, $pos, " . (int) $turno["id_squadra"] . ", 'ASTA', $idAgg, $idGiocatore, '$nome', $ruolo, '$naz', $prezzo, '" . m2_now() . "', $idRich)");
        m2_avanza($stagione, $pos, $giro);

        return ["ok" => true, "giocatore" => $g["descrizione"], "squadra" => $nomeAgg, "prezzo" => $prezzo,
                "residuo" => $bud["residuo"] - $prezzo];
    } finally {
        m2_unlock($stagione);
    }
}

function m2_passa(int $stagione, int $idRich): array
{
    global $conn;
    if (!m2_lock($stagione)) throw new MercatoErrore("Un'altra operazione e' in corso: riprova tra qualche secondo", 409);
    try {
        [$fin, $turno] = m2_verifica_turno($stagione, $idRich);
        $pos = (int) $turno["posizione"]; $giro = (int) $fin["giro"];
        mysqli_query($conn, "INSERT INTO NEW_MERCATO2_TRANSAZIONI
            (stagione, giro, posizione, id_squadra_chiamante, tipo, crediti, creato_il, creato_da)
            VALUES ($stagione, $giro, $pos, " . (int) $turno["id_squadra"] . ", 'PASSO', 0, '" . m2_now() . "', $idRich)");
        mysqli_query($conn, "UPDATE NEW_MERCATO2_ORDINE SET stato = 'PASSATO' WHERE stagione = $stagione AND posizione = $pos");
        m2_avanza($stagione, $pos, $giro);
        return ["ok" => true];
    } finally {
        m2_unlock($stagione);
    }
}

/** Solo admin: annulla l'ultima operazione (asta o passo) e ripristina il turno. */
function m2_annulla_ultima(int $stagione, int $idRich): array
{
    global $conn;
    m2_verifica_ultima($stagione);
    if (!m2_is_admin($stagione, $idRich)) throw new MercatoErrore("Operazione riservata all'amministratore", 403);
    if (!m2_lock($stagione)) throw new MercatoErrore("Un'altra operazione e' in corso: riprova tra qualche secondo", 409);
    try {
        $t = query_one("SELECT * FROM NEW_MERCATO2_TRANSAZIONI WHERE stagione = $stagione ORDER BY id DESC LIMIT 1");
        if (!$t) throw new MercatoErrore("Nessuna operazione da annullare", 409);
        $pos = (int) $t["posizione"];
        if ($t["tipo"] === "ASTA") {
            $id = (int) $t["id_giocatore"]; $sq = (int) $t["id_squadra_aggiudicataria"];
            mysqli_query($conn, "DELETE FROM NEW_GIOCATORI WHERE stagione = $stagione AND id = $id AND squadra = $sq");
            if (mysqli_affected_rows($conn) < 1) throw new MercatoErrore("Il giocatore non e' piu' nella rosa della squadra aggiudicataria: annullamento impossibile", 409);
            $gia = query_one("SELECT COUNT(*) AS n FROM NEW_GIOCATORI_SVINCOLATI WHERE stagione = $stagione AND id = $id");
            if ((int) $gia["n"] === 0) {
                mysqli_query($conn, "INSERT INTO NEW_GIOCATORI_SVINCOLATI (id, stagione, descrizione, ruolo, nazione)
                                     VALUES ($id, $stagione, '" . mr_esc((string) $t["giocatore"]) . "', " . (int) $t["ruolo"] . ", '" . mr_esc((string) $t["nazione"]) . "')");
            }
            // Il giocatore non deve restare in formazioni inserite nel frattempo
            foreach (["NEW_FORMAZIONI", "NEW_FORMAZIONI_CHAMP", "NEW_RIGORISTI_CHAMP"] as $tab) {
                mysqli_query($conn, "DELETE FROM $tab WHERE STAGIONE = $stagione AND ID_SQUADRA = $sq AND ID_GIOCATORE = $id");
            }
        } else {
            mysqli_query($conn, "UPDATE NEW_MERCATO2_ORDINE SET stato = 'ATTIVA' WHERE stagione = $stagione AND posizione = $pos");
        }
        mysqli_query($conn, "DELETE FROM NEW_MERCATO2_TRANSAZIONI WHERE id = " . (int) $t["id"]);
        mysqli_query($conn, "UPDATE NEW_MERCATO2_FINESTRA SET stato = 'ATTIVA', chiusa_il = NULL,
                             turno_posizione = $pos, giro = " . (int) $t["giro"] . " WHERE stagione = $stagione");
        return ["ok" => true, "annullata" => $t["tipo"], "giocatore" => $t["giocatore"]];
    } finally {
        m2_unlock($stagione);
    }
}

// ------------------------------------------------------------
// Lettura
// ------------------------------------------------------------
function m2_stato(int $stagione, ?int $idRich): array
{
    $cfg  = mr_config($stagione);
    $fin  = m2_finestra($stagione);
    $nomi = mr_nomi_squadre($stagione);
    $bud  = m2_budget_squadre($stagione);
    $admin = m2_is_admin($stagione, $idRich);

    $out = [
        "stagione" => $stagione, "ultima_stagione" => $stagione === sg_ultima_stagione(),
        "avviata" => $fin !== null, "stato" => $fin["stato"] ?? null, "giro" => $fin ? (int) $fin["giro"] : null,
        "avviata_il" => $fin["avviata_il"] ?? null, "chiusa_il" => $fin["chiusa_il"] ?? null,
        "budget_totale" => $cfg["budget"], "is_admin" => $admin,
        "ordine" => [], "turno" => null, "puo_operare" => false, "anteprima" => [],
        "puo_annullare" => false,
    ];

    if (!$fin) {
        $out["anteprima"] = m2_ordine_da_classifica($stagione);
        foreach ($out["anteprima"] as &$a) $a["budget"] = $bud[$a["id_squadra"]] ?? null;
        return $out;
    }

    $turno = m2_turno($stagione, $fin);
    foreach (query_all("SELECT * FROM NEW_MERCATO2_ORDINE WHERE stagione = $stagione ORDER BY posizione") as $o) {
        $id = (int) $o["id_squadra"];
        $out["ordine"][] = [
            "posizione" => (int) $o["posizione"], "id_squadra" => $id,
            "nome" => $nomi[$id]["nome"] ?? "Squadra $id", "allenatore" => $nomi[$id]["allenatore"] ?? "",
            "pos_classifica" => (int) $o["pos_classifica"], "punti" => (int) $o["punti"],
            "stato" => $o["stato"], "in_turno" => $turno && (int) $turno["posizione"] === (int) $o["posizione"],
            "budget" => $bud[$id] ?? null,
        ];
    }
    if ($turno) {
        $id = (int) $turno["id_squadra"];
        $out["turno"] = ["posizione" => (int) $turno["posizione"], "id_squadra" => $id, "nome" => $nomi[$id]["nome"] ?? "Squadra $id"];
        $out["puo_operare"] = $admin || ($idRich !== null && $idRich === $id);
    }
    $out["puo_annullare"] = $admin && (int) (query_one("SELECT COUNT(*) AS n FROM NEW_MERCATO2_TRANSAZIONI WHERE stagione = $stagione")["n"]) > 0;
    return $out;
}

/** Svincolati con squadra di Serie A e squadre che non possono acquistarli (perche' li hanno ceduti). */
function m2_svincolati(int $stagione): array
{
    $ceduti = m2_ceduti($stagione);
    $serieA = mr_squadre_serie_a($stagione);
    $out = [];
    foreach (query_all("SELECT id, descrizione, ruolo, nazione FROM NEW_GIOCATORI_SVINCOLATI WHERE stagione = $stagione ORDER BY ruolo, descrizione") as $r) {
        $id = (int) $r["id"];
        $sa = $serieA[$id] ?? "";
        $out[] = ["id" => $id, "descrizione" => $r["descrizione"], "ruolo" => (int) $r["ruolo"], "nazione" => $r["nazione"],
                  "serie_a" => $sa !== "" ? $sa : null, "ceduto_da" => array_values(array_unique($ceduti[$id] ?? []))];
    }
    return $out;
}

function m2_storico(int $stagione): array
{
    return query_all("SELECT t.id, t.giro, t.tipo, t.giocatore, t.ruolo, t.crediti, t.creato_il,
                             t.id_squadra_chiamante, c.nome AS chiamante,
                             t.id_squadra_aggiudicataria, a.nome AS aggiudicataria
                      FROM NEW_MERCATO2_TRANSAZIONI t
                      LEFT JOIN NEW_SQUADRE c ON c.id = t.id_squadra_chiamante AND c.stagione = t.stagione
                      LEFT JOIN NEW_SQUADRE a ON a.id = t.id_squadra_aggiudicataria AND a.stagione = t.stagione
                      WHERE t.stagione = $stagione ORDER BY t.id DESC");
}
