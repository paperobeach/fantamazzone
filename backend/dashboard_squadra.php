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
//   appuntamenti   prossimo match di campionato e di Champions (avversario,
//                  casa/trasferta, giornata), scadenza per l'inserimento della
//                  formazione (prima partita di Serie A della giornata) e
//                  indicatori "formazione inserita" propria e degli avversari
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
require_once __DIR__ . "/lib/FormazioniChampions.php";
require_once __DIR__ . "/lib/DashboardSquadra.php";
require_once __DIR__ . "/lib/FormazioniBlocco.php";

$stagione  = param_int("stagione");
$idSquadra = param_int("id_squadra");
$idRichiedente = param_int("id_richiedente", false) ?? $idSquadra;   // chi sta guardando (formazioni nascoste)

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

$inizio = null;
$giornataSerieA = null;
if (!$conclusa) {
    [$giornataSerieA, $inizio] = ds_inizio_giornata($stagione, $giornataF);
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
            // "Nascondi formazione": non visibile agli altri fino alla prima partita di Serie A
            $miaNascosta = $formMia && fb_formazione_nascosta_a($stagione, $giornataF, $idSquadra, $idRichiedente);
            $avvNascosta = $formAvv && fb_formazione_nascosta_a($stagione, $giornataF, $idAvv, $idRichiedente);
            if ($miaNascosta) $formMia = [];
            if ($avvNascosta) $formAvv = [];

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
                    "mia_nascosta"        => (bool) $miaNascosta,
                    "avversario_nascosta" => (bool) $avvNascosta,
                ],
                "storico" => $storico,
            ];
        }
    }
}

// ── Appuntamenti (intestazione fissa) ────────────────────────
// Prossimo match di campionato e di Champions, limite per l'inserimento
// della formazione e indicatori "formazione inserita" (mia e degli
// avversari). Per gli avversari si espone solo lo stato (inserita sì/no),
// non la formazione.

$appCamp  = null;
$appChamp = null;
if (!$conclusa) {
    $tabCamp = FORMAZIONE_TAB_CAMPIONATO;

    $ac = ds_avversario_camp($stagione, $giornataF, $idSquadra);
    $avvC = $ac ? ds_avversario($stagione, $ac["id_avv"]) : null;
    if ($avvC) {
        $appCamp = [
            "giornata"         => $giornataF,
            "giornata_serie_a" => $giornataSerieA,
            "casa"             => $ac["casa"],
            "scadenza"         => $inizio,
            "avversario"       => $avvC,
            "formazione_mia"   => ds_stato_formazione($tabCamp, $stagione, $giornataF, $idSquadra),
            "formazione_avv"   => ds_stato_formazione($tabCamp, $stagione, $giornataF, $avvC["id"]),
        ];
    }

    $pc = ds_prossima_champions($stagione, $idSquadra, $giornataF);
    $avvH = $pc ? ds_avversario($stagione, $pc["id_avv"]) : null;
    if ($pc && $avvH) {
        $gC = $pc["giornata"];
        if ($gC === $giornataF) {
            $gSerieAC = $giornataSerieA;
            $scadC    = $inizio;
        } else {
            [$gSerieAC, $scadC] = ds_inizio_giornata($stagione, $gC);
        }
        $tabMia = formazione_tabella_champions($stagione, $gC, $idSquadra);
        $tabAvv = formazione_tabella_champions($stagione, $gC, $avvH["id"]);
        $appChamp = [
            "giornata"         => $gC,
            "giornata_serie_a" => $gSerieAC,
            "casa"             => $pc["casa"],
            "turno"            => $pc["turno"],
            "rigori"           => $pc["rigori"],
            "scadenza"         => $scadC,
            "avversario"       => $avvH,
            "formazione_mia"   => ds_stato_formazione($tabMia, $stagione, $gC, $idSquadra),
            "formazione_avv"   => ds_stato_formazione($tabAvv, $stagione, $gC, $avvH["id"]),
        ];
    }
}
$appuntamenti = [
    "campionato" => $appCamp,
    "champions"  => $appChamp,
];

api_success([
    "stagione"   => $stagione,
    "squadra"    => $squadra,
    "utenza"     => $utenza,
    "albo"       => $albo,
    "kpi"        => $kpi,
    "classifica" => $classifica,
    "formazione" => $formazione,
    "appuntamenti" => $appuntamenti,
    "campionato" => $campionato,
    "champions"  => $champions,
    "prossima"   => $prossima,
]);
