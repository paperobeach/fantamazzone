<?php
// ============================================================
// backend/lib/StatoGiornata.php
//
// Regole comuni su "giornata chiusa / in corso", usate da:
//   - calendario.php, dettaglio_partita.php  (risultati visibili
//     SOLO per le giornate chiuse)
//   - live_giornata.php, live_dettaglio.php, live_simulazione.php
//     (pagina "LIVE Giornata in corso")
//
// Definizioni (coerenti con giornata_corrente.php):
//   - giornata CHIUSA   = NEW_CALENDARIO_CK.ck_giocata = 'S'
//   - giornata IN CORSO = prima giornata dopo l'ultima chiusa, ma solo
//     per la stagione PIÙ RECENTE (le stagioni passate sono storiche e
//     possono non avere i flag di chiusura: i loro risultati restano
//     sempre visibili e non hanno alcuna giornata "in corso").
//
// Richiede connect.php (query_one) già incluso dal chiamante.
// ============================================================

function sg_ultima_stagione(): int
{
    $r = query_one("SELECT MAX(stagione) AS s FROM NEW_SQUADRE");
    return (int) ($r['s'] ?? 0);
}

function sg_giornata_chiusa(int $stagione, int $giornata): bool
{
    $ck = query_one("SELECT ck_giocata FROM NEW_CALENDARIO_CK
                     WHERE stagione = $stagione AND giornata = $giornata");
    return $ck !== null && $ck['ck_giocata'] === 'S';
}

// I risultati di una giornata sono "pubblici" (calendario, dettaglio
// partita) se la giornata è chiusa, oppure se la stagione è storica.
function sg_risultati_pubblici(int $stagione, int $giornata): bool
{
    if (sg_giornata_chiusa($stagione, $giornata)) return true;
    return $stagione !== sg_ultima_stagione();
}

// Stato della giornata in corso per la stagione.
// Ritorna: stagione, giornata, in_corso (bool), motivo (se non in
// corso), ultima_chiusa, ultima_calendario, ultima_stagione.
function sg_stato_corrente(int $stagione): array
{
    $ultimaStagione = sg_ultima_stagione();

    $r = query_one("SELECT MAX(giornata) AS g FROM NEW_CALENDARIO_CK
                    WHERE stagione = $stagione AND ck_giocata = 'S'");
    $ultimaChiusa = (int) ($r['g'] ?? 0);

    $r = query_one("SELECT MAX(giornata) AS g FROM NEW_CALENDARIO WHERE stagione = $stagione");
    $ultimaCalendario = (int) ($r['g'] ?? 0);

    $giornata = $ultimaChiusa + 1;
    $inCorso  = true;
    $motivo   = null;

    if ($stagione !== $ultimaStagione) {
        $inCorso = false;
        $motivo  = "La giornata in corso è disponibile solo per la stagione più recente ($ultimaStagione)";
    } elseif ($ultimaCalendario === 0) {
        $inCorso = false;
        $motivo  = "Nessun calendario presente per la stagione $stagione";
    } elseif ($giornata > $ultimaCalendario) {
        $inCorso  = false;
        $giornata = $ultimaCalendario;
        $motivo   = "Tutte le giornate della stagione risultano chiuse";
    }

    return [
        'stagione'          => $stagione,
        'giornata'          => $giornata,
        'in_corso'          => $inCorso,
        'motivo'            => $motivo,
        'ultima_chiusa'     => $ultimaChiusa,
        'ultima_calendario' => $ultimaCalendario,
        'ultima_stagione'   => $ultimaStagione,
    ];
}
