<?php
// ============================================================
// api/squadre.php
// GET ?stagione=2024
// GET ?stagione=2024&id=3   (singola squadra con rosa)
//
// Per ogni squadra restituisce anche:
//   - logo_allenatore  nome file della foto (NEW_ALLENATORI.logo)
//   - trofei           { campionati, secondi_posti, champions, maglie_nere }
//                      contati da NEW_ALBO_ORO (la squadra e' identificata
//                      da NEW_UTENZE.utenza, stabile tra le stagioni):
//                        CAMP  posizione 1  = campionati vinti
//                        CAMP  posizione 2  = secondi posti
//                        CHAMP posizione 1  = Champions vinte
//                        CAMP  posizione 99 = maglie nere (ultimo posto)
//                      "trofei.stagioni" riporta, per ogni tipo, le stagioni (anno di
//                      inizio, ordine crescente) in cui il trofeo e' stato conquistato:
//                        { campionati:[2019,..], secondi_posti:[..], champions:[..], maglie_nere:[..] }
// Nel dettaglio anche:
//   - crediti          { per_ruolo: {1,2,3,4}, totale, budget, residuo }
//                      budget = parametro stagione MERCATO_BUDGET_CREDITI
//                      (default 470, lo stesso usato dal mercato),
//                      residuo = budget - somma dei crediti della rosa.
//   - rosa[].ceduto    true se il giocatore non e' piu' nel listone della Serie A
//                      ("giocatore ceduto", vedi lib/ListaGiocatori.php): resta in
//                      rosa e nelle statistiche ma non e' utilizzabile in formazione.
// ============================================================
require_once __DIR__ . "/connect.php";
require_once __DIR__ . "/lib/ListaGiocatori.php";

$stagione = param_int("stagione");
$id       = param_int("id", false);

const SQ_BUDGET_PARAM   = "MERCATO_BUDGET_CREDITI";
const SQ_BUDGET_DEFAULT = 470;

/** Trofei vuoti (albo non disponibile o squadra senza piazzamenti). */
function sq_trofei_vuoti(): array
{
    return [
        "campionati" => 0, "secondi_posti" => 0, "champions" => 0, "maglie_nere" => 0,
        "stagioni" => sq_stagioni_vuote(),
    ];
}

/** Elenco stagioni vuoto per ogni tipo di trofeo. */
function sq_stagioni_vuote(): array
{
    return ["campionati" => [], "secondi_posti" => [], "champions" => [], "maglie_nere" => []];
}

/** "2019,2021" (GROUP_CONCAT) -> [2019, 2021]. */
function sq_lista_stagioni($csv): array
{
    if ($csv === null || $csv === "") return [];
    return array_map("intval", explode(",", (string) $csv));
}

/**
 * Trofei di tutte le squadre della stagione: id_squadra => trofei.
 * Se NEW_ALBO_ORO non esiste restituisce un array vuoto.
 */
function sq_trofei_stagione(int $stagione): array
{
    global $conn;
    $rT = mysqli_query($conn, "SHOW TABLES LIKE 'NEW_ALBO_ORO'");
    if (!$rT || mysqli_num_rows($rT) === 0) return [];

    $out = [];
    $righe = query_all("SELECT u.id AS id_squadra,
                               SUM(UPPER(a.competizione) = 'CAMP'  AND a.posizione = 1)  AS campionati,
                               SUM(UPPER(a.competizione) = 'CAMP'  AND a.posizione = 2)  AS secondi_posti,
                               SUM(UPPER(a.competizione) = 'CHAMP' AND a.posizione = 1)  AS champions,
                               SUM(UPPER(a.competizione) = 'CAMP'  AND a.posizione = 99) AS maglie_nere,
                               GROUP_CONCAT(CASE WHEN UPPER(a.competizione) = 'CAMP'  AND a.posizione = 1
                                                 THEN a.stagione END ORDER BY a.stagione SEPARATOR ',') AS st_campionati,
                               GROUP_CONCAT(CASE WHEN UPPER(a.competizione) = 'CAMP'  AND a.posizione = 2
                                                 THEN a.stagione END ORDER BY a.stagione SEPARATOR ',') AS st_secondi,
                               GROUP_CONCAT(CASE WHEN UPPER(a.competizione) = 'CHAMP' AND a.posizione = 1
                                                 THEN a.stagione END ORDER BY a.stagione SEPARATOR ',') AS st_champions,
                               GROUP_CONCAT(CASE WHEN UPPER(a.competizione) = 'CAMP'  AND a.posizione = 99
                                                 THEN a.stagione END ORDER BY a.stagione SEPARATOR ',') AS st_nere
                        FROM (SELECT id, MIN(utenza) AS utenza
                              FROM NEW_UTENZE
                              WHERE stagione = $stagione
                              GROUP BY id) u
                        JOIN NEW_ALBO_ORO a ON a.utenza = u.utenza
                        GROUP BY u.id");
    foreach ($righe as $r) {
        $out[(int) $r["id_squadra"]] = [
            "campionati"    => (int) $r["campionati"],
            "secondi_posti" => (int) $r["secondi_posti"],
            "champions"     => (int) $r["champions"],
            "maglie_nere"   => (int) $r["maglie_nere"],
            "stagioni"      => [
                "campionati"    => sq_lista_stagioni($r["st_campionati"]),
                "secondi_posti" => sq_lista_stagioni($r["st_secondi"]),
                "champions"     => sq_lista_stagioni($r["st_champions"]),
                "maglie_nere"   => sq_lista_stagioni($r["st_nere"]),
            ],
        ];
    }
    return $out;
}

/** Budget crediti della stagione (parametro MERCATO_BUDGET_CREDITI, default 470). */
function sq_budget_stagione(int $stagione): int
{
    $r = query_one("SELECT valore FROM NEW_PARAMETRI_STAGIONE
                    WHERE stagione = $stagione AND codice = '" . SQ_BUDGET_PARAM . "'");
    $v = $r ? trim((string) $r["valore"]) : "";
    return ($v !== "" && ctype_digit($v)) ? (int) $v : SQ_BUDGET_DEFAULT;
}

if ($id !== null) {
    $squadra = query_one("SELECT s.id, s.nome, s.logo, s.albo,
                                 a.descrizione AS allenatore, a.logo AS logo_allenatore
                          FROM NEW_SQUADRE s
                          LEFT JOIN NEW_ALLENATORI a ON a.id_squadra = s.id AND a.stagione = s.stagione
                          WHERE s.id = $id AND s.stagione = $stagione");
    if (!$squadra) api_error("Squadra non trovata", 404);

    $rosa = query_all("SELECT g.id, g.descrizione, g.ruolo, g.nazione, g.crediti,
                              COALESCE(ns.giocate, 0)      AS presenze,
                              COALESCE(ns.media, 0)        AS media,
                              CASE WHEN g.ruolo = 1
                                   THEN COALESCE(ns.gols, 0)
                                   ELSE COALESCE(ns.golf, 0)
                              END                          AS gol,
                              COALESCE(ns.assist, 0)       AS assist,
                              COALESCE(ns.ammonizioni, 0)  AS ammonizioni,
                              COALESCE(ns.espulsioni, 0)   AS espulsioni,
                              COALESCE(ns.autogol, 0)      AS autogol,
                              -- Mapping rigori: rigores = Rp + Rf (parati o realizzati),
                              -- rigorep = Rs (sbagliati). Il portiere ha i parati,
                              -- gli altri ruoli i realizzati.
                              CASE WHEN g.ruolo = 1 THEN COALESCE(ns.rigores, 0) ELSE 0 END AS rigori_parati,
                              CASE WHEN g.ruolo = 1 THEN 0 ELSE COALESCE(ns.rigores, 0) END AS rigori_segnati,
                              COALESCE(ns.rigorep, 0)      AS rigori_sbagliati
                       FROM NEW_GIOCATORI g
                       LEFT JOIN NEW_STATISTICHE ns
                              ON ns.id_giocatore = g.id AND ns.stagione = g.stagione
                       WHERE g.squadra = $id AND g.stagione = $stagione
                       ORDER BY g.ruolo, g.descrizione");

    // "Giocatore ceduto": non piu' in lista (Admin -> Aggiorna lista giocatori)
    $fuoriLista = lg_fuori_lista($stagione);
    foreach ($rosa as &$gr) $gr["ceduto"] = isset($fuoriLista[(int) $gr["id"]]);
    unset($gr);

    // Crediti spesi per ruolo e totale, budget e residuo
    $perRuolo = [1 => 0, 2 => 0, 3 => 0, 4 => 0];
    foreach ($rosa as $g) {
        $r = (int) $g["ruolo"];
        if (isset($perRuolo[$r])) $perRuolo[$r] += (int) $g["crediti"];
    }
    $totale = array_sum($perRuolo);
    $budget = sq_budget_stagione($stagione);

    $trofei = sq_trofei_stagione($stagione);
    $squadra["trofei"]  = $trofei[(int) $squadra["id"]] ?? sq_trofei_vuoti();
    $squadra["crediti"] = [
        "per_ruolo" => $perRuolo,
        "totale"    => $totale,
        "budget"    => $budget,
        "residuo"   => $budget - $totale,
    ];
    $squadra["rosa"] = $rosa;
    api_success($squadra);
} else {
    $sql = "SELECT s.id, s.nome, s.logo, s.albo,
                   a.descrizione AS allenatore, a.logo AS logo_allenatore
            FROM NEW_SQUADRE s
            LEFT JOIN NEW_ALLENATORI a ON a.id_squadra = s.id AND a.stagione = s.stagione
            WHERE s.stagione = $stagione
            ORDER BY s.nome";
    $squadre = query_all($sql);
    $trofei  = sq_trofei_stagione($stagione);
    foreach ($squadre as &$sq) {
        $sq["trofei"] = $trofei[(int) $sq["id"]] ?? sq_trofei_vuoti();
    }
    unset($sq);
    api_success($squadre);
}
