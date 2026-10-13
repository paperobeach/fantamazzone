-- ============================================================
-- Migrazione: crea NEW_ALBO_ORO, albo d'oro storico usato dalla
-- "Dashboard squadra" (titoli vinti, secondi posti, finali perse).
--
-- La squadra e' identificata da NEW_UTENZE.utenza (stabile tra le
-- stagioni). Da eseguire una tantum sul database esistente PRIMA di
-- pubblicare il nuovo codice, poi popolare lo storico con gli INSERT
-- sottostanti (un esempio per ogni tipo di piazzamento).
--
--   competizione: 'CAMP' (campionato) | 'CHAMP' (Champions)
--   posizione:    1 = vincitore
--                 2 = secondo posto (CAMP) / finale persa (CHAMP)
-- ============================================================

CREATE TABLE IF NOT EXISTS `NEW_ALBO_ORO` (
  `stagione` int NOT NULL,
  `competizione` varchar(5) NOT NULL COMMENT 'CAMP | CHAMP',
  `posizione` tinyint NOT NULL COMMENT '1 = vincitore, 2 = secondo posto / finale persa',
  `utenza` varchar(50) NOT NULL COMMENT 'NEW_UTENZE.utenza',
  PRIMARY KEY (`stagione`,`competizione`,`posizione`),
  KEY `idx_albo_utenza` (`utenza`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Esempi (sostituire con lo storico reale):
-- INSERT INTO `NEW_ALBO_ORO` (`stagione`,`competizione`,`posizione`,`utenza`) VALUES
--   (2024,'CAMP',1,'utenza_vincitrice'),
--   (2024,'CAMP',2,'utenza_seconda'),
--   (2024,'CHAMP',1,'utenza_vincitrice_champions'),
--   (2024,'CHAMP',2,'utenza_finalista_perso');
