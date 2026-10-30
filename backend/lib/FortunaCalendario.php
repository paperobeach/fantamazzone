<?php
// ============================================================
// backend/lib/FortunaCalendario.php
//
// Misura quanto il calendario ha inciso sul rendimento di ogni squadra:
// i punteggi realmente ottenuti (con le formazioni realmente schierate)
// vengono "rigiocati" contro avversarie diverse da quelle incontrate,
// e i punti reali sono confrontati con quelli ottenibili da calendari
// alternativi.
//
// Nessuna scrittura su DB: tutto è derivato da NEW_RISULTATI,
// NEW_CALENDARIO e dalle regole di calcolo della stagione.
//
// COME SI RIGIOCA UNA PARTITA
// Ogni riga di NEW_RISULTATI (prospettiva di una squadra) contiene
// ftotale = giocatori + attacco + difesa ricevuta + centrocampo + fattore
// casa. Si separa quindi la parte "propria" della squadra (giocatori +
// attacco, indipendente dall'avversaria) da quella che dipende
// dall'avversaria:
//   propria   = ftotale - difesa ricevuta - mod_cc - fattore casa applicato
//   difesa ricevuta = -modificatore_a   (bonus generato dalla difesa avversaria)
//   difesa data     = -modificatore     (bonus che la propria difesa regala
//                                        all'avversaria)
// Contro una nuova avversaria O si ricalcola:
//   totale squadra = propria + difesa data da O + centrocampo(squadra, O)
//                    + fattore casa (solo se in casa)
// il centrocampo con CalcolatoreVoti::modificatoreCentrocampo (usa
// num_cc / tot_cc salvati), i gol con le stesse fasce e regole
// correttive a/b/c del calcolo giornata. Il fattore casa segue il
// campo realmente giocato dalla squadra analizzata in quella giornata.
//
// DUE MISURE COMPLEMENTARI
// 1. ESATTA per squadra: le avversarie realmente incontrate vengono
//    assegnate alle giornate in ogni possibile ordine (permutazioni del
//    calendario di UNA squadra). Media e varianza dei punti si ricavano
//    in forma chiusa (varianza di Hoeffding per assegnazioni casuali),
//    senza simulare.
// 2. MONTE CARLO di campionato: il calendario intero viene ricostruito
//    permutando a caso le squadre sugli slot del calendario reale (resta
//    un calendario valido, stesse giornate/casa-fuori). Per ogni
//    estrazione si rifà la classifica: ne escono distribuzione delle
//    posizioni, probabilità di titolo/ultimo posto e percentile dei punti
//    reali. Il seme è fisso (per stagione e numero di giornate) così il
//    risultato è ripetibile.
// ============================================================

require_once __DIR__ . "/CalcolatoreVoti.php";
require_once __DIR__ . "/RegoleCalcolo.php";
require_once __DIR__ . "/StatoGiornata.php";

// Gol fatti dal punteggio totale (stessa logica di calcolo_giornata.php)
function fortuna_gol_da_punteggio(float $punteggio, array $fasce): int
{
    foreach ($fasce as $f) {
        $da = $f['da'] ?? null;
        $a  = $f['a']  ?? null;
        if ($da !== null && $punteggio < (float) $da) continue;
        if ($a  !== null && $punteggio > (float) $a)  continue;
        return (int) ($f['gol'] ?? 0);
    }
    return 0;
}

// Esito di una partita ipotetica fra la squadra di casa (record $rh) e
// l'ospite (record $ra). Ritorna [golCasa, golOspite, puntiCasa, puntiOspite].
function fortuna_esito_partita(array $rh, array $ra, bool $applicaFattoreCasa, array $ctx): array
{
    $mid = CalcolatoreVoti::modificatoreCentrocampo(
        $rh['cc_s'], $rh['cc_n'], $ra['cc_s'], $ra['cc_n'], $ctx['cc']
    );
    $totCasa   = round($rh['propria'] + $ra['difesa_data'] + $mid['casa'] + ($applicaFattoreCasa ? $ctx['fc'] : 0.0), 2);
    $totOspite = round($ra['propria'] + $rh['difesa_data'] + $mid['ospite'], 2);

    $golCasa   = fortuna_gol_da_punteggio($totCasa,   $ctx['fasce']);
    $golOspite = fortuna_gol_da_punteggio($totOspite, $ctx['fasce']);
    $esito = CalcolatoreVoti::applicaRegoleGolPartita($totCasa, $totOspite, $golCasa, $golOspite, $ctx['regole']);
    $gc = (int) $esito['golf_casa'];
    $go = (int) $esito['golf_ospite'];

    if ($gc > $go)      return [$gc, $go, 3, 0];
    if ($gc === $go)    return [$gc, $go, 1, 1];
    return [$gc, $go, 0, 3];
}

// Confronto classifica: punti, gol fatti, gol subiti (stesso ordine di generale.php)
function fortuna_ordina_classifica(array $pts, array $gf, array $gs, array $ids): array
{
    usort($ids, function ($x, $y) use ($pts, $gf, $gs) {
        return ($pts[$y] <=> $pts[$x]) ?: ($gf[$y] <=> $gf[$x]) ?: ($gs[$y] <=> $gs[$x]) ?: ($x <=> $y);
    });
    return $ids;
}

function fortuna_calendario($conn, int $stagione, int $simulazioni = 3000): array
{
    $simulazioni = max(200, min(10000, $simulazioni));
    @set_time_limit(90);

    $config = caricaConfigurazioneCalcolo($conn, $stagione);
    $par = $config['parametri'];

    $fasce = json_decode($par['FASCE_GOL_PUNTEGGIO'] ?? '', true) ?: [['da' => null, 'a' => null, 'gol' => 0]];
    $ultimaGiornataFc = trim((string) ($par['ULTIMA_GIORNATA_FATTORE_CASA'] ?? ''));
    $ctx = [
        'fasce'  => $fasce,
        'cc'     => $config['centrocampo'] ?? [],
        'fc'     => (float) ($config['bonus']['FATTORE_CASA'] ?? 0),
        'regole' => [
            'diff_min'        => is_numeric($par['GOL_DISTACCO_MIN_PUNTI'] ?? null) ? (float) $par['GOL_DISTACCO_MIN_PUNTI'] : 3,
            'soglia_pareggio' => is_numeric($par['GOL_SOGLIA_PAREGGIO'] ?? null)    ? (float) $par['GOL_SOGLIA_PAREGGIO']    : 66,
            'soglia_omaggio'  => is_numeric($par['GOL_SOGLIA_OMAGGIO'] ?? null)     ? (float) $par['GOL_SOGLIA_OMAGGIO']     : 59,
        ],
    ];

    // ---- Giornate considerate: solo quelle chiuse (storiche: tutte) ----
    $storica = $stagione !== sg_ultima_stagione();
    $chiuse = [];
    foreach (query_all("SELECT giornata FROM NEW_CALENDARIO_CK
                        WHERE stagione = $stagione AND ck_giocata = 'S'") as $c) {
        $chiuse[(int) $c['giornata']] = true;
    }

    // ---- Squadre ----
    $squadre = [];
    foreach (query_all("SELECT id, nome, logo FROM NEW_SQUADRE WHERE stagione = $stagione") as $s) {
        $squadre[(int) $s['id']] = ['nome' => $s['nome'], 'logo' => $s['logo']];
    }

    // ---- Calendario: coppie casa/ospite per giornata ----
    $calendario = [];   // g => [idx => ['h'=>id,'a'=>id]]
    foreach (query_all("SELECT giornata, posizione, squadra FROM NEW_CALENDARIO
                        WHERE stagione = $stagione ORDER BY giornata, posizione") as $r) {
        $g = (int) $r['giornata'];
        $i = (int) (((int) $r['posizione'] - 1) / 2);
        $k = ((int) $r['posizione'] % 2 === 1) ? 'h' : 'a';
        $calendario[$g][$i][$k] = (int) $r['squadra'];
    }

    // ---- Record per squadra/giornata dai risultati reali ----
    $rec = [];       // g => id => record
    $reali = [];     // id => ['punti','golf','gols','n']
    $righe = query_all("SELECT giornata, id_squadra, id_squadra_a, ftotale, modificatore, modificatore_a,
                               fattore_campo, mod_cc, num_cc, tot_cc, golf, gols, punti
                        FROM NEW_RISULTATI WHERE stagione = $stagione ORDER BY giornata");
    foreach ($righe as $r) {
        $g = (int) $r['giornata'];
        if (!$storica && empty($chiuse[$g])) continue;
        $id = (int) $r['id_squadra'];
        if (!isset($squadre[$id])) continue;

        // Campo: dal calendario (posizione dispari = casa); se assente, dal fattore campo salvato
        $inCasa = null;
        foreach ($calendario[$g] ?? [] as $m) {
            if (($m['h'] ?? null) === $id) { $inCasa = true;  break; }
            if (($m['a'] ?? null) === $id) { $inCasa = false; break; }
        }
        if ($inCasa === null) $inCasa = (float) $r['fattore_campo'] > 0;

        $fcApplicato = ($inCasa && ($ultimaGiornataFc === '' || $g <= (int) $ultimaGiornataFc)) ? $ctx['fc'] : 0.0;
        $difesaRicevuta = -(float) $r['modificatore_a'];

        $rec[$g][$id] = [
            'avv'         => (int) $r['id_squadra_a'],
            'casa'        => $inCasa,
            'fc'          => $fcApplicato,
            'propria'     => round((float) $r['ftotale'] - $difesaRicevuta - (float) $r['mod_cc'] - $fcApplicato, 2),
            'difesa_data' => -(float) $r['modificatore'],
            'cc_n'        => (int) $r['num_cc'],
            'cc_s'        => (float) $r['tot_cc'],
            'golf'        => (int) $r['golf'],
            'gols'        => (int) $r['gols'],
            'punti'       => (int) $r['punti'],
        ];
        if (!isset($reali[$id])) $reali[$id] = ['punti' => 0, 'golf' => 0, 'gols' => 0, 'n' => 0];
        $reali[$id]['punti'] += (int) $r['punti'];
        $reali[$id]['golf']  += (int) $r['golf'];
        $reali[$id]['gols']  += (int) $r['gols'];
        $reali[$id]['n']++;
    }

    $giornate = array_keys($rec);
    sort($giornate);
    if (count($giornate) === 0 || count($reali) < 3) {
        return ['stagione' => $stagione, 'disponibile' => false, 'squadre' => [], 'giornate' => 0];
    }

    $ids = array_keys($reali);
    sort($ids);
    $N = count($ids);
    $idx = array_flip($ids);                       // id squadra => indice 0..N-1

    // ---- Tabelle esiti per tutte le coppie ordinate (x in casa, y fuori) ----
    // tab[g][x*N+y] = [golX, golY, ptsX, ptsY]
    $tab = [];
    foreach ($giornate as $g) {
        $applicaFc = true; // l'eventuale limite di giornata è già incorporato in rec[g][id]['fc']
        foreach ($rec[$g] as $ix => $rx) {
            foreach ($rec[$g] as $iy => $ry) {
                if ($ix === $iy) continue;
                $fcX = ($ultimaGiornataFc === '' || $g <= (int) $ultimaGiornataFc);
                $tab[$g][$idx[$ix] * $N + $idx[$iy]] = fortuna_esito_partita($rx, $ry, $applicaFc && $fcX, $ctx);
            }
        }
    }

    // ---- Verifica: la ricostruzione deve riprodurre i risultati reali ----
    $verPartite = 0;
    $verOk = 0;
    foreach ($giornate as $g) {
        foreach ($calendario[$g] ?? [] as $m) {
            $h = $m['h'] ?? null; $a = $m['a'] ?? null;
            if ($h === null || $a === null || !isset($rec[$g][$h], $rec[$g][$a])) continue;
            $e = $tab[$g][$idx[$h] * $N + $idx[$a]] ?? null;
            if ($e === null) continue;
            $verPartite++;
            if ($e[0] === $rec[$g][$h]['golf'] && $e[1] === $rec[$g][$h]['gols']) $verOk++;
        }
    }

    // ---- Punti di una squadra contro un'avversaria, nel campo reale della squadra ----
    $puntiContro = function (int $g, int $id, int $opp) use ($rec, $tab, $idx, $N) {
        if (!isset($rec[$g][$id], $rec[$g][$opp]) || $id === $opp) return null;
        $casa = $rec[$g][$id]['casa'];
        $e = $casa ? ($tab[$g][$idx[$id] * $N + $idx[$opp]] ?? null)
                   : ($tab[$g][$idx[$opp] * $N + $idx[$id]] ?? null);
        if ($e === null) return null;
        return $casa ? $e[2] : $e[3];
    };

    // ---- 1) Misura esatta per squadra (permutazioni delle avversarie) ----
    $out = [];
    foreach ($ids as $id) {
        $gg = [];       // giornate giocate dalla squadra
        foreach ($giornate as $g) if (isset($rec[$g][$id])) $gg[] = $g;
        $n = count($gg);
        if ($n < 2) continue;

        // M[i][j] = punti della squadra in giornata i contro l'avversaria della giornata j
        $M = [];
        for ($i = 0; $i < $n; $i++) {
            $sum = 0; $cnt = 0;
            for ($j = 0; $j < $n; $j++) {
                $v = $puntiContro($gg[$i], $id, $rec[$gg[$j]][$id]['avv']);
                $M[$i][$j] = $v;
                if ($v !== null) { $sum += $v; $cnt++; }
            }
            $media = $cnt ? $sum / $cnt : 0;
            for ($j = 0; $j < $n; $j++) if ($M[$i][$j] === null) $M[$i][$j] = $media; // avversaria assente quel giorno
        }
        $rowM = array_fill(0, $n, 0.0); $colM = array_fill(0, $n, 0.0); $tot = 0.0;
        for ($i = 0; $i < $n; $i++) for ($j = 0; $j < $n; $j++) {
            $rowM[$i] += $M[$i][$j] / $n;
            $colM[$j] += $M[$i][$j] / $n;
            $tot      += $M[$i][$j] / ($n * $n);
        }
        $ss = 0.0;
        for ($i = 0; $i < $n; $i++) for ($j = 0; $j < $n; $j++) {
            $d = $M[$i][$j] - $rowM[$i] - $colM[$j] + $tot;
            $ss += $d * $d;
        }
        $attesi = $tot * $n;                       // E[S] = somma delle medie di riga
        $sd = sqrt($ss / ($n - 1));

        // Dettaglio per giornata: avversaria reale, punti reali, media contro tutte
        $dett = [];
        foreach ($gg as $i => $g) {
            $sum = 0; $cnt = 0; $vinte = 0;
            foreach ($rec[$g] as $oid => $_) {
                if ($oid === $id) continue;
                $v = $puntiContro($g, $id, $oid);
                if ($v === null) continue;
                $sum += $v; $cnt++; if ($v === 3) $vinte++;
            }
            $dett[] = [
                'giornata'     => $g,
                'avversaria'   => $rec[$g][$id]['avv'],
                'casa'         => $rec[$g][$id]['casa'],
                'punti'        => $rec[$g][$id]['punti'],
                'punti_medi'   => $cnt ? round($sum / $cnt, 2) : null,
                'vittorie_pct' => $cnt ? round(100 * $vinte / $cnt, 1) : null,
            ];
        }

        $out[$id] = [
            'id_squadra'   => $id,
            'squadra'      => $squadre[$id]['nome'],
            'logo'         => $squadre[$id]['logo'],
            'partite'      => $n,
            'punti_reali'  => $reali[$id]['punti'],
            'gol_fatti'    => $reali[$id]['golf'],
            'gol_subiti'   => $reali[$id]['gols'],
            'punti_attesi' => round($attesi, 2),
            'fortuna'      => round($reali[$id]['punti'] - $attesi, 2),
            'dev_std'      => round($sd, 2),
            'z'            => $sd > 0 ? round(($reali[$id]['punti'] - $attesi) / $sd, 2) : 0.0,
            'dettaglio'    => $dett,
        ];
    }

    // ---- 2) Monte Carlo sul campionato intero ----
    $ptsReali = []; $gfReali = []; $gsReali = [];
    foreach ($ids as $id) {
        $ptsReali[$id] = $reali[$id]['punti'];
        $gfReali[$id]  = $reali[$id]['golf'];
        $gsReali[$id]  = $reali[$id]['gols'];
    }
    $ordReale = fortuna_ordina_classifica($ptsReali, $gfReali, $gsReali, $ids);
    $rankReale = [];
    foreach ($ordReale as $pos => $id) $rankReale[$id] = $pos + 1;

    // Slot del calendario (indici 0..N-1) per giornata
    $slot = [];
    foreach ($giornate as $g) {
        foreach ($calendario[$g] ?? [] as $m) {
            if (!isset($m['h'], $m['a'], $idx[$m['h']], $idx[$m['a']])) continue;
            $slot[$g][] = [$idx[$m['h']], $idx[$m['a']]];
        }
    }

    mt_srand(crc32("fortuna:$stagione:" . count($giornate)));
    $sommaPts = array_fill(0, $N, 0.0);
    $sommaPts2 = array_fill(0, $N, 0.0);
    $rankCnt = array_fill(0, $N, array_fill(0, $N, 0));
    $minori = array_fill(0, $N, 0);
    $uguali = array_fill(0, $N, 0);
    $idxList = range(0, $N - 1);
    $ptsR = array_map(fn($id) => $ptsReali[$id], $ids);

    for ($s = 0; $s < $simulazioni; $s++) {
        $perm = $idxList;
        for ($i = $N - 1; $i > 0; $i--) {          // Fisher-Yates
            $j = mt_rand(0, $i);
            $t = $perm[$i]; $perm[$i] = $perm[$j]; $perm[$j] = $t;
        }
        $p = array_fill(0, $N, 0); $gf = array_fill(0, $N, 0); $gs = array_fill(0, $N, 0);
        foreach ($slot as $g => $partite) {
            foreach ($partite as $m) {
                $x = $perm[$m[0]]; $y = $perm[$m[1]];
                $e = $tab[$g][$x * $N + $y] ?? null;
                if ($e === null) continue;
                $gf[$x] += $e[0]; $gs[$x] += $e[1]; $p[$x] += $e[2];
                $gf[$y] += $e[1]; $gs[$y] += $e[0]; $p[$y] += $e[3];
            }
        }
        $ord = fortuna_ordina_classifica($p, $gf, $gs, $idxList);
        foreach ($ord as $pos => $x) $rankCnt[$x][$pos]++;
        for ($x = 0; $x < $N; $x++) {
            $sommaPts[$x]  += $p[$x];
            $sommaPts2[$x] += $p[$x] * $p[$x];
            if ($p[$x] < $ptsR[$x]) $minori[$x]++; elseif ($p[$x] === $ptsR[$x]) $uguali[$x]++;
        }
    }

    foreach ($ids as $x => $id) {
        if (!isset($out[$id])) continue;
        $media = $sommaPts[$x] / $simulazioni;
        $var = max(0.0, $sommaPts2[$x] / $simulazioni - $media * $media);
        $dist = array_map(fn($c) => round($c / $simulazioni, 4), $rankCnt[$x]);
        $rankMedio = 0.0;
        foreach ($dist as $pos => $pr) $rankMedio += ($pos + 1) * $pr;
        $out[$id] += [
            'sim_punti_medi'  => round($media, 2),
            'sim_dev_std'     => round(sqrt($var), 2),
            'percentile'      => round(100 * ($minori[$x] + 0.5 * $uguali[$x]) / $simulazioni, 1),
            'posizione_reale' => $rankReale[$id],
            'posizione_media' => round($rankMedio, 2),
            'prob_titolo'     => $dist[0],
            'prob_ultimo'     => $dist[$N - 1],
            'distribuzione_posizioni' => $dist,
        ];
    }

    // ---- Classifica "senza fortuna": ordinata per punti attesi ----
    $perAttesi = array_values($out);
    usort($perAttesi, fn($a, $b) => ($b['punti_attesi'] <=> $a['punti_attesi']) ?: ($a['id_squadra'] <=> $b['id_squadra']));
    foreach ($perAttesi as $pos => $row) $out[$row['id_squadra']]['posizione_senza_fortuna'] = $pos + 1;

    $lista = array_values($out);
    usort($lista, fn($a, $b) => $a['posizione_reale'] <=> $b['posizione_reale']);

    return [
        'stagione'      => $stagione,
        'disponibile'   => true,
        'giornate'      => count($giornate),
        'squadre_n'     => $N,
        'simulazioni'   => $simulazioni,
        'verifica'      => ['partite' => $verPartite, 'coerenti' => $verOk],
        'nomi'          => array_map(fn($s) => $s['nome'], $squadre),
        'squadre'       => $lista,
    ];
}
