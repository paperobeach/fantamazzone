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
// azione = "anteprima"   (GRATUITA: nessuna generazione)
//      Costruisce il prompt reale (dati della giornata + profili +
//      episodi), misura i token di input con l'endpoint ufficiale di
//      conteggio (gratuito) per ciascun modello del listino e restituisce
//      il costo previsto: "tipico" (lunghezza target dell'articolo) e
//      "massimo" (garantito dal limite di token di output).
//      Risposta: { giornata, riepilogo, prompt_hash, max_token,
//                  parole_target, spesa_mese_usd, tetto_mensile_usd,
//                  cambio_eur, modelli[], prompt_utente }
//
// azione = "genera"      (A PAGAMENTO)
//      Body aggiuntivo: modello, prompt_hash (quello dell'anteprima).
//      Rigenera il prompt e procede SOLO se l'impronta coincide (se i
//      dati sono cambiati dopo l'anteprima risponde 409). Salva
//      l'articolo come bozza in NEW_ARTICOLI con token e costo reali.
//      Risposta: { articolo{...}, costo_usd, costo_stimato_usd, interrotto, ... }
//      La risposta è richiesta in streaming (vedi ai_messaggio_stream). Se la
//      connessione cade a metà, il testo già ricevuto viene comunque salvato
//      come bozza troncata (interrotto = true, costo approssimato).
//
// Richiede le tabelle di Script DB/NEW_AI_ARTICOLI.sql e la
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

if ($stagione < 2000 || $stagione > 2100) {
    api_error("Parametro 'stagione' obbligatorio e deve essere un anno a 4 cifre plausibile (2000-2100)", 400);
}
if (!in_array($azione, ["anteprima", "genera"], true)) {
    api_error("Azione non valida: usare 'anteprima' o 'genera'", 400);
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
if (!sg_risultati_pubblici($stagione, $giornata)) {
    api_error("La giornata $giornata non è ancora chiusa: l'articolo si genera solo a giornata conclusa", 409);
}

@set_time_limit(180);

$prompt = art_costruisci($conn, $stagione, $giornata);
if (isset($prompt["errore"])) {
    api_error($prompt["errore"], 404);
}
$hash = art_hash($prompt);

$modelliCfg = ai_config()["modelli"] ?? [];
if (!$modelliCfg) {
    api_error("Nessun modello configurato in config_ai.php", 500);
}

$tettoUsd   = (float) art_cfg("tetto_mensile_usd", 0);        // 0 = nessun tetto
$tokTipico  = (int) art_cfg("token_output_tipico", 1100);
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
                          float $spesaMese, float $tettoUsd): array
{
    $t = art_token_input($id, $prompt);
    $tipico = ai_costo_usd($id, $t["token"], $tokTipico);
    $massimo = ai_costo_usd($id, $t["token"], $maxToken);
    return [
        "modello"              => $id,
        "etichetta"            => $p["etichetta"] ?? $id,
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
        $righe[] = art_riga_modello((string) $id, $p, $prompt, $tokTipico, $maxToken, $spesaMese, $tettoUsd);
    }
    $cambio = art_cfg("cambio_eur_per_usd", null);
    api_success([
        "stagione"          => $stagione,
        "giornata"          => $giornata,
        "riepilogo"         => $prompt["riepilogo"],
        "prompt_hash"       => $hash,
        "max_token"         => $maxToken,
        "parole_target"     => $prompt["parole_target"],
        "spesa_mese_usd"    => round($spesaMese, 6),
        "tetto_mensile_usd" => $tettoUsd > 0 ? $tettoUsd : null,
        "cambio_eur"        => $cambio !== null ? (float) $cambio : null,
        "modelli"           => $righe,
        "prompt_utente"     => $prompt["prompt_utente"],
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
                          $tokTipico, $maxToken, $spesaMese, $tettoUsd);
if (!$stima["entro_tetto"]) {
    api_error(sprintf("Tetto mensile di spesa (%.2f USD) raggiunto: spesi %.4f USD questo mese, la generazione potrebbe costare fino a %.4f USD",
                      $tettoUsd, $spesaMese, (float) $stima["costo_massimo_usd"]), 402);
}

$timeoutGen = (int) art_cfg("timeout_generazione", 120);
// Streaming (default): evita che proxy/firewall dell'hosting chiudano una
// connessione rimasta muta per decine di secondi. Disattivabile con
// "streaming" => false nella sezione "articolo" di config_ai.php.
$streaming = (bool) art_cfg("streaming", true);
$gen = $streaming
    ? ai_messaggio_stream($modelloRichiesto, $prompt["messages"], $maxToken, $prompt["system"], $timeoutGen)
    : ai_messaggio($modelloRichiesto, $prompt["messages"], $maxToken, $prompt["system"], $timeoutGen);

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

$grezzo = "";
foreach (($dati["content"] ?? []) as $blocco) {
    if (($blocco["type"] ?? "") === "text") $grezzo .= (string) ($blocco["text"] ?? "");
}
if (trim($grezzo) === "") {
    api_error("Il modello non ha restituito testo (stop_reason: " . ($dati["stop_reason"] ?? "n/d") . ")", 502);
}

$tokIn   = (int) ($dati["usage"]["input_tokens"] ?? 0);
$tokOut  = (int) ($dati["usage"]["output_tokens"] ?? 0);
$costo   = ai_costo_usd($modelloRichiesto, $tokIn, $tokOut);
$troncato = $interrotto || (($dati["stop_reason"] ?? "") === "max_tokens");
$parsed  = art_parse_testo($grezzo);

$episodiIds = implode(",", array_map(fn($e) => (int) $e["id"], $prompt["riepilogo"]["episodi"]));
$troncatoFlag = $troncato ? "Y" : "N";
$tokStima = (int) $stima["token_input"];
$costoStima = $stima["costo_tipico_usd"];
$costoVal = $costo;

$st = mysqli_prepare($conn, "INSERT INTO NEW_ARTICOLI
        (stagione, giornata, titolo, sommario, testo, stato, modello, troncato,
         token_input_stimati, costo_stimato_usd, token_input, token_output, costo_usd,
         creato_da, episodi_usati)
        VALUES (?, ?, ?, ?, ?, 'bozza', ?, ?, ?, ?, ?, ?, ?, ?, ?)");
if (!$st) {
    api_error("Articolo generato ma non salvato (errore DB: " . mysqli_error($conn) . ")", 500);
}
mysqli_stmt_bind_param($st, "iisssssidiidss",
    $stagione, $giornata, $parsed["titolo"], $parsed["sommario"], $parsed["testo"],
    $modelloRichiesto, $troncatoFlag, $tokStima, $costoStima, $tokIn, $tokOut, $costoVal,
    $utenza, $episodiIds);
if (!mysqli_stmt_execute($st)) {
    api_error("Articolo generato ma non salvato (errore DB: " . mysqli_stmt_error($st) . ")", 500);
}
$idArticolo = (int) mysqli_insert_id($conn);
mysqli_stmt_close($st);

// Rotazione degli aneddoti: segno gli episodi usati
if ($episodiIds !== "") {
    mysqli_query($conn, "UPDATE NEW_EPISODI_LEGA SET ultimo_utilizzo = NOW() WHERE id IN ($episodiIds)");
}

api_success([
    "articolo" => [
        "id"        => $idArticolo,
        "stagione"  => $stagione,
        "giornata"  => $giornata,
        "titolo"    => $parsed["titolo"],
        "sommario"  => $parsed["sommario"],
        "testo"     => $parsed["testo"],
        "stato"     => "bozza",
        "modello"   => $modelloRichiesto,
        "troncato"  => $troncato,
    ],
    "interrotto"         => $interrotto,
    "avviso_interruzione" => $interrotto ? ($gen["errore"] ?? null) : null,
    "costo_approssimato" => $costoApprossimato,
    "formato_ok"         => $parsed["formato_ok"],
    "token_input"        => $tokIn,
    "token_output"       => $tokOut,
    "costo_usd"          => $costo,
    "costo_stimato_usd"  => $costoStima,
    "costo_massimo_usd"  => $stima["costo_massimo_usd"],
    "ms"                 => $gen["ms"],
]);
