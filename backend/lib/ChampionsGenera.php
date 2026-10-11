<?php
// ============================================================
// lib/ChampionsGenera.php
//
// Generazione di NEW_CALENDARIO_CHAMP (stagione corrente), fase per fase.
// NON copia più il calendario della stagione precedente: le righe sono
// calcolate dalle regole sotto.
//
// Colonne della tabella (valide anche per i dati storici):
//   GIORNATA       progressivo del girone/turno (1..6 gironi, 1..2 semifinali)
//   GIORNATA_CAMP  giornata di fantacampionato: letta dalla configurazione
//                  (NEW_PARAMETRI_STAGIONE, codici CHAMP_*)
//   GIRONE         A, B (fase 1) · C, D (fase 2) · S1, S2 (semifinali)
//                  FI (finale) · FR (finale replay)
//   POSIZIONE      posizione nella giornata del girone: coppie 1-2 e 3-4
//                  (fase 1), 1-2 (le altre fasi). Non c'è fattore casa.
//   SQUADRA        id squadra
//
// FASE 1 (generabile all'inizializzazione: non dipende da risultati)
//   Girone A: squadre 1,3,5,7 · Girone B: squadre 2,4,6,8
//   Ordine per giornata (a1..a4 = squadre del girone in ordine di id):
//     1: a1 a2 a3 a4    2: a2 a3 a4 a1    3: a1 a3 a4 a2
//     4: a2 a1 a4 a3    5: a3 a2 a1 a4    6: a3 a1 a2 a4
//
// FASE 2 (richiede la classifica finale della fase 1)
//   Girone C: 1ª di A, 2ª e 3ª di B · Girone D: 1ª di B, 2ª e 3ª di A
//   (x1, x2, x3 = squadre nell'ordine indicato)
//     1: x1-x2   2: x2-x3   3: x1-x3   4: x2-x1   5: x3-x2   6: x3-x1
//
// SEMIFINALI (richiedono la classifica finale della fase 2)
//   S1: 1ª di C vs 2ª di D · S2: 1ª di D vs 2ª di C
//   Andata (giornata 1): in casa (posizione 1) la seconda;
//   ritorno (giornata 2): in casa la vincitrice del girone.
//
// FINALE (FI) e REPLAY (FR): richiedono i risultati delle semifinali /
//   della finale (il replay solo se la finale è finita in parità).
// ============================================================

require_once __DIR__ . "/ChampionsCalendario.php";

/** Squadre della fase 1, in ordine di id. */
const CHAMPIONS_SQUADRE_F1 = ['A' => [1, 3, 5, 7], 'B' => [2, 4, 6, 8]];

/** Fase 1: per giornata, indici (0..3) delle squadre del girone nelle posizioni 1..4. */
const CHAMPIONS_SEQUENZA_F1 = [
    1 => [0, 1, 2, 3],
    2 => [1, 2, 3, 0],
    3 => [0, 2, 3, 1],
    4 => [1, 0, 3, 2],
    5 => [2, 1, 0, 3],
    6 => [2, 0, 1, 3],
];

/** Fase 2: per giornata, indici (0..2) delle squadre del girone nelle posizioni 1..2. */
const CHAMPIONS_SEQUENZA_F2 = [
    1 => [0, 1],
    2 => [1, 2],
    3 => [0, 2],
    4 => [1, 0],
    5 => [2, 1],
    6 => [2, 0],
];

/** Riga di calendario: girone, giornata, posizione, squadra + codice della cella di configurazione. */
function champions_riga(string $girone, int $giornata, int $posizione, int $squadra, string $cella): array
{
    return ["girone" => $girone, "giornata" => $giornata, "posizione" => $posizione,
            "squadra" => $squadra, "cella" => $cella];
}

// ------------------------------------------------------------
// Generatori di righe (funzioni pure)
// ------------------------------------------------------------

/** Fase 1: gironi A e B, 6 giornate. */
function champions_righe_fase1(): array
{
    $righe = [];
    foreach (CHAMPIONS_SQUADRE_F1 as $girone => $squadre) {
        foreach (CHAMPIONS_SEQUENZA_F1 as $giornata => $indici) {
            foreach ($indici as $k => $idx) {
                $righe[] = champions_riga($girone, $giornata, $k + 1, $squadre[$idx],
                                          "CHAMP_F1_{$girone}_T$giornata");
            }
        }
    }
    return $righe;
}

/**
 * Fase 2: gironi C e D, 6 giornate.
 * $classA / $classB = id squadra in ordine di classifica finale della fase 1.
 */
function champions_righe_fase2(array $classA, array $classB): array
{
    if (count($classA) < 3 || count($classB) < 3) {
        throw new RuntimeException("Classifiche della fase 1 incomplete: servono almeno 3 squadre per girone");
    }
    $gironi = [
        'C' => [$classA[0], $classB[1], $classB[2]],
        'D' => [$classB[0], $classA[1], $classA[2]],
    ];
    // I gironi C e D usano le celle di configurazione A e B della fase 2
    $cellaGirone = ['C' => 'A', 'D' => 'B'];
    $righe = [];
    foreach ($gironi as $girone => $squadre) {
        foreach (CHAMPIONS_SEQUENZA_F2 as $giornata => $indici) {
            foreach ($indici as $k => $idx) {
                $righe[] = champions_riga($girone, $giornata, $k + 1, (int) $squadre[$idx],
                                          "CHAMP_F2_{$cellaGirone[$girone]}_T$giornata");
            }
        }
    }
    return $righe;
}

/**
 * Semifinali: S1 = 1ª C vs 2ª D · S2 = 1ª D vs 2ª C.
 * Andata (giornata 1): in casa la seconda; ritorno (giornata 2): in casa la vincitrice del girone.
 * $classC / $classD = id squadra in ordine di classifica finale della fase 2.
 */
function champions_righe_semifinali(array $classC, array $classD): array
{
    if (count($classC) < 2 || count($classD) < 2) {
        throw new RuntimeException("Classifiche della fase 2 incomplete: servono almeno 2 squadre per girone");
    }
    $semi = [
        'S1' => ['prima' => (int) $classC[0], 'seconda' => (int) $classD[1]],
        'S2' => ['prima' => (int) $classD[0], 'seconda' => (int) $classC[1]],
    ];
    $righe = [];
    foreach ($semi as $girone => $s) {
        $andata  = [$s['seconda'], $s['prima']];
        $ritorno = [$s['prima'], $s['seconda']];
        foreach ([1 => $andata, 2 => $ritorno] as $giornata => $coppia) {
            foreach ($coppia as $k => $sq) {
                $righe[] = champions_riga($girone, $giornata, $k + 1, $sq, "CHAMP_SF_T$giornata");
            }
        }
    }
    return $righe;
}

/** Finale (FI) o replay (FR) tra le due finaliste (nessun fattore casa). */
function champions_righe_finale(int $finalista1, int $finalista2, bool $replay = false): array
{
    $girone = $replay ? 'FR' : 'FI';
    $cella  = $replay ? 'CHAMP_FIN_REPLAY' : 'CHAMP_FIN_T1';
    return [
        champions_riga($girone, 1, 1, $finalista1, $cella),
        champions_riga($girone, 1, 2, $finalista2, $cella),
    ];
}

// ------------------------------------------------------------
// Risultati e classifiche (servono alle fasi successive alla prima)
// ------------------------------------------------------------

/** Risultato di una squadra in una giornata di campionato (NEW_RISULTATI_CHAMP) o null. */
function champions_risultato_squadra(int $stagione, int $giornataCamp, int $squadra): ?array
{
    $r = query_one("SELECT golf, gols, punti, ftotale FROM NEW_RISULTATI_CHAMP
                    WHERE stagione = $stagione AND giornata = $giornataCamp AND squadra = $squadra
                    LIMIT 1");
    return $r ?: null;
}

/** Partite (coppie casa/ospite, con giornata_camp) di un gruppo del calendario. */
function champions_partite_gruppo(int $stagione, string $gruppo): array
{
    $righe = query_all("SELECT giornata, giornata_camp, TRIM(girone) AS girone, posizione, squadra
                        FROM NEW_CALENDARIO_CHAMP WHERE stagione = $stagione");
    $per = [];
    foreach ($righe as $r) {
        if (champions_gruppo_girone($r["girone"]) === $gruppo) $per[(int) $r["giornata"]][] = $r;
    }
    ksort($per);
    $partite = [];
    foreach ($per as $giornata => $rg) {
        usort($rg, fn($a, $b) => (int) $a["posizione"] <=> (int) $b["posizione"]);
        foreach (array_chunk($rg, 2) as $coppia) {
            if (count($coppia) < 2) continue; // squadra a riposo
            $partite[] = [
                "giornata" => $giornata,
                "camp"     => (int) $coppia[0]["giornata_camp"],
                "casa"     => (int) $coppia[0]["squadra"],
                "ospite"   => (int) $coppia[1]["squadra"],
            ];
        }
    }
    return $partite;
}

/**
 * Classifica finale di un girone (A, B, C, D): id squadra dalla prima all'ultima.
 * Parità: punti, differenza reti, gol fatti, fantapunti totali (come nella pagina
 * Champions), poi id squadra. Se manca anche un solo risultato solleva un errore.
 */
function champions_classifica_gruppo(int $stagione, string $gruppo): array
{
    $partite = champions_partite_gruppo($stagione, $gruppo);
    if (!$partite) throw new RuntimeException("Calendario del girone $gruppo non presente");

    $tab = [];
    foreach ($partite as $p) {
        foreach ([$p["casa"], $p["ospite"]] as $id) {
            $res = champions_risultato_squadra($stagione, $p["camp"], $id);
            if (!$res) {
                throw new RuntimeException("Girone $gruppo incompleto: manca il risultato della squadra $id "
                    . "(giornata {$p['giornata']}, giornata di campionato {$p['camp']})");
            }
            if (!isset($tab[$id])) $tab[$id] = ["id" => $id, "punti" => 0, "golf" => 0, "gols" => 0, "ftotale" => 0.0];
            $tab[$id]["punti"]   += (int) $res["punti"];
            $tab[$id]["golf"]    += (int) $res["golf"];
            $tab[$id]["gols"]    += (int) $res["gols"];
            $tab[$id]["ftotale"] += (float) $res["ftotale"];
        }
    }
    $tab = array_values($tab);
    usort($tab, fn($a, $b) =>
        [$b["punti"], $b["golf"] - $b["gols"], $b["golf"], $b["ftotale"], $a["id"]]
        <=> [$a["punti"], $a["golf"] - $a["gols"], $a["golf"], $a["ftotale"], $b["id"]]);
    return array_map(fn($t) => $t["id"], $tab);
}

/**
 * Vincitrice di una semifinale (S1/S2) sul totale dei gol dei due incontri.
 * In caso di parità non si decide da sole: serve l'indicazione manuale.
 * @return int id squadra
 */
function champions_vincitore_semifinale(int $stagione, string $semi): int
{
    $tot = [];
    foreach (champions_partite_gruppo($stagione, $semi) as $p) {
        foreach ([$p["casa"], $p["ospite"]] as $id) {
            $res = champions_risultato_squadra($stagione, $p["camp"], $id);
            if (!$res) throw new RuntimeException("Semifinale $semi incompleta: manca il risultato della squadra $id");
            $tot[$id] = ($tot[$id] ?? 0) + (int) $res["golf"];
        }
    }
    if (count($tot) !== 2) throw new RuntimeException("Semifinale $semi non presente o non valida");
    $ids = array_keys($tot);
    if ($tot[$ids[0]] === $tot[$ids[1]]) {
        throw new RuntimeException("Semifinale $semi in parità sul totale dei gol: indicare manualmente la squadra vincitrice");
    }
    return $tot[$ids[0]] > $tot[$ids[1]] ? $ids[0] : $ids[1];
}

/** Finaliste (id squadra) della finale FI già generata, per il replay. */
function champions_finale_esito(int $stagione): array
{
    $p = champions_partite_gruppo($stagione, 'FI')[0] ?? null;
    if (!$p) throw new RuntimeException("Finale non presente");
    $c = champions_risultato_squadra($stagione, $p["camp"], $p["casa"]);
    $o = champions_risultato_squadra($stagione, $p["camp"], $p["ospite"]);
    if (!$c || !$o) throw new RuntimeException("Finale non ancora giocata");
    return ["casa" => $p["casa"], "ospite" => $p["ospite"], "pari" => (int) $c["golf"] === (int) $o["golf"]];
}

// ------------------------------------------------------------
// Scrittura
// ------------------------------------------------------------

/**
 * Scrive le righe in NEW_CALENDARIO_CHAMP per la stagione. GIORNATA_CAMP di ogni
 * riga è la giornata configurata per la sua cella (CHAMP_*): se manca, errore.
 * Prima di inserire elimina le righe già presenti per i gironi interessati
 * (compresi i codici storici C1..D3), quindi l'operazione è ripetibile.
 * Da chiamare dentro una transazione.
 *
 * @param string[] $gironiDaSostituire  gruppi da svuotare, es. ['A','B'] o ['C','D']
 */
function champions_scrivi_righe($conn, int $stagione, array $righe, array $champGiornate, array $gironiDaSostituire): void
{
    // 1) giornata di campionato per ogni cella: tutte o nessuna riga
    foreach ($righe as $r) {
        if ((int) ($champGiornate[$r["cella"]] ?? 0) < 1) {
            $nome = "";
            foreach (champions_turni_piatti() as $c) if ($c["codice"] === $r["cella"]) $nome = $c["nome"];
            throw new RuntimeException("Giornata di campionato non configurata: " . ($nome ?: $r["cella"])
                . " (Gestisci regole di calcolo → Parametri stagione → Struttura stagione)");
        }
    }

    // 2) pulizia dei gironi interessati
    $cond = [];
    foreach ($gironiDaSostituire as $g) {
        $g = mysqli_real_escape_string($conn, $g);
        $cond[] = "TRIM(girone) = '$g'";
        if (in_array($g, ['C', 'D'], true)) $cond[] = "TRIM(girone) LIKE '$g%'"; // C1..C3, D1..D3
    }
    if ($cond && !mysqli_query($conn, "DELETE FROM NEW_CALENDARIO_CHAMP WHERE stagione = $stagione AND ("
                                      . implode(" OR ", $cond) . ")")) {
        throw new RuntimeException(mysqli_error($conn));
    }

    // 3) inserimento
    foreach ($righe as $r) {
        $camp   = (int) $champGiornate[$r["cella"]];
        $girone = mysqli_real_escape_string($conn, $r["girone"]);
        $sql = "INSERT INTO NEW_CALENDARIO_CHAMP (stagione, giornata, giornata_camp, posizione, squadra, girone)
                VALUES ($stagione, {$r['giornata']}, $camp, {$r['posizione']}, {$r['squadra']}, '$girone')";
        if (!mysqli_query($conn, $sql)) throw new RuntimeException(mysqli_error($conn));
    }
}
