<?php
// ============================================================
// api/admin/calcolo_giornata.php
//
// POST { stagione, giornata }
//
// Fase 2 di "Gestione voti": calcola il punteggio fantacalcio di ogni
// giocatore titolare e il risultato di ogni partita della giornata, a
// partire dai voti Serie A caricati in fase 1 (NEW_VOTI_SERIE_A) e
// dalle formazioni (NEW_FORMAZIONI), usando CalcolatoreVoti.php con la
// configurazione di NEW_REGOLE_BONUS / NEW_REGOLE_ALGORITMI.
//
// Scrive:
//   - NEW_VOTI       → punteggio del singolo titolare (DELETE + INSERT
//                       per stagione/giornata/squadra)
//   - NEW_RISULTATI  → risultato di ogni partita, DUE righe (una per
//                       prospettiva squadra), tramite RisultatoPartita.php
//
// Ripetibile: un nuovo calcolo sulla stessa giornata sovrascrive quanto
// già presente, finché la giornata non è chiusa.
//
// Convenzioni concordate (vedi documento di proposta validato e
// correzioni successive):
//   - titolari = NEW_FORMAZIONI.MAGLIA fra 1 e 11; modulo_difensori =
//     conteggio dei titolari con ruolo Difensore (NEW_GIOCATORI.ruolo=2)
//   - corrispondenza giocatore fantacalcio ↔ Serie A: NEW_GIOCATORI.id
//     = NEW_VOTI_SERIE_A.id_giocatore (stesso "Cod." del listone)
//   - NEW_VOTI.rigores = Rp + Rf (rigori parati + realizzati),
//     NEW_VOTI.rigorep = Rs (rigori sbagliati) — mapping richiesto
//   - modificatore attacco: voce di squadra separata (NEW_RISULTATI.mod_att),
//     NON sommata al voto del singolo giocatore in NEW_VOTI.totale
//   - modificatore difesa: generato dai difensori di una squadra e
//     sommato al punteggio dell'AVVERSARIA, vedi
//     CalcolatoreVoti::modificatoreDifesa(); in NEW_RISULTATI la riga di
//     una squadra salva in `modificatore` il valore GENERATO dalla
//     propria difesa e in `modificatore_a` quello generato dalla difesa
//     avversaria, ENTRAMBI CON SEGNO INVERTITO rispetto al bonus
//     realmente sommato al punteggio (es. +1 sommato a una squadra
//     = -1 in `modificatore_a` della sua riga)
//   - modificatore centrocampo: confronto a parità di numero di
//     centrocampisti, pareggiato con un voto fittizio parametrizzabile
//     (algoritmo CENTROCAMPO, parametro "voto_fittizio")
//   - FATTORE_CASA (bonus/malus, valore di default 2): si somma UNA
//     volta al punteggio finale della squadra che gioca in casa,
//     applicato qui (non in CalcolatoreVoti, che non conosce il
//     concetto di "casa/ospite reale"), fino alla giornata indicata dal
//     parametro di stagione ULTIMA_GIORNATA_FATTORE_CASA (vuoto=sempre)
//   - golf (gol fatti) di NEW_RISULTATI: derivato dal punteggio totale
//     finale della squadra (fattore casa incluso) tramite le fasce del
//     parametro di stagione FASCE_GOL_PUNTEGGIO; gols (gol subiti) =
//     golf della squadra avversaria
//   - giocatore "senza voto" (sv) → NEW_VOTI: voto = 0, giocata = 0,
//     totale = 0 (stesso trattamento già riservato dalla tabella
//     esistente ai giocatori non entrati in campo)
//   - SOSTITUZIONI: un titolare senza voto viene sostituito dal primo
//     giocatore in panchina (MAGLIA > 11, in ordine di maglia) dello
//     STESSO ruolo che abbia un voto valido, nel rispetto dei limiti di
//     sostituzione della stagione (parametri SOSTITUZIONI_MAX_MOVIMENTO
//     e SOSTITUZIONI_MAX_PORTIERE, di default 5 + 1, modificabili da
//     "Gestisci regole di calcolo"). Il titolare sostituito, o rimasto
//     senza voto e senza sostituto disponibile, resta comunque
//     tracciato in NEW_VOTI con una riga a zero (nessun contributo al
//     punteggio), tramite salva_non_giocanti().
//
// Tutti i parametri di stagione non riconducibili a bonus/malus o
// modificatori (sostituzioni, fattore casa, fasce gol) vivono in
// NEW_PARAMETRI_STAGIONE, generica (codice => valore testuale): questo
// file legge il singolo codice che le serve con un default di riserva
// se il parametro non è stato configurato (vedi $config['parametri']).
// ============================================================
require_once __DIR__ . "/../connect.php";
require_once __DIR__ . "/../lib/CalcolatoreVoti.php";
require_once __DIR__ . "/../lib/RegoleCalcolo.php";
require_once __DIR__ . "/../lib/RisultatoPartita.php";

mysqli_set_charset($conn, "utf8mb4");

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    api_error("Metodo non consentito. Usare POST.", 405);
}

$input    = json_decode(file_get_contents("php://input"), true) ?? $_POST;
$stagione = (int) ($input["stagione"] ?? 0);
$giornata = (int) ($input["giornata"] ?? 0);
if (!$stagione) api_error("Parametro obbligatorio mancante: stagione", 400);
if (!$giornata) api_error("Parametro obbligatorio mancante: giornata", 400);

// ------------------------------------------------------------
// Precondizioni
// ------------------------------------------------------------
$ck = query_one("SELECT ck_giocata FROM NEW_CALENDARIO_CK
                 WHERE stagione = $stagione AND giornata = $giornata");
if ($ck && $ck["ck_giocata"] === "S") {
    api_error("La giornata $giornata è già chiusa: impossibile ricalcolare", 403);
}

$votiSerieA = query_one("SELECT COUNT(*) AS n FROM NEW_VOTI_SERIE_A
                         WHERE stagione = $stagione AND giornata = $giornata");
if ((int) ($votiSerieA["n"] ?? 0) === 0) {
    api_error("Nessun voto Serie A caricato per la giornata $giornata: caricarlo prima (fase 1)", 400);
}

try {
    $config = caricaConfigurazioneCalcolo($conn, $stagione);
} catch (Throwable $e) {
    api_error($e->getMessage(), 400);
}

// I limiti di sostituzione, la soglia del fattore casa e le fasce
// gol-da-punteggio sono parametri di stagione generici (vedi
// NEW_PARAMETRI_STAGIONE, $config['parametri']): si leggono qui con un
// default di riserva, così un calcolo non si blocca se l'admin non ha
// ancora toccato "Gestisci regole di calcolo" dopo l'introduzione di
// un nuovo parametro.
$maxSostituzioniMovimento    = (int) ($config['parametri']['SOSTITUZIONI_MAX_MOVIMENTO'] ?? 5);
$maxSostituzioniPortiere     = (int) ($config['parametri']['SOSTITUZIONI_MAX_PORTIERE'] ?? 1);
$ultimaGiornataFattoreCasa   = trim((string) ($config['parametri']['ULTIMA_GIORNATA_FATTORE_CASA'] ?? ''));
$fasceGolPunteggio           = json_decode($config['parametri']['FASCE_GOL_PUNTEGGIO'] ?? '', true) ?: [
    ['da' => null, 'a' => null, 'gol' => 0], // nessuna fascia configurata: 0 gol per tutti, per non bloccare il calcolo
];
$fattoreCasaValore = (float) ($config['bonus']['FATTORE_CASA'] ?? 0);

// Ricava i gol fatti di una squadra dal suo punteggio totale finale,
// secondo le fasce configurate (vedi FASCE_GOL_PUNTEGGIO in
// backend/admin/regole_calcolo.php per i valori di default).
function gol_da_punteggio(float $punteggio, array $fasce): int
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
// Helper: statistiche di un giocatore dal voto Serie A della giornata,
// normalizzate nel formato richiesto da CalcolatoreVoti. Se il
// giocatore non ha alcun voto caricato per quella giornata, viene
// trattato come "senza voto" (sv = true) allo stesso modo di un vero
// SV segnalato nel file.
// ------------------------------------------------------------
function carica_stat_giocatore($conn, int $stagione, int $giornata, int $idGiocatore): array
{
    $voto = query_one("SELECT voto, sv, gf, gs, rp, rs, rf, au, amm, esp, ass
                       FROM NEW_VOTI_SERIE_A
                       WHERE stagione = $stagione AND giornata = $giornata
                         AND id_giocatore = $idGiocatore");

    if ($voto === null) {
        return ['id_giocatore' => $idGiocatore, 'voto' => null, 'sv' => true, 'trovato' => false,
                'gf' => 0, 'gs' => 0, 'rp' => 0, 'rf' => 0, 'rs' => 0, 'au' => 0, 'amm' => 0, 'esp' => 0, 'ass' => 0];
    }
    return [
        'id_giocatore' => $idGiocatore,
        'voto'    => $voto['voto'] !== null ? (float) $voto['voto'] : null,
        'sv'      => $voto['sv'] === 'Y' || $voto['voto'] === null,
        'trovato' => true,
        'gf'   => (int) $voto['gf'], 'gs' => (int) $voto['gs'],
        'rp'   => (int) $voto['rp'], 'rf' => (int) $voto['rf'], 'rs' => (int) $voto['rs'],
        'au'   => (int) $voto['au'], 'amm' => (int) $voto['amm'], 'esp' => (int) $voto['esp'],
        'ass'  => (int) $voto['ass'],
    ];
}

// ------------------------------------------------------------
// Helper: carica la formazione EFFETTIVA (dopo le sostituzioni) di una
// squadra, nel formato richiesto da CalcolatoreVoti.
//
// Un titolare (MAGLIA 1-11) privo di voto viene sostituito, se
// possibile, dal primo giocatore in panchina (MAGLIA > 11, in ordine di
// maglia = ordine di priorità) dello STESSO ruolo che abbia un voto
// valido, nel rispetto dei limiti di sostituzione della stagione
// ($maxSostituzioniMovimento per i ruoli diversi dal portiere,
// $maxSostituzioniPortiere per il portiere — vedi NEW_REGOLE_SOSTITUZIONI
// e "Gestisci regole di calcolo"). Se non è possibile sostituirlo
// (limite raggiunto o nessun panchinaro idoneo dello stesso ruolo con
// un voto), il titolare resta "senza voto".
//
// $nonGiocanti in uscita elenca i titolari che NON hanno contribuito al
// calcolo (sostituiti, o senza voto senza sostituto disponibile): a
// loro va comunque scritta una riga a zero in NEW_VOTI per completezza
// storica (il contributo al punteggio squadra arriva dal sostituto).
// ------------------------------------------------------------
function carica_formazione($conn, int $stagione, int $giornata, int $idSquadra, array &$warning, array &$nonGiocanti, int $maxSostituzioniMovimento, int $maxSostituzioniPortiere): array
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

    // Panchina raggruppata per ruolo, in ordine di maglia (= priorità di sostituzione)
    $panchinaPerRuolo = [];
    foreach ($panchina as $p) $panchinaPerRuolo[(int) $p['ruolo']][] = $p;
    $panchinariProvati = []; // id_giocatore già tentati (con o senza successo), per non ririproporli

    $formazione = ['portiere' => null, 'difensori' => [], 'centrocampisti' => [], 'attaccanti' => []];
    $mappaRuolo = [1 => 'portiere', 2 => 'difensori', 3 => 'centrocampisti', 4 => 'attaccanti'];

    $sostituzioniMovimento = 0;
    $sostituzioniPortiere  = 0;

    foreach ($titolari as $t) {
        $ruoloInt = (int) $t['ruolo'];
        $ruoloKey = $mappaRuolo[$ruoloInt] ?? null;
        if ($ruoloKey === null) {
            $warning[] = "Giocatore {$t['descrizione']} (id {$t['id_giocatore']}): ruolo non riconosciuto, escluso dal calcolo";
            continue;
        }

        $idGiocatore = (int) $t['id_giocatore'];
        $stat = carica_stat_giocatore($conn, $stagione, $giornata, $idGiocatore);
        if (!$stat['trovato']) {
            $warning[] = "Giocatore {$t['descrizione']} (id $idGiocatore): nessun voto Serie A trovato";
        }

        if ($stat['sv']) {
            $limiteRaggiunto = $ruoloInt === 1
                ? $sostituzioniPortiere >= $maxSostituzioniPortiere
                : $sostituzioniMovimento >= $maxSostituzioniMovimento;
            $sostituto = null;

            if (!$limiteRaggiunto) {
                foreach ($panchinaPerRuolo[$ruoloInt] ?? [] as $candidato) {
                    $idCandidato = (int) $candidato['id_giocatore'];
                    if (isset($panchinariProvati[$idCandidato])) continue;
                    $panchinariProvati[$idCandidato] = true;

                    $statCandidato = carica_stat_giocatore($conn, $stagione, $giornata, $idCandidato);
                    if (!$statCandidato['sv']) {
                        $sostituto = $statCandidato;
                        $warning[] = "Squadra $idSquadra: {$t['descrizione']} senza voto, sostituito da {$candidato['descrizione']}";
                        if ($ruoloInt === 1) $sostituzioniPortiere++; else $sostituzioniMovimento++;
                        break;
                    }
                }
            }

            if ($sostituto !== null) {
                $stat = $sostituto;
            } else {
                $motivo = $limiteRaggiunto ? 'limite sostituzioni raggiunto' : 'nessun sostituto con voto disponibile nello stesso ruolo';
                $warning[] = "Squadra $idSquadra: {$t['descrizione']} senza voto, non sostituito ($motivo)";
                $nonGiocanti[] = ['id_giocatore' => $idGiocatore];
                continue; // resta fuori dalla formazione effettiva: nessun contributo al punteggio
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
// Salva in NEW_VOTI i punteggi calcolati per una squadra
// ------------------------------------------------------------
function salva_voti_squadra($conn, int $stagione, int $giornata, int $idSquadra, array $formazione, array $risultatoSquadra): void
{
    mysqli_query($conn, "DELETE FROM NEW_VOTI
                         WHERE stagione = $stagione AND giornata = $giornata AND id_squadra = $idSquadra");

    // Indicizza le statistiche originali per id_giocatore (servono i
    // campi grezzi gf/gs/rp/rf/rs/au/amm/esp/ass, non solo il punteggio)
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

        // Mapping concordato: rigores = Rp + Rf, rigorep = Rs
        $rigores = (int) $stat['rp'] + (int) $stat['rf'];
        $rigorep = (int) $stat['rs'];

        mysqli_query($conn, "INSERT INTO NEW_VOTI
            (id_squadra, id_giocatore, stagione, voto, giornata,
             reti, ammonizioni, espulsioni, autogol, retis,
             rigores, rigorep, rufficio, giocata, totale, assist)
            VALUES ($idSquadra, $idGiocatore, $stagione, $voto, $giornata,
                    {$stat['gf']}, {$stat['amm']}, {$stat['esp']}, {$stat['au']}, {$stat['gs']},
                    $rigores, $rigorep, 0, $giocata, $totale, {$stat['ass']})");
    }
}

// Righe a zero per i titolari rimasti senza voto e senza sostituto
// idoneo (vedi carica_formazione): non contribuiscono al punteggio, ma
// restano tracciati in NEW_VOTI per completezza storica.
function salva_non_giocanti($conn, int $stagione, int $giornata, int $idSquadra, array $nonGiocanti): void
{
    foreach ($nonGiocanti as $ng) {
        $idGiocatore = (int) $ng['id_giocatore'];
        mysqli_query($conn, "INSERT INTO NEW_VOTI
            (id_squadra, id_giocatore, stagione, voto, giornata,
             reti, ammonizioni, espulsioni, autogol, retis,
             rigores, rigorep, rufficio, giocata, totale, assist)
            VALUES ($idSquadra, $idGiocatore, $stagione, 0, $giornata,
                    0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0)");
    }
}

// ------------------------------------------------------------
// Partite della giornata: stesso accoppiamento (posizione dispari =
// casa, pari = ospite) usato da calendario.php
// ------------------------------------------------------------
$righeCalendario = query_all("SELECT posizione, squadra AS id_squadra
                              FROM NEW_CALENDARIO
                              WHERE stagione = $stagione AND giornata = $giornata
                              ORDER BY posizione");
if (empty($righeCalendario)) {
    api_error("Nessuna partita a calendario per la giornata $giornata", 400);
}

$partite = [];
foreach ($righeCalendario as $r) {
    $indice = (int) (($r["posizione"] - 1) / 2);
    if ($r["posizione"] % 2 === 1) {
        $partite[$indice]["casa"] = (int) $r["id_squadra"];
    } else {
        $partite[$indice]["ospite"] = (int) $r["id_squadra"];
    }
}

$warning          = [];
$partiteElaborate = 0;
$partiteSaltate    = [];

mysqli_begin_transaction($conn);
try {
    foreach ($partite as $partita) {
        $idCasa   = $partita["casa"]   ?? null;
        $idOspite = $partita["ospite"] ?? null;
        if (!$idCasa || !$idOspite) continue;

        try {
            $nonGiocantiCasa   = [];
            $nonGiocantiOspite = [];
            $formazioneCasa   = carica_formazione(
                $conn, $stagione, $giornata, $idCasa, $warning, $nonGiocantiCasa,
                $maxSostituzioniMovimento, $maxSostituzioniPortiere
            );
            $formazioneOspite = carica_formazione(
                $conn, $stagione, $giornata, $idOspite, $warning, $nonGiocantiOspite,
                $maxSostituzioniMovimento, $maxSostituzioniPortiere
            );
        } catch (Throwable $e) {
            $partiteSaltate[] = $e->getMessage();
            continue;
        }

        $risultato = CalcolatoreVoti::calcolaPartita($formazioneCasa, $formazioneOspite, $config);

        salva_voti_squadra($conn, $stagione, $giornata, $idCasa,   $formazioneCasa,   $risultato['casa']);
        salva_voti_squadra($conn, $stagione, $giornata, $idOspite, $formazioneOspite, $risultato['ospite']);
        salva_non_giocanti($conn, $stagione, $giornata, $idCasa,   $nonGiocantiCasa);
        salva_non_giocanti($conn, $stagione, $giornata, $idOspite, $nonGiocantiOspite);

        // Fattore casa: si applica UNA volta al punteggio finale della
        // squadra di CASA (mai all'ospite), se la giornata rientra nel
        // limite configurato (vuoto = sempre applicato).
        $applicaFattoreCasa = $ultimaGiornataFattoreCasa === '' || $giornata <= (int) $ultimaGiornataFattoreCasa;
        $fattoreCampoCasa   = 0;
        if ($applicaFattoreCasa && $fattoreCasaValore != 0) {
            $fattoreCampoCasa = (int) round($fattoreCasaValore);
            $risultato['casa']['totale_squadra'] = round($risultato['casa']['totale_squadra'] + $fattoreCasaValore, 2);
            $warning[] = "Squadra $idCasa: fattore casa (+$fattoreCasaValore) applicato alla giornata $giornata";
        }

        // golf/gols: golf derivato dal punteggio totale finale (fattore
        // casa incluso) tramite le fasce configurate; gols = golf della
        // squadra avversaria (punto E confermato)
        $golfCasa   = gol_da_punteggio($risultato['casa']['totale_squadra'], $fasceGolPunteggio);
        $golfOspite = gol_da_punteggio($risultato['ospite']['totale_squadra'], $fasceGolPunteggio);

        salvaRigaRisultato(
            $conn, $stagione, $giornata, $idCasa, $idOspite,
            $risultato['casa']['totale_squadra'], $risultato['ospite']['totale_squadra'],
            $golfCasa, $golfOspite,
            (int) round(-$risultato['ospite']['modificatori']['difesa']), (int) round(-$risultato['casa']['modificatori']['difesa']),
            $risultato['casa']['modificatori']['attacco'],
            $risultato['casa']['numero_centrocampisti'], $risultato['casa']['somma_centrocampisti'], $risultato['casa']['modificatori']['centrocampo'],
            $fattoreCampoCasa
        );
        salvaRigaRisultato(
            $conn, $stagione, $giornata, $idOspite, $idCasa,
            $risultato['ospite']['totale_squadra'], $risultato['casa']['totale_squadra'],
            $golfOspite, $golfCasa,
            (int) round(-$risultato['casa']['modificatori']['difesa']), (int) round(-$risultato['ospite']['modificatori']['difesa']),
            $risultato['ospite']['modificatori']['attacco'],
            $risultato['ospite']['numero_centrocampisti'], $risultato['ospite']['somma_centrocampisti'], $risultato['ospite']['modificatori']['centrocampo'],
            0
        );

        $partiteElaborate++;
    }

    mysqli_commit($conn);
} catch (Throwable $e) {
    mysqli_rollback($conn);
    api_error("Errore durante il calcolo: " . $e->getMessage(), 500);
}

api_success([
    "ok"                => true,
    "stagione"          => $stagione,
    "giornata"          => $giornata,
    "partite_elaborate" => $partiteElaborate,
    "partite_saltate"   => $partiteSaltate,
    "warning"           => $warning,
]);
