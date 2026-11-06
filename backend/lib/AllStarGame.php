<?php
// ============================================================
// backend/lib/AllStarGame.php
//
// ALL STAR GAME: partita singola tra la compagine EST e la compagine OVEST.
//
// CONFIGURAZIONE
//   La giornata di fantacampionato in cui si gioca è il parametro di stagione
//   ASG_GIORNATA (NEW_PARAMETRI_STAGIONE, vuoto = All Star Game disattivato),
//   configurabile da "Gestisci regole di calcolo" > Parametri stagione >
//   Struttura stagione.
//
// COMPAGINI
//   Le squadre di lega con id DISPARI compongono l'EST, quelle con id PARI
//   l'OVEST. Ogni compagine è identificata da uno pseudo id di squadra
//   (ASG_ID_EST = 901, ASG_ID_OVEST = 902), usato come ID_SQUADRA nelle tabelle
//   dell'All Star Game: così il motore di calcolo e quello di simulazione, che
//   leggono le formazioni per ID_SQUADRA, funzionano senza modifiche.
//
// ROSA
//   Unione delle rose delle squadre della compagine (giocatori ceduti esclusi).
//
// FORMAZIONE (workflow condiviso tra i fantallenatori della compagine)
//   1. un qualsiasi fantallenatore della compagine salva la formazione (bozza):
//      NEW_FORMAZIONI_ASG (stessa codifica MAGLIA di NEW_FORMAZIONI: 1..11
//      titolari, 12+ panchina) + ordine rigoristi in NEW_RIGORISTI_ASG;
//   2. l'OK di ciascun fantallenatore (NEW_ASG_OK) è una DELEGA: autorizza i
//      compagni a decidere e inviare la formazione. Si può dare in qualsiasi momento
//      (anche prima che esista una bozza) e NON si azzera con le modifiche: resta
//      valido finché il fantallenatore non lo ritira o la formazione è inviata;
//   3. ciascun fantallenatore registra il proprio OK (delega);
//   4. solo quando TUTTI hanno dato l'ok, qualsiasi fantallenatore della
//      compagine può INVIARE la formazione (NEW_ASG_STATO.INVIATA = 1): da quel
//      momento è definitiva e parte la notifica via mail a TUTTI i fantallenatori
//      (di entrambe le compagini).
//   Rispettato anche il blocco di inserimento a tempo (BLOCCO_FORMAZIONE_ATTIVO).
//   Al calcolo vale la formazione inviata; se la compagine non l'ha inviata si
//   usa l'ultima bozza salvata (con avviso); senza alcuna formazione la partita
//   viene saltata.
//
// CALCOLO
//   Stessi parametri del campionato (CalcolatoreVoti), SENZA fattore casa
//   (partita su campo neutro: ASG_FATTORE_CASA_APPLICATO). In caso di parità dopo
//   i 90' si giocano supplementari e, se serve, calci di rigore, con la stessa
//   gestione della Champions (lib/ChampionsSupplementari.php). Risultati in
//   NEW_RISULTATI_ASG / NEW_VOTI_ASG; simulazione LIVE in
//   NEW_SIMULAZIONE_*_ASG. Non influenza classifiche, statistiche e Top/Flop.
//
// Va incluso DOPO connect.php (usa query_one / query_all / $conn).
// Le funzioni asg_sim_* richiedono lib/SimulazioneGiornata.php già caricata.
// ============================================================

require_once __DIR__ . "/FormazioniChampions.php";
require_once __DIR__ . "/ChampionsSupplementari.php";
require_once __DIR__ . "/FormazioniBlocco.php";
require_once __DIR__ . "/StatoGiornata.php";
require_once __DIR__ . "/RosaStorico.php";
require_once __DIR__ . "/ListaGiocatori.php";
require_once __DIR__ . "/PanchinaDettaglio.php";
require_once __DIR__ . "/../mailer.php";

const ASG_PARAM_GIORNATA = "ASG_GIORNATA";

/** Pseudo id delle due compagini (ID_SQUADRA nelle tabelle ASG). */
const ASG_ID_EST   = 901;
const ASG_ID_OVEST = 902;

/** Codice usato come "girone" nella valutazione di supplementari e rigori. */
const ASG_CODICE_TURNO = "AS";

/** Partita su campo neutro: il fattore casa non si applica. */
const ASG_FATTORE_CASA_APPLICATO = false;

const ASG_TAB_FORMAZIONI = "NEW_FORMAZIONI_ASG";
const ASG_TAB_RIGORISTI  = "NEW_RIGORISTI_ASG";
const ASG_TAB_STATO      = "NEW_ASG_STATO";
const ASG_TAB_OK         = "NEW_ASG_OK";
const ASG_TAB_VOTI       = "NEW_VOTI_ASG";
const ASG_TAB_RISULTATI  = "NEW_RISULTATI_ASG";
const ASG_TAB_SIM_VOTI      = "NEW_SIMULAZIONE_VOTI_ASG";
const ASG_TAB_SIM_RISULTATI = "NEW_SIMULAZIONE_RISULTATI_ASG";

// ------------------------------------------------------------
// Configurazione e compagini
// ------------------------------------------------------------

/** Giornata di fantacampionato dell'All Star Game; null se non configurata. */
function asg_giornata_config(int $stagione): ?int
{
    $r = query_one("SELECT valore FROM NEW_PARAMETRI_STAGIONE
                    WHERE stagione = $stagione AND codice = '" . ASG_PARAM_GIORNATA . "'");
    $v = $r ? trim((string) $r["valore"]) : "";
    return ($v !== "" && ctype_digit($v) && (int) $v >= 1) ? (int) $v : null;
}

/** L'All Star Game si gioca proprio in questa giornata? */
function asg_in_giornata(int $stagione, int $giornata): bool
{
    return asg_giornata_config($stagione) === $giornata;
}

/** Lo pseudo id indica una compagine dell'All Star Game? */
function asg_e_compagine(?int $id): bool
{
    return $id === ASG_ID_EST || $id === ASG_ID_OVEST;
}

/** Compagine (pseudo id) a cui appartiene una squadra di lega: id dispari = Est, pari = Ovest. */
function asg_lato_di_squadra(int $idSquadra): int
{
    return $idSquadra % 2 === 1 ? ASG_ID_EST : ASG_ID_OVEST;
}

function asg_nome_lato(int $idLato): string
{
    return $idLato === ASG_ID_EST ? "Est" : "Ovest";
}

function asg_altro_lato(int $idLato): int
{
    return $idLato === ASG_ID_EST ? ASG_ID_OVEST : ASG_ID_EST;
}

/**
 * Squadre di lega (con allenatore ed email) di una compagine.
 * @return array<int,array{id:int,nome:string,logo:string,allenatore:?string,email:string}>
 */
function asg_squadre_lato(int $stagione, int $idLato): array
{
    $resto = $idLato === ASG_ID_EST ? 1 : 0;
    $righe = query_all("SELECT s.id, s.nome, s.logo, a.descrizione AS allenatore, a.email
                        FROM NEW_SQUADRE s
                        LEFT JOIN NEW_ALLENATORI a ON a.id_squadra = s.id AND a.stagione = s.stagione
                        WHERE s.stagione = $stagione AND MOD(s.id, 2) = $resto
                        ORDER BY s.id");
    return array_map(fn($r) => [
        "id"         => (int) $r["id"],
        "nome"       => (string) $r["nome"],
        "logo"       => (string) $r["logo"],
        "allenatore" => $r["allenatore"],
        "email"      => (string) ($r["email"] ?? ""),
    ], $righe);
}

/** Id delle squadre di lega di una compagine. */
function asg_ids_squadre_lato(int $stagione, int $idLato): array
{
    return array_map(fn($s) => $s["id"], asg_squadre_lato($stagione, $idLato));
}

/**
 * Rosa della compagine: unione delle rose delle sue squadre, giocatori ceduti
 * (non più nel listone) esclusi. Ogni giocatore riporta la squadra di lega di appartenenza.
 */
function asg_rosa(int $stagione, int $idLato): array
{
    $ids = asg_ids_squadre_lato($stagione, $idLato);
    if (!$ids) return [];
    $fuori = lg_fuori_lista($stagione);
    $righe = query_all("SELECT g.id, g.descrizione, g.ruolo, g.nazione, g.squadra AS id_squadra, s.nome AS squadra
                        FROM NEW_GIOCATORI g
                        JOIN NEW_SQUADRE s ON s.id = g.squadra AND s.stagione = g.stagione
                        WHERE g.stagione = $stagione AND g.squadra IN (" . implode(",", $ids) . ")
                        ORDER BY g.ruolo, g.descrizione");
    $out = [];
    foreach ($righe as $r) {
        $id = (int) $r["id"];
        if (isset($fuori[$id])) continue;
        $out[] = [
            "id"          => $id,
            "descrizione" => $r["descrizione"],
            "ruolo"       => (int) $r["ruolo"],
            "nazione"     => $r["nazione"],
            "id_squadra"  => (int) $r["id_squadra"],
            "squadra"     => $r["squadra"],
        ];
    }
    return $out;
}

// ------------------------------------------------------------
// Stato della formazione, ok e invio
// ------------------------------------------------------------

/** Stato salvato della formazione di una compagine (default: bozza non inviata). */
function asg_stato_lato(int $stagione, int $giornata, int $idLato): array
{
    $r = query_one("SELECT INVIATA, INVIATA_DA, INVIATA_IL, AGGIORNATA_DA, AGGIORNATA_IL
                    FROM " . ASG_TAB_STATO . "
                    WHERE STAGIONE = $stagione AND GIORNATA = $giornata AND ID_SQUADRA = $idLato");
    return [
        "inviata"        => $r && (int) $r["INVIATA"] === 1,
        "inviata_da"     => $r && $r["INVIATA_DA"] !== null ? (int) $r["INVIATA_DA"] : null,
        "inviata_il"     => $r["INVIATA_IL"] ?? null,
        "aggiornata_da"  => $r && $r["AGGIORNATA_DA"] !== null ? (int) $r["AGGIORNATA_DA"] : null,
        "aggiornata_il"  => $r["AGGIORNATA_IL"] ?? null,
    ];
}

/** Righe della formazione (giocatore, ruolo, maglia) della compagine, ordinate per maglia. */
function asg_formazione(int $stagione, int $giornata, int $idLato): array
{
    $righe = query_all("SELECT f.ID_GIOCATORE AS id_giocatore, g.descrizione AS giocatore, g.ruolo, f.MAGLIA AS maglia
                        FROM " . ASG_TAB_FORMAZIONI . " f
                        JOIN " . rs_sql_anagrafica($stagione) . " g ON g.id = f.ID_GIOCATORE AND g.stagione = f.STAGIONE
                        WHERE f.STAGIONE = $stagione AND f.ID_SQUADRA = $idLato AND f.GIORNATA = $giornata
                        ORDER BY f.MAGLIA");
    return array_map(fn($r) => [
        "id_giocatore" => (int) $r["id_giocatore"],
        "giocatore"    => $r["giocatore"],
        "ruolo"        => (int) $r["ruolo"],
        "maglia"       => (int) $r["maglia"],
    ], $righe);
}

/** Formazione completa = titolari con le maglie 1..11, ciascuna una sola volta. */
function asg_formazione_completa(array $righe): bool
{
    $maglie = [];
    foreach ($righe as $r) {
        $m = (int) $r["maglia"];
        if ($m >= 1 && $m <= 11) $maglie[] = $m;
    }
    sort($maglie);
    return $maglie === range(1, 11);
}

/** Ordine dei rigoristi salvato: [id_giocatore, ...] già ordinato. */
function asg_rigoristi(int $stagione, int $giornata, int $idLato): array
{
    return array_map(fn($r) => (int) $r["id_giocatore"], query_all(
        "SELECT ID_GIOCATORE AS id_giocatore FROM " . ASG_TAB_RIGORISTI . "
         WHERE STAGIONE = $stagione AND GIORNATA = $giornata AND ID_SQUADRA = $idLato
         ORDER BY ORDINE"));
}

/**
 * Elenco dei fantallenatori della compagine con l'ok registrato sulla bozza corrente.
 * @return array<int,array{id:int,nome:string,allenatore:?string,ok:bool,ok_il:?string}>
 */
function asg_elenco_ok(int $stagione, int $giornata, int $idLato): array
{
    $ok = [];
    foreach (query_all("SELECT ID_SQUADRA, OK_IL FROM " . ASG_TAB_OK . "
                        WHERE STAGIONE = $stagione AND GIORNATA = $giornata AND ID_LATO = $idLato") as $r) {
        $ok[(int) $r["ID_SQUADRA"]] = $r["OK_IL"];
    }
    return array_map(fn($s) => [
        "id"         => $s["id"],
        "nome"       => $s["nome"],
        "logo"       => $s["logo"],
        "allenatore" => $s["allenatore"],
        "ok"         => isset($ok[$s["id"]]),
        "ok_il"      => $ok[$s["id"]] ?? null,
    ], asg_squadre_lato($stagione, $idLato));
}

/** Tutti i fantallenatori della compagine hanno dato l'ok? */
function asg_tutti_ok(array $elencoOk): bool
{
    if (!$elencoOk) return false;
    foreach ($elencoOk as $o) if (!$o["ok"]) return false;
    return true;
}

/**
 * Condizioni per poter modificare/inviare la formazione.
 * @return array{ok:bool, motivo:?string, codice:int}
 */
function asg_modificabile(int $stagione, int $giornata): array
{
    $cfg = asg_giornata_config($stagione);
    if ($cfg === null) return ["ok" => false, "motivo" => "L'All Star Game non è previsto in questa stagione", "codice" => 403];
    if ($cfg !== $giornata) {
        return ["ok" => false, "motivo" => "L'All Star Game si gioca alla giornata $cfg: la formazione si inserisce solo in quella giornata", "codice" => 403];
    }
    if (sg_giornata_chiusa($stagione, $giornata)) {
        return ["ok" => false, "motivo" => "Giornata già chiusa, impossibile modificare la formazione dell'All Star Game", "codice" => 403];
    }
    $blocco = fb_stato_blocco($stagione, $giornata);
    if ($blocco["bloccata"]) {
        return ["ok" => false, "motivo" => "Inserimento formazione chiuso: il limite era "
            . date("d/m/Y H:i", strtotime($blocco["limite"]))
            . " (un minuto prima della prima partita di Serie A della giornata).", "codice" => 403];
    }
    return ["ok" => true, "motivo" => null, "codice" => 200];
}

/**
 * Salva la bozza di formazione della compagine (sostituisce quella precedente), con
 * l'ordine dei rigoristi. Gli ok (deleghe) restano validi. Solleva Exception($msg, $codiceHttp).
 *
 * @param array $giocatori [{id, maglia}]
 * @param array|null $rigoristi  id in ordine di tiro (titolari poi panchinari); null = invariato
 */
function asg_salva_formazione(int $stagione, int $giornata, int $idLato, int $idCoach, array $giocatori, ?array $rigoristi): void
{
    global $conn;

    $mod = asg_modificabile($stagione, $giornata);
    if (!$mod["ok"]) throw new Exception($mod["motivo"], $mod["codice"]);

    if (asg_lato_di_squadra($idCoach) !== $idLato) {
        throw new Exception("Non fai parte della compagine " . asg_nome_lato($idLato), 403);
    }
    if (!in_array($idCoach, asg_ids_squadre_lato($stagione, $idLato), true)) {
        throw new Exception("Squadra non presente in questa stagione", 403);
    }
    $stato = asg_stato_lato($stagione, $giornata, $idLato);
    if ($stato["inviata"]) {
        throw new Exception("La formazione è già stata inviata: non è più modificabile", 409);
    }

    // Validazione: giocatori della rosa della compagine, senza doppioni, titolari 1..11 con un portiere
    $rosa = [];
    foreach (asg_rosa($stagione, $idLato) as $p) $rosa[$p["id"]] = $p;

    $viste = [];
    $maglie = [];
    $righe = [];
    foreach ($giocatori as $g) {
        $id = (int) ($g["id"] ?? 0);
        $maglia = (int) ($g["maglia"] ?? 0);
        if ($id <= 0) continue;
        if (!isset($rosa[$id])) {
            throw new Exception("Il giocatore $id non appartiene alla rosa dell'All Star Game " . asg_nome_lato($idLato) . " (o è stato ceduto)", 422);
        }
        if (isset($viste[$id])) throw new Exception("Giocatore presente più volte in formazione", 422);
        if ($maglia < 1) throw new Exception("Numero di maglia non valido", 422);
        if (isset($maglie[$maglia])) throw new Exception("Numero di maglia $maglia assegnato più volte", 422);
        $viste[$id] = true;
        $maglie[$maglia] = true;
        $righe[] = ["id" => $id, "maglia" => $maglia];
    }
    if (!asg_formazione_completa(array_map(fn($r) => ["maglia" => $r["maglia"]], $righe))) {
        throw new Exception("Formazione incompleta: servono 11 titolari (maglie da 1 a 11)", 422);
    }
    $portieri = 0;
    foreach ($righe as $r) if ($r["maglia"] <= 11 && $rosa[$r["id"]]["ruolo"] === 1) $portieri++;
    if ($portieri !== 1) throw new Exception("Tra i titolari serve esattamente un portiere", 422);

    mysqli_begin_transaction($conn);
    try {
        $tab = ASG_TAB_FORMAZIONI;
        if (!mysqli_query($conn, "DELETE FROM $tab WHERE STAGIONE = $stagione AND ID_SQUADRA = $idLato AND GIORNATA = $giornata")) {
            throw new Exception("Scrittura $tab: " . mysqli_error($conn));
        }
        foreach ($righe as $r) {
            if (!mysqli_query($conn, "INSERT INTO $tab (STAGIONE, ID_SQUADRA, ID_GIOCATORE, GIORNATA, MAGLIA)
                                      VALUES ($stagione, $idLato, {$r['id']}, $giornata, {$r['maglia']})")) {
                throw new Exception("Scrittura $tab: " . mysqli_error($conn));
            }
        }

        if ($rigoristi !== null) {
            asg_salva_rigoristi($stagione, $giornata, $idLato, $rigoristi);
        } else {
            // Formazione cambiata ma ordine non inviato: si tengono solo i rigoristi ancora in formazione
            mysqli_query($conn, "DELETE r FROM " . ASG_TAB_RIGORISTI . " r
                                 LEFT JOIN $tab f ON f.STAGIONE = r.STAGIONE AND f.GIORNATA = r.GIORNATA
                                                 AND f.ID_SQUADRA = r.ID_SQUADRA AND f.ID_GIOCATORE = r.ID_GIOCATORE
                                 WHERE r.STAGIONE = $stagione AND r.GIORNATA = $giornata AND r.ID_SQUADRA = $idLato
                                   AND f.ID_GIOCATORE IS NULL");
        }

        $tabS = ASG_TAB_STATO;
        mysqli_query($conn, "DELETE FROM $tabS WHERE STAGIONE = $stagione AND GIORNATA = $giornata AND ID_SQUADRA = $idLato");
        if (!mysqli_query($conn, "INSERT INTO $tabS (STAGIONE, GIORNATA, ID_SQUADRA, INVIATA, AGGIORNATA_DA, AGGIORNATA_IL)
                                  VALUES ($stagione, $giornata, $idLato, 0, $idCoach, NOW())")) {
            throw new Exception("Scrittura $tabS: " . mysqli_error($conn));
        }
        mysqli_commit($conn);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        throw new Exception($e->getMessage(), $e->getCode() >= 400 ? $e->getCode() : 500);
    }
}

/**
 * Salva l'ordine dei rigoristi della compagine. L'ordine ricevuto è riportato alla
 * formazione realmente salvata (stessa logica di formazioni.php): titolari prima dei
 * panchinari, chi manca nella lista segue per numero di maglia.
 */
function asg_salva_rigoristi(int $stagione, int $giornata, int $idLato, array $ids): int
{
    global $conn;
    mysqli_query($conn, "DELETE FROM " . ASG_TAB_RIGORISTI . "
                         WHERE STAGIONE = $stagione AND GIORNATA = $giornata AND ID_SQUADRA = $idLato");
    if (count($ids) === 0) return 0;

    $maglia = [];
    foreach (query_all("SELECT ID_GIOCATORE AS id, MAGLIA AS maglia FROM " . ASG_TAB_FORMAZIONI . "
                        WHERE STAGIONE = $stagione AND ID_SQUADRA = $idLato AND GIORNATA = $giornata
                        ORDER BY MAGLIA") as $r) {
        $maglia[(int) $r["id"]] = (int) $r["maglia"];
    }
    $ricevuti = [];
    foreach ($ids as $id) {
        $id = (int) $id;
        if (isset($maglia[$id]) && !in_array($id, $ricevuti, true)) $ricevuti[] = $id;
    }
    $ordinati = [];
    foreach ([false, true] as $panchina) {
        $gruppo = array_values(array_filter(array_keys($maglia), fn($id) => ($maglia[$id] > 11) === $panchina));
        foreach ($ricevuti as $id) if (in_array($id, $gruppo, true)) $ordinati[] = $id;
        $mancanti = array_values(array_filter($gruppo, fn($id) => !in_array($id, $ricevuti, true)));
        usort($mancanti, fn($a, $b) => $maglia[$a] <=> $maglia[$b]);
        foreach ($mancanti as $id) $ordinati[] = $id;
    }
    foreach ($ordinati as $i => $id) {
        $ordine = $i + 1;
        if (!mysqli_query($conn, "INSERT INTO " . ASG_TAB_RIGORISTI . " (STAGIONE, GIORNATA, ID_SQUADRA, ID_GIOCATORE, ORDINE)
                                  VALUES ($stagione, $giornata, $idLato, $id, $ordine)")) {
            throw new Exception("Scrittura " . ASG_TAB_RIGORISTI . ": " . mysqli_error($conn));
        }
    }
    return count($ordinati);
}

/** Registra (o revoca) l'ok-delega del fantallenatore (valido a prescindere dalle modifiche alla bozza). Solleva Exception($msg, $codice). */
function asg_imposta_ok(int $stagione, int $giornata, int $idLato, int $idCoach, bool $ok): void
{
    global $conn;
    $mod = asg_modificabile($stagione, $giornata);
    if (!$mod["ok"]) throw new Exception($mod["motivo"], $mod["codice"]);
    if (!in_array($idCoach, asg_ids_squadre_lato($stagione, $idLato), true)) {
        throw new Exception("Non fai parte della compagine " . asg_nome_lato($idLato), 403);
    }
    if (asg_stato_lato($stagione, $giornata, $idLato)["inviata"]) {
        throw new Exception("La formazione è già stata inviata", 409);
    }
    mysqli_query($conn, "DELETE FROM " . ASG_TAB_OK . "
                         WHERE STAGIONE = $stagione AND GIORNATA = $giornata AND ID_SQUADRA = $idCoach");
    if ($ok && !mysqli_query($conn, "INSERT INTO " . ASG_TAB_OK . " (STAGIONE, GIORNATA, ID_SQUADRA, ID_LATO, OK_IL)
                                     VALUES ($stagione, $giornata, $idCoach, $idLato, NOW())")) {
        throw new Exception("Scrittura " . ASG_TAB_OK . ": " . mysqli_error($conn), 500);
    }
}

/**
 * Invia la formazione: possibile solo con l'ok di TUTTI i fantallenatori della
 * compagine; può farlo chiunque ne faccia parte. Notifica la formazione via mail.
 * Solleva Exception($msg, $codice).
 *
 * @return array  esito delle mail: [{squadra, allenatore, ok, motivo}]
 */
function asg_invia_formazione(int $stagione, int $giornata, int $idLato, int $idCoach): array
{
    global $conn;
    $mod = asg_modificabile($stagione, $giornata);
    if (!$mod["ok"]) throw new Exception($mod["motivo"], $mod["codice"]);
    if (!in_array($idCoach, asg_ids_squadre_lato($stagione, $idLato), true)) {
        throw new Exception("Non fai parte della compagine " . asg_nome_lato($idLato), 403);
    }
    if (asg_stato_lato($stagione, $giornata, $idLato)["inviata"]) {
        throw new Exception("La formazione è già stata inviata", 409);
    }
    $righe = asg_formazione($stagione, $giornata, $idLato);
    if (!asg_formazione_completa($righe)) {
        throw new Exception("Formazione incompleta: non si può inviare", 409);
    }
    $elenco = asg_elenco_ok($stagione, $giornata, $idLato);
    if (!asg_tutti_ok($elenco)) {
        $mancanti = array_map(fn($o) => $o["allenatore"] ?: $o["nome"], array_filter($elenco, fn($o) => !$o["ok"]));
        throw new Exception("Per inviare serve l'ok di tutti i fantallenatori. Mancano: " . implode(", ", $mancanti), 409);
    }

    mysqli_begin_transaction($conn);
    try {
        $t = ASG_TAB_STATO;
        mysqli_query($conn, "DELETE FROM $t WHERE STAGIONE = $stagione AND GIORNATA = $giornata AND ID_SQUADRA = $idLato");
        if (!mysqli_query($conn, "INSERT INTO $t (STAGIONE, GIORNATA, ID_SQUADRA, INVIATA, INVIATA_DA, INVIATA_IL, AGGIORNATA_DA, AGGIORNATA_IL)
                                  VALUES ($stagione, $giornata, $idLato, 1, $idCoach, NOW(), $idCoach, NOW())")) {
            throw new Exception("Scrittura $t: " . mysqli_error($conn));
        }
        mysqli_commit($conn);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        throw new Exception($e->getMessage(), 500);
    }

    // La notifica non deve mai far fallire l'invio già registrato
    try {
        return asg_notifica_mail($stagione, $giornata, $idLato, $idCoach, $righe);
    } catch (Throwable $e) {
        error_log("asg_notifica_mail: " . $e->getMessage());
        return [];
    }
}

/** Corpo testuale della mail con la formazione inviata. */
function asg_corpo_mail(int $stagione, int $giornata, int $idLato, string $inviataDa, array $righe, array $rigoristi): string
{
    $ruoloLabel = [1 => "Portiere", 2 => "Difensore", 3 => "Centrocampista", 4 => "Attaccante"];
    $titolari = array_values(array_filter($righe, fn($r) => $r["maglia"] >= 1 && $r["maglia"] <= 11));
    $panchina = array_values(array_filter($righe, fn($r) => $r["maglia"] >= 12));
    $conteggio = [2 => 0, 3 => 0, 4 => 0];
    foreach ($titolari as $r) if (isset($conteggio[$r["ruolo"]])) $conteggio[$r["ruolo"]]++;

    $out = [];
    $out[] = "All Star Game — formazione " . asg_nome_lato($idLato) . " INVIATA";
    $out[] = "Giornata $giornata · Stagione $stagione/" . ($stagione + 1);
    $out[] = "Modulo {$conteggio[2]}-{$conteggio[3]}-{$conteggio[4]}";
    $out[] = "Inviata da: " . $inviataDa . " (con la delega di tutti i fantallenatori della compagine)";
    $out[] = "";
    $out[] = "TITOLARI";
    foreach ($titolari as $r) $out[] = $r["maglia"] . ". " . ($ruoloLabel[$r["ruolo"]] ?? "Ruolo " . $r["ruolo"]) . " — " . $r["giocatore"];
    if ($panchina) {
        $out[] = "";
        $out[] = "PANCHINA";
        foreach ($panchina as $r) $out[] = $r["maglia"] . ". " . ($ruoloLabel[$r["ruolo"]] ?? "Ruolo " . $r["ruolo"]) . " — " . $r["giocatore"];
    }
    if ($rigoristi) {
        $nome = [];
        foreach ($righe as $r) $nome[$r["id_giocatore"]] = $r["giocatore"];
        $out[] = "";
        $out[] = "ORDINE DEI RIGORISTI";
        foreach ($rigoristi as $i => $id) $out[] = ($i + 1) . ". " . ($nome[$id] ?? ("giocatore " . $id));
    }
    $out[] = "";
    $out[] = "La formazione è definitiva e non è più modificabile.";
    return implode("\n", $out);
}

/**
 * Notifica via mail la formazione inviata a TUTTI i fantallenatori (Est e Ovest).
 * @return array [{squadra, allenatore, ok, motivo}]
 */
function asg_notifica_mail(int $stagione, int $giornata, int $idLato, int $idCoach, array $righe): array
{
    $squadre = array_merge(asg_squadre_lato($stagione, ASG_ID_EST), asg_squadre_lato($stagione, ASG_ID_OVEST));
    $mittente = "";
    foreach ($squadre as $s) if ($s["id"] === $idCoach) $mittente = ($s["allenatore"] ?: "") !== "" ? $s["allenatore"] . " (" . $s["nome"] . ")" : $s["nome"];
    $corpo = asg_corpo_mail($stagione, $giornata, $idLato, $mittente, $righe, asg_rigoristi($stagione, $giornata, $idLato));
    $oggetto = "All Star Game · Formazione " . asg_nome_lato($idLato) . " · Giornata $giornata";

    $esiti = [];
    foreach ($squadre as $s) {
        $r = invia_email($s["email"], $oggetto, $corpo);
        $esiti[] = ["squadra" => $s["nome"], "allenatore" => $s["allenatore"], "ok" => $r["ok"], "motivo" => $r["motivo"]];
    }
    return $esiti;
}

/**
 * La formazione della compagine è visibile a chi guarda? Sì se il richiedente è
 * della compagine, se la prima partita di Serie A della giornata è iniziata o se la
 * giornata è chiusa; altrimenti resta riservata (come per le formazioni nascoste).
 */
function asg_formazione_visibile(int $stagione, int $giornata, int $idLato, ?int $idRichiedente): bool
{
    if ($idRichiedente !== null && $idRichiedente > 0 && asg_lato_di_squadra($idRichiedente) === $idLato) return true;
    if (sg_giornata_chiusa($stagione, $giornata)) return true;
    if ($stagione !== sg_ultima_stagione()) return true;
    $inizio = fb_inizio_giornata_ts($stagione, $giornata);
    return $inizio !== null && time() >= $inizio;
}

// ------------------------------------------------------------
// Risultato "reale" (NEW_RISULTATI_ASG) e dettaglio
// ------------------------------------------------------------

/**
 * Scrive UNA riga di NEW_RISULTATI_ASG (prospettiva di una compagine). Stessa
 * formula punti/segno di salvaRigaRisultatoChampions(); golf/gols sono i gol dei
 * tempi regolamentari, $extra porta supplementari/rigori (champions_colonne_extra).
 */
function asg_salva_riga_risultato(
    $conn, int $stagione, int $giornata, int $idLato, int $idLatoA,
    float $ftotale, float $ftotaleA, int $golf, int $gols,
    int $modificatore, int $modificatoreA, float $modAtt,
    int $numCc, float $totCc, float $modCc, int $fattoreCampo, array $extra = []
): void {
    if ($golf > $gols)       { $punti = 3; $segno = "V"; }
    elseif ($golf === $gols) { $punti = 1; $segno = "N"; }
    else                     { $punti = 0; $segno = "P"; }

    $f = fn(float $v) => sprintf("%.2f", $v);
    $ex = $extra + [
        "supp_giocati" => 0, "supp_ftotale" => 0.0, "supp_golf" => 0, "supp_gols" => 0,
        "rig_giocati" => 0, "rig_golf" => 0, "rig_gols" => 0, "passa" => 0, "dett_extra" => null,
    ];
    $dett = $ex["dett_extra"] === null ? "NULL" : "'" . mysqli_real_escape_string($conn, (string) $ex["dett_extra"]) . "'";

    mysqli_query($conn, "DELETE FROM " . ASG_TAB_RISULTATI . "
                         WHERE stagione = $stagione AND giornata = $giornata AND id_squadra = $idLato");
    $ok = mysqli_query($conn, "INSERT INTO " . ASG_TAB_RISULTATI . "
        (stagione, giornata, id_squadra, id_squadra_a, ftotale, ftotale_a, golf, gols,
         modificatore, modificatore_a, punti, fattore_campo, segno, mod_att, num_cc, tot_cc, mod_cc,
         supp_giocati, supp_ftotale, supp_golf, supp_gols, rig_giocati, rig_golf, rig_gols, passa, dett_extra)
        VALUES ($stagione, $giornata, $idLato, $idLatoA, {$f($ftotale)}, {$f($ftotaleA)}, $golf, $gols,
                $modificatore, $modificatoreA, $punti, $fattoreCampo, '$segno', {$f($modAtt)}, $numCc, {$f($totCc)}, {$f($modCc)},
                " . (int) $ex["supp_giocati"] . ", {$f((float) $ex["supp_ftotale"])}, " . (int) $ex["supp_golf"] . ", " . (int) $ex["supp_gols"] . ",
                " . (int) $ex["rig_giocati"] . ", " . (int) $ex["rig_golf"] . ", " . (int) $ex["rig_gols"] . ", " . (int) $ex["passa"] . ", $dett)");
    if (!$ok) throw new Exception("Scrittura " . ASG_TAB_RISULTATI . ": " . mysqli_error($conn));
}

/** Supplementari e rigori nel formato del front end (come dp_extra_champions), null se assenti. */
function asg_extra_da_riga(array $r): ?array
{
    $supp = (int) ($r["supp_giocati"] ?? 0) === 1;
    $rig  = (int) ($r["rig_giocati"] ?? 0) === 1;
    $passaCasa   = (int) ($r["passa_casa"] ?? 0) === 1;
    $passaOspite = (int) ($r["passa_ospite"] ?? 0) === 1;
    if (!$supp && !$rig && !$passaCasa && !$passaOspite) return null;

    $dett = !empty($r["dett_extra"]) ? json_decode($r["dett_extra"], true) : null;
    return [
        "supplementari" => $supp,
        "rigori"        => $rig,
        "supp_golf"     => ["casa" => (int) $r["supp_golf"], "ospite" => (int) $r["supp_gols"]],
        "supp_punti"    => ["casa" => (float) ($r["supp_ftotale_casa"] ?? 0), "ospite" => (float) ($r["supp_ftotale_ospite"] ?? 0)],
        "rig_golf"      => ["casa" => (int) $r["rig_golf"], "ospite" => (int) $r["rig_gols"]],
        "passa"         => $passaCasa ? "casa" : ($passaOspite ? "ospite" : null),
        "andata"        => null,
        "dettaglio"     => $dett,
    ];
}

/** Esiste il risultato calcolato (NEW_RISULTATI_ASG) della giornata? */
function asg_ha_risultato_reale(int $stagione, int $giornata): bool
{
    $n = query_one("SELECT COUNT(*) AS n FROM " . ASG_TAB_RISULTATI . "
                    WHERE stagione = $stagione AND giornata = $giornata");
    return (int) ($n["n"] ?? 0) > 0;
}

/**
 * Dettaglio voti della partita (stessa struttura di live_dettaglio.php /
 * dettaglio_partita.php, così il front end riusa MatchDetailPanel).
 * $fonte: "auto" (calcolato se presente, altrimenti simulazione) | "reale" | "simulazione".
 * Ritorna null se non ci sono dati.
 */
function asg_dettaglio(int $stagione, int $giornata, string $fonte = "auto"): ?array
{
    global $conn;
    if ($fonte === "auto") $fonte = asg_ha_risultato_reale($stagione, $giornata) ? "reale" : "simulazione";

    $tRis  = $fonte === "reale" ? ASG_TAB_RISULTATI : ASG_TAB_SIM_RISULTATI;
    $tVoti = $fonte === "reale" ? ASG_TAB_VOTI : ASG_TAB_SIM_VOTI;
    $tForm = ASG_TAB_FORMAZIONI;
    $idCasa = ASG_ID_EST;

    $extraRis = $fonte === "reale" ? "NULL AS calcolato_il, NULL AS simulato_da" : "r.calcolato_il, r.simulato_da";
    $rc = $fonte === "reale" ? false : @mysqli_query($conn, "SHOW COLUMNS FROM $tVoti LIKE 'parziale'");
    $conApi = $rc && mysqli_num_rows($rc) > 0;
    $extra = $fonte === "reale" ? "0 AS provvisorio, 0 AS manuale, 0 AS api, 0 AS parziale"
           : "v.provvisorio, v.manuale" . ($conApi ? ", v.api, v.parziale" : ", 0 AS api, 0 AS parziale");

    $r = query_one("SELECT
            r.id_squadra, r.id_squadra_a,
            r.ftotale, r.ftotale_a, r.golf, r.gols, r.modificatore, r.modificatore_a, r.punti, r.segno,
            $extraRis,
            r.supp_giocati, r.supp_ftotale AS supp_ftotale_casa, r2.supp_ftotale AS supp_ftotale_ospite, r.supp_golf, r.supp_gols,
            r.rig_giocati, r.rig_golf, r.rig_gols, r.passa AS passa_casa, r2.passa AS passa_ospite, r.dett_extra,
            r.mod_att AS mod_att_casa, r.mod_cc AS mod_cc_casa, r.num_cc AS num_cc_casa, r.tot_cc AS tot_cc_casa,
            r2.mod_att AS mod_att_ospite, r2.mod_cc AS mod_cc_ospite,
            r2.num_cc AS num_cc_ospite, r2.tot_cc AS tot_cc_ospite,
            r2.modificatore_a AS modificatore_a_ospite
        FROM $tRis r
        LEFT JOIN $tRis r2 ON r2.stagione = r.stagione AND r2.giornata = r.giornata
                          AND r2.id_squadra = r.id_squadra_a AND r2.id_squadra_a = r.id_squadra
        WHERE r.stagione = $stagione AND r.giornata = $giornata AND r.id_squadra = $idCasa");
    if ($r === null) return null;

    $id_casa   = (int) $r["id_squadra"];
    $id_ospite = (int) $r["id_squadra_a"];

    $voti_raw = query_all("SELECT
            v.id_squadra, v.id_giocatore,
            COALESCE(g.descrizione, 'Riserva d''ufficio') AS giocatore,
            COALESCE(g.ruolo, FLOOR(ABS(v.id_giocatore) / 1000000)) AS ruolo,
            f.MAGLIA AS maglia, v.rufficio, (g.id IS NULL) AS ufficio_fittizio,
            v.voto, v.totale, v.giocata,
            v.reti, v.ammonizioni, v.espulsioni, v.autogol,
            v.retis, v.rigores, v.rigorep, v.assist,
            $extra
        FROM $tVoti v
        LEFT JOIN " . rs_sql_anagrafica($stagione) . " g ON g.id = v.id_giocatore AND g.stagione = v.stagione
        LEFT JOIN $tForm f ON f.ID_GIOCATORE = v.id_giocatore AND f.ID_SQUADRA = v.id_squadra
                          AND f.STAGIONE = v.stagione AND f.GIORNATA = v.giornata
        WHERE v.stagione = $stagione AND v.giornata = $giornata
          AND v.id_squadra IN ($id_casa, $id_ospite)
        ORDER BY v.id_squadra, ruolo, v.totale DESC");

    $per_squadra = [];
    foreach ($voti_raw as $v) {
        $v["ruolo"]            = (int) $v["ruolo"];
        $v["riserva_ufficio"]  = (int) $v["rufficio"] === 1;
        $v["ufficio_fittizio"] = (int) $v["ufficio_fittizio"] === 1;
        $v["provvisorio"]      = (int) $v["provvisorio"] === 1;
        $v["manuale"]          = (int) $v["manuale"] === 1;
        $v["api"]              = (int) $v["api"] === 1;
        $v["parziale"]         = (int) $v["parziale"] === 1;
        $v["subentrato"]       = (int) ($v["maglia"] ?? 0) > 11 && (int) $v["giocata"] === 1 && !$v["riserva_ufficio"];
        $v["origine"] = $v["riserva_ufficio"] ? "ufficio"
                      : ($v["provvisorio"] ? "provvisorio"
                      : ($v["manuale"] ? "manuale"
                      : ($v["api"] ? ($v["parziale"] ? "api_parziale" : "api")
                      : ((int) $v["giocata"] === 1 ? "reale" : "senza_voto"))));
        $per_squadra[(int) $v["id_squadra"]][] = $v;
    }

    $form_per_squadra = pd_carica_formazione($stagione, $giornata, $tForm, [$id_casa, $id_ospite]);
    $panchina_per_squadra = [];
    foreach ([$id_casa, $id_ospite] as $idq) {
        $voti_sq = $per_squadra[$idq] ?? [];
        $panchina_per_squadra[$idq] = pd_costruisci_panchina($form_per_squadra[$idq] ?? [], $voti_sq);
        if ($voti_sq) $per_squadra[$idq] = $voti_sq;
    }

    $num = fn($x) => $x !== null ? (float) $x : null;
    $int = fn($x) => $x !== null ? (int) $x : null;

    return [
        "fonte"        => $fonte,
        "competizione" => "ASG",
        "giornata"     => $giornata,
        "casa" => [
            "id" => $id_casa, "nome" => asg_nome_lato($id_casa), "logo" => null,
            "ftotale" => (float) $r["ftotale"],
            "mod_dif" => 0 - (int) $r["modificatore_a"],
            "mod_cc"  => $num($r["mod_cc_casa"]),  "mod_att" => $num($r["mod_att_casa"]),
            "num_cc"  => $int($r["num_cc_casa"]),  "tot_cc"  => $num($r["tot_cc_casa"]),
            "giocatori" => $per_squadra[$id_casa] ?? [],
            "panchina"  => $panchina_per_squadra[$id_casa] ?? [],
        ],
        "ospite" => [
            "id" => $id_ospite, "nome" => asg_nome_lato($id_ospite), "logo" => null,
            "ftotale" => (float) $r["ftotale_a"],
            "mod_dif" => $r["modificatore_a_ospite"] !== null ? 0 - (int) $r["modificatore_a_ospite"] : 0 - (int) $r["modificatore"],
            "mod_cc"  => $num($r["mod_cc_ospite"]),  "mod_att" => $num($r["mod_att_ospite"]),
            "num_cc"  => $int($r["num_cc_ospite"]),  "tot_cc"  => $num($r["tot_cc_ospite"]),
            "giocatori" => $per_squadra[$id_ospite] ?? [],
            "panchina"  => $panchina_per_squadra[$id_ospite] ?? [],
        ],
        "calcolato_il" => $r["calcolato_il"], "simulato_da" => $r["simulato_da"],
        "golf" => (int) $r["golf"], "gols" => (int) $r["gols"],
        "punti_casa" => (int) $r["punti"], "segno" => $r["segno"],
        "extra" => asg_extra_da_riga($r),
    ];
}

// ------------------------------------------------------------
// LIVE: blocco della pagina "LIVE Giornata in corso"
// ------------------------------------------------------------

/** Risultato di una riga (reale o simulazione) nel formato di live_risultato(). */
function asg_live_risultato(?array $r): ?array
{
    if ($r === null) return null;
    return [
        "ftotale_casa"   => (float) $r["ftotale"],
        "ftotale_ospite" => (float) $r["ftotale_a"],
        "golf"           => (int) $r["golf"],
        "gols"           => (int) $r["gols"],
        "punti_casa"     => (int) $r["punti"],
        "segno"          => $r["segno"],
        "calcolato_il"   => $r["calcolato_il"] ?? null,
        "simulato_da"    => $r["simulato_da"] ?? null,
        "supp"           => (int) ($r["supp_giocati"] ?? 0) === 1 ? ["casa" => (int) $r["supp_golf"], "ospite" => (int) $r["supp_gols"]] : null,
        "rig"            => (int) ($r["rig_giocati"] ?? 0) === 1 ? ["casa" => (int) $r["rig_golf"], "ospite" => (int) $r["rig_gols"]] : null,
    ];
}

/**
 * Blocco "all_star_game" di live_giornata.php: null se la giornata non ospita l'All Star Game.
 * Stessa struttura delle partite di campionato/Champions (casa = Est, ospite = Ovest).
 */
function asg_blocco_live(int $stagione, int $giornata): ?array
{
    if (!asg_in_giornata($stagione, $giornata)) return null;

    $casa   = ["id" => ASG_ID_EST,   "nome" => "Est",   "logo" => null];
    $ospite = ["id" => ASG_ID_OVEST, "nome" => "Ovest", "logo" => null];

    $reale = null; $sim = null; $flag = ["p" => 0, "m" => 0];
    try {
        $reale = query_one("SELECT ftotale, ftotale_a, golf, gols, punti, segno,
                                   supp_giocati, supp_golf, supp_gols, rig_giocati, rig_golf, rig_gols
                            FROM " . ASG_TAB_RISULTATI . "
                            WHERE stagione = $stagione AND giornata = $giornata AND id_squadra = " . ASG_ID_EST);
        $sim = query_one("SELECT ftotale, ftotale_a, golf, gols, punti, segno, calcolato_il, simulato_da,
                                 supp_giocati, supp_golf, supp_gols, rig_giocati, rig_golf, rig_gols
                          FROM " . ASG_TAB_SIM_RISULTATI . "
                          WHERE stagione = $stagione AND giornata = $giornata AND id_squadra = " . ASG_ID_EST);
        $x = query_one("SELECT COALESCE(SUM(provvisorio),0) AS p, COALESCE(SUM(manuale),0) AS m
                        FROM " . ASG_TAB_SIM_VOTI . " WHERE stagione = $stagione AND giornata = $giornata");
        $flag = ["p" => (int) ($x["p"] ?? 0), "m" => (int) ($x["m"] ?? 0)];
    } catch (Throwable $e) {
        // tabelle non ancora create (migrazione non eseguita): blocco senza risultati
    }

    $r = asg_live_risultato($reale);
    $s = asg_live_risultato($sim);
    $fonte = $r !== null ? "reale" : ($s !== null ? "simulazione" : null);

    $formazioni = [];
    foreach ([ASG_ID_EST => "est", ASG_ID_OVEST => "ovest"] as $id => $k) {
        $st = asg_stato_lato($stagione, $giornata, $id);
        $formazioni[$k] = [
            "inviata"  => $st["inviata"],
            "completa" => asg_formazione_completa(asg_formazione($stagione, $giornata, $id)),
        ];
    }

    return [
        "giornata"    => $giornata,
        "partita"     => [
            "casa" => $casa, "ospite" => $ospite,
            "fonte" => $fonte,
            "risultato" => $fonte === "reale" ? $r : $s,
            "reale" => $r, "simulazione" => $s,
            "provvisori" => $fonte !== "simulazione" ? 0 : $flag["p"],
            "manuali"    => $fonte !== "simulazione" ? 0 : $flag["m"],
        ],
        "formazioni"  => $formazioni,
        "calcolato_il" => $sim["calcolato_il"] ?? null,
    ];
}

// ------------------------------------------------------------
// SIMULAZIONE LIVE (usa le funzioni sim_* di SimulazioneGiornata.php)
// ------------------------------------------------------------

/**
 * Tabella della formazione da usare per una compagine (sempre NEW_FORMAZIONI_ASG):
 * se manca del tutto lancia un'eccezione chiara.
 */
function asg_controlla_formazione(int $stagione, int $giornata, int $idLato): void
{
    $n = query_one("SELECT COUNT(*) AS n FROM " . ASG_TAB_FORMAZIONI . "
                    WHERE STAGIONE = $stagione AND GIORNATA = $giornata AND ID_SQUADRA = $idLato AND MAGLIA BETWEEN 1 AND 11");
    if ((int) ($n["n"] ?? 0) === 0) {
        throw new Exception("All Star Game: formazione " . asg_nome_lato($idLato) . " assente");
    }
}

/**
 * Simula l'All Star Game della giornata con i voti disponibili e ricostruisce
 * NEW_SIMULAZIONE_VOTI_ASG / NEW_SIMULAZIONE_RISULTATI_ASG. Se la giornata non ospita
 * l'All Star Game non fa nulla. Ritorna: partite_elaborate, partite_saltate[], warning[],
 * provvisori, manuali, api, parziali (come sim_esegui()).
 */
function asg_sim_esegui($conn, int $stagione, int $giornata, ?string $utente = null): array
{
    mysqli_set_charset($conn, "utf8mb4");
    $vuoto = ["partite_elaborate" => 0, "partite_saltate" => [], "warning" => [], "provvisori" => 0, "manuali" => 0, "api" => 0, "parziali" => 0];
    if (!asg_in_giornata($stagione, $giornata)) return $vuoto;

    $config = caricaConfigurazioneCalcolo($conn, $stagione);
    $maxSostMovimento = (int) ($config["parametri"]["SOSTITUZIONI_MAX_MOVIMENTO"] ?? 5);
    $maxSostPortiere  = (int) ($config["parametri"]["SOSTITUZIONI_MAX_PORTIERE"] ?? 1);
    $fasceGolPunteggio = json_decode($config["parametri"]["FASCE_GOL_PUNTEGGIO"] ?? "", true) ?: [["da" => null, "a" => null, "gol" => 0]];
    $fattoreCasaValore = (float) ($config["bonus"]["FATTORE_CASA"] ?? 0);
    $regoleGol = [
        "diff_min"        => sim_parametro_numerico($config, "GOL_DISTACCO_MIN_PUNTI", 3),
        "soglia_pareggio" => sim_parametro_numerico($config, "GOL_SOGLIA_PAREGGIO", 66),
        "soglia_omaggio"  => sim_parametro_numerico($config, "GOL_SOGLIA_OMAGGIO", 59),
    ];
    $configUfficio = [
        "max"            => max(0, (int) sim_parametro_numerico($config, "RISERVE_UFFICIO_MAX", 1)),
        "voto_portiere"  => sim_parametro_numerico($config, "VOTO_UFFICIO_PORTIERE", 3),
        "voto_movimento" => sim_parametro_numerico($config, "VOTO_UFFICIO_MOVIMENTO", 4),
        "voto_ammonito"  => sim_parametro_numerico($config, "VOTO_UFFICIO_AMMONITO", 5),
    ];

    $idCasa = ASG_ID_EST;
    $idOspite = ASG_ID_OVEST;
    $tabF = ASG_TAB_FORMAZIONI;
    $warning = [];
    $saltate = [];
    $elaborate = 0;
    $ctx = sim_contesto($stagione, $giornata);

    mysqli_begin_transaction($conn);
    try {
        foreach ([ASG_TAB_SIM_VOTI, ASG_TAB_SIM_RISULTATI] as $t) {
            if (!mysqli_query($conn, "DELETE FROM $t WHERE stagione = $stagione AND giornata = $giornata")) {
                throw new Exception("Pulizia $t: " . mysqli_error($conn));
            }
        }

        try {
            asg_controlla_formazione($stagione, $giornata, $idCasa);
            asg_controlla_formazione($stagione, $giornata, $idOspite);
            $warnP = []; $ngCasa = []; $ngOspite = [];
            $formCasa   = sim_carica_formazione($ctx, $stagione, $giornata, $idCasa,   $warnP, $ngCasa,   $maxSostMovimento, $maxSostPortiere, $configUfficio, $tabF);
            $formOspite = sim_carica_formazione($ctx, $stagione, $giornata, $idOspite, $warnP, $ngOspite, $maxSostMovimento, $maxSostPortiere, $configUfficio, $tabF);
        } catch (Throwable $e) {
            $saltate[] = $e->getMessage();
            mysqli_commit($conn);
            return ["partite_saltate" => $saltate] + $vuoto;
        }
        foreach ($warnP as $w) $warning[] = "All Star Game - " . $w;

        // Formazione non inviata: vale l'ultima bozza
        foreach ([$idCasa, $idOspite] as $idL) {
            if (!asg_stato_lato($stagione, $giornata, $idL)["inviata"]) {
                $warning[] = "All Star Game - formazione " . asg_nome_lato($idL) . " non ancora inviata: si usa l'ultima bozza salvata";
            }
        }

        $ris = CalcolatoreVoti::calcolaPartita($formCasa, $formOspite, $config);

        sim_salva_voti_squadra($conn, $stagione, $giornata, $idCasa,   $formCasa,   $ris["casa"],   ASG_TAB_SIM_VOTI);
        sim_salva_voti_squadra($conn, $stagione, $giornata, $idOspite, $formOspite, $ris["ospite"], ASG_TAB_SIM_VOTI);
        sim_salva_non_giocanti($conn, $stagione, $giornata, $idCasa,   $ngCasa,   ASG_TAB_SIM_VOTI);
        sim_salva_non_giocanti($conn, $stagione, $giornata, $idOspite, $ngOspite, ASG_TAB_SIM_VOTI);

        $fattoreCampoCasa = 0;
        if (ASG_FATTORE_CASA_APPLICATO && $fattoreCasaValore != 0) {
            $fattoreCampoCasa = (int) round($fattoreCasaValore);
            $ris["casa"]["totale_squadra"] = round($ris["casa"]["totale_squadra"] + $fattoreCasaValore, 2);
        }

        $golfCasa   = sim_gol_da_punteggio($ris["casa"]["totale_squadra"], $fasceGolPunteggio);
        $golfOspite = sim_gol_da_punteggio($ris["ospite"]["totale_squadra"], $fasceGolPunteggio);
        $esitoGol = CalcolatoreVoti::applicaRegoleGolPartita(
            $ris["casa"]["totale_squadra"], $ris["ospite"]["totale_squadra"], $golfCasa, $golfOspite, $regoleGol);
        if ($esitoGol["regola"] !== null) {
            $warning[] = "All Star Game - regola gol {$esitoGol['regola']} - {$esitoGol['nota']} ($golfCasa-$golfOspite → {$esitoGol['golf_casa']}-{$esitoGol['golf_ospite']})";
        }
        $golfCasa   = $esitoGol["golf_casa"];
        $golfOspite = $esitoGol["golf_ospite"];

        // Supplementari e rigori in caso di parità (voti della simulazione)
        $caricaStat = function (int $idG) use ($ctx) { $w = []; return sim_stat_giocatore($ctx, $idG, $w); };
        $extra = null;
        try {
            $extra = champions_valuta_extra(
                $stagione, $giornata, ASG_CODICE_TURNO, 1, $idCasa, $idOspite, $golfCasa, $golfOspite,
                $ris["casa"]["totale_squadra"], $ris["ospite"]["totale_squadra"],
                $formCasa, $formOspite, $tabF, $tabF, $config, $caricaStat, $warning,
                ASG_TAB_RIGORISTI, "All Star Game");
        } catch (Throwable $e) {
            $warning[] = "All Star Game: supplementari/rigori non calcolati ({$e->getMessage()})";
        }

        sim_salva_riga_risultato(
            $conn, $stagione, $giornata, $idCasa, $idOspite,
            $ris["casa"]["totale_squadra"], $ris["ospite"]["totale_squadra"], $golfCasa, $golfOspite,
            (int) round(-$ris["ospite"]["modificatori"]["difesa"]), (int) round(-$ris["casa"]["modificatori"]["difesa"]),
            $ris["casa"]["modificatori"]["attacco"],
            $ris["casa"]["numero_centrocampisti"], $ris["casa"]["somma_centrocampisti"], $ris["casa"]["modificatori"]["centrocampo"],
            $fattoreCampoCasa, $utente, ASG_TAB_SIM_RISULTATI, champions_colonne_extra($extra, $idCasa, $idOspite, $idCasa));
        sim_salva_riga_risultato(
            $conn, $stagione, $giornata, $idOspite, $idCasa,
            $ris["ospite"]["totale_squadra"], $ris["casa"]["totale_squadra"], $golfOspite, $golfCasa,
            (int) round(-$ris["casa"]["modificatori"]["difesa"]), (int) round(-$ris["ospite"]["modificatori"]["difesa"]),
            $ris["ospite"]["modificatori"]["attacco"],
            $ris["ospite"]["numero_centrocampisti"], $ris["ospite"]["somma_centrocampisti"], $ris["ospite"]["modificatori"]["centrocampo"],
            0, $utente, ASG_TAB_SIM_RISULTATI, champions_colonne_extra($extra, $idCasa, $idOspite, $idOspite));
        $elaborate++;

        mysqli_commit($conn);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        throw $e;
    }

    $conApi = sim_colonne_api(ASG_TAB_SIM_VOTI);
    $tot = query_one("SELECT COALESCE(SUM(provvisorio),0) AS p, COALESCE(SUM(manuale),0) AS m"
                     . ($conApi ? ", COALESCE(SUM(api),0) AS a, COALESCE(SUM(parziale),0) AS pz" : "")
                     . " FROM " . ASG_TAB_SIM_VOTI . " WHERE stagione = $stagione AND giornata = $giornata");
    return [
        "partite_elaborate" => $elaborate,
        "partite_saltate"   => $saltate,
        "warning"           => $warning,
        "provvisori"        => (int) ($tot["p"] ?? 0),
        "manuali"           => (int) ($tot["m"] ?? 0),
        "api"               => (int) ($tot["a"] ?? 0),
        "parziali"          => (int) ($tot["pz"] ?? 0),
    ];
}

/**
 * Cancella la simulazione dell'All Star Game: voti e risultati simulati + editing manuale
 * dei giocatori schierati SOLO nelle formazioni dell'All Star Game (chi gioca anche in
 * campionato/Champions mantiene l'editing, che vale anche per quelle partite).
 * Ritorna il numero di editing manuali rimossi.
 */
function asg_sim_elimina($conn, int $stagione, int $giornata): int
{
    $tab = ASG_TAB_FORMAZIONI;
    $sqlEdit = "DELETE FROM NEW_SIMULAZIONE_EDIT WHERE stagione = $stagione AND giornata = $giornata
                  AND id_giocatore IN (SELECT ID_GIOCATORE FROM $tab WHERE STAGIONE = $stagione AND GIORNATA = $giornata)
                  AND id_giocatore NOT IN (SELECT ID_GIOCATORE FROM NEW_FORMAZIONI WHERE STAGIONE = $stagione AND GIORNATA = $giornata)
                  AND id_giocatore NOT IN (SELECT ID_GIOCATORE FROM " . FORMAZIONE_TAB_CHAMPIONS . " WHERE STAGIONE = $stagione AND GIORNATA = $giornata)";

    mysqli_begin_transaction($conn);
    try {
        $q = [
            "DELETE FROM " . ASG_TAB_SIM_VOTI . " WHERE stagione = $stagione AND giornata = $giornata",
            "DELETE FROM " . ASG_TAB_SIM_RISULTATI . " WHERE stagione = $stagione AND giornata = $giornata",
            $sqlEdit,
        ];
        $edit = 0;
        foreach ($q as $i => $sql) {
            if (!mysqli_query($conn, $sql)) throw new Exception("Cancellazione simulazione: " . mysqli_error($conn));
            if ($i === 2) $edit = mysqli_affected_rows($conn);
        }
        mysqli_commit($conn);
        return $edit;
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        throw $e;
    }
}
