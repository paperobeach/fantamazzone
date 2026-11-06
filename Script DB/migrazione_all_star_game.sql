-- ============================================================
-- Migrazione: ALL STAR GAME (Est contro Ovest)
--
-- Crea le tabelle dell'All Star Game e il parametro di stagione
-- ASG_GIORNATA (giornata di fantacampionato in cui si gioca; vuoto =
-- All Star Game disattivato). Il parametro si imposta da
-- "Gestisci regole di calcolo" > Parametri stagione > Struttura stagione.
--
-- Idempotente: si puo' rilanciare senza danni.
-- ============================================================

--
-- Struttura della tabella `NEW_ASG_OK`
-- All Star Game: ok registrato da ciascun fantallenatore sulla formazione della propria
-- compagine (Est/Ovest). Si azzera a ogni modifica della formazione.
--

CREATE TABLE IF NOT EXISTS `NEW_ASG_OK` (
  `STAGIONE` int NOT NULL DEFAULT '0',
  `GIORNATA` int NOT NULL DEFAULT '0',
  `ID_SQUADRA` int NOT NULL DEFAULT '0' COMMENT 'Fantallenatore (id squadra reale) che ha dato l''ok',
  `ID_LATO` int NOT NULL DEFAULT '0' COMMENT 'Compagine: 901 = Est, 902 = Ovest',
  `OK_IL` datetime NOT NULL,
  PRIMARY KEY (`STAGIONE`,`GIORNATA`,`ID_SQUADRA`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `NEW_ASG_STATO`
-- All Star Game: stato della formazione di ciascuna compagine (bozza / inviata)
--

CREATE TABLE IF NOT EXISTS `NEW_ASG_STATO` (
  `STAGIONE` int NOT NULL DEFAULT '0',
  `GIORNATA` int NOT NULL DEFAULT '0',
  `ID_SQUADRA` int NOT NULL DEFAULT '0' COMMENT 'Compagine: 901 = Est, 902 = Ovest',
  `INVIATA` tinyint NOT NULL DEFAULT '0' COMMENT '1 = formazione inviata (definitiva)',
  `INVIATA_DA` int DEFAULT NULL COMMENT 'Fantallenatore (id squadra reale) che ha eseguito l''invio',
  `INVIATA_IL` datetime DEFAULT NULL,
  `AGGIORNATA_DA` int DEFAULT NULL COMMENT 'Ultimo fantallenatore che ha modificato la bozza',
  `AGGIORNATA_IL` datetime DEFAULT NULL,
  PRIMARY KEY (`STAGIONE`,`GIORNATA`,`ID_SQUADRA`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `NEW_FORMAZIONI_ASG`
-- All Star Game: formazione della compagine (ID_SQUADRA 901 = Est, 902 = Ovest).
-- Stessa codifica di NEW_FORMAZIONI: MAGLIA 1..11 titolari, 12+ panchina.
--

CREATE TABLE IF NOT EXISTS `NEW_FORMAZIONI_ASG` (
  `STAGIONE` int NOT NULL DEFAULT '0',
  `ID_SQUADRA` int NOT NULL DEFAULT '0' COMMENT 'Compagine: 901 = Est, 902 = Ovest',
  `ID_GIOCATORE` int NOT NULL DEFAULT '0',
  `GIORNATA` int NOT NULL DEFAULT '0',
  `MAGLIA` int NOT NULL DEFAULT '0',
  KEY `idx_formazioni_asg` (`STAGIONE`,`GIORNATA`,`ID_SQUADRA`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `NEW_RIGORISTI_ASG`
-- All Star Game: ordine dei rigoristi (come NEW_RIGORISTI_CHAMP)
--

CREATE TABLE IF NOT EXISTS `NEW_RIGORISTI_ASG` (
  `STAGIONE` int NOT NULL DEFAULT '0',
  `GIORNATA` int NOT NULL DEFAULT '0',
  `ID_SQUADRA` int NOT NULL DEFAULT '0' COMMENT 'Compagine: 901 = Est, 902 = Ovest',
  `ID_GIOCATORE` int NOT NULL DEFAULT '0',
  `ORDINE` int NOT NULL DEFAULT '0' COMMENT 'Ordine di tiro: 1..11 titolari, poi i panchinari'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `NEW_RISULTATI_ASG`
-- All Star Game: risultato (due righe, una per compagine; id_squadra 901 = Est, 902 = Ovest)
--

CREATE TABLE IF NOT EXISTS `NEW_RISULTATI_ASG` (
  `giornata` int NOT NULL DEFAULT '0',
  `stagione` int NOT NULL DEFAULT '0',
  `id_squadra` int NOT NULL DEFAULT '0' COMMENT 'Compagine: 901 = Est, 902 = Ovest',
  `id_squadra_a` int NOT NULL DEFAULT '0',
  `ftotale` decimal(6,2) NOT NULL DEFAULT '0.00',
  `ftotale_a` decimal(6,2) NOT NULL DEFAULT '0.00',
  `golf` int NOT NULL DEFAULT '0',
  `gols` int NOT NULL DEFAULT '0',
  `modificatore` int NOT NULL DEFAULT '0',
  `modificatore_a` int NOT NULL DEFAULT '0',
  `punti` int NOT NULL DEFAULT '0',
  `fattore_campo` int NOT NULL DEFAULT '0',
  `segno` char(1) NOT NULL DEFAULT '',
  `mod_att` decimal(5,2) NOT NULL DEFAULT '0.00',
  `num_cc` int NOT NULL DEFAULT '0',
  `tot_cc` decimal(6,2) NOT NULL DEFAULT '0.00',
  `mod_cc` decimal(5,2) NOT NULL DEFAULT '0.00',
  `supp_giocati` tinyint NOT NULL DEFAULT '0' COMMENT '1 = la partita ha avuto i supplementari',
  `supp_ftotale` decimal(6,2) NOT NULL DEFAULT '0.00' COMMENT 'Punti dei 3 panchinari dei supplementari (squadra)',
  `supp_golf` int NOT NULL DEFAULT '0' COMMENT 'Gol segnati ai supplementari',
  `supp_gols` int NOT NULL DEFAULT '0' COMMENT 'Gol subiti ai supplementari',
  `rig_giocati` tinyint NOT NULL DEFAULT '0' COMMENT '1 = la partita e'' stata decisa ai calci di rigore',
  `rig_golf` int NOT NULL DEFAULT '0' COMMENT 'Rigori realizzati',
  `rig_gols` int NOT NULL DEFAULT '0' COMMENT 'Rigori realizzati dall''avversaria',
  `passa` tinyint NOT NULL DEFAULT '0' COMMENT '1 = la compagine vince la sfida',
  `dett_extra` text COMMENT 'JSON con il dettaglio di supplementari e rigori',
  PRIMARY KEY (`stagione`,`giornata`,`id_squadra`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `NEW_SIMULAZIONE_RISULTATI_ASG`
--

CREATE TABLE IF NOT EXISTS `NEW_SIMULAZIONE_RISULTATI_ASG` (
  `giornata` int NOT NULL,
  `stagione` int NOT NULL,
  `id_squadra` int NOT NULL COMMENT 'Compagine: 901 = Est, 902 = Ovest',
  `id_squadra_a` int NOT NULL,
  `ftotale` decimal(6,2) NOT NULL DEFAULT '0.00',
  `ftotale_a` decimal(6,2) NOT NULL DEFAULT '0.00',
  `golf` int NOT NULL DEFAULT '0',
  `gols` int NOT NULL DEFAULT '0',
  `modificatore` int NOT NULL DEFAULT '0',
  `modificatore_a` int NOT NULL DEFAULT '0',
  `punti` int NOT NULL DEFAULT '0',
  `fattore_campo` int NOT NULL DEFAULT '0',
  `segno` char(1) NOT NULL DEFAULT '',
  `mod_att` decimal(5,2) NOT NULL DEFAULT '0.00',
  `num_cc` int NOT NULL DEFAULT '0',
  `tot_cc` decimal(6,2) NOT NULL DEFAULT '0.00',
  `mod_cc` decimal(5,2) NOT NULL DEFAULT '0.00',
  `calcolato_il` datetime(3) NOT NULL,
  `simulato_da` varchar(50) DEFAULT NULL COMMENT 'Utente che ha eseguito la simulazione',
  `supp_giocati` tinyint NOT NULL DEFAULT '0' COMMENT '1 = la partita ha avuto i supplementari',
  `supp_ftotale` decimal(6,2) NOT NULL DEFAULT '0.00' COMMENT 'Punti dei 3 panchinari dei supplementari (squadra)',
  `supp_golf` int NOT NULL DEFAULT '0' COMMENT 'Gol segnati ai supplementari',
  `supp_gols` int NOT NULL DEFAULT '0' COMMENT 'Gol subiti ai supplementari',
  `rig_giocati` tinyint NOT NULL DEFAULT '0' COMMENT '1 = la partita e'' stata decisa ai calci di rigore',
  `rig_golf` int NOT NULL DEFAULT '0' COMMENT 'Rigori realizzati',
  `rig_gols` int NOT NULL DEFAULT '0' COMMENT 'Rigori realizzati dall''avversaria',
  `passa` tinyint NOT NULL DEFAULT '0' COMMENT '1 = la compagine vince la sfida',
  `dett_extra` text COMMENT 'JSON con il dettaglio di supplementari e rigori'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `NEW_SIMULAZIONE_VOTI_ASG`
--

CREATE TABLE IF NOT EXISTS `NEW_SIMULAZIONE_VOTI_ASG` (
  `stagione` int NOT NULL,
  `giornata` int NOT NULL,
  `id_squadra` int NOT NULL COMMENT 'Compagine: 901 = Est, 902 = Ovest',
  `id_giocatore` int NOT NULL COMMENT 'Negativo = riserva d''ufficio fittizia (come NEW_VOTI)',
  `voto` decimal(3,1) NOT NULL DEFAULT '0.0',
  `reti` int NOT NULL DEFAULT '0',
  `ammonizioni` int NOT NULL DEFAULT '0',
  `espulsioni` int NOT NULL DEFAULT '0',
  `autogol` int NOT NULL DEFAULT '0',
  `retis` int NOT NULL DEFAULT '0',
  `rigores` int NOT NULL DEFAULT '0' COMMENT 'Rp + Rf (come NEW_VOTI)',
  `rigorep` int NOT NULL DEFAULT '0' COMMENT 'Rs (come NEW_VOTI)',
  `rufficio` int NOT NULL DEFAULT '0',
  `giocata` int NOT NULL DEFAULT '0',
  `totale` decimal(6,2) NOT NULL DEFAULT '0.00',
  `assist` int NOT NULL DEFAULT '0',
  `provvisorio` tinyint NOT NULL DEFAULT '0',
  `manuale` tinyint NOT NULL DEFAULT '0',
  `api` tinyint NOT NULL DEFAULT '0' COMMENT '1 = dato delle statistiche API',
  `parziale` tinyint NOT NULL DEFAULT '0' COMMENT '1 = partita di Serie A ancora in corso (dato parziale)'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `NEW_VOTI_ASG`
--

CREATE TABLE IF NOT EXISTS `NEW_VOTI_ASG` (
  `id_squadra` int NOT NULL DEFAULT '0' COMMENT 'Compagine: 901 = Est, 902 = Ovest',
  `id_giocatore` int NOT NULL DEFAULT '0' COMMENT 'Negativo = riserva d''ufficio fittizia (come NEW_VOTI)',
  `stagione` int NOT NULL DEFAULT '0',
  `voto` decimal(3,1) NOT NULL DEFAULT '0.0',
  `giornata` int NOT NULL DEFAULT '0',
  `reti` int DEFAULT '0',
  `ammonizioni` int DEFAULT '0',
  `espulsioni` int DEFAULT '0',
  `autogol` int DEFAULT '0',
  `retis` int DEFAULT '0',
  `rigores` int DEFAULT '0',
  `rigorep` int DEFAULT '0',
  `rufficio` int DEFAULT '0',
  `giocata` int NOT NULL DEFAULT '0',
  `totale` decimal(6,2) NOT NULL DEFAULT '0.00',
  `assist` int NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------


-- Parametro di stagione (ultima stagione), vuoto = disattivato
INSERT INTO `NEW_PARAMETRI_STAGIONE` (`stagione`, `codice`, `etichetta`, `valore`)
SELECT s.`stagione`, 'ASG_GIORNATA', 'All Star Game: giornata di fantacampionato in cui si gioca (vuoto = disattivato)', ''
FROM (SELECT MAX(`stagione`) AS `stagione` FROM `NEW_SQUADRE`) s
WHERE s.`stagione` IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM `NEW_PARAMETRI_STAGIONE` p
                  WHERE p.`stagione` = s.`stagione` AND p.`codice` = 'ASG_GIORNATA');
