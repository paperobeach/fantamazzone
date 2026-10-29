-- ============================================================
-- Mercato di riparazione (prima finestra: 4 sessioni)
--
-- NEW_MERCATO_SESSIONI      una riga per sessione (1..4) di ogni finestra
-- NEW_MERCATO_CLASSIFICA    classifica del campionato salvata all'inizio
--                           della prima sessione della finestra
-- NEW_MERCATO_ORDINE        ordine di chiamata di ogni sessione e stato
--                           (ATTESA / FATTO / PASSATO) di ogni squadra
-- NEW_MERCATO_TRANSAZIONI   ogni operazione di mercato salvata
--
-- Parametri di stagione (NEW_PARAMETRI_STAGIONE, pagina "Gestisci regole
-- di calcolo" > Parametri stagione > Struttura stagione):
--   MERCATO_GIORNATA_INIZIO       giornata terminata la quale parte la 1a sessione
--                                 (vuoto = mercato disattivato)
--   MERCATO_PREZZO_PORTIERE       prezzo fisso portiere (default 5)
--   MERCATO_PREZZO_DIFENSORE      prezzo fisso difensore (default 5)
--   MERCATO_PREZZO_CENTROCAMPISTA prezzo fisso centrocampista (default 10)
--   MERCATO_PREZZO_ATTACCANTE     prezzo fisso attaccante (default 20)
--   MERCATO_TEMPO_LIMITE_ORE      ore a disposizione di ogni fantallenatore (default 24)
--   MERCATO_SOLLECITO_ORE         intervallo tra solleciti dopo la scadenza (default 6)
-- ============================================================
CREATE TABLE IF NOT EXISTS `NEW_MERCATO_SESSIONI` (
  `stagione`          int         NOT NULL,
  `finestra`          int         NOT NULL DEFAULT '1',
  `sessione`          int         NOT NULL,
  `giornata_apertura` int         NOT NULL COMMENT 'Giornata chiusa la quale la sessione si apre',
  `stato`             varchar(10) NOT NULL DEFAULT 'APERTA' COMMENT 'APERTA | CHIUSA',
  `aperta_il`         datetime    NOT NULL,
  `chiusa_il`         datetime    DEFAULT NULL,
  `riepilogo_inviato` tinyint     NOT NULL DEFAULT '0',
  PRIMARY KEY (`stagione`,`finestra`,`sessione`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `NEW_MERCATO_CLASSIFICA` (
  `stagione`   int NOT NULL,
  `finestra`   int NOT NULL DEFAULT '1',
  `posizione`  int NOT NULL,
  `id_squadra` int NOT NULL,
  `punti`      int NOT NULL DEFAULT '0',
  `golf`       int NOT NULL DEFAULT '0',
  `gols`       int NOT NULL DEFAULT '0',
  PRIMARY KEY (`stagione`,`finestra`,`posizione`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `NEW_MERCATO_ORDINE` (
  `stagione`         int         NOT NULL,
  `finestra`         int         NOT NULL DEFAULT '1',
  `sessione`         int         NOT NULL,
  `posizione`        int         NOT NULL COMMENT 'Ordine di chiamata (1 = primo a muoversi)',
  `id_squadra`       int         NOT NULL,
  `stato`            varchar(10) NOT NULL DEFAULT 'ATTESA' COMMENT 'ATTESA | FATTO | PASSATO',
  `turno_inizio`     datetime    DEFAULT NULL,
  `solleciti`        int         NOT NULL DEFAULT '0',
  `ultimo_sollecito` datetime    DEFAULT NULL,
  PRIMARY KEY (`stagione`,`finestra`,`sessione`,`posizione`),
  KEY `idx_mercato_ordine_squadra` (`stagione`,`finestra`,`sessione`,`id_squadra`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `NEW_MERCATO_TRANSAZIONI` (
  `id`                    int         NOT NULL AUTO_INCREMENT,
  `stagione`              int         NOT NULL,
  `finestra`              int         NOT NULL DEFAULT '1',
  `sessione`              int         NOT NULL,
  `id_squadra`            int         NOT NULL,
  `tipo`                  varchar(10) NOT NULL DEFAULT 'SCAMBIO' COMMENT 'SCAMBIO | PASSO',
  `id_giocatore_acquistato` int       DEFAULT NULL,
  `giocatore_acquistato`  varchar(50) DEFAULT NULL,
  `ruolo`                 int         DEFAULT NULL,
  `crediti_acquisto`      int         NOT NULL DEFAULT '0',
  `id_giocatore_ceduto`   int         DEFAULT NULL,
  `giocatore_ceduto`      varchar(50) DEFAULT NULL,
  `crediti_recuperati`    int         NOT NULL DEFAULT '0',
  `creato_il`             datetime    NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_mercato_trans_sessione` (`stagione`,`finestra`,`sessione`),
  KEY `idx_mercato_trans_ceduto` (`stagione`,`finestra`,`id_giocatore_ceduto`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
