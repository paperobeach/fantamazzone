<?php
// ============================================================
// backend/lib/FormazioniBlocco.php
//
// Regole di "blocco inserimento formazione" e "nascondi formazione",
// governate da due parametri di stagione (NEW_PARAMETRI_STAGIONE,
// '1' = attivo, assente/'0' = non attivo):
//
//   BLOCCO_FORMAZIONE_ATTIVO        se attivo, l'inserimento (o la modifica)
//        della formazione è consentito fino a UN MINUTO PRIMA dell'inizio
//        della prima partita di Serie A della giornata. Da quel momento
//        la formazione è bloccata. Le formazioni tornano modificabili
//        quando la giornata viene chiusa: a quel punto la giornata di
//        riferimento diventa la successiva, la cui prima partita è
//        ancora nel futuro (e la giornata chiusa resta comunque non
//        modificabile, vedi formazioni.php).
//
//   NASCONDI_FORMAZIONE_CONSENTITO  se attivo, l'allenatore può scegliere
//        di nascondere la propria formazione: gli altri utenti non la
//        vedono fino all'inizio della prima partita di Serie A della
//        giornata (o fino alla chiusura della giornata, se il calendario
//        Serie A non permette di stabilire l'orario). La scelta per
//        squadra+giornata sta in NEW_FORMAZIONI_NASCOSTE.
//
// Orario di riferimento = prima partita di Serie A della giornata
// (ds_inizio_giornata in DashboardSquadra.php: stessa "scadenza" già
// mostrata nella dashboard). Una partita con orario da definire vale
// le 00:00 della sua data (si blocca/scopre prudenzialmente prima).
//
// L'API non ha sessioni: l'allenatore che sta guardando è indicato dal
// front end con il parametro "id_richiedente" (id squadra = id utente),
// come già avviene per id_squadra. Senza il parametro, il richiedente è
// considerato uno "sconosciuto" e vede le formazioni nascoste come
// qualsiasi altro avversario.
//
// Va incluso DOPO connect.php (usa query_one).
// ============================================================

require_once __DIR__ . "/DashboardSquadra.php";
require_once __DIR__ . "/StatoGiornata.php";

const FORMAZIONE_PARAM_BLOCCO   = "BLOCCO_FORMAZIONE_ATTIVO";
const FORMAZIONE_PARAM_NASCONDI = "NASCONDI_FORMAZIONE_CONSENTITO";
const FORMAZIONE_BLOCCO_ANTICIPO_SECONDI = 60;   // un minuto prima della prima partita

/** Parametro di stagione booleano ('1' = attivo). */
function fb_parametro_attivo(int $stagione, string $codice): bool
{
    $codiceEsc = addslashes($codice);
    $r = query_one("SELECT valore FROM NEW_PARAMETRI_STAGIONE
                    WHERE stagione = $stagione AND codice = '$codiceEsc'");
    return $r && trim((string) $r["valore"]) === "1";
}

function fb_blocco_attivo(int $stagione): bool
{
    return fb_parametro_attivo($stagione, FORMAZIONE_PARAM_BLOCCO);
}

function fb_nascondi_consentito(int $stagione): bool
{
    return fb_parametro_attivo($stagione, FORMAZIONE_PARAM_NASCONDI);
}

/** Timestamp della prima partita di Serie A della giornata; null se il calendario non c'è. */
function fb_inizio_giornata_ts(int $stagione, int $giornata): ?int
{
    [, $inizio] = ds_inizio_giornata($stagione, $giornata);
    if ($inizio === null) return null;
    $ts = strtotime($inizio);
    return $ts === false ? null : $ts;
}

/**
 * Stato del blocco per una giornata.
 * @return array{attivo:bool, bloccata:bool, inizio:?string, limite:?string}
 *   inizio = prima partita di Serie A; limite = ultimo istante utile
 *   per inserire la formazione (inizio - 1 minuto), formato "Y-m-d H:i:s".
 */
function fb_stato_blocco(int $stagione, int $giornata): array
{
    $attivo = fb_blocco_attivo($stagione);
    $inizio = fb_inizio_giornata_ts($stagione, $giornata);
    $limite = $inizio !== null ? $inizio - FORMAZIONE_BLOCCO_ANTICIPO_SECONDI : null;

    // Una giornata già chiusa non è modificabile a prescindere (controllo
    // dedicato in formazioni.php): qui "bloccata" riguarda solo il blocco a tempo.
    $bloccata = $attivo && $limite !== null && time() >= $limite
        && !sg_giornata_chiusa($stagione, $giornata);

    return [
        "attivo"   => $attivo,
        "bloccata" => $bloccata,
        "inizio"   => $inizio !== null ? date("Y-m-d H:i:s", $inizio) : null,
        "limite"   => $limite !== null ? date("Y-m-d H:i:s", $limite) : null,
    ];
}

/** La squadra ha chiesto di nascondere la formazione di questa giornata? (solo flag, senza regole) */
function fb_flag_nascosta(int $stagione, int $giornata, int $idSquadra): bool
{
    global $conn;
    // La tabella è creata da una migrazione: se non c'è ancora, nessuno nasconde nulla.
    $res = @mysqli_query($conn, "SELECT 1 FROM NEW_FORMAZIONI_NASCOSTE
                                 WHERE STAGIONE = $stagione AND GIORNATA = $giornata
                                   AND ID_SQUADRA = $idSquadra LIMIT 1");
    return $res instanceof mysqli_result && mysqli_num_rows($res) > 0;
}

/**
 * La formazione di $idSquadra è nascosta a $idRichiedente?
 * Sì se: parametro attivo + la squadra l'ha nascosta + la prima partita di
 * Serie A non è ancora iniziata + la giornata non è chiusa + il richiedente
 * non è la squadra stessa.
 */
function fb_formazione_nascosta_a(int $stagione, int $giornata, int $idSquadra, ?int $idRichiedente): bool
{
    if ($idRichiedente !== null && $idRichiedente === $idSquadra) return false;
    if (!fb_nascondi_consentito($stagione)) return false;
    if (!fb_flag_nascosta($stagione, $giornata, $idSquadra)) return false;
    if (sg_giornata_chiusa($stagione, $giornata)) return false;

    $inizio = fb_inizio_giornata_ts($stagione, $giornata);
    if ($inizio !== null && time() >= $inizio) return false;
    return true;
}

/** Imposta (o toglie) il flag "nascondi formazione" per squadra+giornata. */
function fb_imposta_nascosta(int $stagione, int $giornata, int $idSquadra, bool $nascosta): void
{
    global $conn;
    mysqli_query($conn, "DELETE FROM NEW_FORMAZIONI_NASCOSTE
                         WHERE STAGIONE = $stagione AND GIORNATA = $giornata AND ID_SQUADRA = $idSquadra");
    if ($nascosta) {
        mysqli_query($conn, "INSERT INTO NEW_FORMAZIONI_NASCOSTE (STAGIONE, GIORNATA, ID_SQUADRA)
                             VALUES ($stagione, $giornata, $idSquadra)");
    }
}
