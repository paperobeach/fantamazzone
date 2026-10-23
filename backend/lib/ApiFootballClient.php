<?php
// ============================================================
// backend/lib/ApiFootballClient.php
//
// Client minimale per API-Football (api-sports.io v3), senza librerie
// esterne (hosting condiviso). Configurazione in backend/config_api_football.php
// (NON versionato).
//
// Va incluso DOPO connect.php (usa $conn e query_one/query_all).
//
// Funzioni:
//   af_config()                 configurazione (o null se assente)
//   af_chiave_configurata()     chiave API effettivamente impostata
//   af_cron_token_valido($t)    confronto a tempo costante col token del cron
//   af_cfg($chiave, $default)   singolo valore di configurazione
//   af_ambiente()               diagnostica dell'ambiente PHP
//   af_sonda_rete()             prova di raggiungibilità (nessuna chiave, nessun costo)
//   af_chiamata($endpoint, $parametri, $stagione)
//                               GET verso API-Football con registro in NEW_API_LOG,
//                               tetto giornaliero locale e pausa tra le chiamate
//   af_richieste_oggi()         richieste registrate oggi (UTC)
//   af_ultime_chiamate($n)      ultime chiamate registrate
// ============================================================

/** Carica config_api_football.php; null se il file non esiste o non è valido. */
function af_config(): ?array
{
    static $cfg = false;
    if ($cfg === false) {
        $file = __DIR__ . "/../config_api_football.php";
        $letto = is_file($file) ? include $file : null;
        $cfg = is_array($letto) ? $letto : null;
    }
    return $cfg;
}

/** Singolo valore di configurazione, con default. */
function af_cfg(string $chiave, $default = null)
{
    $cfg = af_config();
    return ($cfg !== null && array_key_exists($chiave, $cfg) && $cfg[$chiave] !== null) ? $cfg[$chiave] : $default;
}

function af_chiave_configurata(): bool
{
    $k = (string) af_cfg("api_key", "");
    return $k !== "" && $k !== "INSERISCI_QUI_LA_CHIAVE";
}

/** True se $token coincide col token del cron (e il token è stato davvero impostato). */
function af_cron_token_valido(string $token): bool
{
    $t = (string) af_cfg("cron_token", "");
    if ($t === "" || $t === "INSERISCI_UN_TOKEN_LUNGO_E_CASUALE" || strlen($t) < 16) return false;
    return $token !== "" && hash_equals($t, $token);
}

/** Anno di stagione da usare verso API-Football per la stagione $stagione del progetto. */
function af_season(int $stagione): int
{
    $s = af_cfg("season_api", null);
    return $s !== null ? (int) $s : $stagione;
}

function af_ambiente(): array
{
    return [
        "php_version"         => PHP_VERSION,
        "curl_disponibile"    => function_exists("curl_init"),
        "allow_url_fopen"     => (bool) ini_get("allow_url_fopen"),
        "openssl_disponibile" => extension_loaded("openssl"),
        "max_execution_time"  => (int) ini_get("max_execution_time"),
        "memory_limit"        => ini_get("memory_limit"),
        "fuso_orario_php"     => date_default_timezone_get(),
    ];
}

/** Applica a un handle cURL l'impostazione "proxy" di config_api_football.php. */
function af_applica_proxy($ch): void
{
    $mode = (string) af_cfg("proxy", "auto");
    if ($mode === "" || $mode === "auto") return;
    if ($mode === "nessuno") {
        curl_setopt($ch, CURLOPT_PROXY, "");
        curl_setopt($ch, CURLOPT_NOPROXY, "*");
        return;
    }
    curl_setopt($ch, CURLOPT_PROXY, $mode);
}

/** Una prova HEAD verso $url, con proxy predefinito o connessione diretta. */
function af_sonda_url(string $url, bool $diretto): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_NOBODY         => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 8,
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
 * Raggiungibilità di v3.football.api-sports.io e di host di controllo.
 * GRATUITA: nessuna chiave, nessuna richiesta conteggiata nella quota.
 *
 * "esito":
 *   raggiungibile             percorso predefinito OK
 *   solo_diretto              funziona solo bypassando il proxy → proxy = "nessuno"
 *   bloccato_solo_api_football  altri host raggiungibili, API-Football no
 *   bloccato_tutto            nessun host esterno raggiungibile
 *   curl_assente              cURL non disponibile
 */
function af_sonda_rete(): array
{
    if (!function_exists("curl_init")) {
        return ["esito" => "curl_assente", "prove" => []];
    }
    $base = rtrim((string) af_cfg("base_url", "https://v3.football.api-sports.io"), "/");
    $bersagli = [
        "api-football" => $base . "/",
        "example.com"  => "https://example.com/",
        "api.github.com" => "https://api.github.com/",
    ];
    $prove = [];
    foreach ($bersagli as $nome => $url) {
        $pred = af_sonda_url($url, false);
        $dir  = $pred["ok"] ? null : af_sonda_url($url, true);
        $prove[] = ["host" => $nome, "predefinito" => $pred, "diretto" => $dir];
    }
    $af = $prove[0];
    $altriOk = false;
    foreach (array_slice($prove, 1) as $p) {
        if ($p["predefinito"]["ok"] || ($p["diretto"]["ok"] ?? false)) $altriOk = true;
    }
    if ($af["predefinito"]["ok"])           $esito = "raggiungibile";
    elseif ($af["diretto"] && $af["diretto"]["ok"]) $esito = "solo_diretto";
    elseif ($altriOk)                       $esito = "bloccato_solo_api_football";
    else                                    $esito = "bloccato_tutto";
    return ["esito" => $esito, "prove" => $prove];
}

// ------------------------------------------------------------
// Registro delle chiamate (NEW_API_LOG) e quota
// ------------------------------------------------------------

function af_log_disponibile(): bool
{
    static $ok = null;
    if ($ok === null) {
        global $conn;
        $r = @mysqli_query($conn, "SHOW TABLES LIKE 'NEW_API_LOG'");
        $ok = $r && mysqli_num_rows($r) > 0;
    }
    return $ok;
}

/** Richieste registrate nella giornata UTC corrente (la quota di API-Football riparte a 00:00 UTC). */
function af_richieste_oggi(): int
{
    if (!af_log_disponibile()) return 0;
    $r = query_one("SELECT COUNT(*) AS n FROM NEW_API_LOG WHERE data_ora >= '" . gmdate("Y-m-d") . " 00:00:00'");
    return (int) ($r["n"] ?? 0);
}

/** Ultime $n chiamate registrate (più recenti per prime). */
function af_ultime_chiamate(int $n = 15): array
{
    if (!af_log_disponibile()) return [];
    $n = max(1, min(100, $n));
    return query_all("SELECT data_ora, endpoint, parametri, http, ms, ok, richieste_rimaste, errore
                      FROM NEW_API_LOG ORDER BY id DESC LIMIT $n");
}

function af_log_scrivi(int $stagione, string $endpoint, array $params, int $http, int $ms, bool $ok, ?int $rimaste, ?string $errore): void
{
    if (!af_log_disponibile()) return;
    global $conn;
    $par = mysqli_real_escape_string($conn, substr(http_build_query($params), 0, 250));
    $ep  = mysqli_real_escape_string($conn, substr($endpoint, 0, 80));
    $err = $errore === null ? "NULL" : "'" . mysqli_real_escape_string($conn, substr($errore, 0, 250)) . "'";
    $rim = $rimaste === null ? "NULL" : (int) $rimaste;
    @mysqli_query($conn, "INSERT INTO NEW_API_LOG
        (data_ora, stagione, endpoint, parametri, http, ms, ok, richieste_rimaste, errore)
        VALUES ('" . gmdate("Y-m-d H:i:s") . "', $stagione, '$ep', '$par', $http, $ms, '" . ($ok ? "Y" : "N") . "', $rim, $err)");
}

/** Rispetta la pausa minima tra due chiamate consecutive (limite al minuto del piano). */
function af_attendi_pausa(): void
{
    $pausa = (int) af_cfg("pausa_tra_chiamate_ms", 0);
    if ($pausa <= 0 || !af_log_disponibile()) return;
    $r = query_one("SELECT TIMESTAMPDIFF(SECOND, MAX(data_ora), '" . gmdate("Y-m-d H:i:s") . "') AS s FROM NEW_API_LOG");
    if (!$r || $r["s"] === null) return;
    $trascorsi = (int) $r["s"] * 1000;
    if ($trascorsi >= 0 && $trascorsi < $pausa) {
        usleep(($pausa - $trascorsi) * 1000);
    }
}

/**
 * GET verso API-Football.
 *
 * Risultato:
 *   ok         true se HTTP 200 e nessun errore nel corpo
 *   http       codice HTTP (0 se la connessione è fallita)
 *   ms         durata
 *   risposta   contenuto del campo "response" (array)
 *   n_risultati  campo "results"
 *   errore     messaggio (nostro o dell'API) se ok = false
 *   rimaste    richieste giornaliere residue secondo l'header (null se assente)
 *   locale     true se la chiamata è stata rifiutata SENZA contattare l'API
 *
 * Gli errori di API-Football (chiave errata, stagione non inclusa nel piano,
 * quota esaurita...) arrivano spesso con HTTP 200 e un campo "errors" non
 * vuoto: vengono riportati in "errore".
 */
function af_chiamata(string $endpoint, array $params = [], int $stagione = 0): array
{
    $vuoto = ["ok" => false, "http" => 0, "ms" => 0, "risposta" => [], "n_risultati" => 0,
              "errore" => null, "rimaste" => null, "locale" => true];

    if (af_config() === null || !af_chiave_configurata()) {
        return ["errore" => "Chiave API non configurata: creare backend/config_api_football.php da config_api_football.example.php e caricarlo via FTP"] + $vuoto;
    }
    if (!function_exists("curl_init")) {
        return ["errore" => "cURL non disponibile sull'hosting"] + $vuoto;
    }
    $tetto = (int) af_cfg("quota_giornaliera_max", 90);
    if ($tetto > 0 && af_richieste_oggi() >= $tetto) {
        return ["errore" => "Tetto giornaliero locale raggiunto ($tetto richieste oggi, UTC): chiamata non effettuata"] + $vuoto;
    }

    af_attendi_pausa();

    $url = rtrim((string) af_cfg("base_url", "https://v3.football.api-sports.io"), "/")
         . "/" . ltrim($endpoint, "/")
         . ($params ? "?" . http_build_query($params) : "");

    $rimaste = null;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT        => (int) af_cfg("timeout", 20),
        CURLOPT_HTTPHEADER     => ["x-apisports-key: " . af_cfg("api_key"), "Accept: application/json"],
        CURLOPT_HEADERFUNCTION => function ($curl, $riga) use (&$rimaste) {
            if (stripos($riga, "x-ratelimit-requests-remaining:") === 0) {
                $rimaste = (int) trim(substr($riga, strlen("x-ratelimit-requests-remaining:")));
            }
            return strlen($riga);
        },
    ]);
    af_applica_proxy($ch);

    $t0   = microtime(true);
    $corpo = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errCurl = $corpo === false ? curl_error($ch) : null;
    curl_close($ch);
    $ms = (int) round((microtime(true) - $t0) * 1000);

    $out = ["http" => $http, "ms" => $ms, "rimaste" => $rimaste, "locale" => false,
            "risposta" => [], "n_risultati" => 0, "ok" => false, "errore" => null];

    if ($corpo === false) {
        $out["errore"] = "Connessione fallita: " . $errCurl;
    } else {
        $json = json_decode($corpo, true);
        if (!is_array($json)) {
            $out["errore"] = "Risposta non valida (HTTP $http)";
        } else {
            $errori = $json["errors"] ?? [];
            if (is_array($errori) && count($errori) > 0) {
                $parti = [];
                foreach ($errori as $k => $v) $parti[] = (is_string($k) ? "$k: " : "") . (is_scalar($v) ? $v : json_encode($v));
                $out["errore"] = implode(" | ", $parti);
            } elseif ($http !== 200) {
                $out["errore"] = "HTTP $http";
            } else {
                $out["ok"] = true;
            }
            $out["risposta"]    = is_array($json["response"] ?? null) ? $json["response"] : [];
            $out["n_risultati"] = (int) ($json["results"] ?? count($out["risposta"]));
        }
    }

    af_log_scrivi($stagione, $endpoint, $params, $http, $ms, $out["ok"], $rimaste, $out["errore"]);
    return $out;
}
