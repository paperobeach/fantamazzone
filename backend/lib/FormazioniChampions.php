<?php
// ============================================================
// lib/FormazioniChampions.php
//
// Regole per la formazione distinta di Champions.
//
// Una squadra può schierare in Champions una formazione diversa da
// quella di campionato SOLO se, contemporaneamente:
//   1. il parametro di stagione FORMAZIONE_CHAMPIONS_DIVERSA
//      (NEW_PARAMETRI_STAGIONE, '1' = ammessa) è attivo;
//   2. nella giornata di campionato la squadra gioca un turno di
//      Champions (NEW_CALENDARIO_CHAMP), cioè compare in una partita
//      e non è la squadra che riposa.
//
// La formazione di campionato sta in NEW_FORMAZIONI, quella di
// Champions in NEW_FORMAZIONI_CHAMP (stessa struttura). Chi calcola
// la Champions deve usare formazione_tabella_champions(): restituisce
// NEW_FORMAZIONI_CHAMP solo se la formazione distinta è ammessa e
// salvata, altrimenti NEW_FORMAZIONI (stessa formazione del campionato).
//
// Va incluso DOPO connect.php (usa query_one/query_all).
// ============================================================

const FORMAZIONE_PARAM_CHAMP_DIVERSA = "FORMAZIONE_CHAMPIONS_DIVERSA";
const FORMAZIONE_TAB_CAMPIONATO      = "NEW_FORMAZIONI";
const FORMAZIONE_TAB_CHAMPIONS       = "NEW_FORMAZIONI_CHAMP";

/** Il parametro di stagione consente una formazione Champions diversa? */
function formazione_champions_separata_attiva(int $stagione): bool
{
    $r = query_one("SELECT valore FROM NEW_PARAMETRI_STAGIONE
                    WHERE stagione = $stagione
                      AND codice = '" . FORMAZIONE_PARAM_CHAMP_DIVERSA . "'");
    return $r && trim((string) $r["valore"]) === "1";
}

/**
 * La squadra gioca un turno di Champions in questa giornata?
 * Come in champions.php, in ogni girone le righe ordinate per posizione
 * formano le partite a coppie; l'eventuale ultima riga spaiata è la
 * squadra che riposa (non gioca, quindi non è "coinvolta").
 */
function champions_squadra_gioca_in_giornata(int $stagione, int $giornata, int $idSquadra): bool
{
    $mia = query_one("SELECT girone FROM NEW_CALENDARIO_CHAMP
                      WHERE stagione = $stagione AND giornata = $giornata AND squadra = $idSquadra");
    if (!$mia) return false;

    $girone = mysqli_real_escape_string($GLOBALS["conn"], trim((string) $mia["girone"]));
    $righe  = query_all("SELECT squadra FROM NEW_CALENDARIO_CHAMP
                         WHERE stagione = $stagione AND giornata = $giornata
                           AND TRIM(girone) = '$girone'
                         ORDER BY posizione");
    $n = count($righe);
    foreach ($righe as $i => $r) {
        if ((int) $r["squadra"] === $idSquadra) {
            $riposa = ($n % 2 === 1) && ($i === $n - 1);
            return !$riposa;
        }
    }
    return false;
}

/** Formazione Champions distinta ammessa per stagione + giornata + squadra? */
function formazione_champions_separata_ammessa(int $stagione, int $giornata, int $idSquadra): bool
{
    return formazione_champions_separata_attiva($stagione)
        && champions_squadra_gioca_in_giornata($stagione, $giornata, $idSquadra);
}

/** Tabella da cui leggere la formazione di una competizione ("CAMP" | "CHAMP"). */
function formazione_tabella(string $competizione): string
{
    return $competizione === "CHAMP" ? FORMAZIONE_TAB_CHAMPIONS : FORMAZIONE_TAB_CAMPIONATO;
}

/**
 * Tabella da usare per CALCOLARE la Champions di squadra+giornata:
 * la formazione distinta se ammessa e salvata, altrimenti quella di campionato.
 */
function formazione_tabella_champions(int $stagione, int $giornata, int $idSquadra): string
{
    if (!formazione_champions_separata_ammessa($stagione, $giornata, $idSquadra)) {
        return FORMAZIONE_TAB_CAMPIONATO;
    }
    $r = query_one("SELECT COUNT(*) AS n FROM " . FORMAZIONE_TAB_CHAMPIONS . "
                    WHERE STAGIONE = $stagione AND GIORNATA = $giornata AND ID_SQUADRA = $idSquadra");
    return ($r && (int) $r["n"] > 0) ? FORMAZIONE_TAB_CHAMPIONS : FORMAZIONE_TAB_CAMPIONATO;
}
