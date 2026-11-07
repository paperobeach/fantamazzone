<?php
// ============================================================
// backend/lib/RecordStorici.php
//
// RECORD STORICI DEI GIOCATORI della lega (Dashboard lega > Record storici).
//
// Le classifiche sono "all time": coprono tutte le stagioni presenti nel DB.
// Poiche' l'id di un giocatore NON e' stabile tra stagioni, la persona e'
// riconosciuta tramite il RACCORDO (NEW_GIOCATORI_RACCORDO, vedi
// lib/RaccordoGiocatori.php); se il giocatore non e' raccordato (o le tabelle
// non esistono) si ripiega sul NOME normalizzato (rg_norm).
//
// Fonti:
//   NEW_VOTI / NEW_VOTI_CHAMP        presenze, gol, assist, cartellini, rigori, fantavoto
//                                     (rufficio = 0: niente riserve d'ufficio)
//   NEW_GIOCATORI + BASE_ASTA         crediti pagati (prezzo di ogni edizione)
//   NEW_GIOCATORI_SVINCOLATI          anagrafica dei giocatori ceduti
//
// Mapping dei rigori (come lib/SchedaGiocatore.php):
//   rigores = portiere: parati, altri ruoli: segnati; rigorep = sbagliati.
//
// Va incluso DOPO connect.php.
// ============================================================

require_once __DIR__ . "/RaccordoGiocatori.php";

const REC_MIN_PRESENZE_MEDIA_CARRIERA = 30;   // presenze minime per la media di carriera
const REC_MIN_PRESENZE_MEDIA_STAGIONE = 10;   // presenze minime per la media di stagione
const REC_MAX_RIGHE = 50;

/** Edizioni (stagione, id) di tutti i giocatori: "stagione-id" => [nome, ruolo]. */
function rec_edizioni(): array
{
    $out = [];
    $righe = query_all("SELECT stagione, id, descrizione, ruolo FROM NEW_GIOCATORI
                        UNION ALL
                        SELECT v.stagione, v.id, v.descrizione, v.ruolo FROM NEW_GIOCATORI_SVINCOLATI v
                         WHERE NOT EXISTS (SELECT 1 FROM NEW_GIOCATORI x WHERE x.stagione = v.stagione AND x.id = v.id)");
    foreach ($righe as $r) {
        $out[(int) $r["stagione"] . "-" . (int) $r["id"]] = [
            "nome"  => trim((string) $r["descrizione"]),
            "ruolo" => (int) $r["ruolo"],
        ];
    }
    return $out;
}

/** Chiave persona per ogni edizione: "p<id_anagrafica>" oppure "n:<nome normalizzato>". */
function rec_persona(array $edizioni): array
{
    $racc = [];
    $nomiAnag = [];
    if (rg_tabelle_esistono()) {
        foreach (query_all("SELECT stagione, id_giocatore, id_anagrafica FROM " . RG_T_RACC) as $r) {
            $racc[(int) $r["stagione"] . "-" . (int) $r["id_giocatore"]] = (int) $r["id_anagrafica"];
        }
        foreach (query_all("SELECT id_anagrafica, nome FROM " . RG_T_ANAG) as $r) {
            $nomiAnag[(int) $r["id_anagrafica"]] = trim((string) $r["nome"]);
        }
    }
    $out = [];
    foreach ($edizioni as $k => $e) {
        if (isset($racc[$k])) {
            $ida = $racc[$k];
            $out[$k] = ["chiave" => "p$ida", "nome" => $nomiAnag[$ida] ?? $e["nome"]];
        } else {
            $out[$k] = ["chiave" => "n:" . rg_norm($e["nome"]), "nome" => $e["nome"]];
        }
    }
    return $out;
}

/** Posizioni con pari merito (1,2,2,4...), taglio a $max righe; record = pari merito al 1° posto. */
function rec_ordina(array $items, int $max, bool $desc = true): array
{
    usort($items, function ($a, $b) use ($desc) {
        $c = $desc ? $b["valore"] <=> $a["valore"] : $a["valore"] <=> $b["valore"];
        return $c ?: strcmp($a["nome"], $b["nome"]);
    });
    $pos = 0; $prec = null; $i = 0;
    foreach ($items as &$it) {
        $i++;
        if ($prec === null || $it["valore"] !== $prec) { $pos = $i; $prec = $it["valore"]; }
        $it["pos"] = $pos;
    }
    unset($it);
    $record = array_values(array_filter($items, fn($x) => $x["pos"] === 1));
    return [
        "righe"  => array_slice($items, 0, $max),
        "record" => array_slice($record, 0, 5),
    ];
}

function rec_scheda(string $chiave, string $titolo, string $gruppo, string $descrizione, string $unita, int $dec, array $ris): array
{
    return [
        "chiave" => $chiave, "gruppo" => $gruppo, "titolo" => $titolo, "descrizione" => $descrizione,
        "unita" => $unita, "decimali" => $dec, "righe" => $ris["righe"], "record" => $ris["record"],
    ];
}

/**
 * Tutte le classifiche.
 * @param string $competizione CAMP | CHAMP
 * @param int    $ruolo        0 = tutti, 1..4 = reparto
 */
function rec_classifiche(string $competizione, int $ruolo, int $max): array
{
    $max = max(3, min(REC_MAX_RIGHE, $max));
    $tab = $competizione === "CHAMP" ? "NEW_VOTI_CHAMP" : "NEW_VOTI";

    $edizioni = rec_edizioni();
    $persona  = rec_persona($edizioni);

    $squadre = [];
    foreach (query_all("SELECT stagione, id, nome, logo FROM NEW_SQUADRE") as $q) {
        $squadre[(int) $q["stagione"] . "-" . (int) $q["id"]] = ["nome" => $q["nome"], "logo" => $q["logo"]];
    }

    // Aggregato per (stagione, id) + squadra dell'ultima giornata
    $agg = query_all("SELECT a.stagione, a.id_giocatore, a.giocate, a.totale, a.reti, a.assist, a.ammonizioni,
                             a.espulsioni, a.autogol, a.rigores, a.rigorep, u.id_squadra
                      FROM (SELECT stagione, id_giocatore, MAX(giornata) AS ultima,
                                   SUM(giocata) AS giocate, SUM(totale) AS totale, SUM(reti) AS reti,
                                   SUM(assist) AS assist, SUM(ammonizioni) AS ammonizioni,
                                   SUM(espulsioni) AS espulsioni, SUM(autogol) AS autogol,
                                   SUM(rigores) AS rigores, SUM(rigorep) AS rigorep
                            FROM $tab WHERE rufficio = 0 AND id_giocatore > 0
                            GROUP BY stagione, id_giocatore) a
                      JOIN $tab u ON u.stagione = a.stagione AND u.id_giocatore = a.id_giocatore
                                 AND u.giornata = a.ultima AND u.rufficio = 0");

    // Persona-stagione
    $ps = [];
    foreach ($agg as $r) {
        $st = (int) $r["stagione"]; $id = (int) $r["id_giocatore"]; $k = "$st-$id";
        $e = $edizioni[$k] ?? ["nome" => "Giocatore #$id", "ruolo" => 0];
        $p = $persona[$k] ?? ["chiave" => "n:" . rg_norm($e["nome"]), "nome" => $e["nome"]];
        $kk = $p["chiave"] . "|" . $st;
        if (!isset($ps[$kk])) {
            $ps[$kk] = ["persona" => $p["chiave"], "nome" => $p["nome"], "stagione" => $st, "ruolo" => $e["ruolo"],
                        "id" => $id, "id_squadra" => (int) $r["id_squadra"],
                        "giocate" => 0, "totale" => 0.0, "gol" => 0, "assist" => 0, "amm" => 0, "esp" => 0,
                        "autogol" => 0, "rig_segnati" => 0, "rig_parati" => 0, "rig_sbagliati" => 0];
        }
        $x = &$ps[$kk];
        if ($id > $x["id"]) { $x["id"] = $id; $x["id_squadra"] = (int) $r["id_squadra"]; $x["ruolo"] = $e["ruolo"]; }
        $x["giocate"] += (int) $r["giocate"];
        $x["totale"]  += (float) $r["totale"];
        $x["gol"]     += (int) $r["reti"];
        $x["assist"]  += (int) $r["assist"];
        $x["amm"]     += (int) $r["ammonizioni"];
        $x["esp"]     += (int) $r["espulsioni"];
        $x["autogol"] += (int) $r["autogol"];
        if ($e["ruolo"] === 1) $x["rig_parati"] += (int) $r["rigores"]; else $x["rig_segnati"] += (int) $r["rigores"];
        $x["rig_sbagliati"] += (int) $r["rigorep"];
        unset($x);
    }

    // Carriera
    $pc = [];
    foreach ($ps as $s) {
        $c = $s["persona"];
        if (!isset($pc[$c])) {
            $pc[$c] = ["nome" => $s["nome"], "ruolo" => $s["ruolo"], "stagione" => $s["stagione"], "id" => $s["id"],
                       "id_squadra" => $s["id_squadra"], "stagioni" => 0, "giocate" => 0, "totale" => 0.0, "gol" => 0,
                       "assist" => 0, "amm" => 0, "esp" => 0, "autogol" => 0, "rig_segnati" => 0, "rig_parati" => 0,
                       "rig_sbagliati" => 0];
        }
        $x = &$pc[$c];
        if ($s["stagione"] > $x["stagione"] || ($s["stagione"] === $x["stagione"] && $s["id"] > $x["id"])) {
            $x["stagione"] = $s["stagione"]; $x["id"] = $s["id"]; $x["ruolo"] = $s["ruolo"]; $x["id_squadra"] = $s["id_squadra"];
        }
        $x["stagioni"]++;
        foreach (["giocate", "totale", "gol", "assist", "amm", "esp", "autogol", "rig_segnati", "rig_parati", "rig_sbagliati"] as $f) {
            $x[$f] += $s[$f];
        }
        unset($x);
    }

    $filtroRuolo = fn(array $x) => $ruolo === 0 || $x["ruolo"] === $ruolo;

    $riga = function (array $x, $valore, bool $conStagione = false) use ($squadre): array {
        $sq = $squadre[$x["stagione"] . "-" . $x["id_squadra"]] ?? null;
        return [
            "id" => $x["id"], "stagione" => $x["stagione"], "nome" => $x["nome"], "ruolo" => $x["ruolo"],
            "valore" => $valore, "squadra" => $sq["nome"] ?? null, "logo" => $sq["logo"] ?? null,
            "giocate" => $x["giocate"] ?? null,
            "media" => isset($x["giocate"]) && $x["giocate"] > 0 && isset($x["totale"]) ? round($x["totale"] / $x["giocate"], 2) : null,
            "stagione_rif" => $conStagione ? $x["stagione"] : null,
        ];
    };

    $mk = function (array $fonte, callable $val, bool $conStagione, bool $desc = true) use ($filtroRuolo, $riga, $max): array {
        $items = [];
        foreach ($fonte as $x) {
            if (!$filtroRuolo($x)) continue;
            $v = $val($x);
            if ($v === null || $v <= 0) continue;
            $items[] = $riga($x, $v, $conStagione);
        }
        return rec_ordina($items, $max, $desc);
    };

    $lista = [];
    $car = array_values($pc);
    $sta = array_values($ps);
    $G = "Carriera";
    $lista[] = rec_scheda("car_presenze", "Presenze", $G, "Partite giocate in tutte le stagioni", "", 0, $mk($car, fn($x) => $x["giocate"], false));
    $lista[] = rec_scheda("car_gol", "Marcatori", $G, "Gol segnati in carriera", "", 0, $mk($car, fn($x) => $x["gol"], false));
    $lista[] = rec_scheda("car_assist", "Assist", $G, "Assist in carriera", "", 0, $mk($car, fn($x) => $x["assist"], false));
    $lista[] = rec_scheda("car_rig_segnati", "Rigori segnati", $G, "Rigori realizzati (esclusi i portieri)", "", 0, $mk($car, fn($x) => $x["rig_segnati"], false));
    $lista[] = rec_scheda("car_rig_parati", "Rigori parati", $G, "Rigori parati dai portieri", "", 0, $mk($car, fn($x) => $x["rig_parati"], false));
    $lista[] = rec_scheda("car_media", "Fantamedia", $G, "Media fantapunti, minimo " . REC_MIN_PRESENZE_MEDIA_CARRIERA . " presenze", "", 2,
        $mk($car, fn($x) => $x["giocate"] >= REC_MIN_PRESENZE_MEDIA_CARRIERA ? round($x["totale"] / $x["giocate"], 2) : null, false));
    $lista[] = rec_scheda("car_stagioni", "Stagioni in lega", $G, "Stagioni disputate", "", 0, $mk($car, fn($x) => $x["stagioni"], false));
    $lista[] = rec_scheda("car_amm", "Ammonizioni", $G, "Cartellini gialli in carriera", "", 0, $mk($car, fn($x) => $x["amm"], false));
    $lista[] = rec_scheda("car_esp", "Espulsioni", $G, "Cartellini rossi in carriera", "", 0, $mk($car, fn($x) => $x["esp"], false));
    $lista[] = rec_scheda("car_autogol", "Autogol", $G, "Autogol in carriera", "", 0, $mk($car, fn($x) => $x["autogol"], false));

    $S = "Singola stagione";
    $lista[] = rec_scheda("sta_gol", "Gol in una stagione", $S, "Più gol in una sola stagione", "", 0, $mk($sta, fn($x) => $x["gol"], true));
    $lista[] = rec_scheda("sta_assist", "Assist in una stagione", $S, "Più assist in una sola stagione", "", 0, $mk($sta, fn($x) => $x["assist"], true));
    $lista[] = rec_scheda("sta_presenze", "Presenze in una stagione", $S, "Più partite giocate in una sola stagione", "", 0, $mk($sta, fn($x) => $x["giocate"], true));
    $lista[] = rec_scheda("sta_media", "Fantamedia in una stagione", $S, "Minimo " . REC_MIN_PRESENZE_MEDIA_STAGIONE . " presenze", "", 2,
        $mk($sta, fn($x) => $x["giocate"] >= REC_MIN_PRESENZE_MEDIA_STAGIONE ? round($x["totale"] / $x["giocate"], 2) : null, true));

    // Miglior fantavoto in una singola partita
    $best = query_all("SELECT stagione, giornata, id_giocatore, id_squadra, totale FROM $tab
                       WHERE giocata = 1 AND rufficio = 0 AND id_giocatore > 0
                       ORDER BY totale DESC LIMIT 400");
    $items = [];
    foreach ($best as $b) {
        $st = (int) $b["stagione"]; $id = (int) $b["id_giocatore"]; $k = "$st-$id";
        $e = $edizioni[$k] ?? ["nome" => "Giocatore #$id", "ruolo" => 0];
        if ($ruolo !== 0 && $e["ruolo"] !== $ruolo) continue;
        $p = $persona[$k] ?? ["nome" => $e["nome"]];
        $sq = $squadre["$st-" . (int) $b["id_squadra"]] ?? null;
        $items[] = ["id" => $id, "stagione" => $st, "nome" => $p["nome"], "ruolo" => $e["ruolo"],
                    "valore" => round((float) $b["totale"], 2), "squadra" => $sq["nome"] ?? null, "logo" => $sq["logo"] ?? null,
                    "giocate" => null, "media" => null, "stagione_rif" => $st, "giornata" => (int) $b["giornata"]];
    }
    $lista[] = rec_scheda("par_migliore", "Miglior prestazione", "Singola partita", "Più alto fantapunteggio in una partita", "", 2, rec_ordina($items, $max));

    // Mercato: crediti pagati per edizione
    $prezzi = query_all("SELECT g.stagione, g.id, g.descrizione AS nome, g.ruolo, g.squadra AS id_squadra, g.crediti
                         FROM NEW_GIOCATORI g WHERE g.crediti > 0 AND g.squadra > 0
                         UNION ALL
                         SELECT b.stagione, b.id_giocatore, b.nome, b.ruolo, b.id_squadra_lega, b.crediti
                         FROM NEW_GIOCATORI_BASE_ASTA b
                         WHERE b.crediti > 0 AND b.id_squadra_lega > 0
                           AND NOT EXISTS (SELECT 1 FROM NEW_GIOCATORI g WHERE g.stagione = b.stagione AND g.id = b.id_giocatore AND g.crediti > 0)");
    $ed = []; $tot = [];
    foreach ($prezzi as $r) {
        $st = (int) $r["stagione"]; $id = (int) $r["id"]; $k = "$st-$id";
        $p = $persona[$k] ?? ["chiave" => "n:" . rg_norm((string) $r["nome"]), "nome" => trim((string) $r["nome"])];
        $sq = $squadre["$st-" . (int) $r["id_squadra"]] ?? null;
        $x = ["id" => $id, "stagione" => $st, "nome" => $p["nome"], "ruolo" => (int) $r["ruolo"],
              "valore" => (int) $r["crediti"], "squadra" => $sq["nome"] ?? null, "logo" => $sq["logo"] ?? null,
              "giocate" => null, "media" => null, "stagione_rif" => $st];
        if ($ruolo === 0 || $x["ruolo"] === $ruolo) $ed[] = $x;
        $c = $p["chiave"];
        if (!isset($tot[$c])) {
            $tot[$c] = $x + ["_ult" => $st];
        } else {
            $somma = $tot[$c]["valore"] + (int) $r["crediti"];
            if ($st >= $tot[$c]["_ult"]) $tot[$c] = $x + ["_ult" => $st];   // intestazione: edizione più recente
            $tot[$c]["valore"] = $somma;
        }
    }
    $M = "Mercato";
    $lista[] = rec_scheda("mer_piu_pagato", "Il più pagato", $M, "Crediti pagati per una singola edizione", "cr.", 0, rec_ordina($ed, $max));
    if ($ruolo === 0) {
        $perRuolo = [];
        for ($r = 1; $r <= 4; $r++) {
            $c = array_values(array_filter($ed, fn($x) => $x["ruolo"] === $r));
            $o = rec_ordina($c, 1);
            if ($o["righe"]) { $x = $o["righe"][0]; $x["pos"] = 1; $perRuolo[] = $x; }
        }
        $lista[] = rec_scheda("mer_per_ruolo", "Il più pagato per reparto", $M, "Il record di spesa di ogni ruolo", "cr.", 0,
            ["righe" => $perRuolo, "record" => []]);
    }
    $totItems = array_values(array_filter($tot, fn($x) => $ruolo === 0 || $x["ruolo"] === $ruolo));
    $lista[] = rec_scheda("mer_spesa_carriera", "Spesa totale in carriera", $M, "Crediti complessivi pagati per lo stesso giocatore", "cr.", 0, rec_ordina($totItems, $max));

    return $lista;
}

/** Elenco delle stagioni presenti nelle votazioni. */
function rec_stagioni(): array
{
    return array_map(fn($r) => (int) $r["s"], query_all("SELECT DISTINCT stagione AS s FROM NEW_VOTI ORDER BY stagione"));
}
