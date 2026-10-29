<?php
// ============================================================
// backend/lib/MercatoRiparazione.php
//
// Mercato di riparazione - PRIMA FINESTRA (4 sessioni).
//
// REGOLE
//  - Parametro di stagione MERCATO_GIORNATA_INIZIO (G0): terminata (chiusa)
//    la giornata G0 si apre la sessione 1. La sessione k (1..4) si apre alla
//    chiusura della giornata G0+k-1 e termina quando INIZIA la giornata
//    G0+k (prima partita di Serie A della giornata, vedi ds_inizio_giornata;
//    se il calendario Serie A non c'e', alla chiusura di G0+k).
//  - All'apertura della sessione 1 si salva la classifica del campionato
//    (NEW_MERCATO_CLASSIFICA). L'ordine di chiamata della sessione 1 e'
//    l'inverso della classifica (ultimo = primo a muoversi); ogni sessione
//    successiva scala di un posto (rolling).
//  - Ogni squadra, al proprio turno, puo' fare UN'operazione: acquistare uno
//    svincolato a prezzo fisso per ruolo (parametri MERCATO_PREZZO_*) cedendo
//    un proprio giocatore dello stesso ruolo (i crediti con cui era stato
//    pagato vengono recuperati), oppure passare.
//  - Non si puo' acquistare un giocatore ceduto da un'altra squadra nella
//    stessa sessione, ne' un giocatore precedentemente ceduto nella finestra.
//  - Ogni operazione e' salvata in NEW_MERCATO_TRANSAZIONI.
//  - Tempo limite per fantallenatore (MERCATO_TEMPO_LIMITE_ORE): scaduto,
//    si inviano solleciti ogni MERCATO_SOLLECITO_ORE (la squadra NON viene
//    saltata automaticamente).
//  - Email a tutti: apertura sessione + prossima squadra chiamata, ogni
//    cambio turno, riepilogo a fine sessione.
//  - Il turno di una squadra si chiude SOLO alla conferma dell'operazione
//    (o al "passa"): ne' il tempo limite ne' il cron lo chiudono.
//
// mr_tick() e' idempotente: apre/chiude le sessioni e invia i solleciti.
// Viene richiamata dalla chiusura giornata e da mercato.php (GET); il cron
// cron_mercato.php la usa SOLO per i solleciti.
//
// Va incluso DOPO connect.php (usa query_one / query_all).
// ============================================================

require_once __DIR__ . "/DashboardSquadra.php";
require_once __DIR__ . "/StatoGiornata.php";
require_once __DIR__ . "/../mailer.php";

const MR_FINESTRA_1       = 1;
const MR_SESSIONI_FINESTRA_1 = 4;

const MR_PARAM_GIORNATA   = "MERCATO_GIORNATA_INIZIO";
const MR_PARAM_TEMPO      = "MERCATO_TEMPO_LIMITE_ORE";
const MR_PARAM_SOLLECITO  = "MERCATO_SOLLECITO_ORE";
const MR_PARAM_PREZZI     = [
    1 => "MERCATO_PREZZO_PORTIERE",
    2 => "MERCATO_PREZZO_DIFENSORE",
    3 => "MERCATO_PREZZO_CENTROCAMPISTA",
    4 => "MERCATO_PREZZO_ATTACCANTE",
];
const MR_PREZZI_DEFAULT   = [1 => 5, 2 => 5, 3 => 10, 4 => 20];
const MR_RUOLI            = [1 => "Portiere", 2 => "Difensore", 3 => "Centrocampista", 4 => "Attaccante"];

/** Eccezione con codice HTTP, per gli errori di validazione delle operazioni. */
class MercatoErrore extends Exception
{
    public int $http;
    public function __construct(string $msg, int $http = 400) { parent::__construct($msg); $this->http = $http; }
}

// ------------------------------------------------------------
// Configurazione
// ------------------------------------------------------------
function mr_param(int $stagione, string $codice): ?string
{
    $c = addslashes($codice);
    $r = query_one("SELECT valore FROM NEW_PARAMETRI_STAGIONE WHERE stagione = $stagione AND codice = '$c'");
    if (!$r) return null;
    $v = trim((string) $r["valore"]);
    return $v === "" ? null : $v;
}

function mr_config(int $stagione): array
{
    $g = mr_param($stagione, MR_PARAM_GIORNATA);
    $prezzi = [];
    foreach (MR_PARAM_PREZZI as $ruolo => $codice) {
        $v = mr_param($stagione, $codice);
        $prezzi[$ruolo] = ($v !== null && ctype_digit($v)) ? (int) $v : MR_PREZZI_DEFAULT[$ruolo];
    }
    $t = mr_param($stagione, MR_PARAM_TEMPO);
    $s = mr_param($stagione, MR_PARAM_SOLLECITO);
    return [
        "giornata_inizio" => ($g !== null && ctype_digit($g) && (int) $g >= 1) ? (int) $g : null,
        "prezzi"          => $prezzi,
        "tempo_limite_ore" => ($t !== null && ctype_digit($t) && (int) $t >= 1) ? (int) $t : 24,
        "sollecito_ore"   => ($s !== null && ctype_digit($s) && (int) $s >= 1) ? (int) $s : 6,
    ];
}

// ------------------------------------------------------------
// Utilita'
// ------------------------------------------------------------
function mr_now(): string { return date("Y-m-d H:i:s"); }

function mr_esc(string $s): string { global $conn; return mysqli_real_escape_string($conn, $s); }

function mr_nome_squadra(int $stagione, int $id): string
{
    $r = query_one("SELECT nome FROM NEW_SQUADRE WHERE stagione = $stagione AND id = $id");
    return $r ? (string) $r["nome"] : "Squadra $id";
}

function mr_nomi_squadre(int $stagione): array
{
    $out = [];
    foreach (query_all("SELECT s.id, s.nome, a.descrizione AS allenatore
                        FROM NEW_SQUADRE s
                        LEFT JOIN NEW_ALLENATORI a ON a.id_squadra = s.id AND a.stagione = s.stagione
                        WHERE s.stagione = $stagione") as $r) {
        $out[(int) $r["id"]] = ["nome" => (string) $r["nome"], "allenatore" => (string) ($r["allenatore"] ?? "")];
    }
    return $out;
}

/** Sessione terminata? (giornata G0+k iniziata o chiusa) */
function mr_sessione_terminata(int $stagione, int $g0, int $k): bool
{
    $gFine = $g0 + $k;
    if (sg_giornata_chiusa($stagione, $gFine)) return true;
    $inizio = mr_inizio_giornata_ts($stagione, $gFine);
    return $inizio !== null && time() >= $inizio;
}

function mr_inizio_giornata_ts(int $stagione, int $giornata): ?int
{
    [, $inizio] = ds_inizio_giornata($stagione, $giornata);
    if ($inizio === null) return null;
    $ts = strtotime($inizio);
    return $ts === false ? null : $ts;
}

// ------------------------------------------------------------
// Email
// ------------------------------------------------------------
/** Invia la stessa email a tutti i fantallenatori della stagione. Ritorna [inviate, fallite]. */
function mr_mail_tutti(int $stagione, string $oggetto, string $corpo): array
{
    $ok = 0; $ko = 0; $viste = [];
    foreach (query_all("SELECT email FROM NEW_ALLENATORI WHERE stagione = $stagione") as $r) {
        $e = strtolower(trim((string) $r["email"]));
        if ($e === "" || isset($viste[$e])) continue;
        $viste[$e] = true;
        $esito = invia_email($e, $oggetto, $corpo);
        if ($esito["ok"]) $ok++; else $ko++;
    }
    return [$ok, $ko];
}

function mr_mail_squadra(int $stagione, int $idSquadra, string $oggetto, string $corpo): array
{
    $r = query_one("SELECT email FROM NEW_ALLENATORI WHERE stagione = $stagione AND id_squadra = $idSquadra LIMIT 1");
    return invia_email($r["email"] ?? "", $oggetto, $corpo);
}

function mr_scadenza_turno(array $cfg, ?string $turnoInizio): ?string
{
    if ($turnoInizio === null) return null;
    $ts = strtotime($turnoInizio);
    return $ts === false ? null : date("d/m/Y H:i", $ts + $cfg["tempo_limite_ore"] * 3600);
}

function mr_mail_turno(int $stagione, int $finestra, int $sessione, ?string $esitoPrecedente, bool $apertura): void
{
    $cfg   = mr_config($stagione);
    $nomi  = mr_nomi_squadre($stagione);
    $turno = mr_turno_corrente($stagione, $finestra, $sessione);

    $righe = [];
    if ($apertura) {
        $righe[] = "Si e' aperta la sessione $sessione di " . MR_SESSIONI_FINESTRA_1 . " della prima finestra del Mercato di riparazione.";
    } elseif ($esitoPrecedente !== null) {
        $righe[] = $esitoPrecedente;
    }
    if ($turno) {
        $n = $nomi[(int) $turno["id_squadra"]] ?? ["nome" => "Squadra " . $turno["id_squadra"], "allenatore" => ""];
        $righe[] = "";
        $righe[] = "Prossima squadra chiamata ad agire: " . $n["nome"] . ($n["allenatore"] !== "" ? " (" . $n["allenatore"] . ")" : "");
        $righe[] = "Tempo limite: entro il " . mr_scadenza_turno($cfg, $turno["turno_inizio"]) . " (" . $cfg["tempo_limite_ore"] . " ore).";
        $oggetto = "Mercato di riparazione - sessione $sessione: tocca a " . $n["nome"];
    } else {
        $righe[] = "";
        $righe[] = "Tutte le squadre hanno agito: la sessione $sessione e' completata.";
        $oggetto = "Mercato di riparazione - sessione $sessione completata";
    }
    mr_mail_tutti($stagione, $oggetto, implode("\n", $righe) . "\n");
}

function mr_mail_riepilogo(int $stagione, int $finestra, int $sessione): void
{
    $nomi = mr_nomi_squadre($stagione);
    $righe = ["Riepilogo della sessione $sessione di " . MR_SESSIONI_FINESTRA_1 . " - Mercato di riparazione (prima finestra).", ""];
    $tr = query_all("SELECT * FROM NEW_MERCATO_TRANSAZIONI
                     WHERE stagione = $stagione AND finestra = $finestra AND sessione = $sessione ORDER BY id");
    $nOp = 0;
    foreach ($tr as $t) {
        $nome = $nomi[(int) $t["id_squadra"]]["nome"] ?? ("Squadra " . $t["id_squadra"]);
        if ($t["tipo"] === "PASSO") { $righe[] = "- $nome: ha passato"; continue; }
        $nOp++;
        $righe[] = "- $nome: acquista {$t['giocatore_acquistato']} ({$t['crediti_acquisto']} cr.), cede {$t['giocatore_ceduto']} (recupera {$t['crediti_recuperati']} cr.)";
    }
    $nonAgito = query_all("SELECT id_squadra FROM NEW_MERCATO_ORDINE
                           WHERE stagione = $stagione AND finestra = $finestra AND sessione = $sessione AND stato = 'ATTESA'
                           ORDER BY posizione");
    foreach ($nonAgito as $o) {
        $righe[] = "- " . ($nomi[(int) $o["id_squadra"]]["nome"] ?? ("Squadra " . $o["id_squadra"])) . ": non ha operato";
    }
    $righe[] = "";
    $righe[] = "Operazioni effettuate: $nOp.";
    mr_mail_tutti($stagione, "Mercato di riparazione - riepilogo sessione $sessione", implode("\n", $righe) . "\n");
}

// ------------------------------------------------------------
// Stato sessione / turni
// ------------------------------------------------------------
function mr_sessione_aperta(int $stagione, int $finestra): ?array
{
    return query_one("SELECT * FROM NEW_MERCATO_SESSIONI
                      WHERE stagione = $stagione AND finestra = $finestra AND stato = 'APERTA'
                      ORDER BY sessione DESC LIMIT 1") ?: null;
}

function mr_turno_corrente(int $stagione, int $finestra, int $sessione): ?array
{
    return query_one("SELECT * FROM NEW_MERCATO_ORDINE
                      WHERE stagione = $stagione AND finestra = $finestra AND sessione = $sessione AND stato = 'ATTESA'
                      ORDER BY posizione LIMIT 1") ?: null;
}

/** Avvia il turno della prima squadra in attesa. Ritorna l'id squadra o null. */
function mr_avvia_turno(int $stagione, int $finestra, int $sessione): ?int
{
    global $conn;
    $t = mr_turno_corrente($stagione, $finestra, $sessione);
    if (!$t) return null;
    $now = mr_now();
    mysqli_query($conn, "UPDATE NEW_MERCATO_ORDINE SET turno_inizio = '$now', solleciti = 0, ultimo_sollecito = NULL
                         WHERE stagione = $stagione AND finestra = $finestra AND sessione = $sessione
                           AND posizione = " . (int) $t["posizione"]);
    return (int) $t["id_squadra"];
}

/** Salva la classifica del campionato (se non gia' salvata) e la restituisce in ordine di classifica. */
function mr_classifica_salvata(int $stagione, int $finestra): array
{
    global $conn;
    $esiste = query_one("SELECT COUNT(*) AS n FROM NEW_MERCATO_CLASSIFICA WHERE stagione = $stagione AND finestra = $finestra");
    if ((int) $esiste["n"] === 0) {
        $rows = query_all("SELECT id_squadra, punti, golf, gols FROM NEW_GENERALE
                           WHERE stagione = $stagione ORDER BY punti DESC, golf DESC, gols DESC, id_squadra ASC");
        $pos = 1;
        foreach ($rows as $r) {
            mysqli_query($conn, "INSERT INTO NEW_MERCATO_CLASSIFICA (stagione, finestra, posizione, id_squadra, punti, golf, gols)
                                 VALUES ($stagione, $finestra, $pos, " . (int) $r["id_squadra"] . ", " . (int) $r["punti"] . ", "
                                 . (int) $r["golf"] . ", " . (int) $r["gols"] . ")");
            $pos++;
        }
    }
    return query_all("SELECT c.posizione, c.id_squadra, c.punti, c.golf, c.gols, s.nome
                      FROM NEW_MERCATO_CLASSIFICA c
                      LEFT JOIN NEW_SQUADRE s ON s.id = c.id_squadra AND s.stagione = c.stagione
                      WHERE c.stagione = $stagione AND c.finestra = $finestra ORDER BY c.posizione");
}

/** Ordine di chiamata della sessione k: classifica invertita, ruotata di (k-1) posti. */
function mr_ordine_sessione(array $classifica, int $k): array
{
    $ids = array_map(fn($r) => (int) $r["id_squadra"], array_reverse($classifica));
    $n = count($ids);
    if ($n === 0) return [];
    $shift = ($k - 1) % $n;
    return array_merge(array_slice($ids, $shift), array_slice($ids, 0, $shift));
}

function mr_apri_sessione(int $stagione, int $finestra, int $k, int $gApertura): void
{
    global $conn;
    $classifica = mr_classifica_salvata($stagione, $finestra);
    if (empty($classifica)) throw new MercatoErrore("Classifica del campionato non disponibile", 500);
    $now = mr_now();
    mysqli_query($conn, "INSERT INTO NEW_MERCATO_SESSIONI (stagione, finestra, sessione, giornata_apertura, stato, aperta_il)
                         VALUES ($stagione, $finestra, $k, $gApertura, 'APERTA', '$now')");
    $pos = 1;
    foreach (mr_ordine_sessione($classifica, $k) as $id) {
        mysqli_query($conn, "INSERT INTO NEW_MERCATO_ORDINE (stagione, finestra, sessione, posizione, id_squadra, stato)
                             VALUES ($stagione, $finestra, $k, $pos, $id, 'ATTESA')");
        $pos++;
    }
    mr_avvia_turno($stagione, $finestra, $k);
    mr_mail_turno($stagione, $finestra, $k, null, true);
}

function mr_chiudi_sessione(int $stagione, int $finestra, int $k): void
{
    global $conn;
    $now = mr_now();
    mysqli_query($conn, "UPDATE NEW_MERCATO_SESSIONI SET stato = 'CHIUSA', chiusa_il = '$now'
                         WHERE stagione = $stagione AND finestra = $finestra AND sessione = $k");
    mr_mail_riepilogo($stagione, $finestra, $k);
    mysqli_query($conn, "UPDATE NEW_MERCATO_SESSIONI SET riepilogo_inviato = 1
                         WHERE stagione = $stagione AND finestra = $finestra AND sessione = $k");
}

function mr_lock(int $stagione, int $timeout = 0): bool
{
    global $conn;
    $r = mysqli_query($conn, "SELECT GET_LOCK('fantamazzone_mercato_$stagione', $timeout) AS l");
    $row = $r ? mysqli_fetch_assoc($r) : null;
    return $row && (int) $row["l"] === 1;
}

function mr_unlock(int $stagione): void
{
    global $conn;
    mysqli_query($conn, "SELECT RELEASE_LOCK('fantamazzone_mercato_$stagione')");
}

/**
 * Apre/chiude le sessioni e invia i solleciti. Idempotente.
 * Il TURNO non viene mai chiuso qui: si chiude solo quando l'utente conferma
 * l'operazione (mr_acquista), passa (mr_passa) o l'admin salta la squadra.
 * Con $soloSolleciti = true (cron) non si aprono ne' si chiudono sessioni.
 * Ritorna un rapporto { attivo, aperte, chiuse, solleciti }.
 * $lockGiaPreso = true se il chiamante ha gia' il lock del mercato.
 */
function mr_tick(int $stagione, int $finestra = MR_FINESTRA_1, bool $lockGiaPreso = false, bool $soloSolleciti = false): array
{
    global $conn;
    $rep = ["attivo" => false, "aperte" => [], "chiuse" => [], "solleciti" => 0];
    if ($stagione !== sg_ultima_stagione()) return $rep;       // stagioni storiche: nulla da fare
    $cfg = mr_config($stagione);
    if ($cfg["giornata_inizio"] === null) return $rep;
    $rep["attivo"] = true;
    $g0 = $cfg["giornata_inizio"];

    if (!$lockGiaPreso && !mr_lock($stagione, 0)) return $rep;  // un altro processo se ne sta occupando
    try {
        // 1. Chiusura sessioni terminate (non eseguita dal cron: $soloSolleciti)
        foreach ($soloSolleciti ? [] : query_all("SELECT sessione FROM NEW_MERCATO_SESSIONI
                            WHERE stagione = $stagione AND finestra = $finestra AND stato = 'APERTA'") as $s) {
            $k = (int) $s["sessione"];
            if (mr_sessione_terminata($stagione, $g0, $k)) {
                mr_chiudi_sessione($stagione, $finestra, $k);
                $rep["chiuse"][] = $k;
            }
        }
        // 2. Apertura nuove sessioni
        for ($k = 1; !$soloSolleciti && $k <= MR_SESSIONI_FINESTRA_1; $k++) {
            $gAp = $g0 + $k - 1;
            $esiste = query_one("SELECT COUNT(*) AS n FROM NEW_MERCATO_SESSIONI
                                 WHERE stagione = $stagione AND finestra = $finestra AND sessione = $k");
            if ((int) $esiste["n"] > 0) continue;
            if (!sg_giornata_chiusa($stagione, $gAp)) break;     // le successive dipendono da questa
            if (mr_sessione_terminata($stagione, $g0, $k)) continue; // finestra di tempo gia' passata
            mr_apri_sessione($stagione, $finestra, $k, $gAp);
            $rep["aperte"][] = $k;
        }
        // 3. Solleciti
        $aperta = mr_sessione_aperta($stagione, $finestra);
        // Nessun sollecito se la sessione e' ormai terminata (verra' chiusa al prossimo accesso)
        if ($aperta && !mr_sessione_terminata($stagione, $g0, (int) $aperta["sessione"])) {
            $turno = mr_turno_corrente($stagione, $finestra, (int) $aperta["sessione"]);
            if ($turno && $turno["turno_inizio"] !== null) {
                $scad = strtotime($turno["turno_inizio"]) + $cfg["tempo_limite_ore"] * 3600;
                $ultimo = $turno["ultimo_sollecito"] !== null ? strtotime($turno["ultimo_sollecito"]) : null;
                $prossimo = $ultimo === null ? $scad : $ultimo + $cfg["sollecito_ore"] * 3600;
                if (time() >= $prossimo) {
                    $idSq = (int) $turno["id_squadra"];
                    $nome = mr_nome_squadra($stagione, $idSq);
                    $corpo = "Il tuo tempo limite per agire nella sessione " . $aperta["sessione"]
                           . " del Mercato di riparazione e' scaduto il " . date("d/m/Y H:i", $scad) . ".\n"
                           . "Gli altri fantallenatori sono in attesa: entra in FantaMazzone e completa la tua operazione oppure passa il turno.\n";
                    mr_mail_squadra($stagione, $idSq, "Mercato di riparazione - sollecito per $nome", $corpo);
                    mysqli_query($conn, "UPDATE NEW_MERCATO_ORDINE SET solleciti = solleciti + 1, ultimo_sollecito = '" . mr_now() . "'
                                         WHERE stagione = $stagione AND finestra = $finestra
                                           AND sessione = " . (int) $aperta["sessione"] . " AND posizione = " . (int) $turno["posizione"]);
                    $rep["solleciti"]++;
                }
            }
        }
    } finally {
        if (!$lockGiaPreso) mr_unlock($stagione);
    }
    return $rep;
}

// ------------------------------------------------------------
// Operazioni
// ------------------------------------------------------------
function mr_verifica_turno(int $stagione, int $finestra, int $idSquadra): array
{
    $sess = mr_sessione_aperta($stagione, $finestra);
    if (!$sess) throw new MercatoErrore("Nessuna sessione di mercato aperta in questo momento", 409);
    $turno = mr_turno_corrente($stagione, $finestra, (int) $sess["sessione"]);
    if (!$turno) throw new MercatoErrore("Tutte le squadre hanno gia' agito in questa sessione", 409);
    if ((int) $turno["id_squadra"] !== $idSquadra) {
        throw new MercatoErrore("Non e' il turno di questa squadra: e' chiamata " . mr_nome_squadra($stagione, (int) $turno["id_squadra"]), 403);
    }
    return [$sess, $turno];
}

/** Giocatore ceduto nella finestra: ['sessione'=>, 'id_squadra'=>] o null. */
function mr_giocatore_ceduto(int $stagione, int $finestra, int $idGiocatore): ?array
{
    return query_one("SELECT sessione, id_squadra FROM NEW_MERCATO_TRANSAZIONI
                      WHERE stagione = $stagione AND finestra = $finestra AND tipo = 'SCAMBIO'
                        AND id_giocatore_ceduto = $idGiocatore ORDER BY id LIMIT 1") ?: null;
}

function mr_acquista(int $stagione, int $finestra, int $idSquadra, int $idAcq, int $idCed): array
{
    global $conn;
    if ($stagione !== sg_ultima_stagione()) throw new MercatoErrore("Il mercato e' disponibile solo per l'ultima stagione", 403);
    if (!mr_lock($stagione, 5)) throw new MercatoErrore("Un'altra operazione di mercato e' in corso: riprova tra qualche secondo", 409);
    try {
        mr_tick($stagione, $finestra, true);
        [$sess, $turno] = mr_verifica_turno($stagione, $finestra, $idSquadra);
        $k = (int) $sess["sessione"];
        $cfg = mr_config($stagione);

        $acq = query_one("SELECT id, descrizione, ruolo, nazione FROM NEW_GIOCATORI_SVINCOLATI WHERE stagione = $stagione AND id = $idAcq");
        if (!$acq) throw new MercatoErrore("Il giocatore da acquistare non e' tra gli svincolati", 400);
        $ced = query_one("SELECT id, descrizione, ruolo, nazione, crediti FROM NEW_GIOCATORI
                          WHERE stagione = $stagione AND id = $idCed AND squadra = $idSquadra");
        if (!$ced) throw new MercatoErrore("Il giocatore da cedere non fa parte della tua rosa", 400);
        if ((int) $acq["ruolo"] !== (int) $ced["ruolo"]) {
            throw new MercatoErrore("Il giocatore ceduto deve avere lo stesso ruolo di quello acquistato ("
                . (MR_RUOLI[(int) $acq["ruolo"]] ?? "?") . ")", 400);
        }
        $ruolo = (int) $acq["ruolo"];

        $prec = mr_giocatore_ceduto($stagione, $finestra, $idAcq);
        if ($prec) {
            if ((int) $prec["sessione"] === $k && (int) $prec["id_squadra"] !== $idSquadra) {
                throw new MercatoErrore("Il giocatore e' stato ceduto in questa sessione da un'altra squadra: non e' acquistabile", 400);
            }
            throw new MercatoErrore("Il giocatore e' stato ceduto in precedenza nel mercato di riparazione: non e' acquistabile", 400);
        }

        $prezzo = (int) $cfg["prezzi"][$ruolo];
        $recuperati = (int) $ced["crediti"];
        $nomeAcq = mr_esc((string) $acq["descrizione"]);
        $nazAcq  = mr_esc((string) $acq["nazione"]);
        $nomeCed = mr_esc((string) $ced["descrizione"]);
        $nazCed  = mr_esc((string) $ced["nazione"]);

        // Tabelle MyISAM (NEW_GIOCATORI): nessun rollback, quindi ogni passo e' verificato
        // e in caso di errore si ripristina quanto gia' fatto.
        $fatti = [];
        $annulla = function () use ($conn, &$fatti, $stagione, $idAcq, $idCed, $idSquadra, $acq, $ced, $nomeAcq, $nazAcq, $nomeCed, $nazCed) {
            foreach (array_reverse($fatti) as $f) {
                if ($f === "del_svinc")  mysqli_query($conn, "INSERT INTO NEW_GIOCATORI_SVINCOLATI (id, stagione, descrizione, ruolo, nazione) VALUES ($idAcq, $stagione, '$nomeAcq', " . (int) $acq["ruolo"] . ", '$nazAcq')");
                if ($f === "ins_rosa")   mysqli_query($conn, "DELETE FROM NEW_GIOCATORI WHERE stagione = $stagione AND id = $idAcq");
                if ($f === "del_rosa")   mysqli_query($conn, "INSERT INTO NEW_GIOCATORI (id, stagione, descrizione, squadra, flag, ruolo, nazione, crediti) VALUES ($idCed, $stagione, '$nomeCed', $idSquadra, 0, " . (int) $ced["ruolo"] . ", '$nazCed', " . (int) $ced["crediti"] . ")");
                if ($f === "ins_svinc")  mysqli_query($conn, "DELETE FROM NEW_GIOCATORI_SVINCOLATI WHERE stagione = $stagione AND id = $idCed");
            }
        };
        $passo = function (string $nome, string $sql) use ($conn, &$fatti, $annulla) {
            $ok = mysqli_query($conn, $sql);
            if (!$ok || mysqli_affected_rows($conn) < 1) {
                $annulla();
                throw new MercatoErrore("Operazione non riuscita (passo $nome): nessuna modifica applicata", 500);
            }
            $fatti[] = $nome;
        };
        $passo("del_svinc", "DELETE FROM NEW_GIOCATORI_SVINCOLATI WHERE stagione = $stagione AND id = $idAcq");
        $passo("ins_rosa",  "INSERT INTO NEW_GIOCATORI (id, stagione, descrizione, squadra, flag, ruolo, nazione, crediti)
                             VALUES ($idAcq, $stagione, '$nomeAcq', $idSquadra, 0, $ruolo, '$nazAcq', $prezzo)");
        $passo("del_rosa",  "DELETE FROM NEW_GIOCATORI WHERE stagione = $stagione AND id = $idCed AND squadra = $idSquadra");
        $passo("ins_svinc", "INSERT INTO NEW_GIOCATORI_SVINCOLATI (id, stagione, descrizione, ruolo, nazione)
                             VALUES ($idCed, $stagione, '$nomeCed', $ruolo, '$nazCed')");

        // Il giocatore ceduto esce dalle formazioni (campionato e Champions) delle giornate non ancora chiuse
        $ult = query_one("SELECT COALESCE(MAX(giornata), 0) AS g FROM NEW_CALENDARIO_CK WHERE stagione = $stagione AND ck_giocata = 'S'");
        $gUlt = (int) $ult["g"];
        foreach (["NEW_FORMAZIONI", "NEW_FORMAZIONI_CHAMP"] as $tab) {
            mysqli_query($conn, "DELETE FROM $tab WHERE STAGIONE = $stagione AND ID_SQUADRA = $idSquadra
                                 AND ID_GIOCATORE = $idCed AND GIORNATA > $gUlt");
        }
        mysqli_query($conn, "DELETE FROM NEW_RIGORISTI_CHAMP WHERE STAGIONE = $stagione AND ID_SQUADRA = $idSquadra
                             AND ID_GIOCATORE = $idCed AND GIORNATA > $gUlt");

        $now = mr_now();
        mysqli_query($conn, "INSERT INTO NEW_MERCATO_TRANSAZIONI
            (stagione, finestra, sessione, id_squadra, tipo, id_giocatore_acquistato, giocatore_acquistato, ruolo,
             crediti_acquisto, id_giocatore_ceduto, giocatore_ceduto, crediti_recuperati, creato_il)
            VALUES ($stagione, $finestra, $k, $idSquadra, 'SCAMBIO', $idAcq, '$nomeAcq', $ruolo,
                    $prezzo, $idCed, '$nomeCed', $recuperati, '$now')");
        mysqli_query($conn, "UPDATE NEW_MERCATO_ORDINE SET stato = 'FATTO'
                             WHERE stagione = $stagione AND finestra = $finestra AND sessione = $k
                               AND posizione = " . (int) $turno["posizione"]);

        mr_avvia_turno($stagione, $finestra, $k);
        $nomeSq = mr_nome_squadra($stagione, $idSquadra);
        mr_mail_turno($stagione, $finestra, $k,
            "$nomeSq ha acquistato {$acq['descrizione']} ($prezzo crediti) cedendo {$ced['descrizione']} (recupera $recuperati crediti).", false);

        return ["ok" => true, "sessione" => $k, "prezzo" => $prezzo, "recuperati" => $recuperati, "saldo" => $prezzo - $recuperati];
    } finally {
        mr_unlock($stagione);
    }
}

function mr_passa(int $stagione, int $finestra, int $idSquadra, bool $perConto = false): array
{
    global $conn;
    if ($stagione !== sg_ultima_stagione()) throw new MercatoErrore("Il mercato e' disponibile solo per l'ultima stagione", 403);
    if (!mr_lock($stagione, 5)) throw new MercatoErrore("Un'altra operazione di mercato e' in corso: riprova tra qualche secondo", 409);
    try {
        mr_tick($stagione, $finestra, true);
        [$sess, $turno] = mr_verifica_turno($stagione, $finestra, $idSquadra);
        $k = (int) $sess["sessione"];
        $now = mr_now();
        mysqli_query($conn, "INSERT INTO NEW_MERCATO_TRANSAZIONI
            (stagione, finestra, sessione, id_squadra, tipo, crediti_acquisto, crediti_recuperati, creato_il)
            VALUES ($stagione, $finestra, $k, $idSquadra, 'PASSO', 0, 0, '$now')");
        mysqli_query($conn, "UPDATE NEW_MERCATO_ORDINE SET stato = 'PASSATO'
                             WHERE stagione = $stagione AND finestra = $finestra AND sessione = $k
                               AND posizione = " . (int) $turno["posizione"]);
        mr_avvia_turno($stagione, $finestra, $k);
        $nomeSq = mr_nome_squadra($stagione, $idSquadra);
        mr_mail_turno($stagione, $finestra, $k, $perConto ? "$nomeSq e' stata saltata dall'amministratore." : "$nomeSq ha passato il turno.", false);
        return ["ok" => true, "sessione" => $k];
    } finally {
        mr_unlock($stagione);
    }
}

// ------------------------------------------------------------
// Lettura
// ------------------------------------------------------------
function mr_stato(int $stagione, int $finestra, ?int $idRichiedente): array
{
    $cfg  = mr_config($stagione);
    $nomi = mr_nomi_squadre($stagione);
    $ultima = $stagione === sg_ultima_stagione();
    $out = [
        "stagione" => $stagione, "finestra" => $finestra,
        "attivo" => $cfg["giornata_inizio"] !== null,
        "ultima_stagione" => $ultima,
        "giornata_inizio" => $cfg["giornata_inizio"],
        "prezzi" => $cfg["prezzi"],
        "tempo_limite_ore" => $cfg["tempo_limite_ore"],
        "sessioni_totali" => MR_SESSIONI_FINESTRA_1,
        "sessione_corrente" => null, "ordine" => [], "turno" => null,
        "tuo_turno" => false, "sessioni" => [], "classifica" => [],
        "prossima_apertura" => null,
    ];
    if ($cfg["giornata_inizio"] === null) return $out;
    $g0 = $cfg["giornata_inizio"];

    $righe = [];
    foreach (query_all("SELECT * FROM NEW_MERCATO_SESSIONI WHERE stagione = $stagione AND finestra = $finestra ORDER BY sessione") as $s) {
        $righe[(int) $s["sessione"]] = $s;
    }
    for ($k = 1; $k <= MR_SESSIONI_FINESTRA_1; $k++) {
        $s = $righe[$k] ?? null;
        $out["sessioni"][] = [
            "sessione" => $k,
            "stato" => $s ? $s["stato"] : "DA_APRIRE",
            "giornata_apertura" => $g0 + $k - 1,
            "aperta_il" => $s["aperta_il"] ?? null,
            "chiusa_il" => $s["chiusa_il"] ?? null,
        ];
        if (!$s && $out["prossima_apertura"] === null) $out["prossima_apertura"] = $g0 + $k - 1;
    }
    $out["classifica"] = array_map(fn($r) => [
        "posizione" => (int) $r["posizione"], "id_squadra" => (int) $r["id_squadra"],
        "nome" => $r["nome"], "punti" => (int) $r["punti"],
    ], query_all("SELECT c.posizione, c.id_squadra, c.punti, s.nome FROM NEW_MERCATO_CLASSIFICA c
                  LEFT JOIN NEW_SQUADRE s ON s.id = c.id_squadra AND s.stagione = c.stagione
                  WHERE c.stagione = $stagione AND c.finestra = $finestra ORDER BY c.posizione"));

    // Sessione da mostrare: quella aperta, altrimenti l'ultima chiusa
    $mostra = mr_sessione_aperta($stagione, $finestra);
    if (!$mostra && !empty($righe)) $mostra = $righe[max(array_keys($righe))];
    if (!$mostra) return $out;

    $k = (int) $mostra["sessione"];
    $out["sessione_corrente"] = ["sessione" => $k, "stato" => $mostra["stato"], "aperta_il" => $mostra["aperta_il"], "chiusa_il" => $mostra["chiusa_il"]];
    $turno = $mostra["stato"] === "APERTA" ? mr_turno_corrente($stagione, $finestra, $k) : null;
    foreach (query_all("SELECT * FROM NEW_MERCATO_ORDINE WHERE stagione = $stagione AND finestra = $finestra AND sessione = $k ORDER BY posizione") as $o) {
        $id = (int) $o["id_squadra"];
        $out["ordine"][] = [
            "posizione" => (int) $o["posizione"], "id_squadra" => $id,
            "nome" => $nomi[$id]["nome"] ?? "Squadra $id", "allenatore" => $nomi[$id]["allenatore"] ?? "",
            "stato" => $o["stato"], "solleciti" => (int) $o["solleciti"],
            "in_turno" => $turno && (int) $turno["posizione"] === (int) $o["posizione"],
        ];
    }
    if ($turno) {
        $id = (int) $turno["id_squadra"];
        $out["turno"] = [
            "id_squadra" => $id, "nome" => $nomi[$id]["nome"] ?? "Squadra $id",
            "turno_inizio" => $turno["turno_inizio"],
            "scadenza" => $turno["turno_inizio"] ? date("Y-m-d H:i:s", strtotime($turno["turno_inizio"]) + $cfg["tempo_limite_ore"] * 3600) : null,
            "scaduto" => $turno["turno_inizio"] ? time() >= strtotime($turno["turno_inizio"]) + $cfg["tempo_limite_ore"] * 3600 : false,
        ];
        $out["tuo_turno"] = $idRichiedente !== null && $idRichiedente === $id;
    }
    return $out;
}

function mr_svincolati(int $stagione, int $finestra, ?int $ruolo, string $q): array
{
    $w = "stagione = $stagione";
    if ($ruolo !== null) $w .= " AND ruolo = $ruolo";
    if ($q !== "") $w .= " AND descrizione LIKE '%" . mr_esc($q) . "%'";
    $ceduti = [];
    foreach (query_all("SELECT id_giocatore_ceduto FROM NEW_MERCATO_TRANSAZIONI
                        WHERE stagione = $stagione AND finestra = $finestra AND tipo = 'SCAMBIO'") as $r) {
        $ceduti[(int) $r["id_giocatore_ceduto"]] = true;
    }
    $out = [];
    foreach (query_all("SELECT id, descrizione, ruolo, nazione FROM NEW_GIOCATORI_SVINCOLATI WHERE $w ORDER BY ruolo, descrizione") as $r) {
        $out[] = [
            "id" => (int) $r["id"], "descrizione" => $r["descrizione"], "ruolo" => (int) $r["ruolo"],
            "nazione" => $r["nazione"], "acquistabile" => !isset($ceduti[(int) $r["id"]]),
        ];
    }
    return $out;
}

function mr_storico(int $stagione, int $finestra, ?int $sessione): array
{
    $w = "t.stagione = $stagione AND t.finestra = $finestra";
    if ($sessione !== null) $w .= " AND t.sessione = $sessione";
    return query_all("SELECT t.id, t.sessione, t.id_squadra, s.nome AS squadra, t.tipo,
                             t.giocatore_acquistato, t.ruolo, t.crediti_acquisto,
                             t.giocatore_ceduto, t.crediti_recuperati, t.creato_il
                      FROM NEW_MERCATO_TRANSAZIONI t
                      LEFT JOIN NEW_SQUADRE s ON s.id = t.id_squadra AND s.stagione = t.stagione
                      WHERE $w ORDER BY t.id DESC");
}
