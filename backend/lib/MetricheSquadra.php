<?php
// ============================================================
// backend/lib/MetricheSquadra.php
//
// Catalogo delle metriche della Dashboard squadra e loro calcolo per
// TUTTE le squadre della lega. Alimenta:
//   dashboard_metriche.php  -> statistiche selezionabili, sezioni
//                              "Confronta" e "Andamento"
//
// Modello: ogni metrica ha un valore PER PARTITA (null = non applicabile,
// per esempio "punti in casa" in una gara in trasferta) e una funzione di
// aggregazione che dà il valore della stagione:
//   sum   somma dei valori            (punti, vittorie, gol totali)
//   mean  media dei valori            (punti a partita, percentuali)
//   last  ultimo valore               (serie in corso)
//   max   valore massimo              (miglior fantapunteggio, record serie)
//   min   valore minimo               (peggior fantapunteggio)
// Dagli stessi valori per partita la UI ricava l'andamento: valore della
// singola giornata, valore progressivo (aggregazione sulle giornate fino a
// quella) e media mobile.
//
// Le percentuali sono espresse come 0 / 100 per partita (media = %).
// Le giornate non chiuse non entrano mai (callable $pub).
//
// Va incluso DOPO connect.php, lib/StatoGiornata.php,
// lib/ChampionsCalendario.php e lib/DashboardSquadra.php.
// ============================================================

require_once __DIR__ . "/RosaStorico.php";

const MS_REPARTI = [1 => "portieri", 2 => "difensori", 3 => "centrocampisti", 4 => "attaccanti"];

/** Definizione di una metrica. dir: up (più è alto meglio è) | down | neutral. */
function ms_def(string $key, string $label, string $gruppo, string $agg, string $dir,
                string $unita = "", int $dec = 2, string $descr = ""): array
{
    return [
        "key" => $key, "label" => $label, "gruppo" => $gruppo, "agg" => $agg,
        "dir" => $dir, "unita" => $unita, "dec" => $dec, "descr" => $descr,
    ];
}

/** Catalogo completo, nell'ordine in cui la UI lo presenta. */
function ms_catalogo(bool $champ = false): array
{
    $c = [];
    $G = "Risultati";
    // Posizione in classifica: solo campionato (la Champions ha gironi, non una classifica unica)
    if (!$champ) {
        $c[] = ms_def("posizione", "Posizione in classifica", $G, "last", "down", "", 0,
                      "Posizione dopo ogni giornata (classifica ricostruita dai risultati: punti, gol fatti, gol subiti; senza penalità)");
    }
    $c[] = ms_def("punti",         "Punti",            $G, "sum",  "up",      "", 0);
    $c[] = ms_def("punti_partita", "Punti a partita",  $G, "mean", "up",      "", 2);
    $c[] = ms_def("vittorie",      "Vittorie",         $G, "sum",  "up",      "", 0);
    $c[] = ms_def("pareggi",       "Pareggi",          $G, "sum",  "neutral", "", 0);
    $c[] = ms_def("sconfitte",     "Sconfitte",        $G, "sum",  "down",    "", 0);
    $c[] = ms_def("diff_reti",     "Differenza reti",  $G, "sum",  "up",      "", 0);

    $G = "Gol";
    $c[] = ms_def("gol_fatti",        "Gol fatti a partita",   $G, "mean", "up",      "", 2);
    $c[] = ms_def("gol_subiti",       "Gol subiti a partita",  $G, "mean", "down",    "", 2);
    $c[] = ms_def("gol_fatti_tot",    "Gol fatti (totale)",    $G, "sum",  "up",      "", 0);
    $c[] = ms_def("gol_subiti_tot",   "Gol subiti (totale)",   $G, "sum",  "down",    "", 0);
    $c[] = ms_def("clean_sheet",      "Clean sheet",           $G, "mean", "up",      "%", 0, "Partite senza gol subiti");
    $c[] = ms_def("senza_segnare",    "Senza segnare",         $G, "mean", "down",    "%", 0, "Partite senza gol segnati");
    $c[] = ms_def("gol_segnato",      "Con gol segnato",       $G, "mean", "up",      "%", 0);
    $c[] = ms_def("gol_subito",       "Con gol subito",        $G, "mean", "down",    "%", 0);
    $c[] = ms_def("segnato_e_subito", "Segnato e subito",      $G, "mean", "neutral", "%", 0);

    $G = "Fantapunti";
    $c[] = ms_def("fanta_media",   "Fantapunti",              $G, "mean", "up",   "", 2);
    $c[] = ms_def("fanta_subiti",  "Fantapunti subiti",       $G, "mean", "down", "", 2, "Fantapunti degli avversari");
    $c[] = ms_def("fanta_diff",    "Differenza fantapunti",   $G, "mean", "up",   "", 2);
    $c[] = ms_def("fanta_max",     "Miglior fantapunteggio",  $G, "max",  "up",   "", 1);
    $c[] = ms_def("fanta_min",     "Peggior fantapunteggio",  $G, "min",  "up",   "", 1);

    $G = "Casa e trasferta";
    $c[] = ms_def("ppp_casa",         "Punti a partita in casa",      $G, "mean", "up", "", 2);
    $c[] = ms_def("ppp_trasferta",    "Punti a partita in trasferta", $G, "mean", "up", "", 2);
    $c[] = ms_def("fanta_casa",       "Fantapunti in casa",           $G, "mean", "up", "", 2);
    $c[] = ms_def("fanta_trasferta",  "Fantapunti in trasferta",      $G, "mean", "up", "", 2);

    $G = "Reparti";
    foreach (MS_REPARTI as $r => $nome) {
        $c[] = ms_def("reparto_{$r}_voto",      "Voto $nome",      $G, "mean", "up", "", 2,
                      "Voto base, senza bonus/malus: media delle medie di partita dei giocatori schierati");
        $c[] = ms_def("reparto_{$r}_fantavoto", "Fantavoto $nome", $G, "mean", "up", "", 2,
                      "Voto con bonus/malus: media delle medie di partita dei giocatori schierati");
    }
    return $c;
}

// ── Aggregazione e benchmark ─────────────────────────────────

/** Valore di stagione di una metrica dai suoi valori per partita (null esclusi). */
function ms_aggrega(string $agg, array $valori): ?float
{
    $v = array_values(array_filter($valori, fn($x) => $x !== null));
    if (!$v) return null;
    switch ($agg) {
        case "sum":  return (float) array_sum($v);
        case "mean": return array_sum($v) / count($v);
        case "last": return (float) end($v);
        case "max":  return (float) max($v);
        case "min":  return (float) min($v);
    }
    return null;
}

/** Benchmark di lega su un insieme di valori (uno per squadra). */
function ms_benchmark(array $valori): array
{
    $v = array_values(array_filter($valori, fn($x) => $x !== null));
    $n = count($v);
    if ($n === 0) return ["n" => 0, "media" => null, "mediana" => null, "min" => null, "max" => null];
    sort($v);
    $med = $n % 2 ? $v[intdiv($n, 2)] : ($v[$n / 2 - 1] + $v[$n / 2]) / 2;
    return [
        "n"       => $n,
        "media"   => round(array_sum($v) / $n, 2),
        "mediana" => round($med, 2),
        "min"     => round($v[0], 2),
        "max"     => round($v[$n - 1], 2),
    ];
}

// ── Posizione in classifica ──────────────────────────────────

/**
 * Posizione in classifica di ogni squadra dopo ogni giornata pubblica del campionato:
 * id_squadra => [giornata => posizione]. Stesso ordinamento di generale.php
 * (punti, gol fatti, gol subiti); non considera le penalità.
 */
function ms_posizioni(int $stagione, callable $pub): array
{
    $perGiornata = [];
    foreach (query_all("SELECT giornata, id_squadra, punti, golf, gols
                        FROM NEW_RISULTATI WHERE stagione = $stagione ORDER BY giornata") as $r) {
        if (!$pub((int) $r["giornata"])) continue;
        $perGiornata[(int) $r["giornata"]][] = $r;
    }
    ksort($perGiornata);
    $tot = [];
    $out = [];
    foreach ($perGiornata as $g => $righe) {
        foreach ($righe as $r) {
            $id = (int) $r["id_squadra"];
            if (!isset($tot[$id])) $tot[$id] = ["id" => $id, "punti" => 0, "golf" => 0, "gols" => 0];
            $tot[$id]["punti"] += (int) $r["punti"];
            $tot[$id]["golf"]  += (int) $r["golf"];
            $tot[$id]["gols"]  += (int) $r["gols"];
        }
        $ord = array_values($tot);
        usort($ord, fn($a, $b) => [$b["punti"], $b["golf"], $b["gols"], $a["id"]]
                              <=> [$a["punti"], $a["golf"], $a["gols"], $b["id"]]);
        foreach ($ord as $pos => $t) $out[$t["id"]][$g] = $pos + 1;
    }
    return $out;
}

// ── Valori per partita ───────────────────────────────────────

/**
 * Valori per partita di una squadra: chiave metrica => [giornata => valore|null].
 * Le metriche di reparto (voto/fantavoto) non sono qui: vedi ms_voti_reparti().
 */
function ms_estrai_partite(array $partite): array
{
    $out = [];
    foreach ($partite as $p) {
        $g = (int) $p["giornata"];
        $segno = $p["segno"];
        $golf = (int) $p["golf"];
        $gols = (int) $p["gols"];
        $pt   = (int) $p["punti"];
        $ft   = (float) $p["ftotale"];
        $fta  = (float) $p["ftotale_a"];
        $casa = $p["casa"];   // true | false | null

        $out["punti"][$g]         = $pt;
        $out["punti_partita"][$g] = $pt;
        $out["vittorie"][$g]      = $segno === "V" ? 1 : 0;
        $out["pareggi"][$g]       = $segno === "N" ? 1 : 0;
        $out["sconfitte"][$g]     = $segno === "P" ? 1 : 0;
        $out["diff_reti"][$g]     = $golf - $gols;

        $out["gol_fatti"][$g]      = $golf;
        $out["gol_subiti"][$g]     = $gols;
        $out["gol_fatti_tot"][$g]  = $golf;
        $out["gol_subiti_tot"][$g] = $gols;
        $out["clean_sheet"][$g]      = $gols === 0 ? 100 : 0;
        $out["senza_segnare"][$g]    = $golf === 0 ? 100 : 0;
        $out["gol_segnato"][$g]      = $golf > 0 ? 100 : 0;
        $out["gol_subito"][$g]       = $gols > 0 ? 100 : 0;
        $out["segnato_e_subito"][$g] = ($golf > 0 && $gols > 0) ? 100 : 0;

        $out["fanta_media"][$g]  = $ft;
        $out["fanta_subiti"][$g] = $fta;
        $out["fanta_diff"][$g]   = $ft - $fta;
        $out["fanta_max"][$g]    = $ft;
        $out["fanta_min"][$g]    = $ft;

        $out["ppp_casa"][$g]        = $casa === true  ? $pt : null;
        $out["ppp_trasferta"][$g]   = $casa === false ? $pt : null;
        $out["fanta_casa"][$g]      = $casa === true  ? $ft : null;
        $out["fanta_trasferta"][$g] = $casa === false ? $ft : null;
    }
    return $out;
}

/**
 * Voto e fantavoto per reparto di tutte le squadre (giocatori che hanno
 * concorso al punteggio, riserve d'ufficio comprese): per ogni giornata la
 * media del reparto; la media di stagione è la media di queste medie
 * (stessa definizione di dashboard_ruoli.php, vista SCHIERATI).
 * Ritorna: id_squadra => ruolo => giornata => ["voto" => x, "fanta" => y].
 */
function ms_voti_reparti(int $stagione, bool $champ, callable $pub): array
{
    $tab = $champ ? "NEW_VOTI_CHAMP" : "NEW_VOTI";
    $rows = query_all("SELECT v.id_squadra, v.giornata, v.id_giocatore, v.voto, v.totale, g.ruolo AS ruolo_g
                       FROM $tab v
                       LEFT JOIN " . rs_sql_anagrafica($stagione) . " g
                              ON g.id = v.id_giocatore AND g.stagione = v.stagione
                       WHERE v.stagione = $stagione AND v.giocata = 1");
    $acc = [];
    foreach ($rows as $r) {
        $g = (int) $r["giornata"];
        if (!$pub($g)) continue;
        $id = (int) $r["id_giocatore"];
        if ($r["ruolo_g"] !== null)  $ruolo = (int) $r["ruolo_g"];
        elseif ($id < 0)             $ruolo = intdiv(-$id, 1000000);   // riserva d'ufficio
        else                         continue;
        if ($ruolo < 1 || $ruolo > 4) continue;
        $acc[(int) $r["id_squadra"]][$ruolo][$g]["voto"][]  = (float) $r["voto"];
        $acc[(int) $r["id_squadra"]][$ruolo][$g]["fanta"][] = (float) $r["totale"];
    }
    $out = [];
    foreach ($acc as $sq => $perRuolo) {
        foreach ($perRuolo as $ruolo => $perG) {
            foreach ($perG as $g => $x) {
                $out[$sq][$ruolo][$g] = [
                    "voto"  => array_sum($x["voto"])  / count($x["voto"]),
                    "fanta" => array_sum($x["fanta"]) / count($x["fanta"]),
                ];
            }
        }
    }
    return $out;
}

// ── Calcolo complessivo ──────────────────────────────────────

/**
 * Valori, serie per giornata e benchmark di tutte le metriche per tutte le squadre.
 * $squadre: [["id","nome","logo"], ...].
 */
function ms_calcola(int $stagione, bool $champ, callable $pub, array $squadre): array
{
    $catalogo = ms_catalogo($champ);
    $posizioni = $champ ? [] : ms_posizioni($stagione, $pub);

    $perSq = [];
    $giornateSet = [];
    foreach ($squadre as $s) {
        $id = (int) $s["id"];
        $partite = $champ ? ds_partite_champ($stagione, $id, $pub) : ds_partite_camp($stagione, $id, $pub);
        foreach ($partite as $p) $giornateSet[(int) $p["giornata"]] = true;
        $perSq[$id] = ms_estrai_partite($partite);
        $perSq[$id]["_giornate"] = array_map(fn($p) => (int) $p["giornata"], $partite);
    }
    $reparti = ms_voti_reparti($stagione, $champ, $pub);
    $giornate = array_keys($giornateSet);
    sort($giornate);

    $valori = []; $serie = []; $benchmark = [];
    foreach ($catalogo as $def) {
        $k = $def["key"];
        $vm = [];   // id => valore di stagione
        $sm = [];   // id => serie per giornata
        foreach ($squadre as $s) {
            $id = (int) $s["id"];
            $perG = [];
            if (preg_match('/^reparto_(\d)_(voto|fantavoto)$/', $k, $m)) {
                $campo = $m[2] === "voto" ? "voto" : "fanta";
                foreach ($perSq[$id]["_giornate"] as $g) {
                    $perG[$g] = $reparti[$id][(int) $m[1]][$g][$campo] ?? null;
                }
            } elseif ($k === "posizione") {
                foreach ($perSq[$id]["_giornate"] as $g) $perG[$g] = $posizioni[$id][$g] ?? null;
            } else {
                $perG = $perSq[$id][$k] ?? [];
            }
            $riga = [];
            foreach ($giornate as $g) {
                $x = $perG[$g] ?? null;
                $riga[] = $x === null ? null : round((float) $x, 2);
            }
            $sm[$id] = $riga;
            $v = ms_aggrega($def["agg"], array_values($perG));
            $vm[$id] = $v === null ? null : round($v, 2);
        }
        $valori[$k]    = (object) $vm;
        $serie[$k]     = (object) $sm;
        $benchmark[$k] = ms_benchmark($vm);
    }

    return [
        "giornate"  => $giornate,
        "metriche"  => $catalogo,
        "valori"    => (object) $valori,
        "serie"     => (object) $serie,
        "benchmark" => (object) $benchmark,
    ];
}
