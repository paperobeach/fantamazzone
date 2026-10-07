<?php
// ============================================================
// api/champions.php
// GET ?stagione=2024&sezione=fase1|fase2|finale|gironi|note|classifica
// GET ?stagione=2024&sezione=classifica&girone=A
// ============================================================
require_once __DIR__ . "/connect.php";
require_once __DIR__ . "/lib/ChampionsCalendario.php";
require_once __DIR__ . "/lib/StatoGiornata.php";

$stagione = param_int("stagione");
$sezione  = param_str("sezione", false) ?? "classifica";


// ------------------------------------------------------------
// Calendario + risultati Champions, suddiviso per turno.
// I turni (una giornata di campionato ciascuno) sono associati in
// ordine cronologico ai turni previsti da champions_turni_piatti():
//   fase1 (6) · fase2 (4) · semifinali (2) · finale · replay.
// In ogni girone le righe, ordinate per posizione, formano le
// partite a coppie (dispari = casa, pari = ospite); l'eventuale riga
// spaiata è la squadra che riposa.
// ------------------------------------------------------------
function champions_carica_turni(int $stagione): array
{
    $cal = query_all("SELECT cc.giornata, cc.giornata_camp, cc.posizione, cc.girone,
            cc.squadra AS id_squadra, s.nome, s.logo
        FROM NEW_CALENDARIO_CHAMP cc
        JOIN NEW_SQUADRE s ON s.id = cc.squadra AND s.stagione = cc.stagione
        WHERE cc.stagione = $stagione
        ORDER BY cc.giornata, cc.girone, cc.posizione");

    // I risultati calcolati sono pubblici solo a giornata chiusa (o per le
    // stagioni storiche), come per il campionato: prima sono provvisori e
    // visibili solo nella pagina LIVE.
    $ris = [];
    foreach (query_all("SELECT giornata, squadra AS id_squadra, ftotale, golf, punti, segno
                        FROM NEW_RISULTATI_CHAMP WHERE stagione = $stagione") as $r) {
        if (!sg_risultati_pubblici($stagione, (int) $r["giornata"])) continue;
        $ris[(int) $r["giornata"]][(int) $r["id_squadra"]] = $r;
    }

    $perGiornata = [];
    foreach ($cal as $row) $perGiornata[(int) $row["giornata"]][] = $row;
    ksort($perGiornata);

    $slots = champions_turni_piatti();
    $turni = [];
    $n = 0;
    foreach ($perGiornata as $giornata => $righe) {
        if (!isset($slots[$n])) break;
        $slot = $slots[$n++];

        $lato = function ($riga) use ($ris, $giornata) {
            $id = (int) $riga["id_squadra"];
            $r  = $ris[$giornata][$id] ?? null;
            return [
                "id"      => $id,
                "nome"    => $riga["nome"],
                "logo"    => $riga["logo"],
                "golf"    => $r ? (int) $r["golf"] : null,
                "ftotale" => $r ? (float) $r["ftotale"] : null,
                "punti"   => $r ? (int) $r["punti"] : null,
            ];
        };

        $perGirone = [];
        foreach ($righe as $riga) $perGirone[trim($riga["girone"])][] = $riga;

        $partite = [];
        $riposa = [];
        foreach ($perGirone as $girone => $rg) {
            foreach (array_chunk($rg, 2) as $coppia) {
                if (count($coppia) < 2) {
                    $l = $lato($coppia[0]);
                    $riposa[] = ["girone" => $girone, "id" => $l["id"], "nome" => $l["nome"], "logo" => $l["logo"]];
                    continue;
                }
                $casa = $lato($coppia[0]);
                $ospite = $lato($coppia[1]);
                $partite[] = [
                    "girone"  => $girone,
                    "casa"    => $casa,
                    "ospite"  => $ospite,
                    "giocata" => $casa["golf"] !== null && $ospite["golf"] !== null,
                ];
            }
        }

        $turni[] = [
            "codice"        => $slot["codice"],
            "fase"          => $slot["fase_id"],
            "label"         => $slot["label"],
            "giornata"      => $giornata,
            "giornata_camp" => (int) $righe[0]["giornata_camp"],
            "partite"       => $partite,
            "riposa"        => $riposa,
        ];
    }
    return $turni;
}

// Fase a gironi (fase1 / fase2): calendario per girone + classifica calcolata.
// Parità in classifica: punti, differenza reti, gol fatti, fantapunti totali.
function champions_gironi(array $turni, string $fase, int $qualificano): array
{
    $gironi = [];
    foreach ($turni as $t) {
        if ($t["fase"] !== $fase) continue;
        $per = [];
        foreach ($t["partite"] as $p) $per[$p["girone"]]["partite"][] = $p;
        foreach ($t["riposa"] as $r) $per[$r["girone"]]["riposa"][] = $r;
        foreach ($per as $g => $dati) {
            if (!isset($gironi[$g])) {
                $gironi[$g] = ["girone" => $g, "qualificano" => $qualificano, "squadre" => [], "turni" => []];
            }
            $gironi[$g]["turni"][] = [
                "label"         => $t["label"],
                "giornata"      => $t["giornata"],
                "giornata_camp" => $t["giornata_camp"],
                "partite"       => $dati["partite"] ?? [],
                "riposa"        => $dati["riposa"] ?? [],
            ];
        }
    }

    foreach ($gironi as &$g) {
        $cl = [];
        $nuova = function ($sq) {
            return ["id_squadra" => $sq["id"], "nome" => $sq["nome"], "logo" => $sq["logo"],
                    "giocate" => 0, "vinte" => 0, "nulle" => 0, "perse" => 0,
                    "golf" => 0, "gols" => 0, "punti" => 0, "ftotale" => 0.0];
        };
        foreach ($g["turni"] as $t) {
            foreach ($t["riposa"] as $r) {
                if (!isset($cl[$r["id"]])) $cl[$r["id"]] = $nuova($r);
            }
            foreach ($t["partite"] as $p) {
                foreach (["casa", "ospite"] as $lt) {
                    $sq = $p[$lt];
                    if (!isset($cl[$sq["id"]])) $cl[$sq["id"]] = $nuova($sq);
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
    }
    unset($g);
    ksort($gironi);
    return array_values($gironi);
}

// Lato vincente di una partita giocata (null se non giocata o pari).
function champions_vincitore(?array $p): ?array
{
    if (!$p || !$p["giocata"] || $p["casa"]["golf"] === $p["ospite"]["golf"]) return null;
    $l = $p["casa"]["golf"] > $p["ospite"]["golf"] ? $p["casa"] : $p["ospite"];
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
            foreach ([$pa, $pr] as $p) {
                if (!$p) continue;
                foreach (["casa", "ospite"] as $lt) {
                    $sq = $p[$lt];
                    if (!isset($agg[$sq["id"]])) {
                        $agg[$sq["id"]] = ["id" => $sq["id"], "nome" => $sq["nome"], "logo" => $sq["logo"], "golf" => 0];
                    }
                    if ($p["giocata"]) $agg[$sq["id"]]["golf"] += $sq["golf"];
                }
            }
            $agg = array_values($agg);
            $complete = $pa["giocata"] && $pr && $pr["giocata"];
            $vincente = null;
            if ($complete && count($agg) === 2 && $agg[0]["golf"] !== $agg[1]["golf"]) {
                $w = $agg[0]["golf"] > $agg[1]["golf"] ? $agg[0] : $agg[1];
                $vincente = ["id" => $w["id"], "nome" => $w["nome"], "logo" => $w["logo"]];
            }
            $semifinali[] = [
                "andata"    => $pa,
                "ritorno"   => $pr,
                "aggregato" => $agg,
                "completa"  => $complete,
                "pari"      => $complete && !$vincente,
                "vincente"  => $vincente,
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
                $nota = "Replay in parità: si decide ai supplementari e, se serve, ai calci di rigore.";
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
