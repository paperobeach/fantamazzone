<?php
// ============================================================
// api/champions.php
// GET ?stagione=2024&sezione=fase1|gironi|note|classifica
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
            ORDER BY cc.giornata, cc.posizione");

        $risultati = query_all("SELECT
                rc.giornata, rc.squadra AS id_squadra,
                rc.ftotale, rc.golf, rc.gols, rc.punti, rc.segno, rc.girone
            FROM NEW_RISULTATI_CHAMP rc
            WHERE rc.stagione = $stagione
            ORDER BY rc.giornata");

        api_success(["calendario" => $data, "risultati" => $risultati]);
        break;

    case "fase1":
        // Prima fase: due gironi da 4 squadre, partite di andata e ritorno.
        // Le righe di NEW_CALENDARIO_CHAMP appartenenti alla fase 1 sono
        // quelle in cui, per (giornata, giornata_camp, girone), compaiono
        // 4 squadre. Posizioni dispari = casa, pari = ospite (come NEW_CALENDARIO).
        $cal = query_all("SELECT
                cc.giornata, cc.giornata_camp, cc.posizione, cc.girone,
                cc.squadra AS id_squadra, s.nome, s.logo
            FROM NEW_CALENDARIO_CHAMP cc
            JOIN NEW_SQUADRE s ON s.id = cc.squadra AND s.stagione = cc.stagione
            WHERE cc.stagione = $stagione
            ORDER BY cc.girone, cc.giornata_camp, cc.giornata, cc.posizione");

        $ris_raw = query_all("SELECT giornata, squadra AS id_squadra,
                ftotale, golf, gols, punti, segno
            FROM NEW_RISULTATI_CHAMP
            WHERE stagione = $stagione");
        $ris = [];
        foreach ($ris_raw as $r) {
            $ris[(int)$r["giornata"]][(int)$r["id_squadra"]] = $r;
        }

        // Raggruppo per turno (giornata, giornata_camp, girone)
        $turni_raw = [];
        foreach ($cal as $row) {
            $k = $row["girone"] . "|" . $row["giornata_camp"] . "|" . $row["giornata"];
            $turni_raw[$k][] = $row;
        }

        $gironi = [];
        foreach ($turni_raw as $righe) {
            if (count($righe) !== 4) continue;           // non è fase 1
            $girone = trim($righe[0]["girone"]);
            $giornata = (int)$righe[0]["giornata"];
            $camp = (int)$righe[0]["giornata_camp"];

            if (!isset($gironi[$girone])) {
                $gironi[$girone] = ["girone" => $girone, "squadre" => [], "turni" => []];
            }

            $partite = [];
            foreach (array_chunk($righe, 2) as $coppia) {
                if (count($coppia) < 2) continue;
                $lato = function ($riga) use ($ris, $giornata) {
                    $id = (int)$riga["id_squadra"];
                    $r  = $ris[$giornata][$id] ?? null;
                    return [
                        "id"      => $id,
                        "nome"    => $riga["nome"],
                        "logo"    => $riga["logo"],
                        "golf"    => $r ? (int)$r["golf"] : null,
                        "ftotale" => $r ? (float)$r["ftotale"] : null,
                        "punti"   => $r ? (int)$r["punti"] : null,
                        "segno"   => $r ? $r["segno"] : null,
                    ];
                };
                $casa = $lato($coppia[0]);
                $ospite = $lato($coppia[1]);
                $partite[] = [
                    "casa"    => $casa,
                    "ospite"  => $ospite,
                    "giocata" => $casa["golf"] !== null && $ospite["golf"] !== null,
                ];
            }
            $gironi[$girone]["turni"][] = [
                "giornata"      => $giornata,
                "giornata_camp" => $camp,
                "partite"       => $partite,
            ];
        }

        // Classifica calcolata dalle partite giocate della fase 1
        foreach ($gironi as &$g) {
            $cl = [];
            foreach ($g["turni"] as $t) {
                foreach ($t["partite"] as $p) {
                    foreach (["casa", "ospite"] as $lt) {
                        $sq = $p[$lt];
                        if (!isset($cl[$sq["id"]])) {
                            $cl[$sq["id"]] = ["id_squadra" => $sq["id"], "nome" => $sq["nome"],
                                "logo" => $sq["logo"], "giocate" => 0, "vinte" => 0, "nulle" => 0,
                                "perse" => 0, "golf" => 0, "gols" => 0, "punti" => 0, "ftotale" => 0.0];
                        }
                        if (!$p["giocata"]) continue;
                        $altro = $p[$lt === "casa" ? "ospite" : "casa"];
                        $c = &$cl[$sq["id"]];
                        $c["giocate"]++;
                        $c["golf"] += $sq["golf"];
                        $c["gols"] += $altro["golf"];
                        $c["punti"] += $sq["punti"];
                        $c["ftotale"] += $sq["ftotale"];
                        if ($sq["golf"] > $altro["golf"]) $c["vinte"]++;
                        elseif ($sq["golf"] < $altro["golf"]) $c["perse"]++;
                        else $c["nulle"]++;
                        unset($c);
                    }
                }
            }
            $cl = array_values($cl);
            usort($cl, function ($a, $b) {
                return [$b["punti"], $b["golf"] - $b["gols"], $b["golf"], $b["ftotale"]]
                   <=> [$a["punti"], $a["golf"] - $a["gols"], $a["golf"], $a["ftotale"]];
            });
            $g["squadre"] = $cl;
            usort($g["turni"], function ($a, $b) {
                return [$a["giornata_camp"], $a["giornata"]] <=> [$b["giornata_camp"], $b["giornata"]];
            });
        }
        unset($g);

        ksort($gironi);
        api_success(array_values($gironi));
        break;

    case "note":
        $data = query_all("SELECT * FROM NEW_NOTE_CHAMPIONS
                           WHERE stagione = $stagione");
        api_success($data);
        break;

    default:
        api_error("Sezione non valida. Valori: classifica, gironi, fase1, note");
}
