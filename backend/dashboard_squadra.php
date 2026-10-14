<?php
// ============================================================
// api/dashboard_squadra.php
// GET ?stagione=2026&id_squadra=3
//
// Dati della "Dashboard squadra" in un'unica chiamata, divisi per aree:
//   squadra        nome, logo, allenatore (+ foto), utenza
//   albo           titoli / secondi posti / finali perse (NEW_ALBO_ORO)
//   kpi            miglior / peggior fantapunteggio, media, media avversari
//   classifica     posizione e record in campionato
//   formazione     giornata di riferimento, stato inserimento, link
//   campionato     ultimi 5 risultati, serie, andamento posizione,
//                  matrice vs le altre squadre, Top/Flop 11
//   champions      ultimo risultato, matrice vs le altre squadre, Top/Flop 11
//   prossima       prossimo incontro di campionato: avversario, ultime 5,
//                  ultima formazione schierata, formazione dello scontro
//                  diretto (se già inserita), storico scontri (campionato
//                  e Champions, tutte le stagioni)
//
// Regole:
// - i risultati sono pubblici solo a giornata chiusa (o per le stagioni
//   storiche), come in calendario.php: i provvisori restano nella LIVE;
// - la squadra è riconosciuta tra le stagioni tramite NEW_UTENZE.utenza
//   (l'id squadra è stabile solo all'interno della stessa stagione);
// - in NEW_RISULTATI / NEW_RISULTATI_CHAMP ogni partita ha DUE righe, una
//   per ciascuna prospettiva: si leggono le sole righe della squadra.
// ============================================================
require_once __DIR__ . "/connect.php";
require_once __DIR__ . "/lib/StatoGiornata.php";
require_once __DIR__ . "/lib/ChampionsCalendario.php";

$stagione  = param_int("stagione");
$idSquadra = param_int("id_squadra");

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
                              r.golf, r.gols, r.ftotale, r.ftotale_a, r.punti, r.segno
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

/** Formazione inserita (NEW_FORMAZIONI) di una squadra in una giornata. */
function ds_formazione(int $stagione, int $giornata, int $id): array
{
    $rows = query_all("SELECT f.MAGLIA AS maglia, f.ID_GIOCATORE AS id_giocatore,
                              g.descrizione AS giocatore, g.ruolo
                       FROM NEW_FORMAZIONI f
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

// ── Contesto: giornate pubbliche ─────────────────────────────

$storica = $stagione !== sg_ultima_stagione();
$chiuse  = [];
foreach (query_all("SELECT giornata FROM NEW_CALENDARIO_CK
                    WHERE stagione = $stagione AND ck_giocata = 'S'") as $c) {
    $chiuse[(int) $c["giornata"]] = true;
}
$pub = fn(int $g): bool => $storica || isset($chiuse[$g]);

$ultimaChiusa = $chiuse ? max(array_keys($chiuse)) : 0;
$ultCal = query_one("SELECT MAX(giornata) AS u FROM NEW_CALENDARIO WHERE stagione = $stagione");
$ultimaCalendario = (int) ($ultCal["u"] ?? 0);

// ── Squadra ──────────────────────────────────────────────────

$squadra = query_one("SELECT s.id, s.nome, s.logo,
                             a.descrizione AS allenatore, a.logo AS logo_allenatore
                      FROM NEW_SQUADRE s
                      LEFT JOIN NEW_ALLENATORI a ON a.id_squadra = s.id AND a.stagione = s.stagione
                      WHERE s.id = $idSquadra AND s.stagione = $stagione");
if (!$squadra) api_error("Squadra non trovata", 404);

$uRow   = query_one("SELECT utenza FROM NEW_UTENZE WHERE stagione = $stagione AND id = $idSquadra LIMIT 1");
$utenza = $uRow["utenza"] ?? null;

$tutteSquadre = query_all("SELECT id, nome, logo FROM NEW_SQUADRE WHERE stagione = $stagione ORDER BY nome");

// ── Albo d'oro ───────────────────────────────────────────────

$albo = [
    "disponibile"   => false,
    "campionato"    => ["titoli" => 0, "stagioni" => []],
    "champions"     => ["titoli" => 0, "stagioni" => []],
    "secondi_posti" => ["totale" => 0, "stagioni" => []],
    "finali_perse"  => ["totale" => 0, "stagioni" => []],
];
$rT = mysqli_query($conn, "SHOW TABLES LIKE 'NEW_ALBO_ORO'");
if ($utenza !== null && $rT && mysqli_num_rows($rT) > 0) {
    $albo["disponibile"] = true;
    $u = ds_esc($utenza);
    foreach (query_all("SELECT stagione, competizione, posizione FROM NEW_ALBO_ORO
                        WHERE utenza = '$u' ORDER BY stagione") as $a) {
        $comp = strtoupper($a["competizione"]);
        $pos  = (int) $a["posizione"];
        $st   = (int) $a["stagione"];
        if ($comp === "CAMP"  && $pos === 1) { $albo["campionato"]["titoli"]++;      $albo["campionato"]["stagioni"][]    = $st; }
        if ($comp === "CHAMP" && $pos === 1) { $albo["champions"]["titoli"]++;       $albo["champions"]["stagioni"][]     = $st; }
        if ($comp === "CAMP"  && $pos === 2) { $albo["secondi_posti"]["totale"]++;   $albo["secondi_posti"]["stagioni"][] = $st; }
        if ($comp === "CHAMP" && $pos === 2) { $albo["finali_perse"]["totale"]++;    $albo["finali_perse"]["stagioni"][]  = $st; }
    }
}

// ── Classifica e KPI ─────────────────────────────────────────

$generale = query_all("SELECT id_squadra, squadra, logo, punti, partiteg, vinte, nulle, perse,
                              golf, gols, maxp, minp, media, media_a
                       FROM NEW_GENERALE
                       WHERE stagione = $stagione
                       ORDER BY punti DESC, golf DESC, gols DESC");
$genPerId = [];
foreach ($generale as $i => $g) {
    $g["posizione"] = $i + 1;
    $genPerId[(int) $g["id_squadra"]] = $g;
}
$gen = $genPerId[$idSquadra] ?? null;

$kpi = [
    "migliore"       => $gen ? (float) $gen["maxp"]    : null,
    "peggiore"       => $gen ? (float) $gen["minp"]    : null,
    "media"          => $gen ? (float) $gen["media"]   : null,
    "media_avversari" => $gen ? (float) $gen["media_a"] : null,
];
$classifica = $gen ? [
    "posizione" => (int) $gen["posizione"],
    "squadre"   => count($generale),
    "punti"     => (int) $gen["punti"],
    "giocate"   => (int) $gen["partiteg"],
    "vinte"     => (int) $gen["vinte"],
    "nulle"     => (int) $gen["nulle"],
    "perse"     => (int) $gen["perse"],
    "golf"      => (int) $gen["golf"],
    "gols"      => (int) $gen["gols"],
] : null;

// ── Formazione: giornata di riferimento ──────────────────────

$conclusa  = $ultimaCalendario === 0 || $ultimaChiusa >= $ultimaCalendario;
$giornataF = $conclusa ? $ultimaCalendario : $ultimaChiusa + 1;

// La giornata di fantacampionato NON coincide con quella di Serie A:
// GIORNATA_SERIE_A_INIZIO (Gestisci regole di calcolo) indica la giornata
// di Serie A corrispondente alla 1ª di fantacampionato (stessa regola di
// admin/voti_serie_a.php: serie_a = fanta + inizio - 1).
$inizio = null;
$giornataSerieA = null;
if (!$conclusa) {
    $par = query_one("SELECT valore FROM NEW_PARAMETRI_STAGIONE
                      WHERE stagione = $stagione AND codice = 'GIORNATA_SERIE_A_INIZIO'");
    $v = $par ? trim((string) $par["valore"]) : "";
    $inizioSerieA = ($v !== "" && ctype_digit($v) && (int) $v >= 1) ? (int) $v : 1;
    $giornataSerieA = $giornataF + $inizioSerieA - 1;

    $p = query_one("SELECT data_partita, ora_partita FROM NEW_CALENDARIO_SERIE_A
                    WHERE stagione = $stagione AND giornata = $giornataSerieA
                    ORDER BY data_partita ASC, COALESCE(ora_partita, '23:59:59') ASC
                    LIMIT 1");
    if ($p) $inizio = $p["data_partita"] . " " . ($p["ora_partita"] ?: "00:00:00");
}
$nTit = query_one("SELECT COUNT(*) AS n FROM NEW_FORMAZIONI
                   WHERE STAGIONE = $stagione AND GIORNATA = $giornataF AND ID_SQUADRA = $idSquadra
                     AND MAGLIA BETWEEN 1 AND 11");
$formazione = [
    "giornata"        => $giornataF,
    "giornata_serie_a" => $giornataSerieA,
    "stagione_conclusa" => $conclusa,
    "titolari"        => (int) ($nTit["n"] ?? 0),
    "inserita"        => (int) ($nTit["n"] ?? 0) === 11,
    "inizio_giornata" => $inizio,
];

// ── Campionato ───────────────────────────────────────────────

$mieCamp = ds_partite_camp($stagione, $idSquadra, $pub);
$ultime5 = array_slice(array_reverse($mieCamp), 0, 5);
$serie   = ds_serie(array_map(fn($p) => $p["segno"], $mieCamp));

// Andamento della posizione in classifica giornata per giornata:
// classifica ricostruita dai risultati con lo stesso ordinamento di
// generale.php (punti, gol fatti, gol subiti). Non considera eventuali
// penalità, applicate solo alla classifica generale.
$andamento = [];
$tot = [];
$perGiornata = [];
foreach (query_all("SELECT giornata, id_squadra, punti, golf, gols, ftotale
                    FROM NEW_RISULTATI WHERE stagione = $stagione ORDER BY giornata") as $r) {
    if (!$pub((int) $r["giornata"])) continue;
    $perGiornata[(int) $r["giornata"]][] = $r;
}
ksort($perGiornata);
foreach ($perGiornata as $g => $righe) {
    $mioFp = null;
    foreach ($righe as $r) {
        $id = (int) $r["id_squadra"];
        if (!isset($tot[$id])) $tot[$id] = ["id" => $id, "punti" => 0, "golf" => 0, "gols" => 0];
        $tot[$id]["punti"] += (int) $r["punti"];
        $tot[$id]["golf"]  += (int) $r["golf"];
        $tot[$id]["gols"]  += (int) $r["gols"];
        if ($id === $idSquadra) $mioFp = (float) $r["ftotale"];
    }
    $ord = array_values($tot);
    usort($ord, fn($a, $b) => [$b["punti"], $b["golf"], $b["gols"], $a["id"]]
                          <=> [$a["punti"], $a["golf"], $a["gols"], $b["id"]]);
    foreach ($ord as $pos => $t) {
        if ($t["id"] === $idSquadra) {
            $andamento[] = ["giornata" => $g, "posizione" => $pos + 1,
                            "punti" => $t["punti"], "fantapunti" => $mioFp];
            break;
        }
    }
}

$campionato = [
    "ultime5"   => $ultime5,
    "serie"     => $serie,
    "andamento" => $andamento,
    "matrice"   => ds_matrice($tutteSquadre, $idSquadra, $mieCamp),
    "top11"     => ds_eleven("NEW_TOP11",  $stagione, $idSquadra, "DESC"),
    "flop11"    => ds_eleven("NEW_FLOP11", $stagione, $idSquadra, "ASC"),
];

// ── Champions ────────────────────────────────────────────────

$mieChamp = ds_partite_champ($stagione, $idSquadra, $pub);
$champions = [
    "ultimo"  => $mieChamp ? end($mieChamp) : null,
    "partite" => $mieChamp,
    "matrice" => ds_matrice($tutteSquadre, $idSquadra, $mieChamp),
    "top11"   => ds_eleven("NEW_TOP11_CHAMP",  $stagione, $idSquadra, "DESC"),
    "flop11"  => ds_eleven("NEW_FLOP11_CHAMP", $stagione, $idSquadra, "ASC"),
];

// ── Prossima sfida (campionato) ──────────────────────────────

$prossima = null;
if (!$conclusa) {
    $mio = query_one("SELECT posizione FROM NEW_CALENDARIO
                      WHERE stagione = $stagione AND giornata = $giornataF AND squadra = $idSquadra");
    if ($mio) {
        $pos    = (int) $mio["posizione"];
        $casa   = $pos % 2 === 1;
        $posAvv = $casa ? $pos + 1 : $pos - 1;
        $avvRow = query_one("SELECT squadra FROM NEW_CALENDARIO
                             WHERE stagione = $stagione AND giornata = $giornataF AND posizione = $posAvv");
        $idAvv  = $avvRow ? (int) $avvRow["squadra"] : 0;

        $avv = $idAvv ? query_one("SELECT s.id, s.nome, s.logo,
                                          a.descrizione AS allenatore, a.logo AS logo_allenatore
                                   FROM NEW_SQUADRE s
                                   LEFT JOIN NEW_ALLENATORI a ON a.id_squadra = s.id AND a.stagione = s.stagione
                                   WHERE s.id = $idAvv AND s.stagione = $stagione") : null;
        if ($avv) {
            $gAvv = $genPerId[$idAvv] ?? null;
            $partiteAvv = ds_partite_camp($stagione, $idAvv, $pub);

            // Ultima formazione schierata (ultima giornata chiusa giocata)
            $ultimaForm = null;
            if ($partiteAvv) {
                $ultP = end($partiteAvv);
                $ultimaForm = [
                    "giornata"   => $ultP["giornata"],
                    "partita"    => $ultP,
                    "giocatori"  => ds_formazione_con_voti($stagione, $ultP["giornata"], $idAvv),
                ];
            }

            // Storico scontri (utenza = identità stabile tra le stagioni)
            $uAvvRow = query_one("SELECT utenza FROM NEW_UTENZE WHERE stagione = $stagione AND id = $idAvv LIMIT 1");
            $uAvv    = $uAvvRow["utenza"] ?? null;
            $storico = ["campionato" => null, "champions" => null];
            if ($utenza !== null && $uAvv !== null) {
                $storico["campionato"] = ds_storico("NEW_RISULTATI",       "id_squadra", false, $stagione, $utenza, $uAvv, $pub);
                $storico["champions"]  = ds_storico("NEW_RISULTATI_CHAMP", "squadra",    true,  $stagione, $utenza, $uAvv, $pub);
            }

            $formMia = ds_formazione($stagione, $giornataF, $idSquadra);
            $formAvv = ds_formazione($stagione, $giornataF, $idAvv);

            $prossima = [
                "giornata"  => $giornataF,
                "casa"      => $casa,
                "avversario" => [
                    "id" => (int) $avv["id"], "nome" => $avv["nome"], "logo" => $avv["logo"],
                    "allenatore" => $avv["allenatore"], "logo_allenatore" => $avv["logo_allenatore"],
                    "classifica" => $gAvv ? [
                        "posizione" => (int) $gAvv["posizione"],
                        "punti"     => (int) $gAvv["punti"],
                        "giocate"   => (int) $gAvv["partiteg"],
                        "vinte"     => (int) $gAvv["vinte"],
                        "nulle"     => (int) $gAvv["nulle"],
                        "perse"     => (int) $gAvv["perse"],
                        "golf"      => (int) $gAvv["golf"],
                        "gols"      => (int) $gAvv["gols"],
                        "media"     => (float) $gAvv["media"],
                        "migliore"  => (float) $gAvv["maxp"],
                        "peggiore"  => (float) $gAvv["minp"],
                    ] : null,
                    "ultime5"   => array_slice(array_reverse($partiteAvv), 0, 5),
                ],
                "ultima_formazione_avversario" => $ultimaForm,
                // Formazioni già inserite per questo scontro diretto (se presenti)
                "formazione_scontro" => [
                    "mia"        => $formMia ?: null,
                    "avversario" => $formAvv ?: null,
                ],
                "storico" => $storico,
            ];
        }
    }
}

api_success([
    "stagione"   => $stagione,
    "squadra"    => $squadra,
    "utenza"     => $utenza,
    "albo"       => $albo,
    "kpi"        => $kpi,
    "classifica" => $classifica,
    "formazione" => $formazione,
    "campionato" => $campionato,
    "champions"  => $champions,
    "prossima"   => $prossima,
]);
