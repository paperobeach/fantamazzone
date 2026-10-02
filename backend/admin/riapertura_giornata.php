<?php
// ============================================================
// api/admin/riapertura_giornata.php
//
// Riapertura dell'ULTIMA giornata chiusa (operazione inversa di
// admin/chiusura_giornata.php).
//
// GET  ?stagione=2026
//      Stato: ultima giornata chiusa, se e' riapribile, avvisi.
//
// POST { stagione, giornata }
//      `giornata` deve coincidere con l'ultima giornata chiusa.
//      In sequenza:
//        1. ricostruzione NEW_STATISTICHE con i dati fino alla
//           giornata precedente (giornata - 1)
//        2. ricostruzione NEW_GENERALE (classifica) fino alla precedente
//        3. ricostruzione NEW_TOP11 / NEW_FLOP11 fino alla precedente
//        4. cancellazione della riga di NEW_CALENDARIO_CK (flag di
//           chiusura rimosso) - SOLO se 1-3 sono riusciti
//      Dopo il passo 4 la giornata corrente (giornata_corrente.php:
//      ultima chiusa + 1) torna ad essere la giornata riaperta.
//
// Se si riapre la giornata 1 non esistono giornate precedenti: le
// tabelle aggregate della stagione vengono svuotate.
//
// NON vengono toccati i dati di fase 1 e 2 della giornata riaperta
// (NEW_VOTI_SERIE_A, NEW_VOTI, NEW_RISULTATI): l'utente puo' correggerli,
// ricalcolare e richiudere. Non vengono toccate nemmeno le formazioni.
//
// Come per la chiusura: tabelle MyISAM (niente rollback), ogni passo
// ricostruisce da zero e il flag e' rimosso per ultimo, quindi un errore
// a meta' lascia la giornata CHIUSA e l'operazione si puo' rilanciare.
// Stesso lock applicativo della chiusura (nessuna operazione concorrente).
//
// Risposta POST:
//   { ok, riaperta, giornata, passi: [{codice, etichetta, ok, dettaglio?,
//     errore?}], avvisi: [] }   ok=false => un passo e' fallito.
// ============================================================
require_once __DIR__ . "/../connect.php";
require_once __DIR__ . "/../lib/AggiornamentiGiornata.php";

// ------------------------------------------------------------
// Stato della riapertura
// ------------------------------------------------------------
function stato_riapertura($conn, int $stagione): array
{
    $r = query_one("SELECT MAX(giornata) AS g FROM NEW_CALENDARIO_CK
                    WHERE stagione = $stagione AND ck_giocata = 'S'");
    $ultimaChiusa = (int) ($r['g'] ?? 0);

    $blocchi = [];
    $avvisi  = [];

    if ($ultimaChiusa === 0) {
        $blocchi[] = "Nessuna giornata chiusa per la stagione $stagione: non c'è nulla da riaprire";
    } else {
        // Risultati già calcolati per giornate successive (simulazioni in corso)
        $r = query_one("SELECT COUNT(DISTINCT giornata) AS n FROM NEW_RISULTATI
                        WHERE stagione = $stagione AND giornata > $ultimaChiusa");
        if ((int) $r['n'] > 0) {
            $avvisi[] = "Esistono già risultati calcolati per giornate successive alla "
                      . "$ultimaChiusa: andranno ricalcolati dopo la nuova chiusura";
        }
    }

    return [
        "stagione"            => $stagione,
        "ultima_chiusa"       => $ultimaChiusa ?: null,
        "giornata_precedente" => $ultimaChiusa > 0 ? $ultimaChiusa - 1 : null,
        "riapribile"          => empty($blocchi),
        "motivi_blocco"       => $blocchi,
        "avvisi"              => $avvisi,
    ];
}

// ============================================================
// GET
// ============================================================
if ($_SERVER["REQUEST_METHOD"] === "GET") {
    $stagione = param_int("stagione");
    if (!$stagione) api_error("Parametro obbligatorio mancante: stagione", 400);
    api_success(stato_riapertura($conn, $stagione));
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    api_error("Metodo non consentito. Usare GET o POST.", 405);
}

// ============================================================
// POST - riapertura
// ============================================================
$input    = json_decode(file_get_contents("php://input"), true) ?? $_POST;
$stagione = (int) ($input["stagione"] ?? 0);
$giornata = (int) ($input["giornata"] ?? 0);
if (!$stagione) api_error("Parametro obbligatorio mancante: stagione", 400);
if (!$giornata) api_error("Parametro obbligatorio mancante: giornata", 400);

// Stesso lock della chiusura: una sola operazione alla volta per stagione
$lockName = "fantamazzone_chiusura_$stagione";
$lockRes  = mysqli_query($conn, "SELECT GET_LOCK('$lockName', 0) AS l");
$lockRow  = $lockRes ? mysqli_fetch_assoc($lockRes) : null;
if (!$lockRow || (int) $lockRow["l"] !== 1) {
    api_error("Un'altra operazione di chiusura/riapertura è già in corso per la stagione $stagione", 409);
}
$rilascia = function () use ($conn, $lockName) {
    mysqli_query($conn, "SELECT RELEASE_LOCK('$lockName')");
};

// Stato rivalutato DENTRO il lock
$stato = stato_riapertura($conn, $stagione);
if (!$stato["riapribile"]) {
    $rilascia();
    api_error(implode(". ", $stato["motivi_blocco"]), 409);
}
if ($giornata !== $stato["ultima_chiusa"]) {
    $rilascia();
    api_error("Si può riaprire solo l'ultima giornata chiusa (giornata {$stato['ultima_chiusa']}), non la $giornata", 400);
}

$precedente = $giornata - 1;   // dati considerati nel ricalcolo
$passi      = [];
$avvisi     = $stato["avvisi"];
$errore     = null;

$passiDef = [
    ["statistiche", "Statistiche giocatori", function () use ($conn, $stagione, $precedente) {
        // Con $precedente = 0 la tabella viene svuotata (nessun dato da inserire)
        $n = agg_statistiche($conn, $stagione, $precedente);
        return $precedente > 0 ? "$n giocatori (fino alla giornata $precedente)" : "svuotate";
    }],
    ["generale", "Classifica generale", function () use ($conn, $stagione, $precedente) {
        $n = agg_generale($conn, $stagione, $precedente);
        return $precedente > 0 ? "$n squadre (fino alla giornata $precedente)" : "svuotata";
    }],
    ["top_flop", "Top 11 / Flop 11", function () use ($conn, $stagione, $precedente, &$avvisi) {
        if ($precedente === 0) {
            agg_esegui($conn, "DELETE FROM NEW_TOP11  WHERE stagione = $stagione", "Pulizia Top 11");
            agg_esegui($conn, "DELETE FROM NEW_FLOP11 WHERE stagione = $stagione", "Pulizia Flop 11");
            return "svuotate";
        }
        $av = agg_top_flop($conn, $stagione);
        foreach ($av as $a) $avvisi[] = $a;
        return empty($av) ? "completi" : count($av) . " avvisi";
    }],
    ["riapertura", "Riapertura giornata", function () use ($conn, $stagione, $giornata) {
        agg_esegui($conn, "DELETE FROM NEW_CALENDARIO_CK
                           WHERE stagione = $stagione AND giornata = $giornata",
                   "Rimozione flag di chiusura");
        return "giornata $giornata riaperta";
    }],
];

foreach ($passiDef as [$codice, $etichetta, $fn]) {
    try {
        $dettaglio = $fn();
        $passi[] = ["codice" => $codice, "etichetta" => $etichetta, "ok" => true, "dettaglio" => $dettaglio];
    } catch (Throwable $e) {
        $passi[] = ["codice" => $codice, "etichetta" => $etichetta, "ok" => false, "errore" => $e->getMessage()];
        $errore = "$etichetta: " . $e->getMessage();
        break; // il flag NON viene rimosso: la giornata resta chiusa
    }
}

$rilascia();

if ($errore !== null) {
    api_success([
        "ok"       => false,
        "riaperta" => false,
        "giornata" => $giornata,
        "errore"   => $errore . " — la giornata resta chiusa, la riapertura può essere rilanciata.",
        "passi"    => $passi,
        "avvisi"   => $avvisi,
    ]);
}

api_success([
    "ok"       => true,
    "riaperta" => true,
    "giornata" => $giornata,
    "passi"    => $passi,
    "avvisi"   => $avvisi,
]);
