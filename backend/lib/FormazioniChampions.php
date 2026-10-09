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

require_once __DIR__ . "/ChampionsCalendario.php";

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
 * Partite di Champions della giornata di campionato (giornata_camp).
 * Come in champions.php, in ogni gruppo (vedi champions_gruppo_girone:
 * A, B, C, D, S1, S2, FI, FR) le righe di NEW_CALENDARIO_CHAMP ordinate per
 * posizione formano le partite a coppie (dispari = casa, pari = ospite);
 * l'eventuale ultima riga spaiata è la squadra che riposa.
 *
 * @return array ['partite' => [['girone', 'casa' => id, 'ospite' => id], ...],
 *                'riposa'  => [['girone', 'id'], ...]]
 */
function champions_partite_giornata(int $stagione, int $giornata): array
{
    // $giornata = giornata di FANTACAMPIONATO: si usa giornata_camp
    // (la colonna "giornata" è il progressivo Champions).
    $righe = query_all("SELECT squadra, TRIM(girone) AS girone, posizione
                        FROM NEW_CALENDARIO_CHAMP
                        WHERE stagione = $stagione AND giornata_camp = $giornata");

    $partite = [];
    $riposa  = [];
    foreach (champions_raggruppa_righe($righe) as $gruppo => $rg) {
        $squadre = array_map(fn($r) => (int) $r["squadra"], $rg);
        foreach (array_chunk($squadre, 2) as $coppia) {
            if (count($coppia) < 2) {
                $riposa[] = ["girone" => (string) $gruppo, "id" => $coppia[0]];
            } else {
                $partite[] = ["girone" => (string) $gruppo, "casa" => $coppia[0], "ospite" => $coppia[1]];
            }
        }
    }
    return ["partite" => $partite, "riposa" => $riposa];
}

/**
 * La squadra gioca un turno di Champions in questa giornata?
 * La squadra che riposa non è "coinvolta".
 */
function champions_squadra_gioca_in_giornata(int $stagione, int $giornata, int $idSquadra): bool
{
    foreach (champions_partite_giornata($stagione, $giornata)["partite"] as $p) {
        if ($p["casa"] === $idSquadra || $p["ospite"] === $idSquadra) return true;
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

/**
 * Sotto-query SQL (da usare come tabella derivata, con alias a scelta)
 * con le formazioni EFFETTIVE di Champions delle squadre indicate:
 * per ciascuna, la formazione distinta se ammessa e salvata, altrimenti
 * quella di campionato. Colonne: STAGIONE, ID_SQUADRA, ID_GIOCATORE,
 * GIORNATA, MAGLIA.
 */
function formazione_champions_sql_effettiva(int $stagione, int $giornata, array $idSquadre): string
{
    $parti = [];
    foreach (array_unique(array_map('intval', $idSquadre)) as $id) {
        $tab = formazione_tabella_champions($stagione, $giornata, $id);
        $parti[] = "SELECT STAGIONE, ID_SQUADRA, ID_GIOCATORE, GIORNATA, MAGLIA FROM $tab
                    WHERE STAGIONE = $stagione AND GIORNATA = $giornata AND ID_SQUADRA = $id";
    }
    if (!$parti) {
        return "(SELECT STAGIONE, ID_SQUADRA, ID_GIOCATORE, GIORNATA, MAGLIA FROM " . FORMAZIONE_TAB_CAMPIONATO . " WHERE 1 = 0)";
    }
    return "(" . implode(" UNION ALL ", $parti) . ")";
}
