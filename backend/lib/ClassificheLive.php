<?php
// ============================================================
// backend/lib/ClassificheLive.php
//
// Classifiche AGGIORNATE LIVE per la pagina "LIVE Giornata in corso"
// (live_giornata.php).
//
// Le classifiche ufficiali (NEW_GENERALE, classifica gironi Champions)
// contengono solo le giornate CHIUSE. Qui si parte da quelle e si
// aggiungono i risultati NON definitivi della giornata in corso:
//   - se la squadra ha una riga in NEW_RISULTATI / NEW_RISULTATI_CHAMP
//     (calcolo fase 2, non ancora chiuso) si usa quella;
//   - altrimenti, se esiste, la riga della simulazione
//     (NEW_SIMULAZIONE_RISULTATI / NEW_SIMULAZIONE_RISULTATI_CHAMP);
//   - senza nessuna delle due la squadra resta com'e' nella classifica
//     ufficiale.
// Ogni squadra riporta posizione live, posizione "prima" (classifica
// ufficiale a giornata precedente) e variazione, cosi' la pagina puo'
// mostrare chi sale e chi scende.
//
// Ordinamento campionato identico a generale.php (punti, gol fatti,
// gol subiti DESC); gironi Champions identico a champions_gironi()
// (punti, differenza reti, gol fatti, fantapunti).
//
// Richiede connect.php (query_all) gia' incluso dal chiamante.
// ============================================================
require_once __DIR__ . "/ChampionsClassifica.php";

// ------------------------------------------------------------
// CAMPIONATO
// ------------------------------------------------------------

/** Confronto di classifica del campionato (come generale.php); a parita' totale, per id. */
function cl_cmp_campionato(array $a, array $b): int
{
    $c = [$b["punti"], $b["golf"], $b["gols"]] <=> [$a["punti"], $a["golf"], $a["gols"]];
    return $c !== 0 ? $c : ($a["id_squadra"] <=> $b["id_squadra"]);
}

/**
 * Classifica del campionato con i risultati live della giornata $giornata.
 *
 * @return array ['classifica' => [...], 'partite_live' => int, 'squadre_live' => int]
 */
function cl_campionato_live(int $stagione, int $giornata): array
{
    // Base: tutte le squadre della stagione con le sole giornate precedenti (chiuse)
    $base = query_all("SELECT s.id AS id_squadra, s.nome AS squadra, s.logo,
                              COALESCE(SUM(r.punti), 0)          AS punti,
                              COUNT(r.id_squadra)                AS partiteg,
                              COALESCE(SUM(r.segno = 'V'), 0)    AS vinte,
                              COALESCE(SUM(r.segno = 'N'), 0)    AS nulle,
                              COALESCE(SUM(r.segno = 'P'), 0)    AS perse,
                              COALESCE(SUM(r.golf), 0)           AS golf,
                              COALESCE(SUM(r.gols), 0)           AS gols,
                              COALESCE(SUM(r.ftotale), 0)        AS somma_f
                       FROM NEW_SQUADRE s
                       LEFT JOIN NEW_RISULTATI r
                              ON r.stagione = s.stagione AND r.id_squadra = s.id AND r.giornata < $giornata
                       WHERE s.stagione = $stagione
                       GROUP BY s.id, s.nome, s.logo");

    // Risultati della giornata in corso: calcolati (priorita') o simulati
    $righe = [];
    foreach (query_all("SELECT id_squadra, ftotale, golf, gols, punti, segno
                        FROM NEW_SIMULAZIONE_RISULTATI WHERE stagione = $stagione AND giornata = $giornata") as $r) {
        $righe[(int) $r["id_squadra"]] = $r + ["fonte" => "simulazione"];
    }
    foreach (query_all("SELECT id_squadra, ftotale, golf, gols, punti, segno
                        FROM NEW_RISULTATI WHERE stagione = $stagione AND giornata = $giornata") as $r) {
        $righe[(int) $r["id_squadra"]] = $r + ["fonte" => "reale"];
    }

    $squadre = [];
    foreach ($base as $b) {
        $squadre[] = [
            "id_squadra" => (int) $b["id_squadra"],
            "squadra"    => $b["squadra"],
            "logo"       => $b["logo"],
            "punti"      => (int) $b["punti"],
            "partiteg"   => (int) $b["partiteg"],
            "vinte"      => (int) $b["vinte"],
            "nulle"      => (int) $b["nulle"],
            "perse"      => (int) $b["perse"],
            "golf"       => (int) $b["golf"],
            "gols"       => (int) $b["gols"],
            "somma_f"    => (float) $b["somma_f"],
        ];
    }

    // Posizione "prima": classifica ufficiale a giornata precedente
    usort($squadre, "cl_cmp_campionato");
    $prima = [];
    foreach ($squadre as $i => $s) $prima[$s["id_squadra"]] = $i + 1;

    // Applica i risultati live
    $nLive = 0;
    foreach ($squadre as &$s) {
        $r = $righe[$s["id_squadra"]] ?? null;
        $s["live"] = $r !== null;
        if ($r !== null) {
            $nLive++;
            $s["punti"]    += (int) $r["punti"];
            $s["partiteg"] += 1;
            if ($r["segno"] === "V")      $s["vinte"]++;
            elseif ($r["segno"] === "N")  $s["nulle"]++;
            else                          $s["perse"]++;
            $s["golf"]    += (int) $r["golf"];
            $s["gols"]    += (int) $r["gols"];
            $s["somma_f"] += (float) $r["ftotale"];
        }
        $s["live_fonte"] = $r["fonte"] ?? null;
        $s["live_segno"] = $r["segno"] ?? null;
        $s["live_punti"] = $r !== null ? (int) $r["punti"] : null;
        $s["live_golf"]  = $r !== null ? (int) $r["golf"]  : null;
        $s["live_gols"]  = $r !== null ? (int) $r["gols"]  : null;
        $s["media"]      = $s["partiteg"] > 0 ? round($s["somma_f"] / $s["partiteg"], 2) : 0.0;
        unset($s["somma_f"]);
    }
    unset($s);

    usort($squadre, "cl_cmp_campionato");
    foreach ($squadre as $i => &$s) {
        $s["posizione"]        = $i + 1;
        $s["posizione_prima"]  = $prima[$s["id_squadra"]];
        $s["delta"]            = $s["posizione_prima"] - $s["posizione"];   // > 0: sale
    }
    unset($s);

    return [
        "classifica"   => $squadre,
        "squadre_live" => $nLive,
        "partite_live" => intdiv($nLive, 2),
    ];
}

// ------------------------------------------------------------
// CHAMPIONS (fasi a gironi)
// ------------------------------------------------------------

/**
 * Righe live della Champions per la giornata di campionato $giornata, nella
 * forma [giornata][id_squadra] => riga (stesse colonne lette da
 * champions_carica_turni): calcolate in NEW_RISULTATI_CHAMP se presenti,
 * altrimenti simulate in NEW_SIMULAZIONE_RISULTATI_CHAMP.
 */
function cl_champions_righe_live(int $stagione, int $giornata): array
{
    $cols = "ftotale, golf, punti, segno, ftotale_a, modificatore, mod_cc, mod_att,
             supp_giocati, supp_golf, supp_gols, rig_giocati, rig_golf, rig_gols, passa";
    $out = [];
    foreach (query_all("SELECT giornata, id_squadra, $cols
                        FROM NEW_SIMULAZIONE_RISULTATI_CHAMP
                        WHERE stagione = $stagione AND giornata = $giornata") as $r) {
        $out[$giornata][(int) $r["id_squadra"]] = $r + ["fonte" => "simulazione"];
    }
    foreach (query_all("SELECT giornata, squadra AS id_squadra, $cols
                        FROM NEW_RISULTATI_CHAMP
                        WHERE stagione = $stagione AND giornata = $giornata") as $r) {
        $out[$giornata][(int) $r["id_squadra"]] = $r + ["fonte" => "reale"];
    }
    return $out;
}

/**
 * Classifica dei gironi Champions della fase in corso con i risultati live.
 * Ritorna null se la giornata non prevede una fase a gironi (fase finale,
 * nessun turno): in quel caso non esiste una classifica.
 *
 * @param string|null $faseId   'fase1' | 'fase2' | 'finale' | null
 * @param array       $gruppiOggi  gironi che giocano o riposano nella giornata
 */
function cl_champions_live(int $stagione, int $giornata, ?string $faseId, array $gruppiOggi): ?array
{
    if (!in_array($faseId, ["fase1", "fase2"], true)) return null;

    $qualificano = $faseId === "fase1" ? 3 : 2;
    $override    = cl_champions_righe_live($stagione, $giornata);
    $live        = $override[$giornata] ?? [];

    $dopo  = champions_gironi(champions_carica_turni($stagione, $override), $faseId, $qualificano);
    $prima = champions_gironi(champions_carica_turni($stagione), $faseId, $qualificano);

    // Posizione "prima" per girone e squadra
    $posPrima = [];
    foreach ($prima as $g) {
        foreach ($g["squadre"] as $i => $s) $posPrima[$g["girone"]][(int) $s["id_squadra"]] = $i + 1;
    }

    $fasi = [];
    foreach (champions_fasi() as $f) $fasi[$f["id"]] = $f["label"];

    $gironi = [];
    foreach ($dopo as $g) {
        // Solo veri gironi (A-D): semifinali e finale non hanno classifica
        if (!preg_match('/^[A-D]$/', (string) $g["girone"])) continue;
        // Solo i gironi coinvolti nella giornata (gli altri giocano in un'altra giornata)
        if ($gruppiOggi && !in_array((string) $g["girone"], array_map("strval", $gruppiOggi), true)) continue;
        $squadre = [];
        foreach ($g["squadre"] as $i => $s) {
            $id = (int) $s["id_squadra"];
            $r  = $live[$id] ?? null;
            $pp = $posPrima[$g["girone"]][$id] ?? null;
            $squadre[] = [
                "id_squadra"      => $id,
                "nome"            => $s["nome"],
                "logo"            => $s["logo"],
                "giocate"         => (int) $s["giocate"],
                "vinte"           => (int) $s["vinte"],
                "nulle"           => (int) $s["nulle"],
                "perse"           => (int) $s["perse"],
                "golf"            => (int) $s["golf"],
                "gols"            => (int) $s["gols"],
                "punti"           => (int) $s["punti"],
                "media"           => (float) $s["media"],
                "posizione"       => $i + 1,
                "posizione_prima" => $pp,
                "delta"           => $pp !== null ? $pp - ($i + 1) : 0,   // > 0: sale
                "live"            => $r !== null,
                "live_fonte"      => $r["fonte"] ?? null,
                "live_segno"      => $r["segno"] ?? null,
                "live_punti"      => $r !== null ? (int) $r["punti"] : null,
                "live_golf"       => $r !== null ? (int) $r["golf"]  : null,
            ];
        }
        $gironi[] = [
            "girone"  => $g["girone"],
            "label"   => champions_etichetta_gruppo((string) $g["girone"]),
            "squadre" => $squadre,
        ];
    }

    return [
        "fase"         => $faseId,
        "fase_label"   => $fasi[$faseId] ?? $faseId,
        "qualificano"  => $qualificano,
        "squadre_live" => count($live),
        "gironi"       => $gironi,
    ];
}
