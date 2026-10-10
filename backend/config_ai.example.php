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

    // Modello usato dal test di connessione (il più economico)
    "modello_test" => "claude-haiku-5-5",

    // Listino in USD per milione di token. Verificare periodicamente su
    // https://platform.claude.com/docs/en/about-claude/pricing
    "modelli" => [
        "claude-sonnet-5-5" => ["etichetta" => "Sonnet 5.5", "input" => 2.00, "output" => 10.00],
        "claude-haiku-5-5"  => ["etichetta" => "Haiku 5.5",  "input" => 0.10, "output" => 0.50],
    ],
];
