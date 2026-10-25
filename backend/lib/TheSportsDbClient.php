<?php
// ============================================================
// backend/lib/TheSportsDbClient.php
//
// Client minimale per TheSportsDB (API v1), usato quando in
// config_api_football.php è impostato  "provider" => "thesportsdb".
// Condivide configurazione, registro (NEW_API_LOG) e pausa tra le chiamate
// con ApiFootballClient.php (da includere prima).
//
// ATTENZIONE AI PIANI: con la chiave gratuita (123) formazioni, cronologia
// (gol, cartellini, sostituzioni) e statistiche di una partita sono TRONCATE
// a 5 elementi, l'elenco squadre a 10, le rose a 10 giocatori e il calendario
// di stagione a 15 partite: con questi limiti NON è possibile ricavare le
// statistiche dei giocatori. Servono una chiave personale (piano a pagamento)
// e il limite sale a 100 elementi per partita. Limite di richieste: 30 al
// minuto gratuito, 100 a pagamento.
// ============================================================

/** La chiave configurata è una chiave di prova pubblica (risposte troncate)? */
function tsdb_chiave_gratuita(): bool
{
    return in_array(trim((string) af_cfg("tsdb_key", "123")), ["1", "2", "3", "123"], true);
}

/** Stagione nel formato di TheSportsDB: 2026 → "2026-2027". */
function tsdb_season_str(int $stagione): string
{
    $y = af_season($stagione);
    return $y . "-" . ($y + 1);
}

/**
 * GET verso TheSportsDB v1.
 *
 * @param string $endpoint es. "lookupevent.php"
 * @return array {ok, http, ms, dati (corpo decodificato), errore, rimaste (null), locale}
 */
function tsdb_chiamata(string $endpoint, array $params = [], int $stagione = 0): array
{
    $vuoto = ["ok" => false, "http" => 0, "ms" => 0, "dati" => [], "errore" => null, "rimaste" => null, "locale" => true];

    if (af_config() === null || !af_chiave_configurata()) {
        return ["errore" => "Chiave TheSportsDB non configurata: impostare tsdb_key in backend/config_api_football.php"] + $vuoto;
    }
    if (!function_exists("curl_init")) {
        return ["errore" => "cURL non disponibile sull'hosting"] + $vuoto;
    }

    af_attendi_pausa();

    // La chiave fa parte dell'indirizzo: non viene mai scritta nel registro delle chiamate
    $url = af_base_url() . "/" . rawurlencode(trim((string) af_cfg("tsdb_key", "123"))) . "/" . ltrim($endpoint, "/")
         . ($params ? "?" . http_build_query($params, "", "&", PHP_QUERY_RFC3986) : "");

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT        => (int) af_cfg("timeout", 20),
        CURLOPT_HTTPHEADER     => ["Accept: application/json"],
    ]);
    af_applica_proxy($ch);

    $t0    = microtime(true);
    $corpo = curl_exec($ch);
    $http  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errCurl = $corpo === false ? curl_error($ch) : null;
    curl_close($ch);
    $ms = (int) round((microtime(true) - $t0) * 1000);

    $out = ["http" => $http, "ms" => $ms, "rimaste" => null, "locale" => false,
            "dati" => [], "ok" => false, "errore" => null];

    if ($corpo === false) {
        $out["errore"] = "Connessione fallita: " . $errCurl;
    } elseif ($http === 429) {
        $out["errore"] = "Limite di richieste al minuto superato (HTTP 429): attendere un minuto";
    } elseif ($http !== 200) {
        $out["errore"] = "HTTP $http";
    } else {
        $json = json_decode($corpo, true);
        if (!is_array($json)) {
            $out["errore"] = "Risposta non valida (HTTP $http)";
        } else {
            $out["ok"] = true;
            $out["dati"] = $json;   // gli elenchi vuoti arrivano come {"events":null}
        }
    }

    af_log_scrivi($stagione, "tsdb:" . $endpoint, $params, $http, $ms, $out["ok"], null, $out["errore"]);
    return $out;
}
