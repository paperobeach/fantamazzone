-- ============================================================
-- Integrazione API-Football (Fasi 0-1): tabelle di appoggio
--
--   NEW_API_MAPPING_SQUADRE    squadre API-Football <-> squadre Serie A del
--                              progetto (nome come in NEW_CALENDARIO_SERIE_A)
--   NEW_API_MAPPING_GIOCATORI  giocatori API-Football <-> NEW_GIOCATORI_BASE_ASTA
--                              (id_giocatore = colonna "Cod." del listone)
--   NEW_API_PARTITE            stato di sincronizzazione di ogni partita del
--                              calendario Serie A (chiave = NEW_CALENDARIO_SERIE_A)
--   NEW_API_STATISTICHE        statistiche grezze per giocatore e partita, SEPARATE
--                              da NEW_VOTI_SERIE_A (che resta la fonte ufficiale
--                              importata da xlsx). Sono collegate ai giocatori
--                              del progetto tramite NEW_API_MAPPING_GIOCATORI
--   NEW_API_LOG                registro delle chiamate (quota giornaliera, errori)
--
-- Script ripetibile (CREATE TABLE IF NOT EXISTS). Da eseguire una tantum sul
-- database esistente (es. da phpMyAdmin) PRIMA di pubblicare il nuovo codice.
-- Nessuna tabella esistente viene modificata.
-- ============================================================

CREATE TABLE IF NOT EXISTS `NEW_API_LOG` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `data_ora` datetime NOT NULL COMMENT 'UTC (il contatore giornaliero di API-Football riparte a mezzanotte UTC)',
  `stagione` int NOT NULL DEFAULT '0',
  `endpoint` varchar(80) NOT NULL,
  `parametri` varchar(255) NOT NULL DEFAULT '',
  `http` smallint NOT NULL DEFAULT '0',
  `ms` int NOT NULL DEFAULT '0',
  `ok` char(1) NOT NULL DEFAULT 'N' COMMENT 'Y = risposta valida senza errori',
  `richieste_rimaste` int DEFAULT NULL COMMENT 'Richieste giornaliere residue (header x-ratelimit-requests-remaining)',
  `errore` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_data` (`data_ora`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `NEW_API_MAPPING_GIOCATORI` (
  `stagione` int NOT NULL,
  `api_player_id` int NOT NULL COMMENT 'ID giocatore in API-Football',
  `api_team_id` int DEFAULT NULL,
  `api_nome` varchar(80) NOT NULL DEFAULT '',
  `api_ruolo` varchar(20) NOT NULL DEFAULT '' COMMENT 'Goalkeeper, Defender, Midfielder, Attacker',
  `numero` smallint DEFAULT NULL,
  `id_giocatore` int DEFAULT NULL COMMENT 'NEW_GIOCATORI_BASE_ASTA.id_giocatore (NULL = non ancora associato)',
  `metodo` varchar(10) DEFAULT NULL COMMENT 'AUTO | MANUALE',
  `punteggio` smallint DEFAULT NULL COMMENT 'Affidabilità dell''abbinamento automatico (0-100)',
  `aggiornato_il` datetime NOT NULL,
  PRIMARY KEY (`stagione`,`api_player_id`),
  KEY `idx_giocatore` (`stagione`,`id_giocatore`),
  KEY `idx_team` (`stagione`,`api_team_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `NEW_API_MAPPING_SQUADRE` (
  `stagione` int NOT NULL,
  `api_team_id` int NOT NULL COMMENT 'ID squadra in API-Football',
  `api_nome` varchar(80) NOT NULL DEFAULT '',
  `squadra_serie_a` varchar(50) DEFAULT NULL COMMENT 'Nome come in NEW_CALENDARIO_SERIE_A / NEW_GIOCATORI_BASE_ASTA (NULL = non associata)',
  `metodo` varchar(10) DEFAULT NULL COMMENT 'AUTO | MANUALE',
  `aggiornato_il` datetime NOT NULL,
  PRIMARY KEY (`stagione`,`api_team_id`),
  KEY `idx_squadra` (`stagione`,`squadra_serie_a`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `NEW_API_PARTITE` (
  `stagione` int NOT NULL,
  `giornata` int NOT NULL COMMENT 'Giornata di SERIE A (come NEW_CALENDARIO_SERIE_A)',
  `n_partita` int NOT NULL COMMENT 'N° partita nella giornata (come NEW_CALENDARIO_SERIE_A)',
  `api_fixture_id` int DEFAULT NULL,
  `api_stato` varchar(10) NOT NULL DEFAULT '' COMMENT 'Stato API-Football: NS, 1H, HT, 2H, ET, P, FT, AET, PEN, PST, CANC, ABD, SUSP, INT, AWD, WO',
  `gol_casa` smallint DEFAULT NULL,
  `gol_ospite` smallint DEFAULT NULL,
  `ultimo_controllo` datetime DEFAULT NULL COMMENT 'Ultima chiamata API su questa partita (ora di Roma)',
  `ultimo_sync` datetime DEFAULT NULL COMMENT 'Ultimo salvataggio delle statistiche giocatore (ora di Roma)',
  `n_controlli` int NOT NULL DEFAULT '0',
  `dettaglio_finale` char(1) NOT NULL DEFAULT 'N' COMMENT 'Y = statistiche a partita conclusa già salvate: nessuna ulteriore chiamata',
  `n_giocatori` int NOT NULL DEFAULT '0' COMMENT 'Giocatori con statistiche salvate',
  `n_non_mappati` int NOT NULL DEFAULT '0' COMMENT 'Giocatori della partita senza associazione a NEW_GIOCATORI_BASE_ASTA',
  `errore` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`stagione`,`giornata`,`n_partita`),
  KEY `idx_fixture` (`stagione`,`api_fixture_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `NEW_API_STATISTICHE` (
  `stagione` int NOT NULL,
  `api_fixture_id` int NOT NULL,
  `api_player_id` int NOT NULL,
  `api_team_id` int DEFAULT NULL,
  `presente` char(1) NOT NULL DEFAULT 'N' COMMENT 'Y = ha giocato (minuti > 0), N = in distinta ma non entrato',
  `minuti` smallint NOT NULL DEFAULT '0',
  `titolare` char(1) NOT NULL DEFAULT 'N',
  `voto_api` decimal(3,1) DEFAULT NULL COMMENT 'Rating di API-Football (NON è il fantavoto)',
  `gf` int NOT NULL DEFAULT '0',
  `gs` int NOT NULL DEFAULT '0',
  `rp` int NOT NULL DEFAULT '0',
  `rs` int NOT NULL DEFAULT '0',
  `rf` int NOT NULL DEFAULT '0',
  `au` int NOT NULL DEFAULT '0',
  `amm` int NOT NULL DEFAULT '0',
  `esp` int NOT NULL DEFAULT '0',
  `ass` int NOT NULL DEFAULT '0',
  `aggiornato_il` datetime NOT NULL,
  PRIMARY KEY (`stagione`,`api_fixture_id`,`api_player_id`),
  KEY `idx_player` (`stagione`,`api_player_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
