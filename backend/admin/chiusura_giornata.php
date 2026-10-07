<?php
// ============================================================
// api/admin/chiusura_giornata.php
//
// Fase 3 di "Gestione voti": CHIUSURA GIORNATA.
//
// GET  ?stagione=2026[&giornata=5]
//      Stato della chiusura (giornata di default = giornata
//      corrente): chiusa, chiudibile, motivi di blocco, avvisi non
//      bloccanti, riepilogo (voti Serie A, partite calcolate).
//
// POST { stagione, giornata }
//      Chiude definitivamente la giornata, in sequenza:
//        1. ricostruzione NEW_STATISTICHE
//        2. ricostruzione NEW_GENERALE (classifica)
//        3. ricostruzione NEW_TOP11 / NEW_FLOP11
//        4. NEW_CALENDARIO_CK.ck_giocata = 'S'  (SOLO se 1-3 riusciti)
//      Dopo il passo 4 la "giornata corrente" (vedi
//      giornata_corrente.php: prima giornata dopo l'ultima chiusa)
//      diventa automaticamente la successiva.
//
// Le tabelle sono MyISAM (nessun rollback): ogni passo ricostruisce
// la tabella da zero e il flag di chiusura e' scritto per ultimo, quindi
// un errore a meta' lascia la giornata APERTA e la chiusura si puo'
// rilanciare senza danni. Un lock applicativo (GET_LOCK) impedisce
// due chiusure contemporanee sulla stessa stagione.
//
// Risposta POST:
//   { ok, chiusa, giornata, prossima_giornata, stagione_conclusa,
//     passi: [{codice, etichetta, ok, dettaglio?, errore?}], avvisi: [] }
//   ok=false => un passo e' fallito: chiusa=false, errore valorizzato.
//
// Non e' bloccata dall'.htaccess (che nega i file chiudi_*/aggiorna_*).
// ============================================================
require_once __DIR__ . "/../connect.php";
require_once __DIR__ . "/../lib/AggiornamentiGiornata.php";
require_once __DIR__ . "/../lib/FormazioniChampions.php";

// ------------------------------------------------------------
// Stato della chiusura di una giornata
// ------------------------------------------------------------
function stato_chiusura($conn, int $stagione, ?int $giornata): array
{
    $ult = query_one("SELECT MAX(giornata) AS g FROM NEW_CALENDARIO_CK
                      WHERE stagione = $stagione AND ck_giocata = 'S'");
    $ultimaChiusa = (int) ($ult['g'] ?? 0);

    $cal = query_one("SELECT MAX(giornata) AS g FROM NEW_CALENDARIO WHERE stagione = $stagione");
    $ultimaCalendario = (int) ($cal['g'] ?? 0);

    // Stessa regola di giornata_corrente.php / voti_serie_a.php
    $corrente = $ultimaChiusa + 1;
    if ($ultimaCalendario > 0 && $corrente > $ultimaCalendario) $corrente = $ultimaCalendario;

    if ($giornata === null) $giornata = $corrente;

    $ck = query_one("SELECT ck_giocata FROM NEW_CALENDARIO_CK
                     WHERE stagione = $stagione AND giornata = $giornata");
    $chiusa = $ck !== null && $ck['ck_giocata'] === 'S';

    $r = query_one("SELECT COUNT(*) AS n FROM NEW_CALENDARIO
                    WHERE stagione = $stagione AND giornata = $giornata");
    $righeCalendario = (int) $r['n'];
    $partiteAttese   = intdiv($righeCalendario, 2);

    $r = query_one("SELECT COUNT(*) AS n
                    FROM NEW_CALENDARIO c
                    JOIN NEW_RISULTATI r ON r.stagione = c.stagione
                                        AND r.giornata = c.giornata
                                        AND r.id_squadra = c.squadra
                    WHERE c.stagione = $stagione AND c.giornata = $giornata");
    $righeRisultato   = (int) $r['n'];
    $partiteCalcolate = intdiv($righeRisultato, 2);

    // Champions: se nella giornata si gioca un turno, anche le sue partite
    // devono essere calcolate (a giornata chiusa non si può più ricalcolare)
    $champAttese = count(champions_partite_giornata($stagione, $giornata)['partite']);
    $r = query_one("SELECT COUNT(*) AS n FROM NEW_RISULTATI_CHAMP
                    WHERE stagione = $stagione AND giornata = $giornata");
    $champCalcolate = intdiv((int) $r['n'], 2);

    $r = query_one("SELECT COUNT(*) AS n FROM NEW_VOTI_SERIE_A
                    WHERE stagione = $stagione AND giornata = $giornata");
    $votiSerieA = (int) $r['n'];

    $r = query_one("SELECT COUNT(*) AS n FROM NEW_VOTI
                    WHERE stagione = $stagione AND giornata = $giornata AND giocata = 0");
    $senzaContributo = (int) $r['n'];

    $blocchi = [];
    if ($righeCalendario === 0) {
        $blocchi[] = "La giornata $giornata non esiste nel calendario della stagione $stagione";
    } else {
        if ($chiusa) {
            $blocchi[] = "La giornata $giornata è già chiusa";
        } elseif ($giornata !== $corrente) {
            $blocchi[] = $giornata > $corrente
                ? "Chiudere prima le giornate precedenti (giornata corrente: $corrente)"
                : "La giornata $giornata non è la giornata corrente ($corrente)";
        }
        if ($righeRisultato < $righeCalendario) {
            $blocchi[] = "Calcolo non completo: $partiteCalcolate partite calcolate su $partiteAttese (eseguire la fase 2)";
        }
        if ($champCalcolate < $champAttese) {
            $blocchi[] = "Calcolo Champions non completo: $champCalcolate partite calcolate su $champAttese (eseguire la fase 2)";
        }
    }

    $avvisi = [];
    if ($senzaContributo > 0) {
        $avvisi[] = "$senzaContributo titolari non hanno contribuito al punteggio (senza voto o sostituiti da panchinari)";
    }

    return [
        "stagione"           => $stagione,
        "giornata"           => $giornata,
        "giornata_corrente"  => $corrente,
        "ultima_chiusa"      => $ultimaChiusa,
        "ultima_calendario"  => $ultimaCalendario,
        "ultima_della_stagione" => $ultimaCalendario > 0 && $giornata === $ultimaCalendario,
        "chiusa"             => $chiusa,
        "voti_serie_a"       => $votiSerieA,
        "partite_attese"     => $partiteAttese,
        "partite_calcolate"  => $partiteCalcolate,
        "champions_attese"   => $champAttese,
        "champions_calcolate" => $champCalcolate,
        "senza_contributo"   => $senzaContributo,
        "chiudibile"         => empty($blocchi),
        "motivi_blocco"      => $blocchi,
        "avvisi"             => $avvisi,
    ];
}

// ============================================================
// GET
// ============================================================
if ($_SERVER["REQUEST_METHOD"] === "GET") {
    $stagione = param_int("stagione");
    if (!$stagione) api_error("Parametro obbligatorio mancante: stagione", 400);
    $giornata = param_int("giornata", false);

    api_success(stato_chiusura($conn, $stagione, $giornata ?: null));
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    api_error("Metodo non consentito. Usare GET o POST.", 405);
}

// ============================================================
// POST - chiusura
// ============================================================
$input    = json_decode(file_get_contents("php://input"), true) ?? $_POST;
$stagione = (int) ($input["stagione"] ?? 0);
$giornata = (int) ($input["giornata"] ?? 0);
if (!$stagione) api_error("Parametro obbligatorio mancante: stagione", 400);
if (!$giornata) api_error("Parametro obbligatorio mancante: giornata", 400);

// Lock applicativo: una sola chiusura alla volta per stagione
$lockName = "fantamazzone_chiusura_$stagione";
$lockRes  = mysqli_query($conn, "SELECT GET_LOCK('$lockName', 0) AS l");
$lockRow  = $lockRes ? mysqli_fetch_assoc($lockRes) : null;
if (!$lockRow || (int) $lockRow["l"] !== 1) {
    api_error("Un'altra chiusura giornata è già in corso per la stagione $stagione", 409);
}

$rilascia = function () use ($conn, $lockName) {
    mysqli_query($conn, "SELECT RELEASE_LOCK('$lockName')");
};

// Stato rivalutato DENTRO il lock (evita doppie chiusure concorrenti)
$stato = stato_chiusura($conn, $stagione, $giornata);
if (!$stato["chiudibile"]) {
    $rilascia();
    api_error(implode(". ", $stato["motivi_blocco"]), $stato["chiusa"] ? 409 : 400);
}

$passi  = [];
$avvisi = $stato["avvisi"];
$errore = null;

$passiDef = [
    ["statistiche", "Statistiche giocatori", function () use ($conn, $stagione, $giornata, &$avvisi) {
        $n = agg_statistiche($conn, $stagione, $giornata);
        return "$n giocatori";
    }],
    ["generale", "Classifica generale", function () use ($conn, $stagione, $giornata) {
        $n = agg_generale($conn, $stagione, $giornata);
        return "$n squadre";
    }],
    ["top_flop", "Top 11 / Flop 11", function () use ($conn, $stagione, &$avvisi) {
        $av = agg_top_flop($conn, $stagione);
        foreach ($av as $a) $avvisi[] = $a;
        return empty($av) ? "completi" : count($av) . " avvisi";
    }],
    ["chiusura", "Chiusura giornata", function () use ($conn, $stagione, $giornata) {
        $ck = query_one("SELECT ck_giocata FROM NEW_CALENDARIO_CK
                         WHERE stagione = $stagione AND giornata = $giornata");
        $sql = $ck !== null
            ? "UPDATE NEW_CALENDARIO_CK SET ck_giocata = 'S'
               WHERE stagione = $stagione AND giornata = $giornata"
            : "INSERT INTO NEW_CALENDARIO_CK (stagione, giornata, ck_giocata)
               VALUES ($stagione, $giornata, 'S')";
        agg_esegui($conn, $sql, "Scrittura flag di chiusura");
        return "giornata $giornata chiusa";
    }],
];

foreach ($passiDef as [$codice, $etichetta, $fn]) {
    try {
        $dettaglio = $fn();
        $passi[] = ["codice" => $codice, "etichetta" => $etichetta, "ok" => true, "dettaglio" => $dettaglio];
    } catch (Throwable $e) {
        $passi[] = ["codice" => $codice, "etichetta" => $etichetta, "ok" => false, "errore" => $e->getMessage()];
        $errore = "$etichetta: " . $e->getMessage();
        break; // il flag di chiusura NON viene scritto: la giornata resta aperta
    }
}

$rilascia();

if ($errore !== null) {
    api_success([
        "ok"        => false,
        "chiusa"    => false,
        "giornata"  => $giornata,
        "errore"    => $errore . " — la giornata resta aperta, la chiusura può essere rilanciata.",
        "passi"     => $passi,
        "avvisi"    => $avvisi,
    ]);
}

$ultima = $stato["ultima_della_stagione"];
api_success([
    "ok"                => true,
    "chiusa"            => true,
    "giornata"          => $giornata,
    "prossima_giornata" => $ultima ? null : $giornata + 1,
    "stagione_conclusa" => $ultima,
    "passi"             => $passi,
    "avvisi"            => $avvisi,
]);
