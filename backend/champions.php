<?php
// ============================================================
// api/champions.php
// GET ?stagione=2024&sezione=gironi|semifinali|finale|classifica
// GET ?stagione=2024&sezione=classifica&girone=A
// ============================================================
require_once __DIR__ . "/connect.php";

$stagione = param_int("stagione");
$sezione  = param_str("sezione", false) ?? "classifica";

switch ($sezione) {

    case "classifica":
        $girone = param_str("girone", false);
        $where_girone = $girone ? "AND girone = '$girone'" : "";
        $data = query_all("SELECT
                gc.squadra AS id_squadra, gc.nome, gc.logo,
                gc.punti, gc.golf, gc.gols, gc.girone
            FROM NEW_GENERALE_CHAMP gc
            WHERE gc.stagione = $stagione $where_girone
            ORDER BY gc.girone, gc.punti DESC, gc.golf DESC");
        api_success($data);
        break;

    case "gironi":
        // Calendario + risultati fase a gironi
        $data = query_all("SELECT
                cc.giornata, cc.giornata_camp, cc.posizione,
                cc.squadra AS id_squadra, s.nome, s.logo, cc.girone
            FROM NEW_CALENDARIO_CHAMP cc
            JOIN NEW_SQUADRE s ON s.id = cc.squadra AND s.stagione = cc.stagione
            WHERE cc.stagione = $stagione
            ORDER BY cc.gironata, cc.posizione");

        $risultati = query_all("SELECT
                rc.giornata, rc.squadra AS id_squadra,
                rc.ftotale, rc.golf, rc.gols, rc.punti, rc.segno, rc.girone
            FROM NEW_RISULTATI_CHAMP rc
            WHERE rc.stagione = $stagione
            ORDER BY rc.giornata");

        api_success(["calendario" => $data, "risultati" => $risultati]);
        break;

    case "note":
        $data = query_all("SELECT * FROM NEW_NOTE_CHAMPIONS
                           WHERE stagione = $stagione
                           ORDER BY id");
        api_success($data);
        break;

    default:
        api_error("Sezione non valida. Valori: classifica, gironi, note");
}
