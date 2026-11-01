<?php
// ============================================================
// api/champions.php
// GET ?stagione=2024&sezione=fase1|fase2|finale|gironi|note|classifica
// GET ?stagione=2024&sezione=classifica&girone=A
// ============================================================
require_once __DIR__ . "/connect.php";
require_once __DIR__ . "/lib/ChampionsCalendario.php";
require_once __DIR__ . "/lib/StatoGiornata.php";
require_once __DIR__ . "/lib/ChampionsClassifica.php";   // champions_carica_turni(), champions_gironi()

$stagione = param_int("stagione");
$sezione  = param_str("sezione", false) ?? "classifica";



// Gol di una squadra comprensivi dei supplementari (i rigori non sono gol)
function champions_gol_totali(array $lato): int
{
    return (int) $lato["golf"] + (int) ($lato["supp_golf"] ?? 0);
}

// Lato vincente di una partita giocata (null se non giocata o pari). Conta il
// risultato dopo i supplementari; se è ancora pari decidono i calci di rigore
// (colonna "passa" della squadra che supera il turno).
function champions_vincitore(?array $p): ?array
{
    if (!$p || !$p["giocata"]) return null;
    $gc = champions_gol_totali($p["casa"]);
    $go = champions_gol_totali($p["ospite"]);
    if ($gc === $go) {
        if (!empty($p["casa"]["passa"]))        $l = $p["casa"];
        elseif (!empty($p["ospite"]["passa"]))  $l = $p["ospite"];
        else return null;
    } else {
        $l = $gc > $go ? $p["casa"] : $p["ospite"];
    }
    return ["id" => $l["id"], "nome" => $l["nome"], "logo" => $l["logo"]];
}

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
            ORDER BY cc.giornata, cc.posizione");

        $risultati = array_values(array_filter(query_all("SELECT
                rc.giornata, rc.squadra AS id_squadra,
                rc.ftotale, rc.golf, rc.gols, rc.punti, rc.segno, rc.girone
            FROM NEW_RISULTATI_CHAMP rc
            WHERE rc.stagione = $stagione
            ORDER BY rc.giornata"), fn($r) => sg_risultati_pubblici($stagione, (int) $r["giornata"])));

        api_success(["calendario" => $data, "risultati" => $risultati]);
        break;

    case "fase1":
        // Due gironi da 4 squadre, andata e ritorno: le prime tre passano alla fase 2.
        api_success(champions_gironi(champions_carica_turni($stagione), "fase1", 3));
        break;

    case "fase2":
        // Due gironi da 3 squadre: le prime due accedono alle semifinali.
        api_success(champions_gironi(champions_carica_turni($stagione), "fase2", 2));
        break;

    case "finale":
        // Semifinali andata/ritorno, finale, replay in caso di parità.
        $per = [];
        foreach (champions_carica_turni($stagione) as $t) $per[$t["codice"]] = $t;

        $semifinali = [];
        $andata  = $per["CHAMP_SF_T1"]["partite"] ?? [];
        $ritorno = $per["CHAMP_SF_T2"]["partite"] ?? [];
        foreach ($andata as $pa) {
            $ids = [$pa["casa"]["id"], $pa["ospite"]["id"]];
            sort($ids);
            $pr = null;
            foreach ($ritorno as $cand) {
                $c = [$cand["casa"]["id"], $cand["ospite"]["id"]];
                sort($c);
                if ($c === $ids) { $pr = $cand; break; }
            }
            // Aggregato sui due incontri
            $agg = [];
            $passa = null; // squadra che supera il turno ai rigori (se disputati)
            foreach ([$pa, $pr] as $p) {
                if (!$p) continue;
                foreach (["casa", "ospite"] as $lt) {
                    $sq = $p[$lt];
                    if (!isset($agg[$sq["id"]])) {
                        $agg[$sq["id"]] = ["id" => $sq["id"], "nome" => $sq["nome"], "logo" => $sq["logo"], "golf" => 0];
                    }
                    // Aggregato = gol dei tempi regolamentari + eventuali supplementari
                    if ($p["giocata"]) $agg[$sq["id"]]["golf"] += champions_gol_totali($sq);
                    if ($p["giocata"] && !empty($sq["passa"])) $passa = $sq["id"];
                }
            }
            $agg = array_values($agg);
            $complete = $pa["giocata"] && $pr && $pr["giocata"];
            $vincente = null;
            if ($complete && count($agg) === 2) {
                if ($agg[0]["golf"] !== $agg[1]["golf"]) {
                    $w = $agg[0]["golf"] > $agg[1]["golf"] ? $agg[0] : $agg[1];
                    $vincente = ["id" => $w["id"], "nome" => $w["nome"], "logo" => $w["logo"]];
                } elseif ($passa !== null) {
                    // Aggregato in parità anche dopo i supplementari: decidono i rigori
                    foreach ($agg as $a) {
                        if ($a["id"] === $passa) $vincente = ["id" => $a["id"], "nome" => $a["nome"], "logo" => $a["logo"]];
                    }
                }
            }
            $semifinali[] = [
                "andata"    => $pa,
                "ritorno"   => $pr,
                "aggregato" => $agg,
                "completa"  => $complete,
                "pari"      => $complete && !$vincente,
                "vincente"  => $vincente,
                // il ritorno è proseguito ai supplementari / ai rigori
                "supplementari" => !empty($pr["supplementari"]),
                "rigori"        => !empty($pr["rigori"]),
            ];
        }

        $finale = $per["CHAMP_FIN_T1"]["partite"][0] ?? null;
        $replay = $per["CHAMP_FIN_REPLAY"]["partite"][0] ?? null;
        $campione = champions_vincitore($finale) ?? champions_vincitore($replay);
        $nota = null;
        if (!$campione) {
            if ($finale && $finale["giocata"] && !($replay && $replay["giocata"])) {
                $nota = "Finale in parità: si gioca il replay.";
            } elseif ($replay && $replay["giocata"]) {
                $nota = "Replay in parità: esito da definire (supplementari e rigori non previsti per questo turno).";
            }
        }

        api_success([
            "semifinali" => $semifinali,
            "finale"     => $finale ? $finale + ["giornata" => $per["CHAMP_FIN_T1"]["giornata"]] : null,
            "replay"     => $replay ? $replay + ["giornata" => $per["CHAMP_FIN_REPLAY"]["giornata"]] : null,
            "campione"   => $campione,
            "nota"       => $nota,
        ]);
        break;

    case "note":
        $data = query_all("SELECT * FROM NEW_NOTE_CHAMPIONS
                           WHERE stagione = $stagione");
        api_success($data);
        break;

    default:
        api_error("Sezione non valida. Valori: classifica, gironi, fase1, fase2, finale, note");
}
