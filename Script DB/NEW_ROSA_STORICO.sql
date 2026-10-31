-- ============================================================
-- Storico delle rose: NEW_ROSA_STORICO
--
-- Le rose cambiano SOLO durante il mercato (riparazione e seconda
-- finestra). La tabella non e' quindi uno snapshot per giornata ma un
-- elenco di PERIODI: il giocatore e' nella rosa della squadra dalla
-- giornata `da_giornata` alla giornata `a_giornata` inclusa (NULL = ancora
-- in rosa). Un'operazione di mercato fatta dopo la chiusura della giornata G:
--   cessione  -> a_giornata  = G      (ultima giornata in rosa)
--   acquisto  -> da_giornata = G + 1  (prima giornata in rosa)
-- Il giocatore ceduto resta cosi' nella rosa della squadra precedente per
-- tutte le giornate in cui c'era (e li' resta visibile in tutto lo storico).
--
-- Scritta da lib/MercatoRiparazione.php, lib/MercatoSecondaFinestra.php e
-- azzerata da admin/inserimento_rose.php (backend/lib/RosaStorico.php).
-- Letta dalla vista "Tutta la rosa" della Dashboard squadra.
--
-- I giocatori attualmente in rosa SENZA alcuna riga nello storico valgono
-- "in rosa per tutta la stagione": la lettura funziona anche con la tabella
-- vuota. Il blocco di BACKFILL qui sotto ricostruisce lo storico delle
-- operazioni di mercato gia' avvenute: va eseguito UNA SOLA VOLTA.
-- ============================================================
CREATE TABLE IF NOT EXISTS `NEW_ROSA_STORICO` (
  `stagione`     int NOT NULL,
  `id_giocatore` int NOT NULL,
  `id_squadra`   int NOT NULL,
  `da_giornata`  int NOT NULL DEFAULT '1' COMMENT 'Prima giornata in cui il giocatore e'' in rosa',
  `a_giornata`   int DEFAULT NULL COMMENT 'Ultima giornata in rosa; NULL = ancora in rosa',
  PRIMARY KEY (`stagione`,`id_giocatore`,`id_squadra`,`da_giornata`),
  KEY `idx_rosa_storico_squadra` (`stagione`,`id_squadra`),
  KEY `idx_rosa_storico_giocatore` (`stagione`,`id_giocatore`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;


-- ============================================================
-- BACKFILL (ESEGUIRE UNA SOLA VOLTA, dopo la creazione della tabella)
--
-- Ricostruisce lo storico a partire dalla rosa attuale e dalle operazioni
-- di mercato registrate (NEW_MERCATO_TRANSAZIONI / NEW_MERCATO2_TRANSAZIONI).
-- ATTENZIONE: svuota la tabella; non rieseguire dopo che il mercato ha
-- gia' scritto righe nuove.
--
-- Seconda finestra (aste): le transazioni non registrano la giornata, quindi
-- si assume che sia stata fatta dopo l'ULTIMA sessione della riparazione
-- (o, senza sessioni, dopo l'ultima giornata chiusa). Se la finestra si e'
-- tenuta dopo un'altra giornata chiusa, impostare @g_m2 con quella giornata
-- PRIMA del blocco D (es. SET @g_m2 = 20;).
-- ============================================================
TRUNCATE TABLE `NEW_ROSA_STORICO`;

SET @g_m2 = NULL;

-- A) Rosa attuale: in rosa dalla giornata 1 e ancora in rosa
INSERT INTO `NEW_ROSA_STORICO` (`stagione`, `id_giocatore`, `id_squadra`, `da_giornata`, `a_giornata`)
SELECT g.`stagione`, g.`id`, g.`squadra`, 1, NULL
FROM `NEW_GIOCATORI` g
WHERE g.`squadra` > 0;

-- B) Acquisti del mercato di riparazione (sessioni): in rosa dalla giornata
--    successiva a quella di apertura della sessione
UPDATE `NEW_ROSA_STORICO` r
JOIN `NEW_MERCATO_TRANSAZIONI` t
  ON t.`stagione` = r.`stagione` AND t.`id_giocatore_acquistato` = r.`id_giocatore`
 AND t.`id_squadra` = r.`id_squadra` AND t.`tipo` = 'SCAMBIO'
JOIN `NEW_MERCATO_SESSIONI` s
  ON s.`stagione` = t.`stagione` AND s.`finestra` = t.`finestra` AND s.`sessione` = t.`sessione`
SET r.`da_giornata` = s.`giornata_apertura` + 1
WHERE r.`a_giornata` IS NULL;

-- C) Cessioni del mercato di riparazione: periodo che termina alla giornata
--    di apertura della sessione; inizia da 1 oppure, se il giocatore era
--    stato acquistato in precedenza dalla stessa squadra, dall'acquisto
INSERT INTO `NEW_ROSA_STORICO` (`stagione`, `id_giocatore`, `id_squadra`, `da_giornata`, `a_giornata`)
SELECT t.`stagione`, t.`id_giocatore_ceduto`, t.`id_squadra`,
       COALESCE((SELECT MIN(s0.`giornata_apertura`) + 1
                   FROM `NEW_MERCATO_TRANSAZIONI` t0
                   JOIN `NEW_MERCATO_SESSIONI` s0
                     ON s0.`stagione` = t0.`stagione` AND s0.`finestra` = t0.`finestra` AND s0.`sessione` = t0.`sessione`
                  WHERE t0.`stagione` = t.`stagione` AND t0.`id_squadra` = t.`id_squadra`
                    AND t0.`id_giocatore_acquistato` = t.`id_giocatore_ceduto`
                    AND t0.`tipo` = 'SCAMBIO' AND t0.`id` < t.`id`), 1),
       s.`giornata_apertura`
FROM `NEW_MERCATO_TRANSAZIONI` t
JOIN `NEW_MERCATO_SESSIONI` s
  ON s.`stagione` = t.`stagione` AND s.`finestra` = t.`finestra` AND s.`sessione` = t.`sessione`
WHERE t.`tipo` = 'SCAMBIO' AND t.`id_giocatore_ceduto` IS NOT NULL;

-- D) Acquisti della seconda finestra (aste): in rosa dalla giornata successiva
--    a quella assunta per la finestra (vedi nota sopra)
UPDATE `NEW_ROSA_STORICO` r
JOIN `NEW_MERCATO2_TRANSAZIONI` t
  ON t.`stagione` = r.`stagione` AND t.`id_giocatore` = r.`id_giocatore`
 AND t.`id_squadra_aggiudicataria` = r.`id_squadra` AND t.`tipo` = 'ASTA'
SET r.`da_giornata` = COALESCE(
      @g_m2,
      (SELECT MAX(s.`giornata_apertura`) FROM `NEW_MERCATO_SESSIONI` s WHERE s.`stagione` = r.`stagione`),
      (SELECT MAX(c.`giornata`) FROM `NEW_CALENDARIO_CK` c WHERE c.`stagione` = r.`stagione` AND c.`ck_giocata` = 'S'),
      0) + 1
WHERE r.`a_giornata` IS NULL;

-- Verifica (facoltativa): periodi diversi da "tutta la stagione"
-- SELECT * FROM `NEW_ROSA_STORICO` WHERE `da_giornata` > 1 OR `a_giornata` IS NOT NULL ORDER BY `stagione`, `id_squadra`, `id_giocatore`;
