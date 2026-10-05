<?php
// ============================================================
// backend/lib/SimulazioneGiornata.php
//
// Simulazione del calcolo della giornata IN CORSO con i voti
// disponibili al momento (pagina "LIVE Giornata in corso").
//
// Riusa il motore CalcolatoreVoti::calcolaPartita() e la stessa
// configurazione di calcolo della fase 2 (RegoleCalcolo.php), ma NON
// scrive mai su NEW_VOTI / NEW_RISULTATI: salva su
//   - NEW_SIMULAZIONE_VOTI       (voti per giocatore)
//   - NEW_SIMULAZIONE_RISULTATI  (risultati, due righe per partita)
// ricostruite da zero ad ogni esecuzione. L'editing manuale vive in
//   - NEW_SIMULAZIONE_EDIT
// (vedi Script DB/NEW_SIMULAZIONE.sql).
//
// Le regole di sostituzione/riserva d'ufficio/fattore casa/fasce gol
// sono quelle di backend/admin/calcolo_giornata.php (le funzioni sono
// riscritte qui con prefisso sim_ per non modificare il calcolo reale).
//
// TRATTAMENTO DEL GIOCATORE SENZA VOTO (per ogni giocatore, in ordine):
//   1. esiste la riga in NEW_VOTI_SERIE_A per la giornata → dato REALE
//      (se è un SV segue la normale sostituzione da panchina/d'ufficio);
//   2. la squadra di Serie A del giocatore HA GIÀ GIOCATO (esiste
//      almeno un voto per quella squadra nella giornata) ma il
//      giocatore non ha riga → senza voto → va SOSTITUITO;
//   3. la squadra di Serie A DEVE ANCORA GIOCARE (nessun voto per la
//      squadra):
//        a. se c'è un editing manuale (NEW_SIMULAZIONE_EDIT) →
//           voto/dati manuali (manuale = 1): PREVALE sul 6 provvisorio;
//        b. altrimenti 6 PROVVISORIO (provvisorio = 1), nessun bonus.
//   Se la squadra di Serie A del giocatore non è determinabile si
//   applica il punto 3 (con un avviso).
//
// Anche un panchinaro candidato alla sostituzione segue le stesse
// regole: se la sua squadra deve ancora giocare entra con il suo 6
// provvisorio (o con l'editing manuale), evidenziato come tale.
//
// Richiede connect.php (query_one / query_all) già incluso dal
// chiamante.
// ============================================================
require_once __DIR__ . "/CalcolatoreVoti.php";
require_once __DIR__ . "/RegoleCalcolo.php";

const SIM_VOTO_PROVVISORIO = 6.0;

// Nome squadra normalizzato per i confronti (maiuscolo, senza spazi ai lati)
function sim_norm(?string $s): string
{
    $s = trim((string) $s);
    return function_exists('mb_strtoupper') ? mb_strtoupper($s, 'UTF-8') : strtoupper($s);
}

// ------------------------------------------------------------
// Contesto di calcolo (letture preliminari, una volta sola)
// ------------------------------------------------------------

// id_giocatore => nome squadra di Serie A. Base: staging dell'asta
// (NEW_GIOCATORI_BASE_ASTA); l'ultima squadra con cui il giocatore
// compare in NEW_VOTI_SERIE_A prevale (gestisce i trasferimenti).
function sim_squadre_serie_a_giocatori(int $stagione): array
{
    $mappa = [];
    foreach (query_all("SELECT id_giocatore, squadra_serie_a AS squadra
                        FROM NEW_GIOCATORI_BASE_ASTA WHERE stagione = $stagione") as $r) {
        $mappa[(int) $r['id_giocatore']] = $r['squadra'];
    }
    foreach (query_all("SELECT id_giocatore, squadra FROM NEW_VOTI_SERIE_A
                        WHERE stagione = $stagione ORDER BY giornata") as $r) {
        $mappa[(int) $r['id_giocatore']] = $r['squadra'];
    }
    return $mappa;
}

// Contesto: voti Serie A della giornata, squadre già scese in campo,
// squadra di Serie A di ogni giocatore, editing manuale.
function sim_contesto(int $stagione, int $giornata): array
{
    $voti = [];
    $giocate = [];
    foreach (query_all("SELECT id_giocatore, squadra, voto, sv, gf, gs, rp, rs, rf, au, amm, esp, ass
                        FROM NEW_VOTI_SERIE_A
                        WHERE stagione = $stagione AND giornata = $giornata") as $r) {
        $voti[(int) $r['id_giocatore']] = $r;
        $giocate[sim_norm($r['squadra'])] = true;
    }

    $edit = [];
    foreach (query_all("SELECT id_giocatore, voto, gf, gs, rp, rs, rf, au, amm, esp, ass
                        FROM NEW_SIMULAZIONE_EDIT
                        WHERE stagione = $stagione AND giornata = $giornata") as $r) {
        $edit[(int) $r['id_giocatore']] = $r;
    }

    return [
        'voti'    => $voti,
        'giocate' => $giocate,
        'squadre' => sim_squadre_serie_a_giocatori($stagione),
        'edit'    => $edit,
    ];
}

// La squadra di Serie A del giocatore ha già giocato nella giornata?
// null se la squadra non è determinabile.
function sim_squadra_ha_giocato(array $ctx, int $idGiocatore): ?bool
{
    $squadra = $ctx['squadre'][$idGiocatore] ?? null;
    if ($squadra === null || trim((string) $squadra) === '') return null;
    return isset($ctx['giocate'][sim_norm($squadra)]);
}

// Il giocatore è editabile? Solo se non ha già il voto reale e la sua
// squadra di Serie A non è ancora scesa in campo.
// Ritorna [bool, motivo|null]
function sim_giocatore_modificabile(array $ctx, int $idGiocatore): array
{
    if (isset($ctx['voti'][$idGiocatore])) {
        return [false, "Il giocatore ha già un voto reale per la giornata"];
    }
    if (sim_squadra_ha_giocato($ctx, $idGiocatore) === true) {
        return [false, "La squadra di Serie A del giocatore ha già giocato: il voto reale prevale"];
    }
    return [true, null];
}

// ------------------------------------------------------------
// Helper di calcolo (stessa logica di calcolo_giornata.php)
// ------------------------------------------------------------
function sim_parametro_numerico(array $config, string $codice, float $default): float
{
    $v = $config['parametri'][$codice] ?? null;
    if ($v === null || trim((string) $v) === '' || !is_numeric($v)) return $default;
    return (float) $v;
}

function sim_id_riserva_ufficio(int $ruolo, int $idSquadra, int $progressivo): int
{
    return -($ruolo * 1000000 + $idSquadra * 100 + $progressivo);
}

function sim_crea_stat_ufficio(int $idGiocatore, float $voto, int $ammonizioniRegistrate = 0): array
{
    return [
        'id_giocatore' => $idGiocatore, 'voto' => $voto, 'sv' => false, 'trovato' => true,
        'rufficio' => true, 'amm_reg' => $ammonizioniRegistrate, 'provvisorio' => false, 'manuale' => false,
        'gf' => 0, 'gs' => 0, 'rp' => 0, 'rf' => 0, 'rs' => 0, 'au' => 0, 'amm' => 0, 'esp' => 0, 'ass' => 0,
    ];
}

function sim_gol_da_punteggio(float $punteggio, array $fasce): int
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

// ------------------------------------------------------------
// Statistiche di un giocatore secondo le regole della simulazione
// (vedi intestazione): dato reale / senza voto / editing / 6 provvisorio
// ------------------------------------------------------------
function sim_stat_giocatore(array $ctx, int $idGiocatore, array &$warning, string $nome = ''): array
{
    $base = ['id_giocatore' => $idGiocatore, 'provvisorio' => false, 'manuale' => false,
             'gf' => 0, 'gs' => 0, 'rp' => 0, 'rf' => 0, 'rs' => 0, 'au' => 0, 'amm' => 0, 'esp' => 0, 'ass' => 0];

    // 1. dato reale
    $r = $ctx['voti'][$idGiocatore] ?? null;
    if ($r !== null) {
        return array_merge($base, [
            'voto'    => $r['voto'] !== null ? (float) $r['voto'] : null,
            'sv'      => $r['sv'] === 'Y' || $r['voto'] === null,
            'trovato' => true,
            'gf' => (int) $r['gf'], 'gs' => (int) $r['gs'], 'rp' => (int) $r['rp'], 'rf' => (int) $r['rf'],
            'rs' => (int) $r['rs'], 'au' => (int) $r['au'], 'amm' => (int) $r['amm'], 'esp' => (int) $r['esp'],
            'ass' => (int) $r['ass'],
        ]);
    }

    // 2. squadra di Serie A già scesa in campo, ma il giocatore non ha riga → senza voto
    $haGiocato = sim_squadra_ha_giocato($ctx, $idGiocatore);
    if ($haGiocato === true) {
        return array_merge($base, ['voto' => null, 'sv' => true, 'trovato' => false]);
    }
    if ($haGiocato === null) {
        $warning[] = "Giocatore " . ($nome !== '' ? $nome : "id $idGiocatore") . ": squadra di Serie A non determinabile, trattato come \"non ancora in campo\"";
    }

    // 3a. editing manuale: prevale sul 6 provvisorio
    $e = $ctx['edit'][$idGiocatore] ?? null;
    if ($e !== null) {
        return array_merge($base, [
            'voto' => (float) $e['voto'], 'sv' => false, 'trovato' => true, 'manuale' => true,
            'gf' => (int) $e['gf'], 'gs' => (int) $e['gs'], 'rp' => (int) $e['rp'], 'rf' => (int) $e['rf'],
            'rs' => (int) $e['rs'], 'au' => (int) $e['au'], 'amm' => (int) $e['amm'], 'esp' => (int) $e['esp'],
            'ass' => (int) $e['ass'],
        ]);
    }

    // 3b. 6 provvisorio
    return array_merge($base, ['voto' => SIM_VOTO_PROVVISORIO, 'sv' => false, 'trovato' => true, 'provvisorio' => true]);
}

// ------------------------------------------------------------
// Formazione EFFETTIVA di una squadra (dopo sostituzioni), come
// carica_formazione() di calcolo_giornata.php ma con le statistiche
// della simulazione.
// ------------------------------------------------------------
function sim_carica_formazione(array $ctx, int $stagione, int $giornata, int $idSquadra, array &$warning, array &$nonGiocanti,
                               int $maxSostMovimento, int $maxSostPortiere, array $configUfficio): array
{
    $tutti = query_all("SELECT f.ID_GIOCATORE AS id_giocatore, f.MAGLIA AS maglia, g.ruolo, g.descrizione
                        FROM NEW_FORMAZIONI f
                        JOIN NEW_GIOCATORI g ON g.id = f.ID_GIOCATORE AND g.stagione = f.STAGIONE
                        WHERE f.STAGIONE = $stagione AND f.ID_SQUADRA = $idSquadra AND f.GIORNATA = $giornata
                        ORDER BY f.MAGLIA");

    $titolari = array_values(array_filter($tutti, fn($r) => (int) $r['maglia'] >= 1 && (int) $r['maglia'] <= 11));
    $panchina = array_values(array_filter($tutti, fn($r) => (int) $r['maglia'] > 11));

    if (count($titolari) === 0) {
        throw new Exception("Formazione titolare assente per la squadra $idSquadra alla giornata $giornata");
    }
    if (count($titolari) < 11) {
        $warning[] = "La squadra $idSquadra ha solo " . count($titolari) . " titolari su 11 (si procede comunque)";
    }

    $panchinaPerRuolo = [];
    foreach ($panchina as $p) $panchinaPerRuolo[(int) $p['ruolo']][] = $p;
    $panchinariProvati = [];

    $formazione = ['portiere' => null, 'difensori' => [], 'centrocampisti' => [], 'attaccanti' => []];
    $mappaRuolo = [1 => 'portiere', 2 => 'difensori', 3 => 'centrocampisti', 4 => 'attaccanti'];

    $sostMovimento = 0;
    $sostPortiere  = 0;
    $riserveUfficio = 0;

    foreach ($titolari as $t) {
        $ruoloInt = (int) $t['ruolo'];
        $ruoloKey = $mappaRuolo[$ruoloInt] ?? null;
        if ($ruoloKey === null) {
            $warning[] = "Giocatore {$t['descrizione']} (id {$t['id_giocatore']}): ruolo non riconosciuto, escluso dal calcolo";
            continue;
        }

        $idGiocatore = (int) $t['id_giocatore'];
        $stat = sim_stat_giocatore($ctx, $idGiocatore, $warning, $t['descrizione']);

        if ($stat['sv']) {
            $limiteRaggiunto = $ruoloInt === 1
                ? $sostPortiere >= $maxSostPortiere
                : $sostMovimento >= $maxSostMovimento;
            $puoUfficio = !$limiteRaggiunto && $riserveUfficio < $configUfficio['max'];
            $sostituto  = null;

            if ($puoUfficio && (int) $stat['amm'] > 0) {
                // Senza voto ma ammonito → voto d'ufficio con l'ID del giocatore stesso
                $stat = sim_crea_stat_ufficio($idGiocatore, $configUfficio['voto_ammonito'], (int) $stat['amm']);
                $riserveUfficio++;
                if ($ruoloInt === 1) $sostPortiere++; else $sostMovimento++;
                $warning[] = "Squadra $idSquadra: {$t['descrizione']} senza voto ma ammonito, riserva d'ufficio con voto {$configUfficio['voto_ammonito']}";
            } else {
                if (!$limiteRaggiunto) {
                    foreach ($panchinaPerRuolo[$ruoloInt] ?? [] as $candidato) {
                        $idCandidato = (int) $candidato['id_giocatore'];
                        if (isset($panchinariProvati[$idCandidato])) continue;
                        $panchinariProvati[$idCandidato] = true;

                        $statCandidato = sim_stat_giocatore($ctx, $idCandidato, $warning, $candidato['descrizione']);
                        if (!$statCandidato['sv']) {
                            $sostituto = $statCandidato;
                            $warning[] = "Squadra $idSquadra: {$t['descrizione']} senza voto, sostituito da {$candidato['descrizione']}"
                                       . ($statCandidato['provvisorio'] ? " (voto provvisorio)" : "");
                            if ($ruoloInt === 1) $sostPortiere++; else $sostMovimento++;
                            break;
                        }
                    }
                }

                if ($sostituto !== null) {
                    $stat = $sostituto;
                } elseif ($puoUfficio) {
                    $voto = $ruoloInt === 1 ? $configUfficio['voto_portiere'] : $configUfficio['voto_movimento'];
                    $riserveUfficio++;
                    if ($ruoloInt === 1) $sostPortiere++; else $sostMovimento++;
                    $stat = sim_crea_stat_ufficio(sim_id_riserva_ufficio($ruoloInt, $idSquadra, $riserveUfficio), $voto);
                    $nonGiocanti[] = ['id_giocatore' => $idGiocatore];
                    $warning[] = "Squadra $idSquadra: {$t['descrizione']} senza voto e nessun sostituto, riserva d'ufficio con voto $voto";
                } else {
                    $motivo = $limiteRaggiunto ? 'limite sostituzioni raggiunto' : 'nessun sostituto con voto disponibile nello stesso ruolo';
                    if (!$limiteRaggiunto) $motivo .= ' e limite riserve d\'ufficio raggiunto';
                    $warning[] = "Squadra $idSquadra: {$t['descrizione']} senza voto, non sostituito ($motivo)";
                    $nonGiocanti[] = ['id_giocatore' => $idGiocatore];
                    continue;
                }
            }
        }

        if ($ruoloKey === 'portiere') {
            $formazione['portiere'] = $stat;
        } else {
            $formazione[$ruoloKey][] = $stat;
        }
    }

    $formazione['modulo_difensori'] = count($formazione['difensori']);
    return $formazione;
}

// ------------------------------------------------------------
// Scrittura su NEW_SIMULAZIONE_VOTI
// ------------------------------------------------------------
function sim_salva_voti_squadra($conn, int $stagione, int $giornata, int $idSquadra, array $formazione, array $risultatoSquadra): void
{
    $statistiche = [];
    foreach (['portiere', 'difensori', 'centrocampisti', 'attaccanti'] as $r) {
        $lista = $r === 'portiere' ? ($formazione['portiere'] ? [$formazione['portiere']] : []) : $formazione[$r];
        foreach ($lista as $stat) $statistiche[$stat['id_giocatore']] = $stat;
    }

    foreach ($risultatoSquadra['giocatori'] as $g) {
        $idGiocatore = (int) $g['id_giocatore'];
        $stat        = $statistiche[$idGiocatore] ?? null;
        if ($stat === null) continue;

        $sv      = !empty($stat['sv']) || $stat['voto'] === null;
        $voto    = $sv ? 0 : (float) $stat['voto'];
        $giocata = $sv ? 0 : 1;
        $totale  = $sv ? 0 : (float) $g['punti'];

        $rigores  = (int) $stat['rp'] + (int) $stat['rf'];
        $rigorep  = (int) $stat['rs'];
        $rufficio = !empty($stat['rufficio']) ? 1 : 0;
        $amm      = (int) ($stat['amm_reg'] ?? $stat['amm']);
        $prov     = !empty($stat['provvisorio']) ? 1 : 0;
        $man      = !empty($stat['manuale']) ? 1 : 0;

        $votoS   = sprintf('%.1f', $voto);
        $totaleS = sprintf('%.2f', $totale);
        $ok = mysqli_query($conn, "INSERT INTO NEW_SIMULAZIONE_VOTI
            (stagione, giornata, id_squadra, id_giocatore, voto,
             reti, ammonizioni, espulsioni, autogol, retis,
             rigores, rigorep, rufficio, giocata, totale, assist, provvisorio, manuale)
            VALUES ($stagione, $giornata, $idSquadra, $idGiocatore, $votoS,
                    {$stat['gf']}, $amm, {$stat['esp']}, {$stat['au']}, {$stat['gs']},
                    $rigores, $rigorep, $rufficio, $giocata, $totaleS, {$stat['ass']}, $prov, $man)");
        if (!$ok) throw new Exception("Scrittura NEW_SIMULAZIONE_VOTI: " . mysqli_error($conn));
    }
}

// Titolari rimasti a zero (sostituiti o senza sostituto): tracciati a zero
function sim_salva_non_giocanti($conn, int $stagione, int $giornata, int $idSquadra, array $nonGiocanti): void
{
    foreach ($nonGiocanti as $ng) {
        $idGiocatore = (int) $ng['id_giocatore'];
        $ok = mysqli_query($conn, "INSERT IGNORE INTO NEW_SIMULAZIONE_VOTI
            (stagione, giornata, id_squadra, id_giocatore, voto,
             reti, ammonizioni, espulsioni, autogol, retis,
             rigores, rigorep, rufficio, giocata, totale, assist, provvisorio, manuale)
            VALUES ($stagione, $giornata, $idSquadra, $idGiocatore, 0,
                    0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0)");
        if (!$ok) throw new Exception("Scrittura NEW_SIMULAZIONE_VOTI: " . mysqli_error($conn));
    }
}

// ------------------------------------------------------------
// Scrittura di UNA riga di NEW_SIMULAZIONE_RISULTATI (prospettiva di
// una squadra). Stessa formula punti/segno di salvaRigaRisultato().
// ------------------------------------------------------------
function sim_salva_riga_risultato(
    $conn, int $stagione, int $giornata, int $idSquadra, int $idSquadraA,
    float $ftotale, float $ftotaleA, int $golf, int $gols,
    int $modificatore, int $modificatoreA, float $modAtt,
    int $numCc, float $totCc, float $modCc, int $fattoreCampo
): void {
    if ($golf > $gols)       { $punti = 3; $segno = 'V'; }
    elseif ($golf === $gols) { $punti = 1; $segno = 'N'; }
    else                     { $punti = 0; $segno = 'P'; }

    $f  = fn(float $v) => sprintf('%.2f', $v);
    $ok = mysqli_query($conn, "INSERT INTO NEW_SIMULAZIONE_RISULTATI
        (giornata, stagione, id_squadra, id_squadra_a, ftotale, ftotale_a, golf, gols,
         modificatore, modificatore_a, punti, fattore_campo, segno,
         mod_att, num_cc, tot_cc, mod_cc, calcolato_il)
        VALUES ($giornata, $stagione, $idSquadra, $idSquadraA, {$f($ftotale)}, {$f($ftotaleA)}, $golf, $gols,
                $modificatore, $modificatoreA, $punti, $fattoreCampo, '$segno',
                {$f($modAtt)}, $numCc, {$f($totCc)}, {$f($modCc)}, NOW(3))");
    if (!$ok) throw new Exception("Scrittura NEW_SIMULAZIONE_RISULTATI: " . mysqli_error($conn));
}

// ------------------------------------------------------------
// Esegue la simulazione dell'intera giornata e ricostruisce
// NEW_SIMULAZIONE_VOTI / NEW_SIMULAZIONE_RISULTATI.
// Le precondizioni (giornata in corso, non chiusa) sono a carico del
// chiamante. Solleva eccezioni (config mancante, errori SQL).
// Se $soloSquadra è valorizzato (id di una delle due squadre della
// partita) viene simulata e salvata SOLO quella partita: le righe delle
// altre partite restano invariate.
// Ritorna: partite_elaborate, partite_saltate[], warning[],
//          provvisori, manuali
// ------------------------------------------------------------
function sim_esegui($conn, int $stagione, int $giornata, ?int $soloSquadra = null): array
{
    mysqli_set_charset($conn, "utf8mb4");

    $config = caricaConfigurazioneCalcolo($conn, $stagione);

    $maxSostMovimento = (int) ($config['parametri']['SOSTITUZIONI_MAX_MOVIMENTO'] ?? 5);
    $maxSostPortiere  = (int) ($config['parametri']['SOSTITUZIONI_MAX_PORTIERE'] ?? 1);
    $ultimaGiornataFattoreCasa = trim((string) ($config['parametri']['ULTIMA_GIORNATA_FATTORE_CASA'] ?? ''));
    $fasceGolPunteggio = json_decode($config['parametri']['FASCE_GOL_PUNTEGGIO'] ?? '', true) ?: [
        ['da' => null, 'a' => null, 'gol' => 0],
    ];
    $fattoreCasaValore = (float) ($config['bonus']['FATTORE_CASA'] ?? 0);

    $configUfficio = [
        'max'            => max(0, (int) sim_parametro_numerico($config, 'RISERVE_UFFICIO_MAX', 1)),
        'voto_portiere'  => sim_parametro_numerico($config, 'VOTO_UFFICIO_PORTIERE', 3),
        'voto_movimento' => sim_parametro_numerico($config, 'VOTO_UFFICIO_MOVIMENTO', 4),
        'voto_ammonito'  => sim_parametro_numerico($config, 'VOTO_UFFICIO_AMMONITO', 5),
    ];

    $righeCalendario = query_all("SELECT posizione, squadra AS id_squadra
                                  FROM NEW_CALENDARIO
                                  WHERE stagione = $stagione AND giornata = $giornata
                                  ORDER BY posizione");
    if (empty($righeCalendario)) {
        throw new Exception("Nessuna partita a calendario per la giornata $giornata");
    }
    $partite = [];
    foreach ($righeCalendario as $r) {
        $indice = (int) (($r['posizione'] - 1) / 2);
        if ($r['posizione'] % 2 === 1) $partite[$indice]['casa'] = (int) $r['id_squadra'];
        else                           $partite[$indice]['ospite'] = (int) $r['id_squadra'];
    }

    // Simulazione di una singola partita: tengo solo quella
    $filtroSquadre = '';
    if ($soloSquadra !== null) {
        $partite = array_values(array_filter($partite, fn($p) =>
            ($p['casa'] ?? null) === $soloSquadra || ($p['ospite'] ?? null) === $soloSquadra));
        if (empty($partite) || !isset($partite[0]['casa'], $partite[0]['ospite'])) {
            throw new Exception("La squadra $soloSquadra non gioca alla giornata $giornata");
        }
        $filtroSquadre = " AND id_squadra IN ({$partite[0]['casa']}, {$partite[0]['ospite']})";
    }

    $ctx = sim_contesto($stagione, $giornata);

    $warning = [];
    $partiteSaltate = [];
    $elaborate = 0;

    mysqli_begin_transaction($conn);
    try {
        foreach (['NEW_SIMULAZIONE_VOTI', 'NEW_SIMULAZIONE_RISULTATI'] as $tab) {
            if (!mysqli_query($conn, "DELETE FROM $tab WHERE stagione = $stagione AND giornata = $giornata$filtroSquadre")) {
                throw new Exception("Pulizia $tab: " . mysqli_error($conn));
            }
        }

        foreach ($partite as $partita) {
            $idCasa   = $partita['casa']   ?? null;
            $idOspite = $partita['ospite'] ?? null;
            if (!$idCasa || !$idOspite) continue;

            try {
                $ngCasa = []; $ngOspite = [];
                $formCasa   = sim_carica_formazione($ctx, $stagione, $giornata, $idCasa, $warning, $ngCasa, $maxSostMovimento, $maxSostPortiere, $configUfficio);
                $formOspite = sim_carica_formazione($ctx, $stagione, $giornata, $idOspite, $warning, $ngOspite, $maxSostMovimento, $maxSostPortiere, $configUfficio);
            } catch (Throwable $e) {
                $partiteSaltate[] = $e->getMessage();
                continue;
            }

            $ris = CalcolatoreVoti::calcolaPartita($formCasa, $formOspite, $config);

            sim_salva_voti_squadra($conn, $stagione, $giornata, $idCasa,   $formCasa,   $ris['casa']);
            sim_salva_voti_squadra($conn, $stagione, $giornata, $idOspite, $formOspite, $ris['ospite']);
            sim_salva_non_giocanti($conn, $stagione, $giornata, $idCasa,   $ngCasa);
            sim_salva_non_giocanti($conn, $stagione, $giornata, $idOspite, $ngOspite);

            $fattoreCampoCasa = 0;
            $applicaFattoreCasa = $ultimaGiornataFattoreCasa === '' || $giornata <= (int) $ultimaGiornataFattoreCasa;
            if ($applicaFattoreCasa && $fattoreCasaValore != 0) {
                $fattoreCampoCasa = (int) round($fattoreCasaValore);
                $ris['casa']['totale_squadra'] = round($ris['casa']['totale_squadra'] + $fattoreCasaValore, 2);
            }

            $golfCasa   = sim_gol_da_punteggio($ris['casa']['totale_squadra'], $fasceGolPunteggio);
            $golfOspite = sim_gol_da_punteggio($ris['ospite']['totale_squadra'], $fasceGolPunteggio);

            sim_salva_riga_risultato(
                $conn, $stagione, $giornata, $idCasa, $idOspite,
                $ris['casa']['totale_squadra'], $ris['ospite']['totale_squadra'],
                $golfCasa, $golfOspite,
                (int) round(-$ris['ospite']['modificatori']['difesa']), (int) round(-$ris['casa']['modificatori']['difesa']),
                $ris['casa']['modificatori']['attacco'],
                $ris['casa']['numero_centrocampisti'], $ris['casa']['somma_centrocampisti'], $ris['casa']['modificatori']['centrocampo'],
                $fattoreCampoCasa
            );
            sim_salva_riga_risultato(
                $conn, $stagione, $giornata, $idOspite, $idCasa,
                $ris['ospite']['totale_squadra'], $ris['casa']['totale_squadra'],
                $golfOspite, $golfCasa,
                (int) round(-$ris['casa']['modificatori']['difesa']), (int) round(-$ris['ospite']['modificatori']['difesa']),
                $ris['ospite']['modificatori']['attacco'],
                $ris['ospite']['numero_centrocampisti'], $ris['ospite']['somma_centrocampisti'], $ris['ospite']['modificatori']['centrocampo'],
                0
            );

            $elaborate++;
        }

        mysqli_commit($conn);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        throw $e;
    }

    $tot = query_one("SELECT COALESCE(SUM(provvisorio),0) AS p, COALESCE(SUM(manuale),0) AS m
                      FROM NEW_SIMULAZIONE_VOTI WHERE stagione = $stagione AND giornata = $giornata");

    return [
        'partite_elaborate' => $elaborate,
        'partite_saltate'   => $partiteSaltate,
        'warning'           => $warning,
        'provvisori'        => (int) ($tot['p'] ?? 0),
        'manuali'           => (int) ($tot['m'] ?? 0),
    ];
}


// ------------------------------------------------------------
// Partita (casa/ospite) a cui partecipa una squadra nella giornata.
// Ritorna [idCasa, idOspite] oppure null.
// ------------------------------------------------------------
function sim_partita_di_squadra(int $stagione, int $giornata, int $idSquadra): ?array
{
    $r = query_one("SELECT posizione FROM NEW_CALENDARIO
                    WHERE stagione = $stagione AND giornata = $giornata AND squadra = $idSquadra");
    if ($r === null) return null;
    $pos = (int) $r['posizione'];
    $posCasa = $pos % 2 === 1 ? $pos : $pos - 1;
    $rows = query_all("SELECT posizione, squadra FROM NEW_CALENDARIO
                       WHERE stagione = $stagione AND giornata = $giornata
                         AND posizione IN ($posCasa, " . ($posCasa + 1) . ")");
    $casa = $ospite = null;
    foreach ($rows as $x) {
        if ((int) $x['posizione'] === $posCasa) $casa = (int) $x['squadra']; else $ospite = (int) $x['squadra'];
    }
    return ($casa && $ospite) ? [$casa, $ospite] : null;
}

// ------------------------------------------------------------
// Cancella la simulazione di UNA partita: voti e risultati simulati
// delle due squadre + editing manuale dei giocatori schierati dalle
// due squadre nella giornata. Le altre partite non vengono toccate.
// Ritorna il numero di editing manuali rimossi.
// ------------------------------------------------------------
function sim_elimina_partita($conn, int $stagione, int $giornata, int $idSquadra): int
{
    $p = sim_partita_di_squadra($stagione, $giornata, $idSquadra);
    if ($p === null) throw new Exception("La squadra $idSquadra non gioca alla giornata $giornata");
    [$casa, $ospite] = $p;

    mysqli_begin_transaction($conn);
    try {
        $q = [
            "DELETE FROM NEW_SIMULAZIONE_VOTI WHERE stagione = $stagione AND giornata = $giornata
               AND id_squadra IN ($casa, $ospite)",
            "DELETE FROM NEW_SIMULAZIONE_RISULTATI WHERE stagione = $stagione AND giornata = $giornata
               AND id_squadra IN ($casa, $ospite)",
            "DELETE FROM NEW_SIMULAZIONE_EDIT WHERE stagione = $stagione AND giornata = $giornata
               AND id_giocatore IN (SELECT ID_GIOCATORE FROM NEW_FORMAZIONI
                                    WHERE STAGIONE = $stagione AND GIORNATA = $giornata
                                      AND ID_SQUADRA IN ($casa, $ospite))",
        ];
        $edit = 0;
        foreach ($q as $i => $sql) {
            if (!mysqli_query($conn, $sql)) throw new Exception("Cancellazione simulazione: " . mysqli_error($conn));
            if ($i === 2) $edit = mysqli_affected_rows($conn);
        }
        mysqli_commit($conn);
        return $edit;
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        throw $e;
    }
}
