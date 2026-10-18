-- ============================================================
-- Gazzetta AI - Fase 4: contesto fisso, note di giornata, libreria
-- immagini ed edizione sfogliabile.
--
-- Da eseguire UNA SOLA VOLTA (es. da phpMyAdmin) PRIMA di pubblicare il
-- nuovo codice, dopo Script DB/NEW_AI_ARTICOLI.sql.
--
-- Contenuto:
--   1. NEW_CONTESTO_FISSO       blocchi di contesto fisso e di stile
--   2. NEW_IMMAGINI_GAZZETTA    libreria immagini (file salvati nel DB)
--   3. NEW_ARTICOLI_PAGINE      pagine delle edizioni sfogliabili
--   4. NEW_ARTICOLI             nuove colonne (ALTER, eseguire una volta)
-- ============================================================

-- 1. Contesto fisso: informazioni stabili sulla lega (storia, regole,
-- personaggi, glossario) e, a parte, lo STILE di scrittura. Vengono
-- inseriti nel prompt di sistema, in aggiunta a profili ed episodi.
-- tipo = 'contesto' | 'stile'. Se non c'è nessun blocco 'stile' attivo
-- si usa lo stile predefinito (ispirato a Stefano Benni) del codice.
CREATE TABLE IF NOT EXISTS `NEW_CONTESTO_FISSO` (
  `id`                 int          NOT NULL AUTO_INCREMENT,
  `tipo`               varchar(10)  NOT NULL DEFAULT 'contesto' COMMENT 'contesto | stile',
  `titolo`             varchar(100) NOT NULL,
  `testo`              text         NOT NULL,
  `ordine`             int          NOT NULL DEFAULT 0,
  `attivo`             char(1)      NOT NULL DEFAULT 'Y',
  `data_aggiornamento` datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_tipo_ordine` (`tipo`, `ordine`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- 2. Libreria immagini della Gazzetta. I file sono ridimensionati
-- (lato lungo max ~1400 px) e salvati nel DB: così non dipendono dal
-- filesystem né dal deploy. "descrizione", "tag", "tono" e "utenze" sono
-- ciò che l'AI legge per scegliere le immagini degli articoli (opzione B:
-- scritti a mano; opzione C: proposti dall'AI con visione).
CREATE TABLE IF NOT EXISTS `NEW_IMMAGINI_GAZZETTA` (
  `id`                  int           NOT NULL AUTO_INCREMENT,
  `nome_file`           varchar(200)  NOT NULL DEFAULT '',
  `mime`                varchar(30)   NOT NULL DEFAULT 'image/jpeg',
  `larghezza`           int           NOT NULL DEFAULT 0,
  `altezza`             int           NOT NULL DEFAULT 0,
  `hash_file`           char(40)      NOT NULL DEFAULT '' COMMENT 'sha1 del file salvato: evita i doppioni',
  `dati`                mediumblob    NOT NULL,
  `miniatura`           mediumblob    NULL,
  `descrizione`         text          NULL,
  `tag`                 varchar(300)  NOT NULL DEFAULT '',
  `tono`                varchar(60)   NOT NULL DEFAULT '',
  `utenze`              varchar(500)  NOT NULL DEFAULT '' COMMENT 'NEW_UTENZE.utenza di chi compare, separate da virgola',
  `origine_descrizione` varchar(10)   NOT NULL DEFAULT 'manuale' COMMENT 'manuale | ai',
  `modello`             varchar(60)   NOT NULL DEFAULT '',
  `token_input`         int           NULL,
  `token_output`        int           NULL,
  `costo_usd`           decimal(10,6) NULL,
  `data_descrizione`    datetime      NULL COMMENT 'ultima descrizione AI',
  `attivo`              char(1)       NOT NULL DEFAULT 'Y',
  `ultimo_utilizzo`     datetime      NULL,
  `caricata_da`         varchar(50)   NOT NULL DEFAULT '',
  `data_caricamento`    datetime      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_hash` (`hash_file`),
  KEY `idx_attivo_utilizzo` (`attivo`, `ultimo_utilizzo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- 3. NEW_ARTICOLI: nuove colonne
--   formato                'articolo' (testo unico) | 'edizione' (sfogliabile)
--   contesto_giornata      note di giornata inserite da chi genera
--   id_immagine_copertina  immagine di apertura
--   immagini_usate         id immagini (copertina, pagine, segnaposto nel testo)
ALTER TABLE `NEW_ARTICOLI`
  ADD COLUMN `formato`               varchar(10)  NOT NULL DEFAULT 'articolo' COMMENT 'articolo | edizione',
  ADD COLUMN `contesto_giornata`     text         NULL COMMENT 'Note di giornata inserite da chi genera l''articolo',
  ADD COLUMN `id_immagine_copertina` int          NULL COMMENT 'NEW_IMMAGINI_GAZZETTA.id',
  ADD COLUMN `immagini_usate`        varchar(300) NOT NULL DEFAULT '' COMMENT 'id NEW_IMMAGINI_GAZZETTA separati da virgola';

-- 4. Pagine delle edizioni sfogliabili (una per partita + commento
-- generale). "dati" contiene i fatti (punteggi, marcatori, classifica)
-- congelati alla generazione, così la pagina non cambia se il DB cambia.
CREATE TABLE IF NOT EXISTS `NEW_ARTICOLI_PAGINE` (
  `id`           int          NOT NULL AUTO_INCREMENT,
  `id_articolo`  int          NOT NULL,
  `ordine`       int          NOT NULL,
  `tipo`         varchar(12)  NOT NULL DEFAULT 'partita' COMMENT 'partita | commento',
  `competizione` varchar(12)  NOT NULL DEFAULT '' COMMENT 'campionato | champions',
  `titolo`       varchar(250) NOT NULL DEFAULT '',
  `testo`        mediumtext   NULL,
  `id_immagine`  int          NULL COMMENT 'NEW_IMMAGINI_GAZZETTA.id',
  `didascalia`   varchar(300) NOT NULL DEFAULT '',
  `dati`         mediumtext   NULL COMMENT 'JSON: fatti della pagina',
  PRIMARY KEY (`id`),
  KEY `idx_articolo_ordine` (`id_articolo`, `ordine`),
  CONSTRAINT `fk_pagine_articolo` FOREIGN KEY (`id_articolo`) REFERENCES `NEW_ARTICOLI` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
