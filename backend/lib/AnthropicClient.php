<?php
// ============================================================
// backend/lib/AnthropicClient.php
//
// Client minimale per le API Anthropic (senza librerie esterne, adatto
// all'hosting condiviso). La configurazione (chiave API, listino) è in
// backend/config_ai.php, NON versionato.
//
// Funzioni:
//   ai_config()                      configurazione (o null se assente)
//   ai_ambiente()                    diagnostica dell'ambiente PHP
//   ai_sonda_rete()                  prova di raggiungibilità (gratuita) di
//                                    api.anthropic.com e di host di controllo
//   ai_chiamata($endpoint, $payload) POST JSON verso Anthropic
//   ai_conta_token($modello, $messaggi, $system)  token di input (gratuito)
//   ai_messaggio($modello, $messaggi, $maxToken, $system)  generazione
//   ai_costo_usd($modello, $tokIn, $tokOut)       costo in USD
//   ai_verifica_abilitato($conn, $stagione, $utenza, $password)
//                                    utenza con NEW_UTENZE.abilita_ai = 'Y'
// ============================================================

const AI_BASE_URL = "https://api.anthropic.com";

/** Carica config_ai.php; restituisce null se il file non esiste o non è valido. */
function ai_config(): ?array
{
    static $cfg = false;
    if ($cfg === false) {
        $file = __DIR__ . "/../config_ai.php";
        $letto = is_file($file) ? include $file : null;
        $cfg = is_array($letto) ? $letto : null;
    }
    return $cfg;
}

/** True se la chiave API è stata effettivamente impostata. */
function ai_chiave_configurata(): bool
{
    $cfg = ai_config();
    $k = $cfg["api_key"] ?? "";
    return $k !== "" && $k !== "INSERISCI_QUI_LA_CHIAVE";
}

/** Informazioni sull'ambiente PHP utili a capire se le chiamate in uscita sono possibili. */
function ai_ambiente(): array
{
    return [
        "php_version"         => PHP_VERSION,
        "curl_disponibile"    => function_exists("curl_init"),
        "allow_url_fopen"     => (bool) ini_get("allow_url_fopen"),
        "openssl_disponibile" => extension_loaded("openssl"),
        "max_execution_time"  => (int) ini_get("max_execution_time"),
        "memory_limit"        => ini_get("memory_limit"),
    ];
}

/**
 * Applica a un handle cURL l'impostazione "proxy" di config_ai.php:
 *   "auto" (default o assente) → comportamento predefinito di cURL (variabili
 *                                d'ambiente http_proxy/https_proxy dell'hosting)
 *   "nessuno"                  → connessione diretta, ignora i proxy
 *   "http://host:porta"        → usa quel proxy
 */
function ai_applica_proxy($ch): void
{
    $cfg  = ai_config();
    $mode = (string) ($cfg["proxy"] ?? "auto");
    if ($mode === "" || $mode === "auto") return;
    if ($mode === "nessuno") {
        curl_setopt($ch, CURLOPT_PROXY, "");
        curl_setopt($ch, CURLOPT_NOPROXY, "*");
        return;
    }
    curl_setopt($ch, CURLOPT_PROXY, $mode);
}

/** Variabili d'ambiente di proxy visibili a PHP, senza credenziali (solo schema://host:porta). */
function ai_proxy_ambiente(): array
{
    $out = [];
    foreach (["https_proxy", "HTTPS_PROXY", "http_proxy", "HTTP_PROXY", "all_proxy", "ALL_PROXY", "no_proxy", "NO_PROXY"] as $nome) {
        $v = getenv($nome);
        if ($v === false || $v === "") continue;
        $p = @parse_url($v);
        $out[$nome] = $p && isset($p["host"])
            ? (($p["scheme"] ?? "") !== "" ? $p["scheme"] . "://" : "") . $p["host"] . (isset($p["port"]) ? ":" . $p["port"] : "")
            : "(impostata)";
    }
    return $out;
}

/** Una prova HEAD verso $url, con proxy predefinito o connessione diretta. */
function ai_sonda_url(string $url, bool $diretto): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_NOBODY         => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT        => 6,
    ]);
    if ($diretto) {
        curl_setopt($ch, CURLOPT_PROXY, "");
        curl_setopt($ch, CURLOPT_NOPROXY, "*");
    }
    $t0   = microtime(true);
    $res  = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = $res === false ? curl_error($ch) : null;
    curl_close($ch);
    return [
        "ok"     => $res !== false && $http > 0,
        "http"   => $http,
        "ms"     => (int) round((microtime(true) - $t0) * 1000),
        "errore" => $err,
    ];
}

/**
 * Prova di raggiungibilità, GRATUITA (nessuna chiave, nessuna generazione).
 * Per ogni host prova prima il percorso predefinito (con l'eventuale proxy
 * dell'hosting) e, se fallisce, la connessione diretta. Gli host di controllo
 * servono a capire se il blocco riguarda solo Anthropic o tutto il traffico.
 *
 * "esito":
 *   anthropic_raggiungibile   percorso predefinito OK
 *   anthropic_solo_diretto    funziona solo bypassando il proxy → proxy="nessuno"
 *   bloccato_solo_anthropic   altri host raggiungibili, Anthropic no
 *   bloccato_tutto            nessun host esterno raggiungibile
 *   curl_assente              cURL non disponibile
 */
function ai_sonda_rete(): array
{
    if (!function_exists("curl_init")) {
        return ["esito" => "curl_assente", "prove" => [], "proxy_ambiente" => ai_proxy_ambiente(),
                "proxy_configurato" => (string) (ai_config()["proxy"] ?? "auto")];
    }
    $bersagli = [
        "api.anthropic.com" => "https://api.anthropic.com/",
        "example.com"       => "https://example.com/",
        "api.github.com"    => "https://api.github.com/",
        "workers.dev"       => "https://workers.dev/",
    ];
    $prove = [];
    foreach ($bersagli as $host => $url) {
        $pred = ai_sonda_url($url, false);
        $dir  = $pred["ok"] ? null : ai_sonda_url($url, true);
        $prove[] = ["host" => $host, "predefinito" => $pred, "diretto" => $dir];
    }

    $anth   = $prove[0];
    $altri  = array_slice($prove, 1);
    $altriOk = false;
    foreach ($altri as $a) {
        if ($a["predefinito"]["ok"] || ($a["diretto"]["ok"] ?? false)) $altriOk = true;
    }
    if ($anth["predefinito"]["ok"])            $esito = "anthropic_raggiungibile";
    elseif ($anth["diretto"]["ok"] ?? false)   $esito = "anthropic_solo_diretto";
    elseif ($altriOk)                          $esito = "bloccato_solo_anthropic";
    else                                       $esito = "bloccato_tutto";

    return [
        "esito"             => $esito,
        "prove"             => $prove,
        "proxy_ambiente"    => ai_proxy_ambiente(),
        "proxy_configurato" => (string) (ai_config()["proxy"] ?? "auto"),
        "curl_versione"     => curl_version()["version"] ?? null,
    ];
}

/**
 * POST JSON verso Anthropic.
 * Ritorna ["ok"=>bool, "http"=>int, "ms"=>int, "dati"=>array|null, "errore"=>string|null].
 * Non lancia eccezioni e non include mai la chiave nei messaggi di errore.
 */
function ai_chiamata(string $endpoint, array $payload): array
{
    $cfg = ai_config();
    if (!ai_chiave_configurata()) {
        return ["ok" => false, "http" => 0, "ms" => 0, "dati" => null,
                "errore" => "Chiave API non configurata (config_ai.php mancante o non compilato)"];
    }

    $url     = AI_BASE_URL . $endpoint;
    $timeout = (int) ($cfg["timeout"] ?? 25);
    $body    = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $headers = [
        "content-type: application/json",
        "x-api-key: " . $cfg["api_key"],
        "anthropic-version: " . ($cfg["anthropic_version"] ?? "2023-06-01"),
    ];

    $t0 = microtime(true);
    $risposta = null;
    $http = 0;
    $errTrasporto = null;

    if (function_exists("curl_init")) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => $timeout,
        ]);
        ai_applica_proxy($ch);
        $risposta = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($risposta === false) $errTrasporto = "cURL: " . curl_error($ch);
        curl_close($ch);
    } elseif (ini_get("allow_url_fopen")) {
        $ctx = stream_context_create(["http" => [
            "method"        => "POST",
            "header"        => implode("\r\n", $headers),
            "content"       => $body,
            "timeout"       => $timeout,
            "ignore_errors" => true,
        ]]);
        $risposta = @file_get_contents($url, false, $ctx);
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
            $http = (int) $m[1];
        }
        if ($risposta === false) $errTrasporto = "file_get_contents: connessione non riuscita";
    } else {
        $errTrasporto = "Né cURL né allow_url_fopen sono disponibili: chiamate in uscita impossibili";
    }
    $ms = (int) round((microtime(true) - $t0) * 1000);

    if ($errTrasporto !== null) {
        return ["ok" => false, "http" => $http, "ms" => $ms, "dati" => null, "errore" => $errTrasporto];
    }

    $dati = json_decode((string) $risposta, true);
    if ($http < 200 || $http >= 300) {
        $msg = $dati["error"]["message"] ?? "Risposta HTTP $http non valida";
        return ["ok" => false, "http" => $http, "ms" => $ms, "dati" => is_array($dati) ? $dati : null,
                "errore" => "Anthropic: $msg"];
    }
    if (!is_array($dati)) {
        return ["ok" => false, "http" => $http, "ms" => $ms, "dati" => null,
                "errore" => "Risposta non in formato JSON"];
    }
    return ["ok" => true, "http" => $http, "ms" => $ms, "dati" => $dati, "errore" => null];
}

/** Conta i token di input di una richiesta (endpoint gratuito, nessuna generazione). */
function ai_conta_token(string $modello, array $messaggi, ?string $system = null): array
{
    $payload = ["model" => $modello, "messages" => $messaggi];
    if ($system !== null && $system !== "") $payload["system"] = $system;
    return ai_chiamata("/v1/messages/count_tokens", $payload);
}

/** Genera un messaggio (A PAGAMENTO). */
function ai_messaggio(string $modello, array $messaggi, int $maxToken, ?string $system = null): array
{
    $payload = ["model" => $modello, "max_tokens" => $maxToken, "messages" => $messaggi];
    if ($system !== null && $system !== "") $payload["system"] = $system;
    return ai_chiamata("/v1/messages", $payload);
}

/** Costo in USD dato il numero di token, secondo il listino di config_ai.php (null se modello ignoto). */
function ai_costo_usd(string $modello, int $tokInput, int $tokOutput): ?float
{
    $cfg = ai_config();
    $p = $cfg["modelli"][$modello] ?? null;
    if (!$p) return null;
    return ($tokInput * (float) $p["input"] + $tokOutput * (float) $p["output"]) / 1000000;
}

/**
 * L'utenza (con la sua password) è abilitata alle funzioni AI nella stagione?
 * Controlla NEW_UTENZE.abilita_ai = 'Y'. È un'abilitazione specifica,
 * indipendente da "amministratore". Ritorna false anche se la colonna non
 * esiste ancora (migrazione_abilita_ai.sql non eseguita).
 */
function ai_verifica_abilitato($conn, int $stagione, string $utenza, string $password): bool
{
    if ($utenza === "" || $password === "") return false;

    $col = mysqli_query($conn, "SHOW COLUMNS FROM NEW_UTENZE LIKE 'abilita_ai'");
    if (!$col || mysqli_num_rows($col) === 0) return false;

    $esc = mysqli_real_escape_string($conn, $utenza);
    $righe = query_all("SELECT PASSWORD, abilita_ai FROM NEW_UTENZE
                        WHERE stagione = $stagione AND utenza = '$esc'");
    foreach ($righe as $r) {
        if (hash_equals((string) $r["PASSWORD"], $password)
            && strtoupper(trim((string) $r["abilita_ai"])) === "Y") {
            return true;
        }
    }
    return false;
}
