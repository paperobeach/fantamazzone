<?php
// ============================================================
// backend/lib/AggiornamentiGiornata.php
//
// Aggiornamenti eseguiti dalla FASE 3 "Chiusura giornata"
// (backend/admin/chiusura_giornata.php). Sostituiscono i vecchi
// endpoint admin/aggiorna_statistiche.php, aggiorna_generale.php e
// aggiorna_top_flop.php.
//
// Ogni funzione RICOSTRUISCE da zero la propria tabella per la
// stagione (DELETE + INSERT ... SELECT) usando solo i dati delle
// giornate <= $giornata: e' quindi idempotente e, se un passaggio
// fallisce, basta rilanciare la chiusura. Le tabelle sono MyISAM
// (nessuna transazione): per questo il flag di chiusura viene
// scritto per ultimo dal chiamante.
//
// Logica allineata ai vecchi sorgenti del sito:
//   - web_aggiorna_statistiche_new.php
//   - web_aggiorna_generale.php
//   - web_aggiorna_top11.php / web_aggiorna_flop11.php
//
// NEW_RISULTATI contiene DUE righe per partita (una per ciascun
// punto di vista): per ogni squadra si leggono SOLO le righe con
// id_squadra = squadra, esattamente come faceva il vecchio sito.
// ============================================================

// Composizione Top/Flop 11 (modulo 1-3-4-3): ruolo => numero giocatori
const TOPFLOP_COMPOSIZIONE = [1 => 1, 2 => 3, 3 => 4, 4 => 3];

// Valori di riserva se i parametri di stagione non sono stati configurati
const TOPFLOP_SOGLIA_PERCENTUALE_DEFAULT = 50;

/**
 * Legge un parametro di stagione da NEW_PARAMETRI_STAGIONE.
 * Se manca (o e' vuoto) restituisce $default.
 */
function agg_parametro_stagione($conn, int $stagione, string $codice, $default)
{
    $codiceEsc = mysqli_real_escape_string($conn, $codice);
    $row = query_one("SELECT valore FROM NEW_PARAMETRI_STAGIONE
                      WHERE stagione = $stagione AND codice = '$codiceEsc'");
    if ($row === null || trim((string) $row['valore']) === '') return $default;
    return $row['valore'];
}

/**
 * Esegue una query e solleva un'eccezione in caso di errore.
 */
function agg_esegui($conn, string $sql, string $contesto): void
{
    if (!mysqli_query($conn, $sql)) {
        throw new Exception("$contesto: " . mysqli_error($conn));
    }
}

// ------------------------------------------------------------
// STATISTICHE giocatori
//
// Una riga per giocatore (come il vecchio sito: giocatori con
// NEW_GIOCATORI.flag <> 1 che hanno almeno una riga in NEW_VOTI).
//   giocate = SUM(giocata)
//   media   = SUM(totale) / giocate   (0 se non ha mai giocato)
//   golf    = SUM(reti)    gol fatti
//   gols    = SUM(retis)   gol subiti (portieri)
// La squadra riportata e' quella dell'ultima giornata con voti.
//
// NOTA: la media e' sul punteggio fantacalcio (totale). Il vecchio
// sito sommava all'attaccante anche l'eccedenza dei voti sopra 6;
// nel nuovo motore il modificatore attacco e' una voce di squadra
// separata e NON fa parte del punteggio del singolo giocatore, quindi
// non viene sommato alla media.
// ------------------------------------------------------------
function agg_statistiche($conn, int $stagione, int $giornata): int
{
    agg_esegui($conn, "DELETE FROM NEW_STATISTICHE WHERE stagione = $stagione", "Pulizia statistiche");

    $sql = "INSERT INTO NEW_STATISTICHE
              (stagione, id_squadra, squadra, logo, id_giocatore, giocatore,
               giocate, media, golf, gols, ammonizioni, espulsioni,
               rigores, rigorep, autogol, ruolo, assist)
            SELECT
              a.stagione, u.id_squadra, s.nome, s.logo, a.id_giocatore, g.descrizione,
              a.giocate,
              CASE WHEN a.giocate > 0 THEN ROUND(a.totale / a.giocate, 2) ELSE 0 END,
              a.reti, a.retis, a.ammonizioni, a.espulsioni,
              a.rigores, a.rigorep, a.autogol, g.ruolo, a.assist
            FROM (
              SELECT stagione, id_giocatore,
                     MAX(giornata)      AS ultima,
                     SUM(giocata)       AS giocate,
                     SUM(totale)        AS totale,
                     SUM(reti)          AS reti,
                     SUM(retis)         AS retis,
                     SUM(ammonizioni)   AS ammonizioni,
                     SUM(espulsioni)    AS espulsioni,
                     SUM(rigores)       AS rigores,
                     SUM(rigorep)       AS rigorep,
                     SUM(autogol)       AS autogol,
                     SUM(assist)        AS assist
              FROM NEW_VOTI
              WHERE stagione = $stagione AND giornata <= $giornata AND rufficio = 0
              GROUP BY stagione, id_giocatore
            ) a
            JOIN NEW_VOTI u     ON u.stagione = a.stagione AND u.id_giocatore = a.id_giocatore
                               AND u.giornata = a.ultima
            JOIN NEW_GIOCATORI g ON g.id = a.id_giocatore AND g.stagione = a.stagione AND g.flag <> 1
            JOIN NEW_SQUADRE s   ON s.id = u.id_squadra   AND s.stagione = a.stagione";
    agg_esegui($conn, $sql, "Ricostruzione statistiche");

    $n = query_one("SELECT COUNT(*) AS n FROM NEW_STATISTICHE WHERE stagione = $stagione");
    return (int) $n['n'];
}

// ------------------------------------------------------------
// CLASSIFICA GENERALE
//
// Una riga per squadra con almeno una partita. Sono lette solo le
// righe NEW_RISULTATI con id_squadra = squadra (una per partita).
//   punti, partiteg, vinte/nulle/perse (da segno V/N/P), golf, gols,
//   maxp/minp (ftotale), media (ftotale), media_a (ftotale_a),
//   segno = segno dell'ultima giornata,
//   media_mod_dif = media di `modificatore`,
//   media_mod_cc  = media di `mod_cc`,
//   media_mod_att = media di `mod_att`.
// ------------------------------------------------------------
function agg_generale($conn, int $stagione, int $giornata): int
{
    agg_esegui($conn, "DELETE FROM NEW_GENERALE WHERE stagione = $stagione", "Pulizia classifica");

    // Le medie dei modificatori stanno in decimal(3,2): clamp di sicurezza
    $clamp = fn(string $expr) => "GREATEST(LEAST(ROUND(AVG($expr), 2), 9.99), -9.99)";

    $sql = "INSERT INTO NEW_GENERALE
              (stagione, id_squadra, squadra, logo, punti, partiteg, vinte, nulle, perse,
               golf, gols, maxp, minp, media, media_a, segno,
               media_mod_dif, media_mod_cc, media_mod_att)
            SELECT
              r.stagione, r.id_squadra, s.nome, s.logo,
              SUM(r.punti), COUNT(*),
              SUM(r.segno = 'V'), SUM(r.segno = 'N'), SUM(r.segno = 'P'),
              SUM(r.golf), SUM(r.gols),
              MAX(r.ftotale), MIN(r.ftotale),
              ROUND(AVG(r.ftotale), 2), ROUND(AVG(r.ftotale_a), 2),
              COALESCE((SELECT r2.segno FROM NEW_RISULTATI r2
                        WHERE r2.stagione = r.stagione AND r2.id_squadra = r.id_squadra
                          AND r2.giornata <= $giornata
                        ORDER BY r2.giornata DESC LIMIT 1), ''),
              " . $clamp('r.modificatore') . ",
              " . $clamp('r.mod_cc') . ",
              " . $clamp('r.mod_att') . "
            FROM NEW_RISULTATI r
            JOIN NEW_SQUADRE s ON s.id = r.id_squadra AND s.stagione = r.stagione
            WHERE r.stagione = $stagione AND r.giornata <= $giornata
            GROUP BY r.stagione, r.id_squadra, s.nome, s.logo";
    agg_esegui($conn, $sql, "Ricostruzione classifica generale");

    $n = query_one("SELECT COUNT(*) AS n FROM NEW_GENERALE WHERE stagione = $stagione");
    return (int) $n['n'];
}

// ------------------------------------------------------------
// TOP 11 / FLOP 11
//
// Modulo 1-3-4-3 (TOPFLOP_COMPOSIZIONE). Candidati: giocatori di
// NEW_STATISTICHE con giocate >= soglia, dove
//   soglia = MAX(partiteg di NEW_GENERALE) * percentuale / 100
// (percentuale = parametro di stagione TOPFLOP_SOGLIA_PERCENTUALE,
// default 50: nel vecchio sito era fissa a meta' delle partite).
// Top: media decrescente; Flop: media crescente; a parita' piu'
// giocate. Va eseguita DOPO statistiche e classifica generale.
//
// Se i candidati sono meno di quelli richiesti per un ruolo, si
// inseriscono quelli disponibili e si restituisce un avviso.
// Ritorna l'elenco degli avvisi.
// ------------------------------------------------------------
function agg_top_flop($conn, int $stagione): array
{
    $avvisi = [];

    $pct = (float) agg_parametro_stagione($conn, $stagione, 'TOPFLOP_SOGLIA_PERCENTUALE', TOPFLOP_SOGLIA_PERCENTUALE_DEFAULT);
    if ($pct < 0)   $pct = 0;
    if ($pct > 100) $pct = 100;

    $row = query_one("SELECT MAX(partiteg) AS p FROM NEW_GENERALE WHERE stagione = $stagione");
    $partiteg = (int) ($row['p'] ?? 0);
    $soglia   = sprintf('%.4F', $partiteg * $pct / 100);

    agg_esegui($conn, "DELETE FROM NEW_TOP11  WHERE stagione = $stagione", "Pulizia Top 11");
    agg_esegui($conn, "DELETE FROM NEW_FLOP11 WHERE stagione = $stagione", "Pulizia Flop 11");

    $nomiRuolo = [1 => 'portieri', 2 => 'difensori', 3 => 'centrocampisti', 4 => 'attaccanti'];

    foreach (['NEW_TOP11' => 'DESC', 'NEW_FLOP11' => 'ASC'] as $tabella => $ordine) {
        $etichetta = $tabella === 'NEW_TOP11' ? 'Top 11' : 'Flop 11';
        foreach (TOPFLOP_COMPOSIZIONE as $ruolo => $quanti) {
            agg_esegui($conn, "INSERT INTO $tabella
                    (stagione, id_giocatore, giocatore, media, ruolo, giocate, id_squadra, squadra, logo)
                SELECT stagione, id_giocatore, giocatore, media, ruolo, giocate, id_squadra, squadra, logo
                FROM NEW_STATISTICHE
                WHERE stagione = $stagione AND ruolo = $ruolo AND giocate >= $soglia
                ORDER BY media $ordine, giocate DESC, id_giocatore
                LIMIT $quanti", "Ricostruzione $etichetta");

            $inseriti = mysqli_affected_rows($conn);
            if ($inseriti < $quanti) {
                $avvisi[] = "$etichetta: solo $inseriti $nomiRuolo[$ruolo] su $quanti idonei "
                          . "(soglia minima $soglia giocate)";
            }
        }
    }

    return $avvisi;
}

// ============================================================
// CHAMPIONS: statistiche e Top 11 / Flop 11
//
// Stessa logica del campionato (agg_statistiche + agg_top_flop) ma
// sui voti della sola Champions (NEW_VOTI_CHAMP) e sulle tabelle
// NEW_STATISTICHE_CHAMP, NEW_TOP11_CHAMP, NEW_FLOP11_CHAMP.
// Vengono ricostruite alla chiusura della giornata di campionato in
// cui si sono giocate le partite Champions (e dalla riapertura).
// $giornata e' sempre la giornata di FANTACAMPIONATO: e' la stessa
// usata per salvare NEW_VOTI_CHAMP e NEW_RISULTATI_CHAMP.
// ============================================================

// ------------------------------------------------------------
// STATISTICHE giocatori Champions
//
// Considera solo i voti di partite Champions effettivamente disputate
// (esiste il risultato della squadra in NEW_RISULTATI_CHAMP per quella
// giornata). Colonne e formule come NEW_STATISTICHE; la squadra
// riportata e' quella dell'ultima giornata Champions con voti.
// ------------------------------------------------------------
function agg_statistiche_champions($conn, int $stagione, int $giornata): int
{
    agg_esegui($conn, "DELETE FROM NEW_STATISTICHE_CHAMP WHERE stagione = $stagione", "Pulizia statistiche Champions");

    $sql = "INSERT INTO NEW_STATISTICHE_CHAMP
              (stagione, id_squadra, squadra, logo, id_giocatore, giocatore,
               giocate, media, golf, gols, ammonizioni, espulsioni,
               rigores, rigorep, autogol, ruolo, assist)
            SELECT
              a.stagione, u.id_squadra, s.nome, s.logo, a.id_giocatore, g.descrizione,
              a.giocate,
              CASE WHEN a.giocate > 0 THEN ROUND(a.totale / a.giocate, 2) ELSE 0 END,
              a.reti, a.retis, a.ammonizioni, a.espulsioni,
              a.rigores, a.rigorep, a.autogol, g.ruolo, a.assist
            FROM (
              SELECT v.stagione, v.id_giocatore,
                     MAX(v.giornata)      AS ultima,
                     SUM(v.giocata)       AS giocate,
                     SUM(v.totale)        AS totale,
                     SUM(v.reti)          AS reti,
                     SUM(v.retis)         AS retis,
                     SUM(v.ammonizioni)   AS ammonizioni,
                     SUM(v.espulsioni)    AS espulsioni,
                     SUM(v.rigores)       AS rigores,
                     SUM(v.rigorep)       AS rigorep,
                     SUM(v.autogol)       AS autogol,
                     SUM(v.assist)        AS assist
              FROM NEW_VOTI_CHAMP v
              JOIN NEW_RISULTATI_CHAMP r ON r.stagione = v.stagione
                                        AND r.giornata = v.giornata
                                        AND r.squadra  = v.id_squadra
              WHERE v.stagione = $stagione AND v.giornata <= $giornata AND v.rufficio = 0
              GROUP BY v.stagione, v.id_giocatore
            ) a
            JOIN NEW_VOTI_CHAMP u ON u.stagione = a.stagione AND u.id_giocatore = a.id_giocatore
                                 AND u.giornata = a.ultima
            JOIN NEW_GIOCATORI g ON g.id = a.id_giocatore AND g.stagione = a.stagione AND g.flag <> 1
            JOIN NEW_SQUADRE s   ON s.id = u.id_squadra   AND s.stagione = a.stagione";
    agg_esegui($conn, $sql, "Ricostruzione statistiche Champions");

    $n = query_one("SELECT COUNT(*) AS n FROM NEW_STATISTICHE_CHAMP WHERE stagione = $stagione");
    return (int) $n['n'];
}

// ------------------------------------------------------------
// TOP 11 / FLOP 11 Champions
//
// Come agg_top_flop(): modulo 1-3-4-3, candidati con
//   giocate >= soglia, soglia = partite Champions della squadra che ne
//   ha giocate di piu' (fino a $giornata) * percentuale / 100,
// percentuale = parametro TOPFLOP_SOGLIA_PERCENTUALE (default 50).
// Va eseguita DOPO agg_statistiche_champions(). Ritorna gli avvisi
// (nessuno finche' non e' stata giocata alcuna partita Champions).
// ------------------------------------------------------------
function agg_top_flop_champions($conn, int $stagione, int $giornata): array
{
    $avvisi = [];

    $pct = (float) agg_parametro_stagione($conn, $stagione, 'TOPFLOP_SOGLIA_PERCENTUALE', TOPFLOP_SOGLIA_PERCENTUALE_DEFAULT);
    if ($pct < 0)   $pct = 0;
    if ($pct > 100) $pct = 100;

    $row = query_one("SELECT MAX(n) AS p FROM (
                          SELECT COUNT(*) AS n FROM NEW_RISULTATI_CHAMP
                          WHERE stagione = $stagione AND giornata <= $giornata
                          GROUP BY squadra
                      ) t");
    $partiteg = (int) ($row['p'] ?? 0);
    $soglia   = sprintf('%.4F', $partiteg * $pct / 100);

    agg_esegui($conn, "DELETE FROM NEW_TOP11_CHAMP  WHERE stagione = $stagione", "Pulizia Top 11 Champions");
    agg_esegui($conn, "DELETE FROM NEW_FLOP11_CHAMP WHERE stagione = $stagione", "Pulizia Flop 11 Champions");

    if ($partiteg === 0) return $avvisi;   // nessuna partita Champions giocata

    $nomiRuolo = [1 => 'portieri', 2 => 'difensori', 3 => 'centrocampisti', 4 => 'attaccanti'];

    foreach (['NEW_TOP11_CHAMP' => 'DESC', 'NEW_FLOP11_CHAMP' => 'ASC'] as $tabella => $ordine) {
        $etichetta = $tabella === 'NEW_TOP11_CHAMP' ? 'Top 11 Champions' : 'Flop 11 Champions';
        foreach (TOPFLOP_COMPOSIZIONE as $ruolo => $quanti) {
            agg_esegui($conn, "INSERT INTO $tabella
                    (stagione, id_giocatore, giocatore, media, ruolo, giocate, id_squadra, squadra, logo)
                SELECT stagione, id_giocatore, giocatore, media, ruolo, giocate, id_squadra, squadra, logo
                FROM NEW_STATISTICHE_CHAMP
                WHERE stagione = $stagione AND ruolo = $ruolo AND giocate >= $soglia
                ORDER BY media $ordine, giocate DESC, id_giocatore
                LIMIT $quanti", "Ricostruzione $etichetta");

            $inseriti = mysqli_affected_rows($conn);
            if ($inseriti < $quanti) {
                $avvisi[] = "$etichetta: solo $inseriti $nomiRuolo[$ruolo] su $quanti idonei "
                          . "(soglia minima $soglia giocate)";
            }
        }
    }

    return $avvisi;
}
