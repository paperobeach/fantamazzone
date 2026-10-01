<?php
// ============================================================
// backend/lib/RegoleCalcolo.php
//
// Lettura, in sola lettura, della configurazione di NEW_REGOLE_BONUS /
// NEW_REGOLE_ALGORITMI / NEW_PARAMETRI_STAGIONE già pronta nel formato
// richiesto da CalcolatoreVoti::calcolaPartita() ($config, vedi
// CalcolatoreVoti.php §2.5) e da backend/admin/calcolo_giornata.php. È
// un lettore separato da backend/admin/regole_calcolo.php (che invece
// serve la pagina admin "Gestisci regole di calcolo" con le righe
// complete per la UI, ed è responsabile anche di creare/ereditare la
// configurazione quando manca): qui, se la stagione non è configurata,
// si restituisce un errore esplicito che invita a visitare prima quella
// pagina, senza generare in automatico una configurazione "a sorpresa"
// per un calcolo reale.
//
// NEW_PARAMETRI_STAGIONE è generica (codice => valore testuale, che sia
// un numero o del JSON): viene restituita qui così com'è, come mappa
// codice => valore grezzo (stringa). Chi la usa (calcolo_giornata.php)
// converte/decodifica il singolo codice di cui ha bisogno, con un
// default di riserva se il parametro non è stato configurato.
// ============================================================

function caricaConfigurazioneCalcolo($conn, int $stagione): array
{
    $bonusRows = query_all("SELECT codice, valore FROM NEW_REGOLE_BONUS
                            WHERE stagione = $stagione AND attivo = 1");

    $algoritmiRows = query_all("SELECT tipo, parametri FROM NEW_REGOLE_ALGORITMI
                                WHERE stagione = $stagione");

    $parametriRows = query_all("SELECT codice, valore FROM NEW_PARAMETRI_STAGIONE
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

    $parametri = [];
    foreach ($parametriRows as $r) $parametri[$r["codice"]] = $r["valore"];

    return [
        "bonus"       => $bonus,
        "difesa"      => $algoritmi["DIFESA"],
        "centrocampo" => $algoritmi["CENTROCAMPO"],
        "attacco"     => $algoritmi["ATTACCO"],
        "parametri"   => $parametri, // codice => valore grezzo (stringa); vedi PARAMETRI_DEFAULT in regole_calcolo.php
    ];
}
