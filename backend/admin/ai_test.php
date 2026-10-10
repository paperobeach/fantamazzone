<?php
// ============================================================
// api/admin/ai_test.php
//
// FASE 1 - Test di connessione verso le API Anthropic dall'hosting.
// Serve a verificare, prima di sviluppare il resto, che Altervista
// consenta le chiamate in uscita e che la chiave API funzioni.
// Usato dalla pagina "Test connessione" della sezione di menu "AI".
//
// Solo POST. Body: { stagione, utenza, password, azione }
//
// ACCESSO: l'utenza deve avere NEW_UTENZE.abilita_ai = 'Y' (abilitazione
// specifica, indipendente da "amministratore"), con password verificata
// lato server, perché il test ha un costo.
//
// azione = "diagnostica"
//      Controlli locali, NESSUNA chiamata verso Anthropic e nessun
//      costo: versione PHP, cURL/allow_url_fopen/openssl, tempo massimo
//      di esecuzione, presenza della chiave in config_ai.php (mai
//      restituita), modelli e listino configurati.
//
// azione = "rete"
//      Verifica di raggiungibilità, gratuita (nessuna chiave, nessuna
//      generazione): prova api.anthropic.com e alcuni host di controllo,
//      sia col proxy predefinito dell'hosting sia in connessione diretta,
//      e indica se il blocco riguarda solo Anthropic o tutto il traffico.
//
// azione = "test" (default)
//      In sequenza:
//        1. conteggio token di un prompt minimo (gratuito);
//        2. generazione di una risposta brevissima (max 20 token di
//           output: costo dell'ordine di frazioni di centesimo).
//      Restituisce esito, codice HTTP, tempi, token e costo reale.
// ============================================================

require_once __DIR__ . "/../connect.php";
require_once __DIR__ . "/../lib/AnthropicClient.php";

function ai_test_modelli(): array
{
    $cfg = ai_config();
    $out = [];
    foreach (($cfg["modelli"] ?? []) as $id => $p) {
        $out[] = [
            "modello"         => $id,
            "etichetta"       => $p["etichetta"] ?? $id,
            "usd_per_mln_in"  => (float) $p["input"],
            "usd_per_mln_out" => (float) $p["output"],
        ];
    }
    return $out;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    api_error("Metodo non consentito. Usare POST.", 405);
}

$input    = json_decode(file_get_contents("php://input"), true) ?? $_POST;
$stagione = (int) ($input["stagione"] ?? 0);
$utenza   = trim((string) ($input["utenza"] ?? ""));
$password = (string) ($input["password"] ?? "");
$azione   = strtolower(trim((string) ($input["azione"] ?? "test")));

if ($stagione < 2000 || $stagione > 2100) {
    api_error("Parametro 'stagione' obbligatorio e deve essere un anno a 4 cifre plausibile (2000-2100)", 400);
}
if (!in_array($azione, ["diagnostica", "rete", "test"], true)) {
    api_error("Azione non valida: usare 'diagnostica', 'rete' o 'test'", 400);
}
if (!ai_verifica_abilitato($conn, $stagione, $utenza, $password)) {
    api_error("Credenziali non valide oppure utenza non abilitata alle funzioni AI", 403);
}

// ============================================================
// Diagnostica locale (gratuita)
// ============================================================
if ($azione === "diagnostica") {
    api_success([
        "ambiente"                     => ai_ambiente(),
        "config_presente"              => ai_config() !== null,
        "chiave_configurata"           => ai_chiave_configurata(),
        "modello_test"                 => ai_config()["modello_test"] ?? null,
        "modelli"                      => ai_test_modelli(),
        "chiamate_in_uscita_possibili" =>
            function_exists("curl_init") || (bool) ini_get("allow_url_fopen"),
    ]);
}

// ============================================================
// Verifica di rete (gratuita)
// ============================================================
if ($azione === "rete") {
    @set_time_limit(60);
    api_success(ai_sonda_rete());
}

// ============================================================
// Test reale (costo trascurabile)
// ============================================================
if (ai_config() === null || !ai_chiave_configurata()) {
    api_error("Chiave API non configurata: creare backend/config_ai.php da config_ai.example.php e caricarlo via FTP", 500);
}

@set_time_limit(60);

$modello  = (string) (ai_config()["modello_test"] ?? "claude-haiku-5-5");
$messaggi = [["role" => "user", "content" => "Rispondi con una sola parola: OK"]];

// 1. Conteggio token (gratuito)
$conteggio = ai_conta_token($modello, $messaggi);
$esito = [
    "modello"         => $modello,
    "conteggio_token" => [
        "ok"          => $conteggio["ok"],
        "http"        => $conteggio["http"],
        "ms"          => $conteggio["ms"],
        "token_input" => $conteggio["ok"] ? (int) ($conteggio["dati"]["input_tokens"] ?? 0) : null,
        "errore"      => $conteggio["errore"],
    ],
];

// 2. Generazione minima (a pagamento, ~ frazioni di centesimo)
$gen = ai_messaggio($modello, $messaggi, 20);
$tokIn = $tokOut = null;
$testo = null;
$costo = null;
if ($gen["ok"]) {
    $tokIn  = (int) ($gen["dati"]["usage"]["input_tokens"] ?? 0);
    $tokOut = (int) ($gen["dati"]["usage"]["output_tokens"] ?? 0);
    $testo  = $gen["dati"]["content"][0]["text"] ?? null;
    $costo  = ai_costo_usd($modello, $tokIn, $tokOut);
}
$esito["generazione"] = [
    "ok"           => $gen["ok"],
    "http"         => $gen["http"],
    "ms"           => $gen["ms"],
    "risposta"     => $testo,
    "token_input"  => $tokIn,
    "token_output" => $tokOut,
    "costo_usd"    => $costo,
    "errore"       => $gen["errore"],
];
$esito["tutto_ok"] = $conteggio["ok"] && $gen["ok"];
$esito["ambiente"] = ai_ambiente();

api_success($esito);
