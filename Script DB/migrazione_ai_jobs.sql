-- ============================================================
-- Generazione DIFFERITA degli articoli/edizioni AI (Message Batches).
-- Tiene traccia degli ordini inviati ad Anthropic e ancora da ritirare.
-- Da eseguire una tantum (es. da phpMyAdmin) PRIMA di pubblicare il
-- nuovo codice. Non dipende da altre tabelle; non è toccata da
-- "Reset stagione".
-- ============================================================

CREATE TABLE IF NOT EXISTS `NEW_AI_JOBS` (
  `id` int NOT NULL AUTO_INCREMENT,
  `stagione` int NOT NULL,
  `giornata` int NOT NULL,
  `formato` varchar(10) NOT NULL DEFAULT 'articolo' COMMENT 'articolo | edizione',
  `modello` varchar(60) NOT NULL DEFAULT '',
  `batch_id` varchar(80) NOT NULL DEFAULT '' COMMENT 'id del Message Batch di Anthropic',
  `stato` varchar(14) NOT NULL DEFAULT 'in_corso' COMMENT 'in_corso | in_salvataggio | completato | errore',
  `prompt_hash` varchar(64) NOT NULL DEFAULT '',
  `contesto_giornata` text COMMENT 'Note di giornata inserite da chi genera',
  `creato_da` varchar(50) NOT NULL DEFAULT '',
  `token_input_stimati` int DEFAULT NULL,
  `costo_stimato_usd` decimal(10,6) DEFAULT NULL,
  `costo_massimo_usd` decimal(10,6) DEFAULT NULL,
  `id_articolo` int DEFAULT NULL COMMENT 'NEW_ARTICOLI.id della bozza salvata',
  `errore` text,
  `data_creazione` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `data_aggiornamento` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `data_fine` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_stagione_giornata` (`stagione`,`giornata`,`formato`),
  KEY `idx_stato` (`stato`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
