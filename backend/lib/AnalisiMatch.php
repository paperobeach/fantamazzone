<?php
// ============================================================
// backend/lib/AnalisiMatch.php
//
// Area "Analizza match passati" della Dashboard squadra.
//
// Per una partita già giocata (campionato o Champions) di una squadra
// produce quattro blocchi:
//   performance        metriche della giornata della squadra a confronto con la
//                      media della stagione (sopra / in linea / sotto la media)
//   performance_avv    lo stesso per la formazione dell'avversario
//   scelte             formazione schierata vs migliore formazione possibile,
//                      bonus persi, gol possibili, percentile della formazione
//                      scelta tra TUTTE le formazioni possibili ed esiti
//                      (vinte / pareggiate / perse) contro la formazione
//                      realmente schierata dall'avversario
//   scelte_avv         bonus persi, percentile ed esiti per l'avversario
//                      (contro la formazione realmente schierata dalla squadra)
//
// DEFINIZIONI
//   Formazione schierata   i giocatori che hanno concorso al punteggio
//                          (NEW_VOTI / NEW_VOTI_CHAMP, giocata = 1), cioè i
//                          titolari con voto, i panchinari subentrati e le
//                          riserve d'ufficio.
//   Formazioni possibili   tutte le combinazioni di 11 giocatori (1 portiere +
//                          difensori/centrocampisti/attaccanti di un MODULO
//                          VALIDO, vedi AM_MODULI) scelti tra i giocatori
//                          della rosa alla giornata (storico: RosaStorico.php)
//                          che hanno ottenuto un voto reale in quella giornata
//                          (NEW_VOTI_SERIE_A) o hanno concorso al punteggio.
//                          Chi non ha un voto non migliorerebbe mai una
//                          formazione: la sostituzione da panchina è un
//                          meccanismo del calcolo giornata, non una scelta.
//   Fantamedia             media dei fantavoti (voto + bonus/malus) degli 11
//                          giocatori = somma / 11.
//   Migliore formazione    quella con la fantamedia massima tra tutte le
//                          formazioni possibili (a parità, il punteggio finale
//                          di squadra più alto contro l'avversario reale).
//   Punteggio finale       fantavoti + modificatori di difesa, centrocampo e
//                          attacco + fattore campo, calcolato con le STESSE
//                          regole del calcolo giornata (CalcolatoreVoti) e
//                          convertito in gol con le fasce della stagione e le
//                          regole correttive a/b/c.
//   Percentile             quota di formazioni possibili con fantamedia
//                          inferiore a quella schierata (a parità, metà del
//                          gruppo): 100 = la migliore possibile.
//   Esiti                  per ogni formazione possibile si ricalcola la
//                          partita contro la formazione REALE dell'avversario
//                          (che quindi non cambia): V / N / P.
//
// La parte di calcolo è priva di accesso al database (come CalcolatoreVoti):
// le funzioni am_* "pure" ricevono solo dati e configurazione.
//
// Va incluso DOPO connect.php, lib/StatoGiornata.php,
// lib/ChampionsCalendario.php e lib/FormazioniChampions.php.
// ============================================================

require_once __DIR__ . "/CalcolatoreVoti.php";
require_once __DIR__ . "/RegoleCalcolo.php";
require_once __DIR__ . "/RosaStorico.php";
require_once __DIR__ . "/DashboardSquadra.php";

/** Moduli validi: nome => [difensori, centrocampisti, attaccanti] (allineato a frontend/src/lib/pitchLayout.js). */
const AM_MODULI = [
    "3-4-3" => [3, 4, 3],
    "4-4-2" => [4, 4, 2],
    "5-4-1" => [5, 4, 1],
    "4-3-3" => [4, 3, 3],
    "5-3-2" => [5, 3, 2],
    "4-5-1" => [4, 5, 1],
    "3-5-2" => [3, 5, 2],
];

/** Oltre questo numero di formazioni da valutare si rinuncia (tempo di risposta). */
const AM_MAX_COMBINAZIONI = 4000000;

/** Metriche della performance di giornata: codice, etichetta, gruppo, direzione migliore, tolleranza minima, decimali. */
const AM_METRICHE = [
    ["codice" => "ftotale",       "label" => "Fantapunti",                  "gruppo" => "Risultato",    "migliore" => "alto",  "tol" => 1.0,  "dec" => 1],
    ["codice" => "gol_fatti",     "label" => "Gol fatti",                   "gruppo" => "Risultato",    "migliore" => "alto",  "tol" => 0.5,  "dec" => 0],
    ["codice" => "gol_subiti",    "label" => "Gol subiti",                  "gruppo" => "Risultato",    "migliore" => "basso", "tol" => 0.5,  "dec" => 0],
    ["codice" => "fp_subiti",     "label" => "Fantapunti dell'avversario",  "gruppo" => "Risultato",    "migliore" => "basso", "tol" => 1.0,  "dec" => 1],
    ["codice" => "fv_giocatori",  "label" => "Fantavoti dei giocatori",     "gruppo" => "Giocatori",    "migliore" => "alto",  "tol" => 1.0,  "dec" => 1],
    ["codice" => "voto_medio",    "label" => "Voto medio degli schierati",  "gruppo" => "Giocatori",    "migliore" => "alto",  "tol" => 0.15, "dec" => 2],
    ["codice" => "bonus_netto",   "label" => "Bonus/malus netti",           "gruppo" => "Giocatori",    "migliore" => "alto",  "tol" => 0.5,  "dec" => 1],
    ["codice" => "mod_dif",       "label" => "Modificatore difesa",         "gruppo" => "Modificatori", "migliore" => "alto",  "tol" => 0.5,  "dec" => 1],
    ["codice" => "mod_cc",        "label" => "Modificatore centrocampo",    "gruppo" => "Modificatori", "migliore" => "alto",  "tol" => 0.5,  "dec" => 1],
    ["codice" => "mod_att",       "label" => "Modificatore attacco",        "gruppo" => "Modificatori", "migliore" => "alto",  "tol" => 0.5,  "dec" => 1],
    ["codice" => "fv_p",          "label" => "Fantavoto medio portiere",    "gruppo" => "Reparti",      "migliore" => "alto",  "tol" => 0.5,  "dec" => 2],
    ["codice" => "fv_d",          "label" => "Fantavoto medio difesa",      "gruppo" => "Reparti",      "migliore" => "alto",  "tol" => 0.3,  "dec" => 2],
    ["codice" => "fv_c",          "label" => "Fantavoto medio centrocampo", "gruppo" => "Reparti",      "migliore" => "alto",  "tol" => 0.3,  "dec" => 2],
    ["codice" => "fv_a",          "label" => "Fantavoto medio attacco",     "gruppo" => "Reparti",      "migliore" => "alto",  "tol" => 0.3,  "dec" => 2],
];

// ════════════════════════════════════════════════════════════
// PARTE PURA (nessun accesso al database)
// ════════════════════════════════════════════════════════════

/** Ruolo (1 P, 2 D, 3 C, 4 A) di un giocatore; le riserve d'ufficio hanno ID negativo = -(ruolo*1000000 + ...). */
function am_ruolo_da_id(int $id, ?int $ruoloAnagrafica): int
{
    if ($id < 0) return (int) floor(-$id / 1000000);
    return (int) ($ruoloAnagrafica ?? 0);
}

/** Gol dalla fascia di punteggio (stessa logica di calcolo_giornata.php::gol_da_punteggio). */
function am_gol_da_punteggio(float $punteggio, array $fasce): int
{
    foreach ($fasce as $f) {
        $da = $f["da"] ?? null;
        $a  = $f["a"]  ?? null;
        if ($da !== null && $punteggio < (float) $da) continue;
        if ($a  !== null && $punteggio > (float) $a)  continue;
        return (int) ($f["gol"] ?? 0);
    }
    return 0;
}

/**
 * Gol finali delle due squadre dai punteggi: fasce + regole correttive a/b/c.
 * @return array [golA, golB]
 */
function am_gol_partita(float $ptA, float $ptB, array $cfg): array
{
    $gA = am_gol_da_punteggio($ptA, $cfg["fasce"]);
    $gB = am_gol_da_punteggio($ptB, $cfg["fasce"]);
    $e = CalcolatoreVoti::applicaRegoleGolPartita($ptA, $ptB, $gA, $gB, $cfg["regole_gol"]);
    return [(int) $e["golf_casa"], (int) $e["golf_ospite"]];
}

/** 1 = vittoria, 0 = pareggio, -1 = sconfitta di A su B. */
function am_segno_gol(int $a, int $b): int
{
    return $a <=> $b;
}

function am_lettera_segno(int $s): string
{
    return $s > 0 ? "V" : ($s < 0 ? "P" : "N");
}

/** Tutte le combinazioni (indici crescenti) di $k elementi su $n. */
function am_combinazioni(int $n, int $k): array
{
    if ($k < 0 || $k > $n) return [];
    if ($k === 0) return [[]];
    $idx = range(0, $k - 1);
    $out = [];
    while (true) {
        $out[] = $idx;
        $i = $k - 1;
        while ($i >= 0 && $idx[$i] === $n - $k + $i) $i--;
        if ($i < 0) break;
        $idx[$i]++;
        for ($j = $i + 1; $j < $k; $j++) $idx[$j] = $idx[$j - 1] + 1;
    }
    return $out;
}

/**
 * Profilo di una formazione (insieme di giocatori) per il calcolo dei modificatori:
 * ciò che serve all'AVVERSARIO per calcolare i propri (voti puri, numeri, gol).
 *
 * @param array $giocatori elenco di ['ruolo','voto','fv','gf']
 */
function am_profilo(array $giocatori, array $calc): array
{
    $fv = 0.0; $votiD = []; $sommaC = 0.0; $nC = 0; $attMod = 0.0;
    $n = ["P" => 0, "D" => 0, "C" => 0, "A" => 0];
    foreach ($giocatori as $g) {
        $fv += (float) $g["fv"];
        switch ((int) $g["ruolo"]) {
            case 1: $n["P"]++; break;
            case 2: $n["D"]++; $votiD[] = (float) $g["voto"]; break;
            case 3: $n["C"]++; $sommaC += (float) $g["voto"]; $nC++; break;
            case 4:
                $n["A"]++;
                if ((int) ($g["gf"] ?? 0) === 0) {
                    $attMod += CalcolatoreVoti::bonusAttaccoSenzaGol((float) $g["voto"], $calc["attacco"] ?? []);
                }
                break;
        }
    }
    return [
        "fv_sum"    => round($fv, 2),
        "media_dif" => $votiD ? round(array_sum($votiD) / count($votiD), 2) : 0.0,
        "n_dif"     => count($votiD),
        "cc_sum"    => round($sommaC, 2),
        "cc_n"      => $nC,
        "att_mod"   => round($attMod, 2),
        "modulo"    => "{$n["D"]}-{$n["C"]}-{$n["A"]}",
        "n"         => $n,
        "n_tot"     => count($giocatori),
    ];
}

/**
 * Punteggi finali di due squadre date le due formazioni (profili): stesso calcolo di
 * CalcolatoreVoti::calcolaPartita + fattore campo.
 * @return array [punteggioMio, punteggioAvversario]
 */
function am_punteggi_profili(array $mio, array $avv, array $calc, float $fcMio, float $fcAvv): array
{
    $modDifRicevuto = CalcolatoreVoti::modificatoreDifesa($avv["media_dif"], $avv["n_dif"], $calc["difesa"]);
    $modDifConcesso = CalcolatoreVoti::modificatoreDifesa($mio["media_dif"], $mio["n_dif"], $calc["difesa"]);
    $cc = CalcolatoreVoti::modificatoreCentrocampo(
        $mio["cc_sum"], $mio["cc_n"], $avv["cc_sum"], $avv["cc_n"], $calc["centrocampo"]
    );
    $mia = round($mio["fv_sum"] + $modDifRicevuto + $cc["casa"] + $mio["att_mod"] + $fcMio, 2);
    $suo = round($avv["fv_sum"] + $modDifConcesso + $cc["ospite"] + $avv["att_mod"] + $fcAvv, 2);
    return [$mia, $suo];
}

/**
 * Valuta TUTTE le formazioni possibili di una squadra contro la formazione fissa
 * dell'avversario.
 *
 * @param array $pool       giocatori disponibili per ruolo: [1 => [...], 2 => [...], 3 => [...], 4 => [...]],
 *                          ciascuno ['id','voto','fv','gf']
 * @param array $fisso      profilo (am_profilo) della formazione avversaria reale
 * @param array $cfg        ['calc' => config di caricaConfigurazioneCalcolo, 'fasce', 'regole_gol']
 * @param float $fcMio      fattore campo della squadra valutata (0 se in trasferta)
 * @param float $fcAvv      fattore campo dell'avversario
 * @param float $fantaReale somma dei fantavoti della formazione realmente schierata
 * @param float $puntiReali punteggio finale (calcolato) della formazione realmente schierata
 *
 * @return array|null null se non esiste alcuna formazione valida (rosa/voti insufficienti);
 *                    ['troppe' => n] se le combinazioni superano AM_MAX_COMBINAZIONI.
 */
function am_valuta_formazioni(array $pool, array $fisso, array $cfg, float $fcMio, float $fcAvv,
                              float $fantaReale, float $puntiReali): ?array
{
    $calc = $cfg["calc"];
    $eps = 1e-6;
    $kMio = CalcolatoreVoti::modificatoreDifesa($fisso["media_dif"], $fisso["n_dif"], $calc["difesa"]) + $fcMio;
    $kAvv = $fisso["fv_sum"] + $fisso["att_mod"] + $fcAvv;

    $G = array_values($pool[1] ?? []);
    $D = array_values($pool[2] ?? []);
    $C = array_values($pool[3] ?? []);
    $A = array_values($pool[4] ?? []);
    if (!$G) return null;

    // Moduli realizzabili e dimensione dello spazio di ricerca
    $moduli = [];
    $totale = 0;
    foreach (AM_MODULI as $nome => [$d, $c, $a]) {
        if (count($D) < $d || count($C) < $c || count($A) < $a) continue;
        $nd = count(am_combinazioni(count($D), $d));
        $nc = count(am_combinazioni(count($C), $c));
        $na = count(am_combinazioni(count($A), $a));
        $moduli[$nome] = [$d, $c, $a];
        $totale += $nd * $nc * $na * count($G);
        if ($totale > AM_MAX_COMBINAZIONI) return ["troppe" => $totale];
    }
    if (!$moduli) return null;

    $stat = [
        "totale" => 0, "V" => 0, "N" => 0, "P" => 0,
        "fanta_sotto" => 0, "fanta_uguale" => 0, "fanta_sopra" => 0,
        "punti_sotto" => 0, "punti_uguale" => 0, "punti_sopra" => 0,
    ];
    $best = null;   // [fanta, punti, modulo, g, D idx, C idx, A idx]
    $cacheEsito = [];

    foreach ($moduli as $nome => [$d, $c, $a]) {
        // Difensori: somma fantavoti e modificatore che la difesa concede all'avversario
        $DL = [];
        foreach (am_combinazioni(count($D), $d) as $idx) {
            $fv = 0.0; $somma = 0.0;
            foreach ($idx as $i) { $fv += $D[$i]["fv"]; $somma += $D[$i]["voto"]; }
            $DL[] = [
                "fv" => $fv, "idx" => $idx,
                "modAvv" => CalcolatoreVoti::modificatoreDifesa(round($somma / $d, 2), $d, $calc["difesa"]),
            ];
        }
        // Centrocampisti: somma fantavoti e modificatore (mio / avversario)
        $CL = [];
        foreach (am_combinazioni(count($C), $c) as $idx) {
            $fv = 0.0; $somma = 0.0;
            foreach ($idx as $i) { $fv += $C[$i]["fv"]; $somma += $C[$i]["voto"]; }
            $m = CalcolatoreVoti::modificatoreCentrocampo(round($somma, 2), $c, $fisso["cc_sum"], $fisso["cc_n"], $calc["centrocampo"]);
            $CL[] = ["fv" => $fv, "idx" => $idx, "modMio" => $m["casa"], "modAvv" => $m["ospite"]];
        }
        // Attaccanti + portiere (indipendenti da difesa e centrocampo): coppie precalcolate
        $AL = [];
        foreach (am_combinazioni(count($A), $a) as $idx) {
            $fv = 0.0; $mod = 0.0;
            foreach ($idx as $i) {
                $fv += $A[$i]["fv"];
                if ((int) $A[$i]["gf"] === 0) {
                    $mod += CalcolatoreVoti::bonusAttaccoSenzaGol((float) $A[$i]["voto"], $calc["attacco"] ?? []);
                }
            }
            $AL[] = ["fv" => $fv, "mod" => $mod, "idx" => $idx];
        }
        $GA = [];
        foreach ($G as $gi => $g) {
            foreach ($AL as $ai => $al) {
                $GA[] = ["fv" => $g["fv"] + $al["fv"], "sc" => $g["fv"] + $al["fv"] + $al["mod"], "g" => $gi, "a" => $ai];
            }
        }

        foreach ($DL as $di => $dl) {
            foreach ($CL as $ci => $cl) {
                $baseF = $dl["fv"] + $cl["fv"];
                $baseM = $baseF + $cl["modMio"] + $kMio;
                $opp = round($dl["modAvv"] + $cl["modAvv"] + $kAvv, 2);
                $kOpp = (int) round($opp * 100);
                foreach ($GA as $x) {
                    $fanta = $baseF + $x["fv"];
                    $mia = round($baseM + $x["sc"], 2);

                    $kMia = (int) round($mia * 100);
                    if (!isset($cacheEsito[$kOpp][$kMia])) {
                        [$gm, $go] = am_gol_partita($mia, $opp, $cfg);
                        $cacheEsito[$kOpp][$kMia] = am_segno_gol($gm, $go);
                    }
                    $s = $cacheEsito[$kOpp][$kMia];

                    $stat["totale"]++;
                    if ($s > 0) $stat["V"]++; elseif ($s < 0) $stat["P"]++; else $stat["N"]++;

                    if ($fanta < $fantaReale - $eps)      $stat["fanta_sotto"]++;
                    elseif ($fanta <= $fantaReale + $eps) $stat["fanta_uguale"]++;
                    else                                  $stat["fanta_sopra"]++;
                    if ($mia < $puntiReali - $eps)        $stat["punti_sotto"]++;
                    elseif ($mia <= $puntiReali + $eps)   $stat["punti_uguale"]++;
                    else                                  $stat["punti_sopra"]++;

                    if ($best === null || $fanta > $best[0] + 1e-9 || (abs($fanta - $best[0]) <= 1e-9 && $mia > $best[1])) {
                        $best = [$fanta, $mia, $nome, $x["g"], $dl["idx"], $cl["idx"], $AL[$x["a"]]["idx"], $opp];
                    }
                }
            }
        }
    }

    [$fantaB, $puntiB, $nomeB, $gB, $idxD, $idxC, $idxA, $puntiAvvB] = $best;
    $scelti = [$G[$gB]];
    foreach ($idxD as $i) $scelti[] = $D[$i];
    foreach ($idxC as $i) $scelti[] = $C[$i];
    foreach ($idxA as $i) $scelti[] = $A[$i];

    return [
        "stat" => $stat,
        "migliore" => [
            "modulo"        => $nomeB,
            "ids"           => array_map(fn($p) => $p["id"], $scelti),
            "somma"         => round($fantaB, 2),
            "punti"         => $puntiB,
            "punti_avv"     => $puntiAvvB,
        ],
    ];
}

/** Percentile (0-100) con metà dei pari merito, e posizione in graduatoria (1 = la migliore). */
function am_percentile(int $sotto, int $uguale, int $sopra): array
{
    $n = $sotto + $uguale + $sopra;
    if ($n === 0) return ["percentile" => null, "posizione" => null];
    return [
        "percentile" => (int) round(($sotto + $uguale / 2) / $n * 100),
        "posizione"  => $sopra + 1,
    ];
}

/**
 * Valutazione di un valore rispetto alla media della stagione.
 * "In linea" entro max(tolleranza minima, mezza deviazione standard) dalla media.
 */
function am_valuta_metrica(?float $v, array $altri, float $tolMin, string $migliore): array
{
    $n = count($altri);
    if ($v === null || $n < 1) {
        return ["media" => null, "delta" => null, "verdetto" => null, "giudizio" => null, "n_rif" => $n];
    }
    $media = array_sum($altri) / $n;
    $sd = 0.0;
    if ($n >= 3) {
        $q = 0.0;
        foreach ($altri as $x) $q += ($x - $media) ** 2;
        $sd = sqrt($q / $n);
    }
    $tol = max($tolMin, 0.5 * $sd);
    $delta = $v - $media;
    if (abs($delta) <= $tol) {
        $verdetto = "in_linea"; $giudizio = "neutro";
    } else {
        $verdetto = $delta > 0 ? "sopra" : "sotto";
        $giudizio = (($delta > 0) === ($migliore === "alto")) ? "positivo" : "negativo";
    }
    return [
        "media" => round($media, 3), "delta" => round($delta, 3),
        "verdetto" => $verdetto, "giudizio" => $giudizio, "n_rif" => $n,
    ];
}

/**
 * Metriche di UNA giornata di una squadra.
 *
 * @param array $ris   riga di risultato (ftotale, ftotale_a, golf, gols, modificatore_a, mod_cc, mod_att)
 * @param array $voti  giocatori che hanno concorso al punteggio: ['ruolo','voto','totale']
 */
function am_metriche_giornata(array $ris, array $voti): array
{
    $n = count($voti);
    $fv = 0.0; $vp = 0.0; $bonus = 0.0;
    $perRuolo = [1 => [], 2 => [], 3 => [], 4 => []];
    foreach ($voti as $v) {
        $fv += (float) $v["totale"];
        $vp += (float) $v["voto"];
        $bonus += (float) $v["totale"] - (float) $v["voto"];
        if (isset($perRuolo[(int) $v["ruolo"]])) $perRuolo[(int) $v["ruolo"]][] = (float) $v["totale"];
    }
    $media = fn(array $x) => $x ? array_sum($x) / count($x) : null;
    return [
        "ftotale"      => (float) $ris["ftotale"],
        "gol_fatti"    => (float) $ris["golf"],
        "gol_subiti"   => (float) $ris["gols"],
        "fp_subiti"    => (float) $ris["ftotale_a"],
        "fv_giocatori" => $n ? round($fv, 2) : null,
        "voto_medio"   => $n ? round($vp / $n, 3) : null,
        "bonus_netto"  => $n ? round($bonus, 2) : null,
        // Il modificatore difesa RICEVUTO dalla squadra è salvato con segno invertito in modificatore_a
        "mod_dif"      => 0 - (float) $ris["modificatore_a"],
        "mod_cc"       => (float) $ris["mod_cc"],
        "mod_att"      => (float) $ris["mod_att"],
        "fv_p"         => $media($perRuolo[1]),
        "fv_d"         => $media($perRuolo[2]),
        "fv_c"         => $media($perRuolo[3]),
        "fv_a"         => $media($perRuolo[4]),
    ];
}

/**
 * Performance di una giornata a confronto con le altre giornate della stagione.
 *
 * @param array $perGiornata giornata => metriche (am_metriche_giornata)
 */
function am_performance(array $perGiornata, int $giornata): ?array
{
    if (!isset($perGiornata[$giornata])) return null;
    $righe = [];
    $conteggio = ["positivo" => 0, "neutro" => 0, "negativo" => 0];
    $nRif = 0;
    foreach (AM_METRICHE as $m) {
        $v = $perGiornata[$giornata][$m["codice"]] ?? null;
        $altri = [];
        foreach ($perGiornata as $g => $met) {
            if ($g === $giornata) continue;
            if (($met[$m["codice"]] ?? null) !== null) $altri[] = (float) $met[$m["codice"]];
        }
        $val = am_valuta_metrica($v === null ? null : (float) $v, $altri, $m["tol"], $m["migliore"]);
        if ($val["giudizio"] !== null) $conteggio[$val["giudizio"]]++;
        $nRif = max($nRif, $val["n_rif"]);
        $righe[] = array_merge([
            "codice" => $m["codice"], "label" => $m["label"], "gruppo" => $m["gruppo"],
            "migliore" => $m["migliore"], "dec" => $m["dec"],
            "valore" => $v === null ? null : round((float) $v, 3),
        ], $val);
    }
    $ft = null;
    foreach ($righe as $r) if ($r["codice"] === "ftotale") $ft = $r;
    return [
        "giornata"   => $giornata,
        "n_rif"      => $nRif,
        "metriche"   => $righe,
        "sintesi"    => [
            "positive" => $conteggio["positivo"],
            "neutre"   => $conteggio["neutro"],
            "negative" => $conteggio["negativo"],
            "verdetto" => $ft["verdetto"] ?? null,     // sul solo punteggio di squadra
            "giudizio" => $ft["giudizio"] ?? null,
        ],
    ];
}

/** Bonus positivi dei giocatori NON schierati (giocatori con voto che non hanno concorso al punteggio). */
function am_bonus_persi(array $nonSchierati): array
{
    $tot = 0.0; $gol = 0; $assist = 0; $rigParati = 0; $rigReal = 0;
    $elenco = [];
    foreach ($nonSchierati as $p) {
        $bonus = round($p["fv"] - $p["voto"], 2);
        if ($bonus <= 0) continue;
        $tot += $bonus;
        $gol += (int) $p["gf"]; $assist += (int) $p["ass"];
        $rigParati += (int) $p["rp"]; $rigReal += (int) $p["rf"];
        $elenco[] = [
            "id_giocatore" => $p["id"], "giocatore" => $p["nome"], "ruolo" => $p["ruolo"],
            "voto" => $p["voto"], "fantavoto" => $p["fv"], "bonus" => $bonus,
            "gol" => (int) $p["gf"], "assist" => (int) $p["ass"],
            "rigori_parati" => (int) $p["rp"], "rigori_realizzati" => (int) $p["rf"],
            "posizione" => $p["maglia"] !== null && $p["maglia"] > 11 ? "panchina" : "non schierato",
        ];
    }
    usort($elenco, fn($a, $b) => $b["bonus"] <=> $a["bonus"]);
    return [
        "totale" => round($tot, 2), "gol" => $gol, "assist" => $assist,
        "rigori_parati" => $rigParati, "rigori_realizzati" => $rigReal,
        "giocatori" => $elenco,
    ];
}

// ════════════════════════════════════════════════════════════
// ACCESSO AI DATI
// ════════════════════════════════════════════════════════════

/** Configurazione di calcolo della stagione pronta per l'analisi; null se non configurata. */
function am_config(int $stagione): ?array
{
    global $conn;
    try {
        $calc = caricaConfigurazioneCalcolo($conn, $stagione);
    } catch (Throwable $e) {
        return null;
    }
    $fasce = json_decode($calc["parametri"]["FASCE_GOL_PUNTEGGIO"] ?? "", true)
        ?: [["da" => null, "a" => null, "gol" => 0]];
    $num = function (string $codice, float $default) use ($calc): float {
        $v = $calc["parametri"][$codice] ?? null;
        return ($v === null || trim((string) $v) === "" || !is_numeric($v)) ? $default : (float) $v;
    };
    return [
        "calc"  => $calc,
        "fasce" => $fasce,
        "regole_gol" => [
            "diff_min"        => $num("GOL_DISTACCO_MIN_PUNTI", 3),
            "soglia_pareggio" => $num("GOL_SOGLIA_PAREGGIO", 66),
            "soglia_omaggio"  => $num("GOL_SOGLIA_OMAGGIO", 59),
        ],
    ];
}

/** Anagrafica (anche dei ceduti) di un elenco di ID: id => [descrizione, ruolo]. */
function am_anagrafica(int $stagione, array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map("intval", $ids), fn($i) => $i > 0)));
    if (!$ids) return [];
    $lista = implode(",", $ids);
    $out = [];
    foreach (query_all("SELECT g.id, g.descrizione, g.ruolo FROM " . rs_sql_anagrafica($stagione) . " g
                        WHERE g.id IN ($lista)") as $a) {
        $out[(int) $a["id"]] = ["nome" => $a["descrizione"], "ruolo" => (int) $a["ruolo"]];
    }
    return $out;
}

/** Riga di risultato di una squadra, per giornata (campionato o Champions). */
function am_risultati_squadra(int $stagione, int $id, bool $champ): array
{
    $cols = "giornata, ftotale, ftotale_a, golf, gols, segno, modificatore, modificatore_a,
             fattore_campo, mod_att, mod_cc, id_squadra_a";
    $sql = $champ
        ? "SELECT $cols FROM NEW_RISULTATI_CHAMP WHERE stagione = $stagione AND squadra = $id"
        : "SELECT $cols FROM NEW_RISULTATI WHERE stagione = $stagione AND id_squadra = $id";
    $out = [];
    foreach (query_all($sql) as $r) $out[(int) $r["giornata"]] = $r;
    return $out;
}

/**
 * Giocatori che hanno concorso al punteggio di una squadra nelle giornate indicate
 * (giocata = 1), con ruolo: giornata => elenco di righe.
 * Le riserve d'ufficio (ID negativo) hanno ruolo ricavato dall'ID e nome generico.
 */
function am_voti_schierati(int $stagione, int $id, bool $champ, array $giornate): array
{
    $giornate = array_values(array_unique(array_map("intval", $giornate)));
    if (!$giornate) return [];
    $lista = implode(",", $giornate);
    $tab = $champ ? "NEW_VOTI_CHAMP" : "NEW_VOTI";
    $rows = query_all("SELECT giornata, id_giocatore, voto, totale, reti, retis, assist, ammonizioni,
                              espulsioni, autogol, rigores, rigorep, rufficio
                       FROM $tab
                       WHERE stagione = $stagione AND id_squadra = $id AND giocata = 1
                         AND giornata IN ($lista)");
    $anag = am_anagrafica($stagione, array_map(fn($r) => (int) $r["id_giocatore"], $rows));
    $out = [];
    foreach ($rows as $r) {
        $idg = (int) $r["id_giocatore"];
        $out[(int) $r["giornata"]][] = [
            "id" => $idg,
            "nome" => $idg < 0 ? "Riserva d'ufficio" : ($anag[$idg]["nome"] ?? "Giocatore $idg"),
            "ruolo" => am_ruolo_da_id($idg, $anag[$idg]["ruolo"] ?? null),
            "voto" => (float) $r["voto"], "fv" => (float) $r["totale"], "totale" => (float) $r["totale"],
            "gf" => (int) $r["reti"], "gs" => (int) $r["retis"], "ass" => (int) $r["assist"],
            "amm" => (int) $r["ammonizioni"], "esp" => (int) $r["espulsioni"], "au" => (int) $r["autogol"],
            "rufficio" => (int) $r["rufficio"] === 1,
        ];
    }
    return $out;
}

/**
 * Pool di giocatori disponibili per una squadra in una giornata: rosa alla giornata
 * (più chi ha concorso al punteggio) con un voto reale. Per ogni giocatore
 * stesse statistiche e fantavoto del calcolo giornata.
 *
 * @return array elenco di giocatori (vedi chiavi sotto)
 * @throws RuntimeException se mancano i voti Serie A della giornata
 */
function am_pool_giocatori(int $stagione, int $giornata, int $idSquadra, array $effettivi, string $tabFormazione, array $calc): array
{
    $periodi = rs_rosa_squadra($stagione, $idSquadra);
    $ids = [];
    foreach ($periodi as $id => $p) if (rs_in_rosa($p, $giornata)) $ids[(int) $id] = true;
    foreach ($effettivi as $e) if ($e["id"] > 0) $ids[$e["id"]] = true;

    // Posizione reale in formazione (maglia 1-11 titolare, oltre panchina)
    $maglie = [];
    foreach (query_all("SELECT ID_GIOCATORE AS id, MAGLIA AS maglia FROM $tabFormazione
                        WHERE STAGIONE = $stagione AND GIORNATA = $giornata AND ID_SQUADRA = $idSquadra") as $f) {
        $maglie[(int) $f["id"]] = (int) $f["maglia"];
        $ids[(int) $f["id"]] = true;
    }
    $ids = array_keys($ids);
    if (!$ids) return [];
    $lista = implode(",", array_map("intval", $ids));

    $serieA = [];
    foreach (query_all("SELECT id_giocatore, voto, sv, gf, gs, rp, rs, rf, au, amm, esp, ass
                        FROM NEW_VOTI_SERIE_A
                        WHERE stagione = $stagione AND giornata = $giornata AND id_giocatore IN ($lista)") as $v) {
        $serieA[(int) $v["id_giocatore"]] = $v;
    }
    if (!$serieA) {
        $n = query_one("SELECT COUNT(*) AS n FROM NEW_VOTI_SERIE_A WHERE stagione = $stagione AND giornata = $giornata");
        if ((int) ($n["n"] ?? 0) === 0) {
            throw new RuntimeException("Voti Serie A non disponibili per la giornata $giornata.");
        }
    }

    $anag = am_anagrafica($stagione, $ids);
    $eff = [];
    foreach ($effettivi as $e) $eff[$e["id"]] = $e;

    $out = [];
    foreach ($ids as $id) {
        $id = (int) $id;
        if (!isset($anag[$id])) continue;
        $s = $serieA[$id] ?? null;
        $haVoto = $s !== null && $s["sv"] !== "Y" && $s["voto"] !== null;
        $e = $eff[$id] ?? null;
        if (!$haVoto && $e === null) continue;

        if ($haVoto) {
            $stat = [
                "voto" => (float) $s["voto"], "sv" => false,
                "gf" => (int) $s["gf"], "gs" => (int) $s["gs"], "rp" => (int) $s["rp"], "rf" => (int) $s["rf"],
                "rs" => (int) $s["rs"], "au" => (int) $s["au"], "amm" => (int) $s["amm"],
                "esp" => (int) $s["esp"], "ass" => (int) $s["ass"],
            ];
            $fv = $e !== null ? $e["fv"] : CalcolatoreVoti::puntiGiocatore($stat, $calc["bonus"]);
            $voto = $e !== null ? $e["voto"] : $stat["voto"];
        } else {
            // Schierato ma senza riga nei voti Serie A: si usano i valori salvati
            $stat = ["gf" => $e["gf"], "gs" => $e["gs"], "rp" => 0, "rf" => 0, "rs" => 0,
                     "au" => $e["au"], "amm" => $e["amm"], "esp" => $e["esp"], "ass" => $e["ass"]];
            $fv = $e["fv"]; $voto = $e["voto"];
        }
        if ($fv === null) continue;

        $out[] = [
            "id" => $id, "nome" => $anag[$id]["nome"], "ruolo" => $anag[$id]["ruolo"],
            "voto" => (float) $voto, "fv" => (float) $fv,
            "gf" => $stat["gf"], "gs" => $stat["gs"], "rp" => $stat["rp"], "rf" => $stat["rf"],
            "rs" => $stat["rs"], "au" => $stat["au"], "amm" => $stat["amm"], "esp" => $stat["esp"], "ass" => $stat["ass"],
            "maglia" => $maglie[$id] ?? null,
            "effettivo" => $e !== null,
        ];
    }
    return $out;
}

/** Giocatore nel formato esposto dall'API. */
function am_scheda_giocatore(array $p, ?array $extra = null): array
{
    return array_merge([
        "id_giocatore" => $p["id"], "giocatore" => $p["nome"], "ruolo" => $p["ruolo"],
        "voto" => $p["voto"], "fantavoto" => $p["fv"], "bonus" => round($p["fv"] - $p["voto"], 2),
    ], $extra ?? []);
}

/**
 * Analisi delle scelte di UNA squadra: formazione schierata vs possibili.
 *
 * @param array $effettivi giocatori schierati (am_voti_schierati)
 * @param array $pool      giocatori disponibili (am_pool_giocatori)
 * @param array $fissoAvv  profilo (am_profilo) della formazione reale dell'avversario
 * @param array $ris       riga di risultato della squadra (golf, gols, ftotale, segno, fattore_campo)
 * @param float $fcAvv     fattore campo dell'avversario
 */
function am_scelte_squadra(array $effettivi, array $pool, array $fissoAvv, array $cfg, array $ris, float $fcAvv): array
{
    $calc = $cfg["calc"];
    $fcMio = (float) $ris["fattore_campo"];
    $profiloReale = am_profilo($effettivi, $calc);
    [$puntiModello, $puntiAvvModello] = am_punteggi_profili($profiloReale, $fissoAvv, $calc, $fcMio, $fcAvv);

    $perRuolo = [1 => [], 2 => [], 3 => [], 4 => []];
    foreach ($pool as $p) if (isset($perRuolo[$p["ruolo"]])) $perRuolo[$p["ruolo"]][] = $p;

    $val = am_valuta_formazioni($perRuolo, $fissoAvv, $cfg, $fcMio, $fcAvv, $profiloReale["fv_sum"], $puntiModello);
    if ($val === null) {
        return ["disponibile" => false, "motivo" => "Rosa o voti insufficienti per comporre una formazione valida."];
    }
    if (isset($val["troppe"])) {
        return ["disponibile" => false, "motivo" => "Troppe formazioni possibili da valutare (" . number_format($val["troppe"], 0, ",", ".") . ")."];
    }

    $stat = $val["stat"];
    $perId = [];
    foreach ($pool as $p) $perId[$p["id"]] = $p;

    $idsBest = array_flip($val["migliore"]["ids"]);
    $idsReali = [];
    foreach ($effettivi as $e) $idsReali[$e["id"]] = true;

    // Differenze: chi esce dalla formazione reale e chi entra nella migliore
    $usciti = []; $entrati = [];
    foreach ($effettivi as $e) {
        if (!isset($idsBest[$e["id"]])) $usciti[] = am_scheda_giocatore($e);
    }
    foreach ($val["migliore"]["ids"] as $id) {
        if (isset($idsReali[$id])) continue;
        $p = $perId[$id];
        $entrati[] = am_scheda_giocatore($p, [
            "gol" => $p["gf"], "assist" => $p["ass"],
            "posizione" => $p["maglia"] !== null && $p["maglia"] > 11 ? "panchina" : ($p["maglia"] !== null ? "titolare" : "non schierato"),
        ]);
    }
    $ordRuolo = fn($a, $b) => [$a["ruolo"], -$a["fantavoto"]] <=> [$b["ruolo"], -$b["fantavoto"]];
    usort($usciti, $ordRuolo);
    usort($entrati, $ordRuolo);

    $bestGiocatori = array_map(fn($id) => $perId[$id], $val["migliore"]["ids"]);
    usort($bestGiocatori, fn($a, $b) => [$a["ruolo"], -$a["fv"]] <=> [$b["ruolo"], -$b["fv"]]);
    $profiloBest = am_profilo($bestGiocatori, $calc);

    // Gol possibili con la formazione migliore (stessa catena del calcolo giornata)
    [$golBest, $golSubBest] = am_gol_partita($val["migliore"]["punti"], $val["migliore"]["punti_avv"], $cfg);
    $segnoBest = am_segno_gol($golBest, $golSubBest);

    // Formazione reale: gol e segno salvati (verità del campo)
    $golReali = (int) $ris["golf"]; $golSubReali = (int) $ris["gols"];
    $segnoReale = strtoupper(trim((string) $ris["segno"]));
    $segnoReale = $segnoReale === "W" ? "V" : ($segnoReale === "L" ? "P" : $segnoReale);

    $schierati = array_map(fn($e) => am_scheda_giocatore($e), $effettivi);
    usort($schierati, fn($a, $b) => [$a["ruolo"], -$a["fantavoto"]] <=> [$b["ruolo"], -$b["fantavoto"]]);

    $nonSchierati = array_values(array_filter($pool, fn($p) => !isset($idsReali[$p["id"]])));
    $pctFanta  = am_percentile($stat["fanta_sotto"], $stat["fanta_uguale"], $stat["fanta_sopra"]);
    $pctPunti  = am_percentile($stat["punti_sotto"], $stat["punti_uguale"], $stat["punti_sopra"]);
    $n = $stat["totale"];

    return [
        "disponibile" => true,
        "schierata" => [
            "modulo" => $profiloReale["modulo"], "n_giocatori" => $profiloReale["n_tot"],
            "somma" => $profiloReale["fv_sum"], "fantamedia" => round($profiloReale["fv_sum"] / 11, 3),
            "punteggio" => (float) $ris["ftotale"], "gol" => $golReali, "gol_subiti" => $golSubReali,
            "segno" => $segnoReale, "giocatori" => $schierati,
        ],
        "migliore" => [
            "modulo" => $val["migliore"]["modulo"], "somma" => $val["migliore"]["somma"],
            "fantamedia" => round($val["migliore"]["somma"] / 11, 3),
            "punteggio" => $val["migliore"]["punti"], "punteggio_avv" => $val["migliore"]["punti_avv"],
            "gol" => $golBest, "gol_subiti" => $golSubBest, "segno" => am_lettera_segno($segnoBest),
            "giocatori" => array_map(fn($p) => am_scheda_giocatore($p), $bestGiocatori),
        ],
        "differenze" => [
            "usciti" => $usciti, "entrati" => $entrati,
            "delta_somma" => round($val["migliore"]["somma"] - $profiloReale["fv_sum"], 2),
            "delta_fantamedia" => round(($val["migliore"]["somma"] - $profiloReale["fv_sum"]) / 11, 3),
            "delta_punteggio" => round($val["migliore"]["punti"] - $puntiModello, 2),
            "stessa_formazione" => !$usciti && !$entrati,
        ],
        "bonus_persi" => am_bonus_persi($nonSchierati),
        "gol_possibili" => [
            "gol_reali" => $golReali, "gol_subiti_reali" => $golSubReali,
            "gol_migliore" => $golBest, "gol_subiti_migliore" => $golSubBest,
            "differenza" => $golBest - $golReali,
        ],
        "percentile" => [
            "fantamedia" => $pctFanta["percentile"], "posizione" => $pctFanta["posizione"],
            "punteggio" => $pctPunti["percentile"], "posizione_punteggio" => $pctPunti["posizione"],
            "formazioni" => $n,
        ],
        "sintesi" => [
            "formazioni" => $n,
            "vincenti" => $stat["V"], "pareggianti" => $stat["N"], "perdenti" => $stat["P"],
            "perc_v" => $n ? round($stat["V"] / $n * 100, 1) : null,
            "perc_n" => $n ? round($stat["N"] / $n * 100, 1) : null,
            "perc_p" => $n ? round($stat["P"] / $n * 100, 1) : null,
            "esito_reale" => $segnoReale,
        ],
        // Il modello ricalcola il punteggio reale dalle formazioni: se si scosta da quello
        // salvato (dati storici incompleti, correzioni manuali) le stime sono approssimate.
        "scostamento_modello" => round($puntiModello - (float) $ris["ftotale"], 2),
    ];
}

/**
 * Costruisce la risposta completa dell'analisi di un match.
 *
 * @param callable $pub  giornata => bool (risultato pubblico)
 */
function am_analisi(int $stagione, int $idSquadra, bool $champ, ?int $giornataRichiesta, callable $pub): array
{
    $partite = $champ
        ? ds_partite_champ($stagione, $idSquadra, $pub)
        : ds_partite_camp($stagione, $idSquadra, $pub);
    $elenco = array_map(fn($p) => [
        "giornata" => $p["giornata"], "id_avv" => $p["id_avv"], "avversario" => $p["avversario"],
        "logo_avv" => $p["logo_avv"], "golf" => $p["golf"], "gols" => $p["gols"],
        "ftotale" => $p["ftotale"], "ftotale_a" => $p["ftotale_a"], "segno" => $p["segno"],
        "casa" => $p["casa"], "turno" => $p["turno"],
    ], $partite);

    $base = [
        "stagione" => $stagione, "competizione" => $champ ? "CHAMP" : "CAMP",
        "partite" => $elenco, "giornata" => null, "partita" => null,
    ];
    if (!$elenco) return $base;

    // Partita scelta (default: l'ultima giocata)
    $partita = end($elenco);
    if ($giornataRichiesta !== null) {
        $trovata = null;
        foreach ($elenco as $p) if ($p["giornata"] === $giornataRichiesta) $trovata = $p;
        if ($trovata === null) api_error("Partita non trovata per la giornata $giornataRichiesta", 404);
        $partita = $trovata;
    }
    $giornata = (int) $partita["giornata"];
    $idAvv = (int) $partita["id_avv"];
    $base["giornata"] = $giornata;
    $base["partita"] = $partita;

    $avv = ds_avversario($stagione, $idAvv);
    $base["avversario"] = $avv;

    // ── Performance: giornata vs media della stagione ────────
    // Si considerano le sole giornate pubbliche (chiuse) della squadra nella competizione.
    $perSquadra = function (int $id) use ($stagione, $champ, $pub): array {
        $ris = am_risultati_squadra($stagione, $id, $champ);
        $ris = array_filter($ris, fn($r, $g) => $pub((int) $g), ARRAY_FILTER_USE_BOTH);
        $voti = am_voti_schierati($stagione, $id, $champ, array_keys($ris));
        $out = [];
        foreach ($ris as $g => $r) $out[$g] = am_metriche_giornata($r, $voti[$g] ?? []);
        return $out;
    };
    $metMia = $perSquadra($idSquadra);
    $metAvv = $perSquadra($idAvv);
    $base["performance"]     = am_performance($metMia, $giornata);
    $base["performance_avv"] = am_performance($metAvv, $giornata);

    // ── Scelte ───────────────────────────────────────────────
    $cfg = am_config($stagione);
    $nonDisp = function (string $motivo): array { return ["disponibile" => false, "motivo" => $motivo]; };
    if ($cfg === null) {
        $m = "Regole di calcolo non configurate per la stagione $stagione.";
        $base["scelte"] = $nonDisp($m);
        $base["scelte_avv"] = $nonDisp($m);
        return $base;
    }

    $rMia = am_risultati_squadra($stagione, $idSquadra, $champ)[$giornata] ?? null;
    $rAvv = am_risultati_squadra($stagione, $idAvv, $champ)[$giornata] ?? null;
    $effMia = am_voti_schierati($stagione, $idSquadra, $champ, [$giornata])[$giornata] ?? [];
    $effAvv = am_voti_schierati($stagione, $idAvv, $champ, [$giornata])[$giornata] ?? [];
    if (!$rMia || !$rAvv || !$effMia || !$effAvv) {
        $m = "Dettaglio delle formazioni non disponibile per questa partita.";
        $base["scelte"] = $nonDisp($m);
        $base["scelte_avv"] = $nonDisp($m);
        return $base;
    }

    $tabMia = $champ ? formazione_tabella_champions($stagione, $giornata, $idSquadra) : FORMAZIONE_TAB_CAMPIONATO;
    $tabAvv = $champ ? formazione_tabella_champions($stagione, $giornata, $idAvv) : FORMAZIONE_TAB_CAMPIONATO;

    try {
        $poolMia = am_pool_giocatori($stagione, $giornata, $idSquadra, $effMia, $tabMia, $cfg["calc"]);
        $poolAvv = am_pool_giocatori($stagione, $giornata, $idAvv, $effAvv, $tabAvv, $cfg["calc"]);
    } catch (RuntimeException $e) {
        $base["scelte"] = $nonDisp($e->getMessage());
        $base["scelte_avv"] = $nonDisp($e->getMessage());
        return $base;
    }

    $profMia = am_profilo($effMia, $cfg["calc"]);
    $profAvv = am_profilo($effAvv, $cfg["calc"]);
    $base["scelte"]     = am_scelte_squadra($effMia, $poolMia, $profAvv, $cfg, $rMia, (float) $rAvv["fattore_campo"]);
    $base["scelte_avv"] = am_scelte_squadra($effAvv, $poolAvv, $profMia, $cfg, $rAvv, (float) $rMia["fattore_campo"]);
    return $base;
}
