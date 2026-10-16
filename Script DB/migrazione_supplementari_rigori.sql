-- ============================================================
-- Migrazione: supplementari e calci di rigore in Champions
--
-- 1) NEW_REGOLE_ALGORITMI.tipo: nuovo valore SUPPLEMENTARI (fasce di
--    conversione punti -> gol dei supplementari).
-- 2) NEW_RISULTATI_CHAMP: colonne con l'esito di supplementari e
--    rigori (gol/punti dei supplementari, rigori realizzati, squadra
--    che passa il turno, dettaglio JSON).
-- 3) NEW_RIGORISTI_CHAMP: ordine dei rigoristi scelto dall'allenatore
--    (1..11 titolari, poi i panchinari) per i turni che prevedono
--    supplementari e rigori.
-- 4) Default: flag "supplementari e rigori" sulla semifinale di
--    ritorno, sulla finale e sul replay della finale; riga
--    SUPPLEMENTARI degli algoritmi con le fasce di default
--    (18-23,99 punti = 1 gol, poi 1 gol ogni 6 punti).
--
-- Da eseguire UNA VOLTA sul database esistente (es. da phpMyAdmin)
-- PRIMA di pubblicare il nuovo codice.
-- ============================================================

-- 1) nuovo tipo di algoritmo
ALTER TABLE `NEW_REGOLE_ALGORITMI`
  MODIFY `tipo` enum('DIFESA','CENTROCAMPO','ATTACCO','SUPPLEMENTARI') NOT NULL;

-- 2) esito di supplementari e rigori nei risultati Champions
ALTER TABLE `NEW_RISULTATI_CHAMP`
  ADD COLUMN `supp_giocati` tinyint NOT NULL DEFAULT '0' COMMENT '1 = la partita ha avuto i supplementari',
  ADD COLUMN `supp_ftotale` decimal(6,2) NOT NULL DEFAULT '0.00' COMMENT 'Punti dei 3 panchinari dei supplementari (squadra)',
  ADD COLUMN `supp_golf` int NOT NULL DEFAULT '0' COMMENT 'Gol segnati ai supplementari',
  ADD COLUMN `supp_gols` int NOT NULL DEFAULT '0' COMMENT 'Gol subiti ai supplementari',
  ADD COLUMN `rig_giocati` tinyint NOT NULL DEFAULT '0' COMMENT '1 = la partita e'' stata decisa ai calci di rigore',
  ADD COLUMN `rig_golf` int NOT NULL DEFAULT '0' COMMENT 'Rigori realizzati',
  ADD COLUMN `rig_gols` int NOT NULL DEFAULT '0' COMMENT 'Rigori realizzati dall''avversaria',
  ADD COLUMN `passa` tinyint NOT NULL DEFAULT '0' COMMENT '1 = la squadra vince la sfida (solo turni con supplementari/rigori)',
  ADD COLUMN `dett_extra` text COMMENT 'JSON con il dettaglio di supplementari e rigori';

-- 3) ordine dei rigoristi
CREATE TABLE IF NOT EXISTS `NEW_RIGORISTI_CHAMP` (
  `STAGIONE`     int NOT NULL DEFAULT '0',
  `GIORNATA`     int NOT NULL DEFAULT '0',
  `ID_SQUADRA`   int NOT NULL DEFAULT '0',
  `ID_GIOCATORE` int NOT NULL DEFAULT '0',
  `ORDINE`       int NOT NULL DEFAULT '0' COMMENT 'Ordine di tiro: 1..11 titolari, poi i panchinari',
  PRIMARY KEY (`STAGIONE`, `GIORNATA`, `ID_SQUADRA`, `ID_GIOCATORE`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- 4a) Algoritmo "calcolo supplementari" per ogni stagione già configurata
--     (se manca). Con "Gestisci regole di calcolo" si possono modificare le fasce.
INSERT INTO `NEW_REGOLE_ALGORITMI` (`stagione`, `tipo`, `algoritmo`, `parametri`, `descrizione`)
SELECT DISTINCT a.`stagione`, 'SUPPLEMENTARI', 'fasce_somma_v1',
       '{"fasce":[{"da":18,"a":23.99,"gol":1}],"ogni_punti":6,"gol_ogni":1}',
       'Gol dei supplementari dalla somma dei punti dei primi panchinari di difesa, centrocampo e attacco'
FROM `NEW_REGOLE_ALGORITMI` a
WHERE NOT EXISTS (
  SELECT 1 FROM `NEW_REGOLE_ALGORITMI` x
  WHERE x.`stagione` = a.`stagione` AND x.`tipo` = 'SUPPLEMENTARI'
);

-- 4b) Flag "supplementari e rigori" per ogni stagione che ha il calendario
--     Champions configurato: semifinale di ritorno, finale, replay della finale.
INSERT INTO `NEW_PARAMETRI_STAGIONE` (`stagione`, `codice`, `etichetta`, `valore`)
SELECT DISTINCT p.`stagione`, 'CHAMP_SF_T2_SUPPL',
       'Champions - Semifinali ritorno: supplementari e rigori (1 = sì, 0 = no)', '1'
FROM `NEW_PARAMETRI_STAGIONE` p
WHERE p.`codice` LIKE 'CHAMP\_%'
  AND NOT EXISTS (SELECT 1 FROM `NEW_PARAMETRI_STAGIONE` x WHERE x.`stagione` = p.`stagione` AND x.`codice` = 'CHAMP_SF_T2_SUPPL');

INSERT INTO `NEW_PARAMETRI_STAGIONE` (`stagione`, `codice`, `etichetta`, `valore`)
SELECT DISTINCT p.`stagione`, 'CHAMP_FIN_T1_SUPPL',
       'Champions - Finale: supplementari e rigori (1 = sì, 0 = no)', '1'
FROM `NEW_PARAMETRI_STAGIONE` p
WHERE p.`codice` LIKE 'CHAMP\_%'
  AND NOT EXISTS (SELECT 1 FROM `NEW_PARAMETRI_STAGIONE` x WHERE x.`stagione` = p.`stagione` AND x.`codice` = 'CHAMP_FIN_T1_SUPPL');

INSERT INTO `NEW_PARAMETRI_STAGIONE` (`stagione`, `codice`, `etichetta`, `valore`)
SELECT DISTINCT p.`stagione`, 'CHAMP_FIN_REPLAY_SUPPL',
       'Champions - Replay finale: supplementari e rigori (1 = sì, 0 = no)', '1'
FROM `NEW_PARAMETRI_STAGIONE` p
WHERE p.`codice` LIKE 'CHAMP\_%'
  AND NOT EXISTS (SELECT 1 FROM `NEW_PARAMETRI_STAGIONE` x WHERE x.`stagione` = p.`stagione` AND x.`codice` = 'CHAMP_FIN_REPLAY_SUPPL');
