<?php
// ============================================================
// api/admin/ai_articolo.php
//
// FASE 2 - Articolo AI di commento alla giornata: anteprima del costo
// e generazione come BOZZA.
//
// Solo POST. Body: { stagione, utenza, password, azione, giornata, ... }
//
// ACCESSO: utenza con NEW_UTENZE.abilita_ai = 'Y' e password verificata
// lato server (la generazione ha un costo).
//
// Body comune opzionale:
//      formato            'articolo' (default, testo unico) | 'edizione'
//                         (giornale sfogliabile: una pagina per partita + commento)
//      contesto_giornata  note libere della redazione sulla giornata (max 4000
//                         caratteri), inserite nel prompt
//
// azione = "anteprima"   (GRATUITA: nessuna generazione)
//      Costruisce il prompt reale (contesto fisso + stile, dati della
//      giornata, profili, episodi, note di giornata, catalogo immagini), misura i token di input con l'endpoint ufficiale di
//      conteggio (gratuito) per ciascun modello del listino e restituisce
//      il costo previsto: "tipico" (lunghezza target dell'articolo) e
//      "massimo" (garantito dal limite di token di output).
//      Risposta: { giornata, formato, riepilogo, prompt_hash, max_token,
//                  parole_target, spesa_mese_usd, tetto_mensile_usd,
//                  cambio_eur, modelli[], prompt_utente, prompt_system }
//
// azione = "genera"      (A PAGAMENTO)
//      Body aggiuntivo: modello, prompt_hash (quello dell'anteprima).
//      Rigenera il prompt e procede SOLO se l'impronta coincide (se i
//      dati sono cambiati dopo l'anteprima risponde 409). Salva
//      l'articolo come bozza in NEW_ARTICOLI con token e costo reali.
//      Risposta: { articolo{..., formato, pagine[]}, pagine_mancanti[], costo_usd,
//                  costo_stimato_usd, interrotto, ... }
//      La risposta è richiesta in streaming (vedi ai_messaggio_stream). Se la
//      connessione cade a metà, il testo già ricevuto viene comunque salvato
//      come bozza troncata (interrotto = true, costo approssimato).
//
// GENERAZIONE DIFFERITA (consigliata se la generazione immediata viene interrotta
// dall'hosting): body aggiuntivo asincrono = true su azione "genera". Invia l'ordine alle
// Message Batches di Anthropic (-50% di costo, di solito pochi minuti) e risponde subito
// { differita: true, job{id, stato, ...} }. Poi:
//
// azione = "stato"       Body: job_id. Controlla il job e, a elaborazione finita, salva la
//      bozza. Risposta: { job{stato: in_corso | in_salvataggio | completato | errore, ...},
//      [articolo{...} e gli altri campi di "genera" quando completato], [avviso] }
//
// Richiede le tabelle di Script DB/NEW_AI_ARTICOLI.sql e di
// Script DB/NEW_AI_GAZZETTA_EDIZIONE.sql e la
// configurazione di backend/config_ai.php (sezione "articolo" opzionale).
// ============================================================

require_once __DIR__ . "/../connect.php";
require_once __DIR__ . "/../lib/StatoGiornata.php";
require_once __DIR__ . "/../lib/AnthropicClient.php";
require_once __DIR__ . "/../lib/ArticoloGiornata.php";

mysqli_set_charset($conn, "utf8mb4");

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    api_error("Metodo non consentito. Usare POST.", 405);
}

$input     = json_decode(file_get_contents("php://input"), true) ?? $_POST;
$stagione  = (int) ($input["stagione"] ?? 0);
$utenza    = trim((string) ($input["utenza"] ?? ""));
$password  = (string) ($input["password"] ?? "");
$azione    = strtolower(trim((string) ($input["azione"] ?? "")));
$giornata  = (int) ($input["giornata"] ?? 0);
$modelloRichiesto = trim((string) ($input["modello"] ?? ""));
$hashRicevuto     = trim((string) ($input["prompt_hash"] ?? ""));
$formato          = strtolower(trim((string) ($input["formato"] ?? "articolo")));
$asincrono        = !empty($input["asincrono"]);
$jobId            = (int) ($input["job_id"] ?? 0);
$contestoGiornata = trim(str_replace(["\r\n", "\r"], "\n", (string) ($input["contesto_giornata"] ?? "")));

if ($stagione < 2000 || $stagione > 2100) {
    api_error("Parametro 'stagione' obbligatorio e deve essere un anno a 4 cifre plausibile (2000-2100)", 400);
}
if (!in_array($azione, ["anteprima", "genera", "stato"], true)) {
    api_error("Azione non valida: usare 'anteprima', 'genera' o 'stato'", 400);
}
if (!in_array($formato, ["articolo", "edizione"], true)) {
    api_error("Formato non valido: usare 'articolo' o 'edizione'", 400);
}
if (mb_strlen($contestoGiornata) > 4000) {
    api_error("Le note di giornata superano la lunghezza massima di 4000 caratteri", 400);
}
if ($giornata < 1 || $giornata > 100) {
    api_error("Parametro 'giornata' obbligatorio (1-100)", 400);
}
if (!ai_verifica_abilitato($conn, $stagione, $utenza, $password)) {
    api_error("Credenziali non valide oppure utenza non abilitata alle funzioni AI", 403);
}
if (ai_config() === null || !ai_chiave_configurata()) {
    api_error("Chiave API non configurata: creare backend/config_ai.php da config_ai.example.php e caricarlo via FTP", 500);
}
if (!art_tabelle_presenti($conn)) {
    api_error("Tabelle degli articoli AI mancanti: eseguire Script DB/NEW_AI_ARTICOLI.sql", 500);
}
if (!art_fase4_presente($conn)) {
    api_error("Tabelle della Gazzetta mancanti: eseguire Script DB/NEW_AI_GAZZETTA_EDIZIONE.sql", 500);
}
if (!sg_risultati_pubblici($stagione, $giornata)) {
    api_error("La giornata $giornata non è ancora chiusa: l'articolo si genera solo a giornata conclusa", 409);
}

// ============================================================
// STATO di una generazione differita (richiesta breve, nessuna chiamata lunga)
// ============================================================
if ($azione === "stato") {
    if (!art_jobs_presenti($conn)) {
        api_error("Tabella dei job mancante: eseguire Script DB/migrazione_ai_jobs.sql", 500);
    }
    if ($jobId < 1) api_error("Parametro 'job_id' obbligatorio", 400);
    $job = art_job_leggi($conn, $jobId, $stagione);
    if (!$job) api_error("Richiesta differita non trovata", 404);
    @set_time_limit(60);
    api_success(art_job_avanza($conn, $job));
}

@set_time_limit($formato === "edizione" ? 300 : 180);

$prompt = art_costruisci($conn, $stagione, $giornata, ["formato" => $formato, "contesto_giornata" => $contestoGiornata]);
if (isset($prompt["errore"])) {
    api_error($prompt["errore"], 404);
}
$hash = art_hash($prompt);

$modelliCfg = ai_config()["modelli"] ?? [];
if (!$modelliCfg) {
    api_error("Nessun modello configurato in config_ai.php", 500);
}

$fasi       = art_piano_chiamate($prompt);                     // edizione: più chiamate brevi
$nChiamate  = count($fasi);
$tettoUsd   = (float) art_cfg("tetto_mensile_usd", 0);        // 0 = nessun tetto
$tokTipico  = (int) $prompt["token_tipico"];
$maxToken   = (int) $prompt["max_token"];
$spesaMese  = art_spesa_mese_usd($conn);

/**
 * Token di input per un modello: conteggio ufficiale (gratuito) oppure,
 * se non risponde, stima per caratteri.
 */
function art_token_input(string $modello, array $prompt): array
{
    $c = ai_conta_token($modello, $prompt["messages"], $prompt["system"]);
    if ($c["ok"] && isset($c["dati"]["input_tokens"])) {
        return ["token" => (int) $c["dati"]["input_tokens"], "approssimato" => false, "errore" => null];
    }
    return ["token" => art_stima_token($prompt), "approssimato" => true, "errore" => $c["errore"]];
}

/** Riga di costo per un modello. */
function art_riga_modello(string $id, array $p, array $prompt, int $tokTipico, int $maxToken,
                          float $spesaMese, float $tettoUsd, int $nChiamate = 1): array
{
    $t = art_token_input($id, $prompt);
    // Generazione a fasi: il prompt viaggia a ogni chiamata, più le pagine già scritte
    // (in media metà dell'output complessivo) rimandate alle fasi successive
    $inTipico  = $t["token"] * $nChiamate + (int) round($tokTipico * ($nChiamate - 1) / 2);
    $inMassimo = $t["token"] * $nChiamate + (int) round($maxToken * ($nChiamate - 1) / 2);
    $tipico = ai_costo_usd($id, $inTipico, $tokTipico);
    $massimo = ai_costo_usd($id, $inMassimo, $maxToken);
    return [
        "modello"              => $id,
        "etichetta"            => $p["etichetta"] ?? $id,
        "chiamate"             => $nChiamate,
        // generazione differita: una sola richiesta, costo scontato
        "costo_tipico_differita_usd"  => ai_costo_usd($id, $t["token"], $tokTipico) !== null ? ai_costo_usd($id, $t["token"], $tokTipico) * ai_fattore_batch() : null,
        "costo_massimo_differita_usd" => ai_costo_usd($id, $t["token"], $maxToken) !== null ? ai_costo_usd($id, $t["token"], $maxToken) * ai_fattore_batch() : null,
        "token_input"          => $t["token"],
        "approssimato"         => $t["approssimato"],
        "errore_conteggio"     => $t["errore"],
        "token_output_tipico"  => $tokTipico,
        "token_output_massimo" => $maxToken,
        "costo_tipico_usd"     => $tipico,
        "costo_massimo_usd"    => $massimo,
        "entro_tetto"          => $tettoUsd <= 0 || ($spesaMese + (float) $massimo) <= $tettoUsd,
    ];
}

// ============================================================
// ANTEPRIMA (gratuita)
// ============================================================
if ($azione === "anteprima") {
    $righe = [];
    foreach ($modelliCfg as $id => $p) {
        $righe[] = art_riga_modello((string) $id, $p, $prompt, $tokTipico, $maxToken, $spesaMese, $tettoUsd, $nChiamate);
    }
    $cambio = art_cfg("cambio_eur_per_usd", null);
    api_success([
        "stagione"          => $stagione,
        "giornata"          => $giornata,
        "formato"           => $formato,
        "chiamate"          => $nChiamate,
        "differita_disponibile" => art_differita_abilitata($conn),
        "sconto_differita"  => ai_fattore_batch(),
        "job_in_corso"      => art_jobs_presenti($conn) ? art_job_aperto($conn, $stagione, $giornata, $formato) : null,
        "riepilogo"         => $prompt["riepilogo"],
        "prompt_hash"       => $hash,
        "max_token"         => $maxToken,
        "parole_target"     => $prompt["parole_target"],
        "spesa_mese_usd"    => round($spesaMese, 6),
        "tetto_mensile_usd" => $tettoUsd > 0 ? $tettoUsd : null,
        "cambio_eur"        => $cambio !== null ? (float) $cambio : null,
        "modelli"           => $righe,
        "prompt_utente"     => $prompt["prompt_utente"],
        "prompt_system"     => $prompt["system"],
    ]);
}

// ============================================================
// GENERAZIONE (a pagamento)
// ============================================================
if (!isset($modelliCfg[$modelloRichiesto])) {
    api_error("Modello non valido o non presente nel listino di config_ai.php", 400);
}
if ($hashRicevuto === "" || !hash_equals($hash, $hashRicevuto)) {
    api_error("I dati della giornata sono cambiati dopo l'anteprima: rifai l'anteprima prima di generare", 409);
}

$stima = art_riga_modello($modelloRichiesto, $modelliCfg[$modelloRichiesto], $prompt,
                          $tokTipico, $maxToken, $spesaMese, $tettoUsd, $nChiamate);
if (!$stima["entro_tetto"]) {
    api_error(sprintf("Tetto mensile di spesa (%.2f USD) raggiunto: spesi %.4f USD questo mese, la generazione potrebbe costare fino a %.4f USD",
                      $tettoUsd, $spesaMese, (float) $stima["costo_massimo_usd"]), 402);
}

// ------------------------------------------------------------
// Generazione DIFFERITA: ordine alle Message Batches e risposta immediata
// ------------------------------------------------------------
if ($asincrono) {
    if (!art_differita_abilitata($conn)) {
        api_error("Generazione differita non disponibile: eseguire Script DB/migrazione_ai_jobs.sql (o abilitarla in config_ai.php)", 500);
    }
    $aperto = art_job_aperto($conn, $stagione, $giornata, $formato);
    if ($aperto) {
        api_error("C'è già una generazione differita in corso per questa giornata e questo formato (job " . (int) $aperto["id"] . "): attendi che termini", 409);
    }
    $stCosto = $stima["costo_tipico_usd"];
    $stMax   = $stima["costo_massimo_usd"];
    $stTok   = (int) $stima["token_input"];
    $st = mysqli_prepare($conn, "INSERT INTO NEW_AI_JOBS
            (stagione, giornata, formato, modello, batch_id, stato, prompt_hash, contesto_giornata, creato_da,
             token_input_stimati, costo_stimato_usd, costo_massimo_usd)
            VALUES (?, ?, ?, ?, '', 'in_corso', ?, ?, ?, ?, ?, ?)");
    if (!$st) api_error("Impossibile registrare la richiesta (errore DB: " . mysqli_error($conn) . ")", 500);
    mysqli_stmt_bind_param($st, "iisssssidd", $stagione, $giornata, $formato, $modelloRichiesto, $hash,
                           $contestoGiornata, $utenza, $stTok, $stCosto, $stMax);
    if (!mysqli_stmt_execute($st)) {
        $errDb = mysqli_stmt_error($st);
        api_error("Impossibile registrare la richiesta (errore DB: $errDb)", 500);
    }
    $nuovoJob = (int) mysqli_insert_id($conn);
    mysqli_stmt_close($st);

    $b = ai_batch_crea("art-$stagione-$giornata-$nuovoJob", $modelloRichiesto, $prompt["messages"], $maxToken, $prompt["system"]);
    if (!$b["ok"]) {
        art_job_fallito($conn, $nuovoJob, (string) $b["errore"]);
        api_error("Invio della richiesta differita non riuscito: " . $b["errore"], 502);
    }
    $bid = mysqli_real_escape_string($conn, $b["batch_id"]);
    mysqli_query($conn, "UPDATE NEW_AI_JOBS SET batch_id = '$bid' WHERE id = $nuovoJob");
    $job = art_job_leggi($conn, $nuovoJob, $stagione);
    api_success(["differita" => true, "job" => art_job_pubblico($job)]);
}

$timeoutGen = $formato === "edizione"
    ? (int) art_cfg("timeout_edizione", 200)
    : (int) art_cfg("timeout_generazione", 120);
// Streaming (default): evita che proxy/firewall dell'hosting chiudano una
// connessione rimasta muta per decine di secondi. Disattivabile con
// "streaming" => false nella sezione "articolo" di config_ai.php.
$streaming = (bool) art_cfg("streaming", true);
// Edizione: più chiamate brevi in sequenza (una sola chiamata lunga viene
// interrotta dall'hosting dopo ~26 s: cURL 18 "partial file"). Vedi art_piano_chiamate().
$gen = art_genera_a_fasi($modelloRichiesto, $prompt, $fasi, $timeoutGen, $streaming);

$interrotto = false;      // connessione caduta a metà: salvato il testo parziale
$costoApprossimato = false;
if ($gen["ok"]) {
    $dati = $gen["dati"];
} elseif (isset($gen["parziale"]) && mb_strlen(trim($gen["parziale"]["testo"])) >= 200) {
    // La parte già ricevuta è stata comunque fatturata: la salvo come bozza troncata
    $p = $gen["parziale"];
    $interrotto = true;
    $costoApprossimato = $p["token_output_stimato"] !== null;
    $dati = [
        "content"     => [["type" => "text", "text" => $p["testo"]]],
        "stop_reason" => "interrotto",
        "usage"       => ["input_tokens" => $p["token_input"],
                          "output_tokens" => $p["token_output_stimato"] ?? $p["token_output"]],
    ];
} else {
    api_error("Generazione non riuscita: " . $gen["errore"], 502);
}

$ctxSalva = [
    "stagione" => $stagione, "giornata" => $giornata, "formato" => $formato,
    "contesto" => $contestoGiornata, "utenza" => $utenza, "modello" => $modelloRichiesto,
    "prompt" => $prompt, "stima" => $stima, "interrotto" => $interrotto,
    "costo_approssimato" => $costoApprossimato, "avviso" => $gen["errore"] ?? null,
    "ms" => $gen["ms"] ?? 0, "fattore" => 1.0,
];
$esito = art_salva_bozza($conn, $ctxSalva, $dati);
$esito["chiamate"] = $gen["chiamate"] ?? $nChiamate;
api_success($esito);
