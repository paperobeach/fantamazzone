<?php
// ============================================================
// backend/config_ai.example.php
//
// MODELLO di configurazione per l'integrazione con le API Anthropic.
//
// ISTRUZIONI:
//   1. copiare questo file in "config_ai.php" (stessa cartella);
//   2. inserire la chiave API al posto di "INSERISCI_QUI_LA_CHIAVE";
//   3. caricare "config_ai.php" A MANO via FTP nella cartella del
//      backend su Altervista (accanto a connect.php).
//
// "config_ai.php" NON è versionato (vedi .gitignore) e il suo accesso
// diretto da browser è bloccato da .htaccess: così la chiave non finisce
// mai su GitHub né nel deploy automatico, che non lo sovrascrive.
// ============================================================

return [
    // Chiave API creata su console.anthropic.com (inizia con "sk-ant-")
    "api_key" => "INSERISCI_QUI_LA_CHIAVE",

    // Versione del protocollo API (valore fisso richiesto da Anthropic)
    "anthropic_version" => "2023-06-01",

    // Timeout in secondi per le chiamate verso Anthropic
    "timeout" => 25,

    // Proxy per le chiamate in uscita (cURL):
    //   "auto"              comportamento predefinito dell'hosting (default)
    //   "nessuno"           connessione diretta, ignora il proxy dell'hosting
    //   "http://host:porta" proxy esplicito
    // Usare "nessuno" solo se "Verifica rete" indica che funziona solo in diretta.
    "proxy" => "auto",

    // Modello usato dal test di connessione (il più economico)
    "modello_test" => "claude-haiku-5-5",

    // Listino in USD per milione di token. Verificare periodicamente su
    // https://platform.claude.com/docs/en/about-claude/pricing
    "modelli" => [
        "claude-sonnet-5-5" => ["etichetta" => "Sonnet 5.5", "input" => 2.00, "output" => 10.00],
        "claude-haiku-5-5"  => ["etichetta" => "Haiku 5.5",  "input" => 0.10, "output" => 0.50],
    ],

    // Articoli AI di commento alla giornata (tutti i valori sono opzionali:
    // se mancano valgono i default indicati).
    "articolo" => [
        // Lunghezza indicativa dell'articolo, in parole (default 500)
        "parole_target" => 500,
        // Tetto di token di output: è il limite che rende garantito il
        // "costo massimo" mostrato nell'anteprima (default 1800). Se gli
        // articoli risultano troncati, alzalo (es. 3000) e porta di
        // conseguenza anche "token_output_tipico".
        "max_token" => 1800,
        // Token di output ipotizzati per il "costo tipico" (default 1100):
        // tienilo vicino alla lunghezza reale degli articoli generati
        "token_output_tipico" => 1100,
        // Quanti episodi storici della lega richiamare al massimo (default 3)
        "max_episodi" => 3,
        // Tetto di spesa mensile in USD sugli articoli generati; 0 = nessun tetto
        "tetto_mensile_usd" => 0,
        // Euro per 1 USD, solo per mostrare anche l'importo in euro (null = non mostrare)
        "cambio_eur_per_usd" => null,
        // Timeout in secondi della chiamata di generazione (default 120)
        "timeout_generazione" => 120,
        // Generazione in streaming (default true): i dati arrivano di continuo,
        // così proxy/firewall dell'hosting non chiudono una connessione rimasta
        // muta per decine di secondi. Mettere false solo per diagnosi.
        "streaming" => true,

        // ── Contesto fisso e note di giornata ─────────────────────
        // Tetto di caratteri dei blocchi di "contesto fisso" attivi (gestiti da
        // Profili ed episodi → Contesto fisso). Ogni carattere finisce nel prompt
        // di ogni articolo: più è lungo, più costa.
        "max_caratteri_contesto_fisso" => 12000,

        // ── Immagini negli articoli ───────────────────────────────
        // Quante immagini della libreria proporre all'AI (le più pertinenti e le
        // meno usate di recente) e quante ne può inserire nel testo di un articolo.
        "max_immagini_catalogo" => 60,
        "max_immagini_inline"   => 2,

        // ── Edizione sfogliabile ──────────────────────────────────
        // Lunghezza target (parole) di ogni pagina partita e del commento finale.
        "parole_per_pagina" => 100,
        "parole_commento"   => 200,
        // Limite di token di output = base + pagine × token_per_pagina + commento
        // + fantallenatori × token_per_pagella, con tetto max_token_edizione.
        "token_base_edizione" => 450,
        "token_per_pagina"    => 300,
        "token_commento"      => 450,
        "token_per_pagella"   => 45,
        "max_token_edizione"  => 8000,
        // Costo "tipico" mostrato in anteprima (0 = 75% del limite)
        "token_output_tipico_edizione" => 0,
        // Timeout (secondi) della generazione di un'edizione (default 200)
        "timeout_edizione" => 200,
        // Includere nell'edizione anche le partite di Champions (una pagina ciascuna)
        "champions_in_edizione" => true,
    ],

    // Libreria immagini della Gazzetta (tutti i parametri sono opzionali)
    "immagini" => [
        // Lato lungo massimo (px) e peso massimo (byte) dell'immagine salvata
        "max_lato" => 1400,
        "max_byte" => 650000,
        // Lato lungo (px) dell'immagine inviata all'AI per la descrizione
        // automatica: più è piccola, meno token (e quindi meno costo)
        "max_lato_ai" => 1000,
        // Numero massimo di immagini in libreria
        "max_totale" => 600,
        // Stima del costo di una descrizione automatica (token di input e output)
        "token_input_stimati"   => 1500,
        "token_output_stimati"  => 220,
        // Tetto di output (massimo garantito del costo) e timeout della chiamata
        "max_token_descrizione" => 500,
        "timeout"               => 60,
    ],
];
