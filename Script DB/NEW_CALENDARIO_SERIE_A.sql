-- ============================================================
-- Tabella NEW_CALENDARIO_SERIE_A
-- Calendario ufficiale della Serie A (file Excel, sheet
-- "Tutte le partite"), caricato per stagione dalla voce di menu
-- admin "Importa calendario Serie A". Il caricamento è ripetibile:
-- le partite della stessa stagione vengono sostituite.
-- InnoDB per consentire transazioni nel caricamento.
-- ============================================================
CREATE TABLE IF NOT EXISTS `NEW_CALENDARIO_SERIE_A` (
  `stagione`       int          NOT NULL,
  `giornata`       int          NOT NULL,
  `n_partita`      int          NOT NULL COMMENT 'N° partita nella giornata',
  `data_partita`   date         NOT NULL,
  `ora_partita`    time         NULL COMMENT 'NULL se orario da definire (TBD)',
  `squadra_casa`   varchar(50)  NOT NULL,
  `squadra_ospite` varchar(50)  NOT NULL,
  `prima_partita`  char(1)      NOT NULL DEFAULT 'N' COMMENT 'S = prima partita della giornata',
  `stato_orario`   varchar(30)  NOT NULL DEFAULT '' COMMENT 'Es. Ufficiale, Orario da definire',
  PRIMARY KEY (`stagione`,`giornata`,`n_partita`),
  KEY `idx_data` (`stagione`,`data_partita`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
