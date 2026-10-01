<?php
// ============================================================
// backend/lib/RisultatoPartita.php
//
// Logica di upsert di UNA riga di NEW_RISULTATI (prospettiva di UNA
// squadra), con calcolo di punti/segno/fattore_campo dai gol. Estratta
// da backend/admin/risultati.php (che la richiama per l'inserimento
// manuale) per essere riusata anche da backend/admin/calcolo_giornata.php
// (fase 2 di "Gestione voti") senza duplicare la formula né richiamare
// l'endpoint via HTTP interno.
//
// Ricorda: una partita in NEW_RISULTATI occupa DUE righe (una per
// ciascuna squadra come "punto di vista"), perché dettaglio_partita.php
// legge da ciascuna riga i modificatori attacco/centrocampo di QUELLA
// squadra. Per salvare una partita completa vanno quindi fatte DUE
// chiamate a salvaRigaRisultato(), una per squadra, con i valori
// invertiti (vedi calcolo_giornata.php per un esempio).
// ============================================================

function salvaRigaRisultato(
    $conn,
    int $stagione,
    int $giornata,
    int $idSquadra,
    int $idSquadraA,
    float $ftotale,
    float $ftotaleA,
    int $golf,
    int $gols,
    int $modificatore,
    int $modificatoreA,
    float $modAtt,
    int $numCc,
    float $totCc,
    float $modCc,
    ?float $fattoreCampo = null
): array {
    // Calcolo punti e segno (identico a backend/admin/risultati.php)
    if ($golf > $gols) {
        $punti = 3; $segno = "W"; $fc = 1; // legacy (inserimento manuale)
    } elseif ($golf === $gols) {
        $punti = 1; $segno = "N"; $fc = 0;
    } else {
        $punti = 0; $segno = "L"; $fc = 0;
    }
    // Se il chiamante passa il fattore campo realmente applicato (calcolo
    // giornata: FATTORE_CASA della squadra di casa, 0 per l'ospite) si
    // scrive quel valore al posto del flag legacy 1/0 legato al risultato.
    if ($fattoreCampo !== null) {
        $fc = $fattoreCampo;
    }

    $exists = query_one("SELECT COUNT(*) AS n FROM NEW_RISULTATI
                         WHERE stagione = $stagione AND giornata = $giornata
                           AND id_squadra = $idSquadra");
    if ((int) $exists["n"] > 0) {
        mysqli_query($conn, "UPDATE NEW_RISULTATI SET
            id_squadra_a = $idSquadraA,
            ftotale = $ftotale, ftotale_a = $ftotaleA,
            golf = $golf, gols = $gols,
            modificatore = $modificatore, modificatore_a = $modificatoreA,
            punti = $punti, fattore_campo = $fc, segno = '$segno',
            mod_att = $modAtt, num_cc = $numCc, tot_cc = $totCc, mod_cc = $modCc
            WHERE stagione = $stagione AND giornata = $giornata AND id_squadra = $idSquadra");
    } else {
        mysqli_query($conn, "INSERT INTO NEW_RISULTATI
            (giornata, stagione, id_squadra, id_squadra_a,
             ftotale, ftotale_a, golf, gols,
             modificatore, modificatore_a, punti, fattore_campo, segno,
             mod_att, num_cc, tot_cc, mod_cc)
            VALUES ($giornata, $stagione, $idSquadra, $idSquadraA,
                    $ftotale, $ftotaleA, $golf, $gols,
                    $modificatore, $modificatoreA, $punti, $fc, '$segno',
                    $modAtt, $numCc, $totCc, $modCc)");
    }

    return ["punti" => $punti, "segno" => $segno, "fattore_campo" => $fc];
}
