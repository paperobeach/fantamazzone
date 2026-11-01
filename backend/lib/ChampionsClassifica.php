<?php
// ============================================================
// backend/lib/ChampionsClassifica.php
//
// Calendario + risultati Champions per turno e classifica dei gironi.
// Estratto da champions.php per essere condiviso con live_giornata.php
// (classifiche aggiornate in tempo reale).
//
// Richiede connect.php (query_all) gia' incluso dal chiamante.
// ============================================================
require_once __DIR__ . "/ChampionsCalendario.php";
require_once __DIR__ . "/StatoGiornata.php";

// ------------------------------------------------------------
// Calendario + risultati Champions, suddiviso per turno.
// I turni (una giornata di campionato ciascuno) sono associati in
// ordine cronologico ai turni previsti da champions_turni_piatti():
//   fase1 (6) · fase2 (4) · semifinali (2) · finale · replay.
// In ogni girone le righe, ordinate per posizione, formano le
// partite a coppie (dispari = casa, pari = ospite); l'eventuale riga
// spaiata è la squadra che riposa.
// ------------------------------------------------------------
function champions_carica_turni(int $stagione, ?array $override = null): array
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
    foreach (query_all("SELECT giornata, squadra AS id_squadra, ftotale, golf, punti, segno,
                               ftotale_a, modificatore, mod_cc, mod_att,
                               supp_giocati, supp_golf, supp_gols, rig_giocati, rig_golf, rig_gols, passa
                        FROM NEW_RISULTATI_CHAMP WHERE stagione = $stagione") as $r) {
        if (!sg_risultati_pubblici($stagione, (int) $r["giornata"])) continue;
        $ris[(int) $r["giornata"]][(int) $r["id_squadra"]] = $r;
    }
    // Risultati NON definitivi (pagina LIVE): righe calcolate o simulate della
    // giornata in corso, nella forma [giornata_camp][id_squadra] => riga, con le
    // stesse colonne della query precedente. Sostituiscono quelle della giornata.
    if ($override) {
        foreach ($override as $g => $perSquadra) {
            foreach ($perSquadra as $idSq => $riga) $ris[(int) $g][(int) $idSq] = $riga;
        }
    }

    $perGiornata = [];
    foreach ($cal as $row) $perGiornata[(int) $row["giornata"]][] = $row;
    ksort($perGiornata);

    // Una giornata Champions (progressivo) = un TURNO che contiene tutti i
    // gironi della fase (es. giornata 1: girone A alla giornata di campionato
    // 2 e girone B alla 3). Le giornate distinte, in ordine, corrispondono
    // per posizione ai turni (Fase 1, Fase 2, Fase finale).
    $turniCron = champions_turni_cronologici();
    $turni = [];
    $n = 0;
    foreach ($perGiornata as $giornata => $righe) {
        if (!isset($turniCron[$n])) break;
        $turnoDef = $turniCron[$n++];
        $celle    = $turnoDef["celle"];
        // Codice del turno: la cella se unica (SF, finale, replay), altrimenti
        // il codice generico senza girone (es. CHAMP_F1_T3)
        $codiceTurno = count($celle) === 1
            ? $celle[0]["codice"]
            : preg_replace('/_[A-Z]_T(\d+)$/', '_T$1', $celle[0]["codice"]);

        // I risultati (NEW_RISULTATI_CHAMP) sono salvati per giornata di
        // FANTACAMPIONATO: si cercano con giornata_camp della riga.
        $lato = function ($riga) use ($ris) {
            $id = (int) $riga["id_squadra"];
            $r  = $ris[(int) $riga["giornata_camp"]][$id] ?? null;
            return [
                "id"      => $id,
                "nome"    => $riga["nome"],
                "logo"    => $riga["logo"],
                "golf"    => $r ? (int) $r["golf"] : null,
                "ftotale" => $r ? (float) $r["ftotale"] : null,
                "punti"   => $r ? (int) $r["punti"] : null,
                // dati per le colonne extra della classifica (come NEW_GENERALE)
                "ftotale_a"   => $r ? (float) $r["ftotale_a"] : null,
                "modificatore" => $r ? (float) $r["modificatore"] : null,
                "mod_cc"      => $r ? (float) $r["mod_cc"] : null,
                "mod_att"     => $r ? (float) $r["mod_att"] : null,
                "segno"       => $r ? (string) $r["segno"] : null,
                // Supplementari e rigori (turni che li prevedono): "golf" resta il
                // risultato dei tempi regolamentari, valido per la classifica dei gironi
                "supp_golf"   => $r ? (int) $r["supp_golf"] : null,
                "rig_golf"    => $r ? (int) $r["rig_golf"] : null,
                "passa"       => $r ? (int) $r["passa"] === 1 : null,
                "_supp"       => $r ? (int) $r["supp_giocati"] === 1 : false,
                "_rig"        => $r ? (int) $r["rig_giocati"] === 1 : false,
            ];
        };

        // Gruppi storici (A, B, C1..C3 -> C, D1..D3 -> D, S1, S2, FI, FR)
        $perGirone = champions_raggruppa_righe($righe);

        $partite = [];
        $riposa = [];
        foreach ($perGirone as $girone => $rg) {
            foreach (array_chunk($rg, 2) as $coppia) {
                if (count($coppia) < 2) {
                    $l = $lato($coppia[0]);
                    $riposa[] = ["girone" => $girone, "giornata_camp" => (int) $coppia[0]["giornata_camp"],
                                 "id" => $l["id"], "nome" => $l["nome"], "logo" => $l["logo"]];
                    continue;
                }
                $casa = $lato($coppia[0]);
                $ospite = $lato($coppia[1]);
                $suppl = $casa["_supp"] || $ospite["_supp"];
                $rigori = $casa["_rig"] || $ospite["_rig"];
                unset($casa["_supp"], $casa["_rig"], $ospite["_supp"], $ospite["_rig"]);
                $partite[] = [
                    "girone"  => $girone,
                    // Giornata di fantacampionato: serve per aprire il dettaglio
                    "giornata" => (int) $coppia[0]["giornata_camp"],
                    "casa"    => $casa,
                    "ospite"  => $ospite,
                    "giocata" => $casa["golf"] !== null && $ospite["golf"] !== null,
                    // La partita è proseguita ai supplementari / ai calci di rigore
                    "supplementari" => $suppl,
                    "rigori"        => $rigori,
                ];
            }
        }

        $turni[] = [
            "codice"        => $codiceTurno,
            "fase"          => $turnoDef["fase_id"],
            "label"         => $turnoDef["label"],
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
                // Ogni girone può giocare la stessa giornata Champions in una
                // giornata di campionato diversa: si usa quella del girone
                "giornata_camp" => $dati["partite"][0]["giornata"] ?? $dati["riposa"][0]["giornata_camp"] ?? $t["giornata_camp"],
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
                    "golf" => 0, "gols" => 0, "punti" => 0, "ftotale" => 0.0,
                    // colonne come la classifica del campionato (NEW_GENERALE)
                    "_sum_a" => 0.0, "_sum_mod_dif" => 0.0, "_sum_mod_cc" => 0.0, "_sum_mod_att" => 0.0,
                    "maxp" => null, "minp" => null, "segno" => ""];
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
                    $c["_sum_a"] += $sq["ftotale_a"];
                    $c["_sum_mod_dif"] += $sq["modificatore"];
                    $c["_sum_mod_cc"]  += $sq["mod_cc"];
                    $c["_sum_mod_att"] += $sq["mod_att"];
                    $c["maxp"] = $c["maxp"] === null ? $sq["ftotale"] : max($c["maxp"], $sq["ftotale"]);
                    $c["minp"] = $c["minp"] === null ? $sq["ftotale"] : min($c["minp"], $sq["ftotale"]);
                    $c["segno"] = $sq["segno"];   // turni in ordine cronologico: resta l'ultimo
                    if ($sq["golf"] > $altro["golf"]) $c["vinte"]++;
                    elseif ($sq["golf"] < $altro["golf"]) $c["perse"]++;
                    else $c["nulle"]++;
                    unset($c);
                }
            }
        }
        // Medie e valori derivati, come NEW_GENERALE (media/media_a su ftotale,
        // medie dei modificatori), arrotondati a 2 decimali.
        foreach ($cl as &$c) {
            $n = max(1, $c["giocate"]);
            $c["media"]         = round($c["ftotale"] / $n, 2);
            $c["media_a"]       = round($c["_sum_a"] / $n, 2);
            $c["media_mod_dif"] = round($c["_sum_mod_dif"] / $n, 2);
            $c["media_mod_cc"]  = round($c["_sum_mod_cc"] / $n, 2);
            $c["media_mod_att"] = round($c["_sum_mod_att"] / $n, 2);
            $c["maxp"] = $c["maxp"] ?? 0.0;
            $c["minp"] = $c["minp"] ?? 0.0;
            unset($c["_sum_a"], $c["_sum_mod_dif"], $c["_sum_mod_cc"], $c["_sum_mod_att"]);
        }
        unset($c);
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
