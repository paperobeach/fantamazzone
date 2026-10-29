
-- ============================================================
-- Mercato di riparazione - SECONDA FINESTRA (in presenza, ad asta)
--
-- NEW_MERCATO2_FINESTRA      una riga per stagione: stato della finestra e turno corrente
-- NEW_MERCATO2_ORDINE        ordine di chiamata (inverso della classifica all'avvio)
--                            e stato di ogni squadra (ATTIVA / PASSATO)
-- NEW_MERCATO2_TRANSAZIONI   ogni asta conclusa (ASTA) o turno passato (PASSO)
--
-- La finestra si svolge in un giorno prestabilito, di persona: turni consecutivi
-- (a giri) in cui la squadra di turno chiama uno svincolato; squadra aggiudicataria
-- e prezzo sono inseriti a mano. Passare il turno chiude la finestra della squadra.
-- ============================================================
CREATE TABLE IF NOT EXISTS `NEW_MERCATO2_FINESTRA` (
  `stagione`        int         NOT NULL,
  `stato`           varchar(10) NOT NULL DEFAULT 'ATTIVA' COMMENT 'ATTIVA | CHIUSA',
  `avviata_il`      datetime    NOT NULL,
  `avviata_da`      int         DEFAULT NULL COMMENT 'Id utente amministratore che ha avviato la finestra',
  `chiusa_il`       datetime    DEFAULT NULL,
  `turno_posizione` int         DEFAULT NULL COMMENT 'Posizione (NEW_MERCATO2_ORDINE) della squadra di turno',
  `giro`            int         NOT NULL DEFAULT '1',
  PRIMARY KEY (`stagione`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `NEW_MERCATO2_ORDINE` (
  `stagione`        int         NOT NULL,
  `posizione`       int         NOT NULL COMMENT 'Ordine di chiamata (1 = primo a chiamare)',
  `id_squadra`      int         NOT NULL,
  `pos_classifica`  int         NOT NULL DEFAULT '0' COMMENT 'Posizione in classifica all''avvio',
  `punti`           int         NOT NULL DEFAULT '0',
  `stato`           varchar(10) NOT NULL DEFAULT 'ATTIVA' COMMENT 'ATTIVA | PASSATO',
  PRIMARY KEY (`stagione`,`posizione`),
  KEY `idx_mercato2_ordine_squadra` (`stagione`,`id_squadra`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `NEW_MERCATO2_TRANSAZIONI` (
  `id`                      int         NOT NULL AUTO_INCREMENT,
  `stagione`                int         NOT NULL,
  `giro`                    int         NOT NULL DEFAULT '1',
  `posizione`               int         NOT NULL COMMENT 'Posizione in ordine della squadra che ha chiamato',
  `id_squadra_chiamante`    int         NOT NULL,
  `tipo`                    varchar(10) NOT NULL DEFAULT 'ASTA' COMMENT 'ASTA | PASSO',
  `id_squadra_aggiudicataria` int       DEFAULT NULL,
  `id_giocatore`            int         DEFAULT NULL,
  `giocatore`               varchar(50) DEFAULT NULL,
  `ruolo`                   int         DEFAULT NULL,
  `nazione`                 varchar(50) DEFAULT NULL,
  `crediti`                 int         NOT NULL DEFAULT '0',
  `creato_il`               datetime    NOT NULL,
  `creato_da`               int         DEFAULT NULL COMMENT 'Id utente che ha registrato l''operazione',
  PRIMARY KEY (`id`),
  KEY `idx_mercato2_trans_stagione` (`stagione`),
  KEY `idx_mercato2_trans_giocatore` (`stagione`,`id_giocatore`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
