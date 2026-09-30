<?php
// ============================================================
// backend/lib/RegoleCalcolo.php
//
// Lettura, in sola lettura, della configurazione di NEW_REGOLE_BONUS /
// NEW_REGOLE_ALGORITMI già pronta nel formato richiesto da
// CalcolatoreVoti::calcolaPartita() ($config, vedi CalcolatoreVoti.php
// §2.5). È un lettore separato da backend/admin/regole_calcolo.php
// (che invece serve la pagina admin "Gestisci regole di calcolo" con le
// righe complete per la UI, ed è responsabile anche di creare/ereditare
// la configurazione quando manca): qui, se la stagione non è
// configurata, si restituisce un errore esplicito che invita a
// visitare prima quella pagina, senza generare in automatico una
// configurazione "a sorpresa" per un calcolo reale.
// ============================================================

function caricaConfigurazioneCalcolo($conn, int $stagione): array
{
    $bonusRows = query_all("SELECT codice, valore FROM NEW_REGOLE_BONUS
                            WHERE stagione = $stagione AND attivo = 1");

    $algoritmiRows = query_all("SELECT tipo, parametri FROM NEW_REGOLE_ALGORITMI
                                WHERE stagione = $stagione");

    if (empty($bonusRows) || empty($algoritmiRows)) {
        throw new Exception(
            "Nessuna regola di calcolo configurata per la stagione $stagione. " .
            "Aprire prima \"Gestisci regole di calcolo\" (sezione Admin) per crearla."
        );
    }

    $bonus = [];
    foreach ($bonusRows as $r) $bonus[$r["codice"]] = (float) $r["valore"];

    $algoritmi = [];
    foreach ($algoritmiRows as $r) $algoritmi[$r["tipo"]] = json_decode($r["parametri"], true) ?? [];

    foreach (["DIFESA", "CENTROCAMPO", "ATTACCO"] as $tipo) {
        if (!isset($algoritmi[$tipo])) {
            throw new Exception(
                "Configurazione incompleta per la stagione $stagione: manca l'algoritmo $tipo. " .
                "Verificare \"Gestisci regole di calcolo\" (sezione Admin)."
            );
        }
    }

    return [
        "bonus"       => $bonus,
        "difesa"      => $algoritmi["DIFESA"],
        "centrocampo" => $algoritmi["CENTROCAMPO"],
        "attacco"     => $algoritmi["ATTACCO"],
    ];
}
