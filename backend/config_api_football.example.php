<?php
// ============================================================
// backend/config_api_football.example.php
//
// MODELLO di configurazione per l'integrazione con API-Football
// (api-sports.io), usata per recuperare le statistiche dei giocatori
// al termine delle partite di Serie A.
//
// ISTRUZIONI:
//   1. copiare questo file in "config_api_football.php" (stessa cartella);
//   2. inserire la chiave API (dashboard.api-football.com → My Access) e un
//      token lungo e casuale per il cron;
//   3. caricare "config_api_football.php" A MANO via FTP nella cartella del
//      backend su Altervista (accanto a connect.php).
//
// "config_api_football.php" NON è versionato (vedi .gitignore) e il suo
// accesso diretto da browser è bloccato da .htaccess.
// ============================================================

return [
    // Chiave API (header x-apisports-key)
    "api_key" => "INSERISCI_QUI_LA_CHIAVE",

    // Token che il cron deve passare a cron_api_football.php (?token=...).
    // Generare una stringa casuale di almeno 24 caratteri.
    "cron_token" => "INSERISCI_UN_TOKEN_LUNGO_E_CASUALE",

    // Endpoint di API-Football (accesso diretto, non RapidAPI)
    "base_url" => "https://v3.football.api-sports.io",

    // Timeout (secondi) di ogni chiamata
    "timeout" => 20,

    // Proxy per le chiamate in uscita (cURL), come in config_ai.php:
    //   "auto"              comportamento predefinito dell'hosting (default)
    //   "nessuno"           connessione diretta, ignora il proxy dell'hosting
    //   "http://host:porta" proxy esplicito
    "proxy" => "auto",

    // Serie A in API-Football (id lega 135)
    "league_id" => 135,

    // Anno della stagione in API-Football (per la 2026/27 vale 2026).
    // null = si usa la stagione del progetto.
    "season_api" => null,

    // Prefisso del nome del turno: la giornata N è "Regular Season - N"
    "round_prefix" => "Regular Season - ",

    // ── Regole del cron (una chiamata all'ora) ───────────────
    // Fine stimata della partita = calcio d'inizio + questa durata (minuti).
    // Dopo la fine stimata, se lo stato API è "partita conclusa" e le
    // statistiche sono state salvate, la partita non viene più interrogata.
    "durata_partita_min" => 120,

    // Oltre questo tempo dal calcio d'inizio (minuti) una partita non
    // conclusa viene abbandonata (es. sospesa): evita chiamate all'infinito.
    "limite_attesa_min" => 360,

    // Tetto di sicurezza delle richieste giornaliere (il piano gratuito ne
    // concede 100): superato, le chiamate vengono rifiutate in locale.
    "quota_giornaliera_max" => 90,

    // Pausa minima tra due chiamate consecutive (ms). Il piano gratuito
    // consente 10 richieste al minuto: 6500 ms resta sotto il limite.
    "pausa_tra_chiamate_ms" => 6500,
];
