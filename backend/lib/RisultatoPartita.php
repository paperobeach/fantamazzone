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
    // Calcolo punti e segno (V = vinta, N = pareggiata, P = persa)
    if ($golf > $gols) {
        $punti = 3; $segno = "V"; $fc = 1; // legacy (inserimento manuale)
    } elseif ($golf === $gols) {
        $punti = 1; $segno = "N"; $fc = 0;
    } else {
        $punti = 0; $segno = "P"; $fc = 0;
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


/**
 * Upsert di UNA riga di NEW_RISULTATI_CHAMP (prospettiva di UNA squadra)
 * per una partita di Champions. Stessa formula punti/segno di
 * salvaRigaRisultato(); chiave (stagione, giornata, squadra), dove
 * giornata = giornata di campionato in cui si gioca il turno.
 * Come per il campionato, una partita occupa DUE righe (una per squadra,
 * con i valori invertiti): vedi calcolo_giornata.php.
 */
function salvaRigaRisultatoChampions(
    $conn,
    int $stagione,
    int $giornata,
    string $girone,
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
    int $fattoreCampo
): array {
    if ($golf > $gols)       { $punti = 3; $segno = "V"; }
    elseif ($golf === $gols) { $punti = 1; $segno = "N"; }
    else                     { $punti = 0; $segno = "P"; }

    $f   = fn(float $v) => sprintf("%.2f", $v);
    $gir = mysqli_real_escape_string($conn, mb_substr(trim($girone), 0, 2));

    mysqli_query($conn, "DELETE FROM NEW_RISULTATI_CHAMP
                         WHERE stagione = $stagione AND giornata = $giornata AND squadra = $idSquadra");
    $ok = mysqli_query($conn, "INSERT INTO NEW_RISULTATI_CHAMP
        (stagione, giornata, squadra, ftotale, golf, gols, punti, segno, girone,
         id_squadra_a, ftotale_a, modificatore, modificatore_a, fattore_campo,
         mod_att, num_cc, tot_cc, mod_cc)
        VALUES ($stagione, $giornata, $idSquadra, {$f($ftotale)}, $golf, $gols, $punti, '$segno', '$gir',
                $idSquadraA, {$f($ftotaleA)}, $modificatore, $modificatoreA, $fattoreCampo,
                {$f($modAtt)}, $numCc, {$f($totCc)}, {$f($modCc)})");
    if (!$ok) throw new Exception("Scrittura NEW_RISULTATI_CHAMP: " . mysqli_error($conn));

    return ["punti" => $punti, "segno" => $segno];
}
