-- ============================================================
-- Articoli AI di commento alla giornata ("La Gazzetta del Fantamazzone")
-- Fase 2: tabelle di contesto (profili fantallenatori, episodi storici
-- della lega) e archivio degli articoli generati.
--
-- Da eseguire una tantum (es. da phpMyAdmin) PRIMA di pubblicare il
-- nuovo codice. Le tabelle sono InnoDB utf8mb4 (testi con accenti ed
-- emoji) e NON dipendono dalla stagione per profili ed episodi, così
-- non vengono toccate da "Reset stagione" (che opera su un elenco
-- esplicito di tabelle).
-- ============================================================

-- Profilo di ogni fantallenatore, agganciato al NOME UTENZA
-- (NEW_UTENZE.utenza): gli id degli allenatori cambiano da una stagione
-- all'altra, il nome utenza no. Tutti i campi di testo sono opzionali.
CREATE TABLE IF NOT EXISTS `NEW_PROFILI_FANTALLENATORE` (
  `utenza`             varchar(50)  NOT NULL COMMENT 'NEW_UTENZE.utenza',
  `soprannome`         varchar(100) NOT NULL DEFAULT '',
  `carattere`          text NULL COMMENT 'Carattere, manie, modo di giocare',
  `squadra_cuore`      varchar(100) NOT NULL DEFAULT '',
  `tormentoni`         text NULL COMMENT 'Frasi o abitudini ricorrenti',
  `rivalita`           text NULL COMMENT 'Rivalità con altri fantallenatori',
  `note`               text NULL COMMENT 'Altre informazioni utili',
  `data_aggiornamento` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`utenza`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Episodi storici della lega da richiamare negli articoli.
-- "utenze" = elenco di NEW_UTENZE.utenza separate da virgola (vuoto =
-- episodio generico, valido per tutti). "ultimo_utilizzo" serve alla
-- rotazione: a parità di pertinenza vengono scelti gli episodi usati
-- meno di recente, così gli aneddoti non si ripetono sempre.
CREATE TABLE IF NOT EXISTS `NEW_EPISODI_LEGA` (
  `id`              int          NOT NULL AUTO_INCREMENT,
  `data_episodio`   date         NULL,
  `titolo`          varchar(150) NOT NULL,
  `descrizione`     text         NOT NULL,
  `tag`             varchar(200) NOT NULL DEFAULT '',
  `utenze`          varchar(500) NOT NULL DEFAULT '' COMMENT 'NEW_UTENZE.utenza separate da virgola',
  `attivo`          char(1)      NOT NULL DEFAULT 'Y',
  `ultimo_utilizzo` datetime     NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Archivio degli articoli generati. Ogni generazione crea una nuova
-- riga (stato 'bozza'); la rigenerazione non sovrascrive le precedenti.
-- I campi token/costo servono al consuntivo della spesa (costo_usd è il
-- costo reale calcolato dai token restituiti dall'API).
CREATE TABLE IF NOT EXISTS `NEW_ARTICOLI` (
  `id`                  int           NOT NULL AUTO_INCREMENT,
  `stagione`            int           NOT NULL,
  `giornata`            int           NOT NULL,
  `titolo`              varchar(250)  NOT NULL DEFAULT '',
  `sommario`            text          NULL,
  `testo`               mediumtext    NULL,
  `stato`               varchar(10)   NOT NULL DEFAULT 'bozza' COMMENT 'bozza | pubblicato',
  `modello`             varchar(60)   NOT NULL DEFAULT '',
  `troncato`            char(1)       NOT NULL DEFAULT 'N' COMMENT 'Y = risposta interrotta per limite di token',
  `token_input_stimati` int           NULL,
  `costo_stimato_usd`   decimal(10,6) NULL,
  `token_input`         int           NULL,
  `token_output`        int           NULL,
  `costo_usd`           decimal(10,6) NULL,
  `creato_da`           varchar(50)   NOT NULL DEFAULT '',
  `episodi_usati`       varchar(200)  NOT NULL DEFAULT '' COMMENT 'id NEW_EPISODI_LEGA separati da virgola',
  `data_generazione`    datetime      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `data_pubblicazione`  datetime      NULL,
  PRIMARY KEY (`id`),
  KEY `idx_stagione_giornata` (`stagione`, `giornata`),
  KEY `idx_data_generazione` (`data_generazione`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ------------------------------------------------------------
-- Esempi per popolare il contesto (finché non c'è la pagina di gestione).
-- Sostituire i nomi utenza con quelli reali di NEW_UTENZE.utenza.
-- ------------------------------------------------------------
-- INSERT INTO `NEW_PROFILI_FANTALLENATORE`
--   (`utenza`, `soprannome`, `carattere`, `squadra_cuore`, `tormentoni`, `rivalita`)
-- VALUES
--   ('nome_utenza', 'Il Professore', 'Ossessionato dalle statistiche, cambia formazione all''ultimo minuto',
--    'Juventus', 'Questa volta ho la formazione perfetta', 'Storica rivalità con un_altra_utenza');
--
-- INSERT INTO `NEW_EPISODI_LEGA` (`data_episodio`, `titolo`, `descrizione`, `tag`, `utenze`)
-- VALUES
--   ('2024-05-26', 'La finale persa per mezzo punto',
--    'Nella finale della stagione 2023/24 la coppa è sfumata per soli 0,5 punti.',
--    'finale,beffa', 'nome_utenza,un_altra_utenza');
