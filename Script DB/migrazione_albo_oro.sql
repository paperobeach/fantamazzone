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
--                 99 = ultimo posto (solo CAMP, "maglia nera")
--
-- La pagina "Albo d'oro" (menu Generale) legge questa tabella tramite
-- api/albo_oro.php.
-- ============================================================

CREATE TABLE IF NOT EXISTS `NEW_ALBO_ORO` (
  `stagione` int NOT NULL,
  `competizione` varchar(5) NOT NULL COMMENT 'CAMP | CHAMP',
  `posizione` tinyint NOT NULL COMMENT '1 = vincitore, 2 = secondo posto / finale persa, 99 = ultimo (solo CAMP)',
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


-- ============================================================
-- Popolamento albo d'oro CAMPIONATO (primo, secondo, ultimo)
-- ricavato da NEW_GENERALE per le stagioni 2003 .. 2025 (2025 = 2025/2026).
-- Ordinamento identico a quello della classifica (api/generale.php):
-- punti DESC, gol fatti DESC, gol subiti DESC.
-- La stagione 2026 (in corso) e' esclusa. La stagione 2024 non e' presente
-- in NEW_GENERALE: va inserita a mano (vedi esempio in fondo).
-- INSERT IGNORE: non sovrascrive righe gia' presenti.
-- Richiede MySQL 8 (funzioni finestra).
-- ============================================================
INSERT IGNORE INTO `NEW_ALBO_ORO` (`stagione`,`competizione`,`posizione`,`utenza`)
SELECT r.stagione,
       'CAMP',
       CASE WHEN r.rn = 1 THEN 1 WHEN r.rn = 2 THEN 2 ELSE 99 END,
       u.utenza
FROM (
  SELECT g.stagione, g.id_squadra,
         ROW_NUMBER() OVER (PARTITION BY g.stagione
                            ORDER BY g.punti DESC, g.golf DESC, g.gols DESC) AS rn,
         COUNT(*)     OVER (PARTITION BY g.stagione) AS tot
  FROM NEW_GENERALE g
  WHERE g.stagione BETWEEN 2003 AND 2025
) r
JOIN (
  SELECT stagione, id, MIN(utenza) AS utenza
  FROM NEW_UTENZE
  GROUP BY stagione, id
) u ON u.stagione = r.stagione AND u.id = r.id_squadra
WHERE r.rn IN (1, 2) OR r.rn = r.tot;

-- Verifica: attese 66 righe CAMP (22 stagioni x 3: 2003-2023 + 2025).
-- SELECT COUNT(*) FROM NEW_ALBO_ORO WHERE competizione = 'CAMP';

-- Stagione 2024 (assente in NEW_GENERALE) e storico CHAMPIONS: da inserire
-- a mano, ad esempio:
-- INSERT INTO `NEW_ALBO_ORO` (`stagione`,`competizione`,`posizione`,`utenza`) VALUES
--   (2024,'CAMP',1,'utenza_primo'),
--   (2024,'CAMP',2,'utenza_secondo'),
--   (2024,'CAMP',99,'utenza_ultimo'),
--   (2024,'CHAMP',1,'utenza_vincitore_champions'),
--   (2024,'CHAMP',2,'utenza_finalista');
