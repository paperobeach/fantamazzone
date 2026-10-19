<?php
// ============================================================
// backend/lib/DashboardSquadra.php
//
// Funzioni condivise dagli endpoint della Dashboard squadra:
//   dashboard_squadra.php      intestazione, appuntamenti, prossima sfida
//   dashboard_statistiche.php  area "Statistiche"
//
// Regole comuni:
// - in NEW_RISULTATI / NEW_RISULTATI_CHAMP ogni partita ha DUE righe, una
//   per ciascuna prospettiva: si leggono le sole righe della squadra;
// - i risultati sono pubblici solo a giornata chiusa (o per le stagioni
//   storiche): il chiamante passa il callable $pub(giornata): bool.
//
// Va incluso DOPO connect.php (usa query_one / query_all) e dopo
// lib/StatoGiornata.php, lib/ChampionsCalendario.php e
// lib/FormazioniChampions.php.
// ============================================================

// ── Helper ───────────────────────────────────────────────────

function ds_segno($s): string
{
    $s = strtoupper(trim((string) $s));
    if ($s === "W") return "V";
    if ($s === "L") return "P";
    return $s;
}

function ds_esc(string $v): string
{
    global $conn;
    return mysqli_real_escape_string($conn, $v);
}

/** Partita dal punto di vista della squadra (riga di NEW_RISULTATI[_CHAMP]). */
function ds_partita(array $r): array
{
    return [
        "stagione"   => isset($r["stagione"]) ? (int) $r["stagione"] : null,
        "giornata"   => (int) $r["giornata"],
        "id_avv"     => isset($r["id_avv"]) ? (int) $r["id_avv"] : null,
        "avversario" => $r["avversario"] ?? null,
        "logo_avv"   => $r["logo_avv"] ?? null,
        "golf"       => (int) $r["golf"],
        "gols"       => (int) $r["gols"],
        "ftotale"    => (float) $r["ftotale"],
        "ftotale_a"  => (float) $r["ftotale_a"],
        "punti"      => (int) $r["punti"],
        "segno"      => ds_segno($r["segno"]),
        "casa"       => isset($r["casa"]) && $r["casa"] !== null ? ((int) $r["casa"] === 1) : null,
        "turno"      => $r["turno"] ?? null,
    ];
}

/** Partite di campionato giocate (e pubbliche) di una squadra nella stagione. */
function ds_partite_camp(int $stagione, int $id, callable $pub): array
{
    $rows = query_all("SELECT r.stagione, r.giornata, r.id_squadra_a AS id_avv,
                              s.nome AS avversario, s.logo AS logo_avv,
                              r.golf, r.gols, r.ftotale, r.ftotale_a, r.punti, r.segno,
                              (cal.posizione % 2 = 1) AS casa
                       FROM NEW_RISULTATI r
                       JOIN NEW_SQUADRE s ON s.id = r.id_squadra_a AND s.stagione = r.stagione
                       LEFT JOIN NEW_CALENDARIO cal ON cal.stagione = r.stagione
                                                   AND cal.giornata = r.giornata
                                                   AND cal.squadra  = r.id_squadra
                       WHERE r.stagione = $stagione AND r.id_squadra = $id
                       ORDER BY r.giornata");
    $out = [];
    foreach ($rows as $r) {
        if (!$pub((int) $r["giornata"])) continue;
        $out[] = ds_partita($r);
    }
    return $out;
}

/** Partite di Champions giocate (e pubbliche) di una squadra nella stagione. */
function ds_partite_champ(int $stagione, int $id, callable $pub): array
{
    $rows = query_all("SELECT r.stagione, r.giornata, r.girone, r.id_squadra_a AS id_avv,
                              s.nome AS avversario, s.logo AS logo_avv,
                              r.golf, r.gols, r.ftotale, r.ftotale_a, r.punti, r.segno,
                              ((SELECT MIN(cc.posizione) FROM NEW_CALENDARIO_CHAMP cc
                                WHERE cc.stagione = r.stagione AND cc.giornata_camp = r.giornata
                                  AND cc.squadra = r.squadra) % 2 = 1) AS casa
                       FROM NEW_RISULTATI_CHAMP r
                       JOIN NEW_SQUADRE s ON s.id = r.id_squadra_a AND s.stagione = r.stagione
                       WHERE r.stagione = $stagione AND r.squadra = $id
                       ORDER BY r.giornata");
    $out = [];
    foreach ($rows as $r) {
        if (!$pub((int) $r["giornata"])) continue;
        $r["turno"] = champions_etichetta_gruppo(champions_gruppo_girone($r["girone"] ?? ""));
        $out[] = ds_partita($r);
    }
    return $out;
}

/** Serie (corrente e record) calcolate sulla sequenza degli esiti V/N/P. */
function ds_serie(array $segni): array
{
    $def = [
        "vittorie"       => ["V"],
        "imbattibilita"  => ["V", "N"],
        "sconfitte"      => ["P"],
        "senza_vittorie" => ["N", "P"],
    ];
    $out = [];
    foreach ($def as $k => $set) {
        $cur = 0; $best = 0;
        foreach ($segni as $s) {
            if (in_array($s, $set, true)) { $cur++; if ($cur > $best) $best = $cur; }
            else $cur = 0;
        }
        $out[$k] = ["corrente" => $cur, "record" => $best];
    }
    return $out;
}

/** Top/Flop 11 della squadra (tab = NEW_TOP11 / NEW_FLOP11 / ..._CHAMP). */
function ds_eleven(string $tab, int $stagione, int $id, string $ordine): array
{
    $rows = query_all("SELECT id_giocatore, giocatore, media, ruolo, giocate
                       FROM $tab
                       WHERE stagione = $stagione AND id_squadra = $id
                       ORDER BY ruolo, media $ordine");
    return array_map(fn($r) => [
        "id_giocatore" => (int) $r["id_giocatore"],
        "giocatore"    => $r["giocatore"],
        "media"        => (float) $r["media"],
        "ruolo"        => (int) $r["ruolo"],
        "giocate"      => (int) $r["giocate"],
    ], $rows);
}

/** Matrice contro ogni altra squadra: per ciascuna, gli incontri giocati. */
function ds_matrice(array $squadre, int $idSquadra, array $partite): array
{
    $per = [];
    foreach ($partite as $p) $per[$p["id_avv"]][] = [
        "giornata" => $p["giornata"], "segno" => $p["segno"],
        "golf" => $p["golf"], "gols" => $p["gols"],
        "casa" => $p["casa"], "turno" => $p["turno"],
    ];
    $out = [];
    foreach ($squadre as $s) {
        $id = (int) $s["id"];
        if ($id === $idSquadra) continue;
        $out[] = [
            "id" => $id, "nome" => $s["nome"], "logo" => $s["logo"],
            "incontri" => $per[$id] ?? [],
        ];
    }
    return $out;
}

/** Formazione inserita di una squadra in una giornata (campionato o, con $tab, Champions). */
function ds_formazione(int $stagione, int $giornata, int $id, string $tab = "NEW_FORMAZIONI"): array
{
    // $tab è sempre una costante interna (NEW_FORMAZIONI / NEW_FORMAZIONI_CHAMP),
    // mai un valore della richiesta.
    $rows = query_all("SELECT f.MAGLIA AS maglia, f.ID_GIOCATORE AS id_giocatore,
                              g.descrizione AS giocatore, g.ruolo
                       FROM $tab f
                       JOIN NEW_GIOCATORI g ON g.id = f.ID_GIOCATORE AND g.stagione = f.STAGIONE
                       WHERE f.STAGIONE = $stagione AND f.GIORNATA = $giornata AND f.ID_SQUADRA = $id
                       ORDER BY f.MAGLIA");
    return array_map(fn($r) => [
        "id_giocatore" => (int) $r["id_giocatore"],
        "giocatore"    => $r["giocatore"],
        "ruolo"        => (int) $r["ruolo"],
        "maglia"       => (int) $r["maglia"],
        "titolare"     => (int) $r["maglia"] >= 1 && (int) $r["maglia"] <= 11,
    ], $rows);
}

/** Formazione schierata in una giornata chiusa, con voti e fantavoti. */
function ds_formazione_con_voti(int $stagione, int $giornata, int $id): array
{
    $rows = query_all("SELECT f.MAGLIA AS maglia, f.ID_GIOCATORE AS id_giocatore,
                              g.descrizione AS giocatore, g.ruolo,
                              v.voto, v.totale, v.giocata
                       FROM NEW_FORMAZIONI f
                       JOIN NEW_GIOCATORI g ON g.id = f.ID_GIOCATORE AND g.stagione = f.STAGIONE
                       LEFT JOIN NEW_VOTI v ON v.stagione = f.STAGIONE AND v.giornata = f.GIORNATA
                                           AND v.id_squadra = f.ID_SQUADRA AND v.id_giocatore = f.ID_GIOCATORE
                       WHERE f.STAGIONE = $stagione AND f.GIORNATA = $giornata AND f.ID_SQUADRA = $id
                       ORDER BY f.MAGLIA");
    return array_map(fn($r) => [
        "id_giocatore" => (int) $r["id_giocatore"],
        "giocatore"    => $r["giocatore"],
        "ruolo"        => (int) $r["ruolo"],
        "maglia"       => (int) $r["maglia"],
        "titolare"     => (int) $r["maglia"] >= 1 && (int) $r["maglia"] <= 11,
        "voto"         => $r["voto"] !== null ? (float) $r["voto"] : null,
        "totale"       => $r["totale"] !== null ? (float) $r["totale"] : null,
        "giocata"      => $r["giocata"] !== null ? (int) $r["giocata"] === 1 : null,
    ], $rows);
}

/** Storico scontri diretti tra due utenze, tutte le stagioni fino a $stagione. */
function ds_storico(string $tabella, string $colCasa, bool $champ, int $stagione, string $uMe, string $uOpp, callable $pub): array
{
    $me  = ds_esc($uMe);
    $opp = ds_esc($uOpp);
    $extra = $champ ? ", r.girone" : "";
    $rows = query_all("SELECT r.stagione, r.giornata, r.golf, r.gols, r.ftotale, r.ftotale_a,
                              r.punti, r.segno $extra
                       FROM $tabella r
                       JOIN (SELECT DISTINCT stagione, id FROM NEW_UTENZE WHERE utenza = '$me')  um
                         ON um.stagione = r.stagione AND um.id = r.$colCasa
                       JOIN (SELECT DISTINCT stagione, id FROM NEW_UTENZE WHERE utenza = '$opp') uo
                         ON uo.stagione = r.stagione AND uo.id = r.id_squadra_a
                       WHERE r.stagione <= $stagione
                       ORDER BY r.stagione DESC, r.giornata DESC");
    $partite = [];
    $riep = ["giocate" => 0, "vinte" => 0, "nulle" => 0, "perse" => 0,
             "golf" => 0, "gols" => 0, "media_fp" => null, "media_fp_avv" => null];
    $sumA = 0.0; $sumB = 0.0;
    foreach ($rows as $r) {
        if ((int) $r["stagione"] === $stagione && !$pub((int) $r["giornata"])) continue;
        $seg = ds_segno($r["segno"]);
        $riep["giocate"]++;
        if ($seg === "V") $riep["vinte"]++;
        elseif ($seg === "N") $riep["nulle"]++;
        else $riep["perse"]++;
        $riep["golf"] += (int) $r["golf"];
        $riep["gols"] += (int) $r["gols"];
        $sumA += (float) $r["ftotale"];
        $sumB += (float) $r["ftotale_a"];
        $partite[] = [
            "stagione" => (int) $r["stagione"],
            "giornata" => (int) $r["giornata"],
            "golf"     => (int) $r["golf"],
            "gols"     => (int) $r["gols"],
            "ftotale"  => (float) $r["ftotale"],
            "ftotale_a" => (float) $r["ftotale_a"],
            "segno"    => $seg,
            "turno"    => $champ ? champions_etichetta_gruppo(champions_gruppo_girone($r["girone"] ?? "")) : null,
        ];
    }
    if ($riep["giocate"] > 0) {
        $riep["media_fp"]     = round($sumA / $riep["giocate"], 2);
        $riep["media_fp_avv"] = round($sumB / $riep["giocate"], 2);
    }
    return ["riepilogo" => $riep, "partite" => array_slice($partite, 0, 30)];
}

/**
 * Inizio della giornata di fantacampionato = data/ora della prima partita
 * di Serie A della giornata corrispondente (limite per l'inserimento della
 * formazione). La giornata di fantacampionato NON coincide con quella di
 * Serie A: GIORNATA_SERIE_A_INIZIO (Gestisci regole di calcolo) indica la
 * giornata di Serie A corrispondente alla 1ª di fantacampionato
 * (serie_a = fanta + inizio - 1, come in admin/voti_serie_a.php).
 *
 * @return array [giornata_serie_a, inizio ("Y-m-d H:i:s" | null)]
 */
function ds_inizio_giornata(int $stagione, int $giornataFanta): array
{
    $par = query_one("SELECT valore FROM NEW_PARAMETRI_STAGIONE
                      WHERE stagione = $stagione AND codice = 'GIORNATA_SERIE_A_INIZIO'");
    $v = $par ? trim((string) $par["valore"]) : "";
    $inizioSerieA = ($v !== "" && ctype_digit($v) && (int) $v >= 1) ? (int) $v : 1;
    $giornataSerieA = $giornataFanta + $inizioSerieA - 1;

    $p = query_one("SELECT data_partita, ora_partita FROM NEW_CALENDARIO_SERIE_A
                    WHERE stagione = $stagione AND giornata = $giornataSerieA
                    ORDER BY data_partita ASC, COALESCE(ora_partita, '23:59:59') ASC
                    LIMIT 1");
    $inizio = $p ? $p["data_partita"] . " " . ($p["ora_partita"] ?: "00:00:00") : null;
    return [$giornataSerieA, $inizio];
}

/** Numero di titolari (maglie 1..11) inseriti in una tabella formazioni. */
function ds_titolari(string $tab, int $stagione, int $giornata, int $id): int
{
    $r = query_one("SELECT COUNT(*) AS n FROM $tab
                    WHERE STAGIONE = $stagione AND GIORNATA = $giornata AND ID_SQUADRA = $id
                      AND MAGLIA BETWEEN 1 AND 11");
    return (int) ($r["n"] ?? 0);
}

/** Stato di inserimento della formazione di una squadra (completa = 11 titolari). */
function ds_stato_formazione(string $tab, int $stagione, int $giornata, int $id): array
{
    $n = ds_titolari($tab, $stagione, $giornata, $id);
    return ["titolari" => $n, "inserita" => $n === 11];
}

/** Dati essenziali di una squadra avversaria (nome, logo, allenatore + foto). */
function ds_avversario(int $stagione, int $idAvv): ?array
{
    $a = query_one("SELECT s.id, s.nome, s.logo,
                           a.descrizione AS allenatore, a.logo AS logo_allenatore
                    FROM NEW_SQUADRE s
                    LEFT JOIN NEW_ALLENATORI a ON a.id_squadra = s.id AND a.stagione = s.stagione
                    WHERE s.id = $idAvv AND s.stagione = $stagione");
    if (!$a) return null;
    return [
        "id" => (int) $a["id"], "nome" => $a["nome"], "logo" => $a["logo"],
        "allenatore" => $a["allenatore"], "logo_allenatore" => $a["logo_allenatore"],
    ];
}

/**
 * Prossima partita di Champions della squadra a partire dalla giornata di
 * fantacampionato $da (inclusa). Usa la stessa logica di accoppiamento di
 * champions.php / FormazioniChampions.php: in ogni gruppo le righe di
 * NEW_CALENDARIO_CHAMP ordinate per posizione formano le partite a coppie
 * (dispari = casa, pari = ospite); l'ultima riga spaiata è chi riposa.
 * Null se la squadra non ha altre partite di Champions in calendario.
 */
function ds_prossima_champions(int $stagione, int $idSquadra, int $da): ?array
{
    $g = query_one("SELECT MIN(giornata_camp) AS g FROM NEW_CALENDARIO_CHAMP
                    WHERE stagione = $stagione AND squadra = $idSquadra AND giornata_camp >= $da");
    // MIN su un insieme vuoto = NULL
    $giornata = $g && $g["g"] !== null ? (int) $g["g"] : 0;

    // La squadra può riposare nella giornata trovata: si prosegue con le successive
    while ($giornata > 0) {
        foreach (champions_partite_giornata($stagione, $giornata)["partite"] as $p) {
            if ($p["casa"] !== $idSquadra && $p["ospite"] !== $idSquadra) continue;
            $casa = $p["casa"] === $idSquadra;
            return [
                "giornata"  => $giornata,
                "casa"      => $casa,
                "id_avv"    => $casa ? $p["ospite"] : $p["casa"],
                "turno"     => champions_etichetta_gruppo(champions_gruppo_girone($p["girone"])),
                "rigori"    => champions_rigori_previsti($stagione, $giornata, $idSquadra),
            ];
        }
        $n = query_one("SELECT MIN(giornata_camp) AS g FROM NEW_CALENDARIO_CHAMP
                        WHERE stagione = $stagione AND squadra = $idSquadra AND giornata_camp > $giornata");
        $giornata = $n && $n["g"] !== null ? (int) $n["g"] : 0;
    }
    return null;
}

/** Casa/trasferta e avversario di campionato di una squadra in una giornata. */
function ds_avversario_camp(int $stagione, int $giornata, int $idSquadra): ?array
{
    $mio = query_one("SELECT posizione FROM NEW_CALENDARIO
                      WHERE stagione = $stagione AND giornata = $giornata AND squadra = $idSquadra");
    if (!$mio) return null;
    $pos    = (int) $mio["posizione"];
    $casa   = $pos % 2 === 1;
    $posAvv = $casa ? $pos + 1 : $pos - 1;
    $avv    = query_one("SELECT squadra FROM NEW_CALENDARIO
                         WHERE stagione = $stagione AND giornata = $giornata AND posizione = $posAvv");
    return $avv ? ["casa" => $casa, "id_avv" => (int) $avv["squadra"]] : null;
}
