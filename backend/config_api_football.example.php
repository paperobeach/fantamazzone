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
    // Fornitore dei dati:
    //   "api-football"  API-Football (api-sports.io): statistiche per giocatore con minuti,
    //                   eventi e rating; il piano gratuito NON include la stagione corrente
    //   "thesportsdb"   TheSportsDB: dati inseriti dalla community. Con la chiave gratuita
    //                   formazioni e cronologia delle partite sono TROPPO limitate (5 elementi):
    //                   per le statistiche dei giocatori serve una chiave personale a pagamento
    "provider" => "api-football",

    // ── API-Football ─────────────────────────────────────────
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

    // ── TheSportsDB (solo se provider = "thesportsdb") ───────
    // Chiave: "123" = chiave gratuita di prova (risposte troncate); per i dati completi
    // inserire la chiave personale del piano a pagamento (profilo utente di TheSportsDB)
    "tsdb_key" => "123",
    "tsdb_base_url" => "https://www.thesportsdb.com/api/v1/json",
    // Serie A: id lega 4332, nome "Italian Serie A"
    "tsdb_league_id" => 4332,
    "tsdb_league_name" => "Italian Serie A",
    // Anno di inizio stagione (2026 = 2026-2027). null = stagione del progetto.
    "tsdb_season" => null,

    // ── Regole del cron (chiamate periodiche, es. ogni 5 minuti) ─────
    // Il cron esterno (es. cron-job.org) chiama cron_api_football.php ogni pochi minuti.
    // Ad ogni chiamata si interroga il fornitore SOLO se c'è una partita in corso o appena
    // conclusa e non ancora sincronizzata; altrimenti la risposta è immediata.

    // Pausa minima (minuti) tra due controlli della stessa partita in corso. Tenerla
    // un po' sotto la cadenza del cron: cron ogni 5 minuti → 4; ogni 10 minuti → 9.
    "intervallo_controllo_min" => 4,

    // Durante la partita le statistiche dei giocatori si salvano come dati PARZIALI a ogni
    // controllo (API-Football: stessa chiamata, nessun costo aggiuntivo) e alimentano la
    // simulazione; a partita conclusa diventano definitive.

    // Dopo ogni sincronizzazione che salva dati, la SIMULAZIONE della giornata in corso viene
    // ricalcolata (come il pulsante "Simula" della pagina Live):
    //   "sempre"    ricalcola la giornata in corso (default)
    //   "esistente" solo se per la giornata esiste già una simulazione
    //   "no"        non ricalcola: la simulazione si aggiorna solo col pulsante "Simula"
    "simulazione_automatica" => "sempre",

    // ── Conversione dei dati API in voto (simulazione) ─────────────
    // Il fantavoto vero non è disponibile via API: il voto si STIMA dal rating del fornitore.
    //   "formula"  voto = arrotonda_a_0,5( intercetta + rating·rating + gol·(gol + rigori segnati)
    //                      + assist·assist + rigore_sbagliato·rigori sbagliati
    //                      + rigore_parato·rigori parati + espulsione·espulsione ),
    //              limitato tra min e max (default). Calibrata su 1.416 giocatori-partita:
    //              errore medio 0,33, 90% dei voti entro ±0,5 (con "sempre 6": 0,47 e 77%).
    //              I rigori arrivano da API-Football (penalty.scored / missed / saved): i gol su rigore
    //              contano come gol (goals.total li comprende) e non si sommano due volte.
    //   "rating"   rating del fornitore arrotondato a 0,5
    //   "sei"      sempre 6
    // Al voto si somma poi il bonus/malus delle statistiche; il voto resta modificabile a mano.
    "voto_conversione" => "formula",
    // Coefficienti facoltativi (valori predefiniti sotto). I termini dei rigori sbagliati/parati e
    // dell'espulsione poggiano su pochi casi (4, 3 e 5): per cautela si possono ridurre
    // (es. rigore_sbagliato -0.5, rigore_parato 0.5, espulsione -0.5).
    "voto_formula" => [
        "intercetta"       => 2.97,
        "rating"           => 0.43,
        "gol"              => 0.61,
        "assist"           => 0.38,
        "rigore_sbagliato" => -0.84,
        "rigore_parato"    => 0.83,
        "espulsione"       => -0.87,
        "min"              => 4.0,
        "max"              => 9.0,
        "passo"            => 0.5,
    ],

    // Senza voto: a partita conclusa chi ha giocato al massimo questi minuti, senza aver
    // segnato (gol o rigore), non ha il voto e va sostituito. Sui dati reali la regola "≤ 11" è
    // corretta nel 97,9% dei casi. 0 = regola disattivata (ogni minuto giocato dà il voto).
    // Durante la partita un giocatore appena entrato resta a 6 provvisorio.
    "sv_minuti_max" => 11,

    // Fine stimata della partita = calcio d'inizio + questa durata (minuti).
    // Dopo la fine stimata, se lo stato API è "partita conclusa" e le
    // statistiche sono state salvate, la partita non viene più interrogata.
    "durata_partita_min" => 120,

    // Oltre questo tempo dal calcio d'inizio (minuti) una partita non
    // conclusa viene abbandonata (es. sospesa): evita chiamate all'infinito.
    "limite_attesa_min" => 360,

    // Tetto di sicurezza delle richieste giornaliere. API-Football gratuito: 100; piano a
    // pagamento (7.500 al giorno): con un cron ogni 5 minuti bastano poche decine di richieste
    // al giorno, il tetto può restare a 500.
    "quota_giornaliera_max" => 500,

    // Pausa minima tra due chiamate consecutive (ms). API-Football gratuito: 10 richieste al
    // minuto → 6500; API-Football a pagamento → 300 (ogni esecuzione del cron deve finire in
    // pochi secondi); TheSportsDB gratuito: 30 al minuto → 2200 (a pagamento → 700).
    "pausa_tra_chiamate_ms" => 300,
];
