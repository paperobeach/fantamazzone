-- phpMyAdmin SQL Dump
-- version 5.2.0
-- https://www.phpmyadmin.net/
--
-- Host: localhost
-- Creato il: Ott 05, 2026 alle 15:09
-- Versione del server: 8.0.45
-- Versione PHP: 8.0.22

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `my_marighettia`
--

-- --------------------------------------------------------

--
-- Struttura della tabella `ACCESSI`
--

CREATE TABLE `ACCESSI` (
  `stagione` int NOT NULL DEFAULT '0',
  `allenatore` varchar(50) NOT NULL DEFAULT '',
  `time` text
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Struttura della tabella `ALLENATORI`
--

CREATE TABLE `ALLENATORI` (
  `id` int NOT NULL DEFAULT '0',
  `descrizione` varchar(50) NOT NULL DEFAULT '',
  `id_squadra` int NOT NULL DEFAULT '0',
  `logo` varchar(20) NOT NULL DEFAULT '',
  `stagione` int NOT NULL DEFAULT '0'
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Struttura della tabella `CALENDARIO`
--

CREATE TABLE `CALENDARIO` (
  `giornata` int NOT NULL DEFAULT '0',
  `posizione` int NOT NULL DEFAULT '0',
  `squadra` int NOT NULL DEFAULT '0',
  `stagione` int NOT NULL DEFAULT '0'
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Struttura della tabella `CALENDARIO_CHAMP`
--

CREATE TABLE `CALENDARIO_CHAMP` (
  `stagione` int NOT NULL DEFAULT '0',
  `giornata` int NOT NULL DEFAULT '0',
  `giornata_camp` int NOT NULL DEFAULT '0',
  `posizione` int NOT NULL DEFAULT '0',
  `squadra` int NOT NULL DEFAULT '0',
  `girone` char(2) NOT NULL DEFAULT ''
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Struttura della tabella `CALENDARIO_CK`
--

CREATE TABLE `CALENDARIO_CK` (
  `STAGIONE` int NOT NULL DEFAULT '0',
  `GIORNATA` int NOT NULL DEFAULT '0',
  `CK_GIOCATA` char(1) NOT NULL DEFAULT ''
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Struttura della tabella `FLOP11`
--

CREATE TABLE `FLOP11` (
  `stagione` int NOT NULL DEFAULT '0',
  `id_giocatore` int NOT NULL DEFAULT '0',
  `giocatore` varchar(50) NOT NULL DEFAULT '',
  `media` decimal(6,2) NOT NULL DEFAULT '0.00',
  `ruolo` int NOT NULL DEFAULT '0',
  `giocate` int NOT NULL DEFAULT '0',
  `id_squadra` int NOT NULL DEFAULT '0',
  `squadra` varchar(50) NOT NULL DEFAULT '',
  `logo` varchar(20) NOT NULL DEFAULT ''
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Struttura della tabella `FORMAZIONI`
--

CREATE TABLE `FORMAZIONI` (
  `STAGIONE` int NOT NULL DEFAULT '0',
  `ID_SQUADRA` int NOT NULL DEFAULT '0',
  `ID_GIOCATORE` int NOT NULL DEFAULT '0',
  `GIORNATA` int NOT NULL DEFAULT '0',
  `MAGLIA` int NOT NULL DEFAULT '0'
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Struttura della tabella `FORMAZIONI_CK`
--

CREATE TABLE `FORMAZIONI_CK` (
  `STAGIONE` int NOT NULL DEFAULT '0',
  `GIORNATA` int NOT NULL DEFAULT '0',
  `ID_SQUADRA` int NOT NULL DEFAULT '0',
  `CK_INSERITA` char(1) NOT NULL DEFAULT '',
  `TIME` text NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Struttura della tabella `GENERALE`
--

CREATE TABLE `GENERALE` (
  `stagione` int NOT NULL DEFAULT '0',
  `id_squadra` int NOT NULL DEFAULT '0',
  `squadra` varchar(50) NOT NULL DEFAULT '',
  `logo` varchar(20) NOT NULL DEFAULT '',
  `punti` int NOT NULL DEFAULT '0',
  `partiteg` int NOT NULL DEFAULT '0',
  `vinte` int NOT NULL DEFAULT '0',
  `nulle` int NOT NULL DEFAULT '0',
  `perse` int NOT NULL DEFAULT '0',
  `golf` int NOT NULL DEFAULT '0',
  `gols` int NOT NULL DEFAULT '0',
  `maxp` decimal(6,2) NOT NULL DEFAULT '0.00',
  `minp` decimal(6,2) NOT NULL DEFAULT '0.00',
  `media` decimal(6,2) NOT NULL DEFAULT '0.00',
  `media_a` decimal(6,2) NOT NULL DEFAULT '0.00',
  `segno` char(1) NOT NULL DEFAULT '',
  `media_mod_dif` decimal(3,2) DEFAULT '0.00',
  `media_mod_cc` decimal(3,2) DEFAULT '0.00',
  `media_mod_att` decimal(3,2) DEFAULT '0.00'
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Struttura della tabella `GENERALE_CHAMP`
--

CREATE TABLE `GENERALE_CHAMP` (
  `stagione` int NOT NULL DEFAULT '0',
  `squadra` int NOT NULL DEFAULT '0',
  `nome` varchar(50) NOT NULL DEFAULT '',
  `logo` varchar(20) NOT NULL DEFAULT '',
  `punti` int NOT NULL DEFAULT '0',
  `golf` int NOT NULL DEFAULT '0',
  `gols` int NOT NULL DEFAULT '0',
  `girone` char(1) NOT NULL DEFAULT ''
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Struttura della tabella `GIOCATORI`
--

CREATE TABLE `GIOCATORI` (
  `id` int NOT NULL DEFAULT '0',
  `stagione` int NOT NULL DEFAULT '0',
  `descrizione` varchar(50) NOT NULL DEFAULT '',
  `squadra` int NOT NULL DEFAULT '0',
  `flag` int NOT NULL DEFAULT '0',
  `ruolo` int NOT NULL DEFAULT '0',
  `nazione` varchar(50) NOT NULL DEFAULT '',
  `crediti` int NOT NULL DEFAULT '0'
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Struttura della tabella `KULOVIC`
--

CREATE TABLE `KULOVIC` (
  `STAGIONE` int NOT NULL,
  `ID_SQUADRA` int NOT NULL,
  `SQUADRA` varchar(50) NOT NULL,
  `LOGO` varchar(20) NOT NULL,
  `PRIMO_RANGE` int NOT NULL,
  `SECONDO_RANGE` int NOT NULL,
  `TERZO_RANGE` int NOT NULL,
  `QUARTO_RANGE` int NOT NULL,
  `QUINTO_RANGE` int NOT NULL,
  `PRIMO_RANGE_A` int NOT NULL,
  `SECONDO_RANGE_A` int NOT NULL,
  `TERZO_RANGE_A` int NOT NULL,
  `QUARTO_RANGE_A` int NOT NULL,
  `QUINTO_RANGE_A` int NOT NULL,
  `PIUTRE` int NOT NULL,
  `PIUDUECINQUE` int NOT NULL,
  `MENOTRE` int NOT NULL,
  `MENODUECINQUE` int NOT NULL,
  `CULO` int NOT NULL,
  `SFIGA` int NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Struttura della tabella `MESSAGGI`
--

CREATE TABLE `MESSAGGI` (
  `id` int NOT NULL DEFAULT '0',
  `stagione` int NOT NULL DEFAULT '0',
  `mittente` int NOT NULL DEFAULT '0',
  `destinatario` int NOT NULL DEFAULT '0',
  `messaggio` varchar(100) NOT NULL DEFAULT ''
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Struttura della tabella `NAZIONI`
--

CREATE TABLE `NAZIONI` (
  `nazione` varchar(50) NOT NULL DEFAULT '',
  `bandiera` varchar(50) NOT NULL DEFAULT ''
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Struttura della tabella `NEW_ACCESSI`
--

CREATE TABLE `NEW_ACCESSI` (
  `stagione` int NOT NULL DEFAULT '0',
  `allenatore` varchar(50) NOT NULL DEFAULT '',
  `time` text
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Struttura della tabella `NEW_ALLENATORI`
--

CREATE TABLE `NEW_ALLENATORI` (
  `id` int NOT NULL DEFAULT '0',
  `descrizione` varchar(50) NOT NULL DEFAULT '',
  `id_squadra` int NOT NULL DEFAULT '0',
  `logo` varchar(20) NOT NULL DEFAULT '',
  `stagione` int NOT NULL DEFAULT '0',
  `email` varchar(120) NOT NULL DEFAULT ''
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Struttura della tabella `NEW_CALENDARIO`
--

CREATE TABLE `NEW_CALENDARIO` (
  `giornata` int NOT NULL DEFAULT '0',
  `posizione` int NOT NULL DEFAULT '0',
  `squadra` int NOT NULL DEFAULT '0',
  `stagione` int NOT NULL DEFAULT '0'
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Struttura della tabella `NEW_CALENDARIO_CHAMP`
--

CREATE TABLE `NEW_CALENDARIO_CHAMP` (
  `stagione` int NOT NULL DEFAULT '0',
  `giornata` int NOT NULL DEFAULT '0',
  `giornata_camp` int NOT NULL DEFAULT '0',
  `posizione` int NOT NULL DEFAULT '0',
  `squadra` int NOT NULL DEFAULT '0',
  `girone` char(2) NOT NULL DEFAULT ''
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Struttura della tabella `NEW_CALENDARIO_CK`
--

CREATE TABLE `NEW_CALENDARIO_CK` (
  `STAGIONE` int NOT NULL DEFAULT '0',
  `GIORNATA` int NOT NULL DEFAULT '0',
  `CK_GIOCATA` char(1) NOT NULL DEFAULT ''
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Struttura della tabella `NEW_CALENDARIO_SERIE_A`
--

CREATE TABLE `NEW_CALENDARIO_SERIE_A` (
  `stagione` int NOT NULL,
  `giornata` int NOT NULL,
  `n_partita` int NOT NULL COMMENT 'N° partita nella giornata',
  `data_partita` date NOT NULL,
  `ora_partita` time DEFAULT NULL COMMENT 'NULL se orario da definire (TBD)',
  `squadra_casa` varchar(50) NOT NULL,
  `squadra_ospite` varchar(50) NOT NULL,
  `prima_partita` char(1) NOT NULL DEFAULT 'N' COMMENT 'S = prima partita della giornata',
  `stato_orario` varchar(30) NOT NULL DEFAULT '' COMMENT 'Es. Ufficiale, Orario da definire'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `NEW_FLOP11`
--

CREATE TABLE `NEW_FLOP11` (
  `stagione` int NOT NULL DEFAULT '0',
  `id_giocatore` int NOT NULL DEFAULT '0',
  `giocatore` varchar(50) NOT NULL DEFAULT '',
  `media` decimal(6,2) NOT NULL DEFAULT '0.00',
  `ruolo` int NOT NULL DEFAULT '0',
  `giocate` int NOT NULL DEFAULT '0',
  `id_squadra` int NOT NULL DEFAULT '0',
  `squadra` varchar(50) NOT NULL DEFAULT '',
  `logo` varchar(20) NOT NULL DEFAULT ''
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Struttura della tabella `NEW_FORMAZIONI`
--

CREATE TABLE `NEW_FORMAZIONI` (
  `STAGIONE` int NOT NULL DEFAULT '0',
  `ID_SQUADRA` int NOT NULL DEFAULT '0',
  `ID_GIOCATORE` int NOT NULL DEFAULT '0',
  `GIORNATA` int NOT NULL DEFAULT '0',
  `MAGLIA` int NOT NULL DEFAULT '0'
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Struttura della tabella `NEW_FORMAZIONI_BCK2026`
--

CREATE TABLE `NEW_FORMAZIONI_BCK2026` (
  `STAGIONE` int NOT NULL DEFAULT '0',
  `ID_SQUADRA` int NOT NULL DEFAULT '0',
  `ID_GIOCATORE` int NOT NULL DEFAULT '0',
  `GIORNATA` int NOT NULL DEFAULT '0',
  `MAGLIA` int NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `NEW_FORMAZIONI_CHAMP`
--

CREATE TABLE `NEW_FORMAZIONI_CHAMP` (
  `STAGIONE` int NOT NULL DEFAULT '0',
  `ID_SQUADRA` int NOT NULL DEFAULT '0',
  `ID_GIOCATORE` int NOT NULL DEFAULT '0',
  `GIORNATA` int NOT NULL DEFAULT '0',
  `MAGLIA` int NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `NEW_FORMAZIONI_CK`
--

CREATE TABLE `NEW_FORMAZIONI_CK` (
  `STAGIONE` int NOT NULL DEFAULT '0',
  `GIORNATA` int NOT NULL DEFAULT '0',
  `ID_SQUADRA` int NOT NULL DEFAULT '0',
  `CK_INSERITA` char(1) NOT NULL DEFAULT '',
  `TIME` text NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Struttura della tabella `NEW_GENERALE`
--

CREATE TABLE `NEW_GENERALE` (
  `stagione` int NOT NULL DEFAULT '0',
  `id_squadra` int NOT NULL DEFAULT '0',
  `squadra` varchar(50) NOT NULL DEFAULT '',
  `logo` varchar(20) NOT NULL DEFAULT '',
  `punti` int NOT NULL DEFAULT '0',
  `partiteg` int NOT NULL DEFAULT '0',
  `vinte` int NOT NULL DEFAULT '0',
  `nulle` int NOT NULL DEFAULT '0',
  `perse` int NOT NULL DEFAULT '0',
  `golf` int NOT NULL DEFAULT '0',
  `gols` int NOT NULL DEFAULT '0',
  `maxp` decimal(6,2) NOT NULL DEFAULT '0.00',
  `minp` decimal(6,2) NOT NULL DEFAULT '0.00',
  `media` decimal(6,2) NOT NULL DEFAULT '0.00',
  `media_a` decimal(6,2) NOT NULL DEFAULT '0.00',
  `segno` char(1) NOT NULL DEFAULT '',
  `media_mod_dif` decimal(3,2) DEFAULT '0.00',
  `media_mod_cc` decimal(3,2) DEFAULT '0.00',
  `media_mod_att` decimal(3,2) DEFAULT '0.00'
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Struttura della tabella `NEW_GENERALE_CHAMP`
--

CREATE TABLE `NEW_GENERALE_CHAMP` (
  `stagione` int NOT NULL DEFAULT '0',
  `squadra` int NOT NULL DEFAULT '0',
  `nome` varchar(50) NOT NULL DEFAULT '',
  `logo` varchar(20) NOT NULL DEFAULT '',
  `punti` int NOT NULL DEFAULT '0',
  `golf` int NOT NULL DEFAULT '0',
  `gols` int NOT NULL DEFAULT '0',
  `girone` char(1) NOT NULL DEFAULT ''
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Struttura della tabella `NEW_GIOCATORI`
--

CREATE TABLE `NEW_GIOCATORI` (
  `id` int NOT NULL DEFAULT '0',
  `stagione` int NOT NULL DEFAULT '0',
  `descrizione` varchar(50) NOT NULL DEFAULT '',
  `squadra` int NOT NULL DEFAULT '0',
  `flag` int NOT NULL DEFAULT '0',
  `ruolo` int NOT NULL DEFAULT '0',
  `nazione` varchar(50) NOT NULL DEFAULT '',
  `crediti` int NOT NULL DEFAULT '0'
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Struttura della tabella `NEW_GIOCATORI_BASE_ASTA`
--

CREATE TABLE `NEW_GIOCATORI_BASE_ASTA` (
  `id_giocatore` int NOT NULL,
  `stagione` int NOT NULL,
  `nome` varchar(50) NOT NULL DEFAULT '',
  `ruolo` int NOT NULL DEFAULT '0',
  `squadra_serie_a` varchar(50) NOT NULL DEFAULT '',
  `nazione` varchar(50) NOT NULL DEFAULT '',
  `id_squadra_lega` int DEFAULT NULL,
  `crediti` int NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Struttura della tabella `NEW_GIOCATORI_SVINCOLATI`
--

CREATE TABLE `NEW_GIOCATORI_SVINCOLATI` (
  `id` int NOT NULL,
  `stagione` int NOT NULL,
  `descrizione` varchar(50) NOT NULL DEFAULT '',
  `ruolo` int NOT NULL DEFAULT '0',
  `nazione` varchar(50) NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Struttura della tabella `NEW_KULOVIC`
--

CREATE TABLE `NEW_KULOVIC` (
  `STAGIONE` int NOT NULL,
  `ID_SQUADRA` int NOT NULL,
  `SQUADRA` varchar(50) NOT NULL,
  `LOGO` varchar(20) NOT NULL,
  `PRIMO_RANGE` int NOT NULL,
  `SECONDO_RANGE` int NOT NULL,
  `TERZO_RANGE` int NOT NULL,
  `QUARTO_RANGE` int NOT NULL,
  `QUINTO_RANGE` int NOT NULL,
  `PRIMO_RANGE_A` int NOT NULL,
  `SECONDO_RANGE_A` int NOT NULL,
  `TERZO_RANGE_A` int NOT NULL,
  `QUARTO_RANGE_A` int NOT NULL,
  `QUINTO_RANGE_A` int NOT NULL,
  `PIUTRE` int NOT NULL,
  `PIUDUECINQUE` int NOT NULL,
  `MENOTRE` int NOT NULL,
  `MENODUECINQUE` int NOT NULL,
  `CULO` int NOT NULL,
  `SFIGA` int NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Struttura della tabella `NEW_NAZIONI`
--

CREATE TABLE `NEW_NAZIONI` (
  `nazione` varchar(50) NOT NULL DEFAULT '',
  `bandiera` varchar(50) NOT NULL DEFAULT ''
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Struttura della tabella `NEW_NOTE_CHAMPIONS`
--

CREATE TABLE `NEW_NOTE_CHAMPIONS` (
  `STAGIONE` int NOT NULL,
  `LABEL` varchar(250) NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Struttura della tabella `NEW_PARAMETRI_STAGIONE`
--

CREATE TABLE `NEW_PARAMETRI_STAGIONE` (
  `stagione` int NOT NULL,
  `codice` varchar(50) NOT NULL,
  `etichetta` varchar(150) NOT NULL,
  `valore` text NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `NEW_REGOLE_ALGORITMI`
--

CREATE TABLE `NEW_REGOLE_ALGORITMI` (
  `stagione` int NOT NULL,
  `tipo` enum('DIFESA','CENTROCAMPO','ATTACCO') NOT NULL,
  `algoritmo` varchar(40) NOT NULL COMMENT 'Codice del modello di calcolo implementato in CalcolatoreVoti.php',
  `parametri` json NOT NULL COMMENT 'Variabili parametrizzabili del modello (fasce, aggiustamenti, ecc.)',
  `descrizione` varchar(150) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `NEW_REGOLE_BONUS`
--

CREATE TABLE `NEW_REGOLE_BONUS` (
  `stagione` int NOT NULL,
  `codice` varchar(30) NOT NULL COMMENT 'Identificativo stabile della voce, usato dal motore di calcolo',
  `etichetta` varchar(80) NOT NULL COMMENT 'Descrizione mostrata in "Gestisci regole di calcolo"',
  `valore` decimal(4,2) NOT NULL COMMENT 'Punti applicati per occorrenza (positivo = bonus, negativo = malus)',
  `ordine` int NOT NULL DEFAULT '0' COMMENT 'Ordine di visualizzazione nella pagina admin',
  `attivo` tinyint(1) NOT NULL DEFAULT '1' COMMENT 'Se 0 la voce non viene applicata (ma resta a storico)'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `NEW_RISULTATI`
--

CREATE TABLE `NEW_RISULTATI` (
  `giornata` int NOT NULL DEFAULT '0',
  `stagione` int NOT NULL DEFAULT '0',
  `id_squadra` int NOT NULL DEFAULT '0',
  `id_squadra_a` int NOT NULL DEFAULT '0',
  `ftotale` decimal(4,1) NOT NULL DEFAULT '0.0',
  `ftotale_a` decimal(4,1) NOT NULL DEFAULT '0.0',
  `golf` int NOT NULL DEFAULT '0',
  `gols` int NOT NULL DEFAULT '0',
  `modificatore` int NOT NULL DEFAULT '0',
  `modificatore_a` int NOT NULL DEFAULT '0',
  `punti` int NOT NULL DEFAULT '0',
  `fattore_campo` int NOT NULL DEFAULT '0',
  `segno` char(1) NOT NULL DEFAULT '',
  `mod_att` decimal(2,1) NOT NULL DEFAULT '0.0',
  `num_cc` int NOT NULL DEFAULT '0',
  `tot_cc` decimal(3,1) NOT NULL DEFAULT '0.0',
  `mod_cc` decimal(3,1) NOT NULL DEFAULT '0.0'
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Struttura della tabella `NEW_RISULTATI_CHAMP`
--

CREATE TABLE `NEW_RISULTATI_CHAMP` (
  `stagione` int NOT NULL DEFAULT '0',
  `giornata` int NOT NULL DEFAULT '0',
  `squadra` int NOT NULL DEFAULT '0',
  `ftotale` decimal(4,1) NOT NULL DEFAULT '0.0',
  `golf` int NOT NULL DEFAULT '0',
  `gols` int NOT NULL DEFAULT '0',
  `punti` int NOT NULL DEFAULT '0',
  `segno` char(1) NOT NULL DEFAULT '',
  `girone` varchar(2) NOT NULL DEFAULT '',
  `id_squadra_a` int NOT NULL DEFAULT '0',
  `ftotale_a` decimal(6,2) NOT NULL DEFAULT '0.00',
  `modificatore` int NOT NULL DEFAULT '0',
  `modificatore_a` int NOT NULL DEFAULT '0',
  `fattore_campo` int NOT NULL DEFAULT '0',
  `mod_att` decimal(5,2) NOT NULL DEFAULT '0.00',
  `num_cc` int NOT NULL DEFAULT '0',
  `tot_cc` decimal(6,2) NOT NULL DEFAULT '0.00',
  `mod_cc` decimal(5,2) NOT NULL DEFAULT '0.00'
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Struttura della tabella `NEW_SIMULAZIONE_EDIT`
--

CREATE TABLE `NEW_SIMULAZIONE_EDIT` (
  `stagione` int NOT NULL,
  `giornata` int NOT NULL,
  `id_giocatore` int NOT NULL,
  `voto` decimal(3,1) NOT NULL,
  `gf` int NOT NULL DEFAULT '0',
  `gs` int NOT NULL DEFAULT '0',
  `rp` int NOT NULL DEFAULT '0',
  `rs` int NOT NULL DEFAULT '0',
  `rf` int NOT NULL DEFAULT '0',
  `au` int NOT NULL DEFAULT '0',
  `amm` int NOT NULL DEFAULT '0',
  `esp` int NOT NULL DEFAULT '0',
  `ass` int NOT NULL DEFAULT '0',
  `aggiornato_il` datetime(3) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `NEW_SIMULAZIONE_RISULTATI`
--

CREATE TABLE `NEW_SIMULAZIONE_RISULTATI` (
  `giornata` int NOT NULL,
  `stagione` int NOT NULL,
  `id_squadra` int NOT NULL,
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
  `simulato_da` varchar(50) DEFAULT NULL COMMENT 'Utente che ha eseguito la simulazione'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `NEW_SIMULAZIONE_RISULTATI_CHAMP`
--

CREATE TABLE `NEW_SIMULAZIONE_RISULTATI_CHAMP` (
  `giornata` int NOT NULL,
  `stagione` int NOT NULL,
  `id_squadra` int NOT NULL,
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
  `simulato_da` varchar(50) DEFAULT NULL COMMENT 'Utente che ha eseguito la simulazione'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `NEW_SIMULAZIONE_VOTI`
--

CREATE TABLE `NEW_SIMULAZIONE_VOTI` (
  `stagione` int NOT NULL,
  `giornata` int NOT NULL,
  `id_squadra` int NOT NULL,
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
  `manuale` tinyint NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `NEW_SIMULAZIONE_VOTI_CHAMP`
--

CREATE TABLE `NEW_SIMULAZIONE_VOTI_CHAMP` (
  `stagione` int NOT NULL,
  `giornata` int NOT NULL,
  `id_squadra` int NOT NULL,
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
  `manuale` tinyint NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `NEW_SISTEMA`
--

CREATE TABLE `NEW_SISTEMA` (
  `stagione` int NOT NULL DEFAULT '0',
  `label` varchar(50) NOT NULL DEFAULT '',
  `valore` varchar(10) NOT NULL DEFAULT ''
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Struttura della tabella `NEW_SQUADRE`
--

CREATE TABLE `NEW_SQUADRE` (
  `id` int NOT NULL DEFAULT '0',
  `nome` varchar(50) NOT NULL DEFAULT '',
  `logo` varchar(20) NOT NULL DEFAULT '',
  `stagione` int NOT NULL DEFAULT '0',
  `albo` char(2) NOT NULL DEFAULT ''
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Struttura della tabella `NEW_STATISTICHE`
--

CREATE TABLE `NEW_STATISTICHE` (
  `stagione` int NOT NULL DEFAULT '0',
  `id_squadra` int NOT NULL DEFAULT '0',
  `squadra` varchar(50) NOT NULL DEFAULT '',
  `logo` varchar(20) NOT NULL DEFAULT '',
  `id_giocatore` int NOT NULL DEFAULT '0',
  `giocatore` varchar(50) NOT NULL DEFAULT '',
  `giocate` int NOT NULL DEFAULT '0',
  `media` decimal(6,2) NOT NULL DEFAULT '0.00',
  `golf` int NOT NULL DEFAULT '0',
  `gols` int NOT NULL DEFAULT '0',
  `ammonizioni` int NOT NULL DEFAULT '0',
  `espulsioni` int NOT NULL DEFAULT '0',
  `rigores` int NOT NULL DEFAULT '0',
  `rigorep` int NOT NULL DEFAULT '0',
  `autogol` int NOT NULL DEFAULT '0',
  `ruolo` int NOT NULL DEFAULT '0',
  `assist` int NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Struttura della tabella `NEW_STAT_RANGE`
--

CREATE TABLE `NEW_STAT_RANGE` (
  `STAGIONE` int NOT NULL,
  `GIORNATA` int NOT NULL,
  `ID_SQUADRA` int NOT NULL,
  `SQUADRA` varchar(50) NOT NULL,
  `LOGO` varchar(20) NOT NULL,
  `ID_SQUADRA_A` int NOT NULL,
  `PRIMO_RANGE` int NOT NULL,
  `SECONDO_RANGE` int NOT NULL,
  `TERZO_RANGE` int NOT NULL,
  `QUARTO_RANGE` int NOT NULL,
  `QUINTO_RANGE` int NOT NULL,
  `PRIMO_RANGE_A` int NOT NULL,
  `SECONDO_RANGE_A` int NOT NULL,
  `TERZO_RANGE_A` int NOT NULL,
  `QUARTO_RANGE_A` int NOT NULL,
  `QUINTO_RANGE_A` int NOT NULL,
  `PIUTRE` int NOT NULL,
  `PIUDUECINQUE` int NOT NULL,
  `MENOTRE` int NOT NULL,
  `MENODUECINQUE` int NOT NULL,
  `CULO` int NOT NULL,
  `SFIGA` int NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Struttura della tabella `NEW_TOP11`
--

CREATE TABLE `NEW_TOP11` (
  `stagione` int NOT NULL DEFAULT '0',
  `id_giocatore` int NOT NULL DEFAULT '0',
  `giocatore` varchar(50) NOT NULL DEFAULT '',
  `media` decimal(6,2) NOT NULL DEFAULT '0.00',
  `ruolo` int NOT NULL DEFAULT '0',
  `giocate` int NOT NULL DEFAULT '0',
  `id_squadra` int NOT NULL DEFAULT '0',
  `squadra` varchar(50) NOT NULL DEFAULT '',
  `logo` varchar(20) NOT NULL DEFAULT ''
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Struttura della tabella `NEW_UTENZE`
--

CREATE TABLE `NEW_UTENZE` (
  `id` int NOT NULL DEFAULT '0',
  `utenza` varchar(50) NOT NULL DEFAULT '',
  `PASSWORD` varchar(8) NOT NULL DEFAULT '',
  `descrizione` varchar(50) NOT NULL DEFAULT '',
  `stagione` int NOT NULL DEFAULT '0',
  `abilitazione` char(1) NOT NULL DEFAULT '',
  `amministratore` char(1) NOT NULL DEFAULT 'N',
  `abilita_ai` char(1) NOT NULL DEFAULT 'N'
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Struttura della tabella `NEW_VOTI`
--

CREATE TABLE `NEW_VOTI` (
  `id_squadra` int NOT NULL DEFAULT '0',
  `id_giocatore` int NOT NULL DEFAULT '0',
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
  `assist` int NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Struttura della tabella `NEW_VOTI_CHAMP`
--

CREATE TABLE `NEW_VOTI_CHAMP` (
  `id_squadra` int NOT NULL DEFAULT '0',
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

--
-- Struttura della tabella `NEW_VOTI_SERIE_A`
--

CREATE TABLE `NEW_VOTI_SERIE_A` (
  `stagione` int NOT NULL,
  `giornata` int NOT NULL,
  `id_giocatore` int NOT NULL COMMENT 'Colonna "Cod." del file',
  `squadra` varchar(50) NOT NULL COMMENT 'Squadra di Serie A (intestazione del blocco)',
  `ruolo` varchar(3) NOT NULL COMMENT 'P, D, C, A oppure ALL (allenatore)',
  `nome` varchar(60) NOT NULL,
  `voto` decimal(3,1) DEFAULT NULL COMMENT 'NULL se il giocatore è entrato ma senza voto (SV = Y)',
  `sv` char(1) NOT NULL DEFAULT 'N' COMMENT 'Y = entrato ma senza voto (nel file voto con asterisco, es. 6*), N altrimenti',
  `gf` int NOT NULL DEFAULT '0',
  `gs` int NOT NULL DEFAULT '0',
  `rp` int NOT NULL DEFAULT '0',
  `rs` int NOT NULL DEFAULT '0',
  `rf` int NOT NULL DEFAULT '0',
  `au` int NOT NULL DEFAULT '0',
  `amm` int NOT NULL DEFAULT '0',
  `esp` int NOT NULL DEFAULT '0',
  `ass` int NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `NOTE_CHAMPIONS`
--

CREATE TABLE `NOTE_CHAMPIONS` (
  `STAGIONE` int NOT NULL,
  `LABEL` varchar(250) NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Struttura della tabella `PERMUTAZIONI`
--

CREATE TABLE `PERMUTAZIONI` (
  `GIORNATA` int NOT NULL,
  `STAGIONE` varchar(4) NOT NULL,
  `ID_SQUADRA` int NOT NULL,
  `TOT_PUNTI` decimal(6,2) NOT NULL,
  `TOT_GOLF` int NOT NULL,
  `TOT_GOLS` int NOT NULL,
  `TOT_V` int NOT NULL,
  `TOT_N` int NOT NULL,
  `TOT_P` int NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Struttura della tabella `RISULTATI`
--

CREATE TABLE `RISULTATI` (
  `giornata` int NOT NULL DEFAULT '0',
  `stagione` int NOT NULL DEFAULT '0',
  `id_squadra` int NOT NULL DEFAULT '0',
  `id_squadra_a` int NOT NULL DEFAULT '0',
  `ftotale` decimal(4,1) NOT NULL DEFAULT '0.0',
  `ftotale_a` decimal(4,1) NOT NULL DEFAULT '0.0',
  `golf` int NOT NULL DEFAULT '0',
  `gols` int NOT NULL DEFAULT '0',
  `modificatore` int NOT NULL DEFAULT '0',
  `modificatore_a` int NOT NULL DEFAULT '0',
  `punti` int NOT NULL DEFAULT '0',
  `fattore_campo` int NOT NULL DEFAULT '0',
  `segno` char(1) NOT NULL DEFAULT '',
  `mod_att` decimal(2,1) NOT NULL DEFAULT '0.0',
  `num_cc` int NOT NULL DEFAULT '0',
  `tot_cc` decimal(3,1) NOT NULL DEFAULT '0.0',
  `mod_cc` decimal(3,1) NOT NULL DEFAULT '0.0'
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Struttura della tabella `RISULTATI_CHAMP`
--

CREATE TABLE `RISULTATI_CHAMP` (
  `stagione` int NOT NULL DEFAULT '0',
  `giornata` int NOT NULL DEFAULT '0',
  `squadra` int NOT NULL DEFAULT '0',
  `ftotale` decimal(4,1) NOT NULL DEFAULT '0.0',
  `golf` int NOT NULL DEFAULT '0',
  `gols` int NOT NULL DEFAULT '0',
  `punti` int NOT NULL DEFAULT '0',
  `segno` char(1) NOT NULL DEFAULT '',
  `girone` varchar(2) NOT NULL DEFAULT ''
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Struttura della tabella `risultati_old`
--

CREATE TABLE `risultati_old` (
  `giornata` int NOT NULL DEFAULT '0',
  `stagione` int NOT NULL DEFAULT '0',
  `id_squadra` int NOT NULL DEFAULT '0',
  `id_squadra_a` int NOT NULL DEFAULT '0',
  `ftotale` decimal(4,1) NOT NULL DEFAULT '0.0',
  `ftotale_a` decimal(4,1) NOT NULL DEFAULT '0.0',
  `golf` int NOT NULL DEFAULT '0',
  `gols` int NOT NULL DEFAULT '0',
  `modificatore` int NOT NULL DEFAULT '0',
  `modificatore_a` int NOT NULL DEFAULT '0',
  `punti` int NOT NULL DEFAULT '0',
  `fattore_campo` int NOT NULL DEFAULT '0',
  `segno` char(1) NOT NULL DEFAULT ''
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Struttura della tabella `SCHEDINA`
--

CREATE TABLE `SCHEDINA` (
  `STAGIONE` int NOT NULL,
  `GIORNATA` int NOT NULL,
  `ID` int NOT NULL,
  `SQUADRA_1` int NOT NULL,
  `SQUADRA_2` int NOT NULL,
  `PRONOSTICO` char(1) NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Struttura della tabella `SCHEDINA_CK`
--

CREATE TABLE `SCHEDINA_CK` (
  `STAGIONE` int NOT NULL,
  `GIORNATA` int NOT NULL,
  `ID_SQUADRA` int NOT NULL,
  `CK_INSERITA` char(1) NOT NULL,
  `TIME` text NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Struttura della tabella `SCHEDINA_CLASSIFICA`
--

CREATE TABLE `SCHEDINA_CLASSIFICA` (
  `STAGIONE` int NOT NULL,
  `ID` int NOT NULL,
  `PUNTI` int NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Struttura della tabella `SCHEDINA_RISULTATI`
--

CREATE TABLE `SCHEDINA_RISULTATI` (
  `STAGIONE` int NOT NULL,
  `GIORNATA` int NOT NULL,
  `ID` int NOT NULL,
  `PUNTI` int NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Struttura della tabella `SISTEMA`
--

CREATE TABLE `SISTEMA` (
  `stagione` int NOT NULL DEFAULT '0',
  `label` varchar(50) NOT NULL DEFAULT '',
  `valore` varchar(10) NOT NULL DEFAULT ''
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Struttura della tabella `SQUADRE`
--

CREATE TABLE `SQUADRE` (
  `id` int NOT NULL DEFAULT '0',
  `nome` varchar(50) NOT NULL DEFAULT '',
  `logo` varchar(20) NOT NULL DEFAULT '',
  `stagione` int NOT NULL DEFAULT '0',
  `albo` char(2) NOT NULL DEFAULT ''
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Struttura della tabella `STATISTICHE`
--

CREATE TABLE `STATISTICHE` (
  `stagione` int NOT NULL DEFAULT '0',
  `id_squadra` int NOT NULL DEFAULT '0',
  `squadra` varchar(50) NOT NULL DEFAULT '',
  `logo` varchar(20) NOT NULL DEFAULT '',
  `id_giocatore` int NOT NULL DEFAULT '0',
  `giocatore` varchar(50) NOT NULL DEFAULT '',
  `giocate` int NOT NULL DEFAULT '0',
  `media` decimal(6,2) NOT NULL DEFAULT '0.00',
  `golf` int NOT NULL DEFAULT '0',
  `gols` int NOT NULL DEFAULT '0',
  `ammonizioni` int NOT NULL DEFAULT '0',
  `espulsioni` int NOT NULL DEFAULT '0',
  `rigores` int NOT NULL DEFAULT '0',
  `rigorep` int NOT NULL DEFAULT '0',
  `autogol` int NOT NULL DEFAULT '0',
  `ruolo` int NOT NULL DEFAULT '0',
  `assist` int NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Struttura della tabella `STAT_RANGE`
--

CREATE TABLE `STAT_RANGE` (
  `STAGIONE` int NOT NULL,
  `GIORNATA` int NOT NULL,
  `ID_SQUADRA` int NOT NULL,
  `SQUADRA` varchar(50) NOT NULL,
  `LOGO` varchar(20) NOT NULL,
  `ID_SQUADRA_A` int NOT NULL,
  `PRIMO_RANGE` int NOT NULL,
  `SECONDO_RANGE` int NOT NULL,
  `TERZO_RANGE` int NOT NULL,
  `QUARTO_RANGE` int NOT NULL,
  `QUINTO_RANGE` int NOT NULL,
  `PRIMO_RANGE_A` int NOT NULL,
  `SECONDO_RANGE_A` int NOT NULL,
  `TERZO_RANGE_A` int NOT NULL,
  `QUARTO_RANGE_A` int NOT NULL,
  `QUINTO_RANGE_A` int NOT NULL,
  `PIUTRE` int NOT NULL,
  `PIUDUECINQUE` int NOT NULL,
  `MENOTRE` int NOT NULL,
  `MENODUECINQUE` int NOT NULL,
  `CULO` int NOT NULL,
  `SFIGA` int NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Struttura della tabella `TOP11`
--

CREATE TABLE `TOP11` (
  `stagione` int NOT NULL DEFAULT '0',
  `id_giocatore` int NOT NULL DEFAULT '0',
  `giocatore` varchar(50) NOT NULL DEFAULT '',
  `media` decimal(6,2) NOT NULL DEFAULT '0.00',
  `ruolo` int NOT NULL DEFAULT '0',
  `giocate` int NOT NULL DEFAULT '0',
  `id_squadra` int NOT NULL DEFAULT '0',
  `squadra` varchar(50) NOT NULL DEFAULT '',
  `logo` varchar(20) NOT NULL DEFAULT ''
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Struttura della tabella `UTENZE`
--

CREATE TABLE `UTENZE` (
  `id` int NOT NULL DEFAULT '0',
  `utenza` varchar(50) NOT NULL DEFAULT '',
  `PASSWORD` varchar(8) NOT NULL DEFAULT '',
  `descrizione` varchar(50) NOT NULL DEFAULT '',
  `stagione` int NOT NULL DEFAULT '0',
  `abilitazione` char(1) NOT NULL DEFAULT ''
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Struttura della tabella `VOTI`
--

CREATE TABLE `VOTI` (
  `id_squadra` int NOT NULL DEFAULT '0',
  `id_giocatore` int NOT NULL DEFAULT '0',
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
  `assist` int NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

--
-- Indici per le tabelle scaricate
--

--
-- Indici per le tabelle `ALLENATORI`
--
ALTER TABLE `ALLENATORI`
  ADD PRIMARY KEY (`id`,`stagione`);

--
-- Indici per le tabelle `CALENDARIO_CHAMP`
--
ALTER TABLE `CALENDARIO_CHAMP`
  ADD PRIMARY KEY (`stagione`,`giornata`,`giornata_camp`,`posizione`,`girone`);

--
-- Indici per le tabelle `CALENDARIO_CK`
--
ALTER TABLE `CALENDARIO_CK`
  ADD PRIMARY KEY (`STAGIONE`,`GIORNATA`);

--
-- Indici per le tabelle `FLOP11`
--
ALTER TABLE `FLOP11`
  ADD KEY `stagione` (`stagione`),
  ADD KEY `id_squadra` (`id_squadra`),
  ADD KEY `id_giocatore` (`id_giocatore`),
  ADD KEY `id_giocatore_2` (`id_giocatore`);

--
-- Indici per le tabelle `FORMAZIONI`
--
ALTER TABLE `FORMAZIONI`
  ADD PRIMARY KEY (`STAGIONE`,`ID_SQUADRA`,`ID_GIOCATORE`,`GIORNATA`);

--
-- Indici per le tabelle `GENERALE`
--
ALTER TABLE `GENERALE`
  ADD PRIMARY KEY (`id_squadra`,`stagione`);

--
-- Indici per le tabelle `GENERALE_CHAMP`
--
ALTER TABLE `GENERALE_CHAMP`
  ADD PRIMARY KEY (`stagione`,`squadra`,`girone`);

--
-- Indici per le tabelle `GIOCATORI`
--
ALTER TABLE `GIOCATORI`
  ADD PRIMARY KEY (`id`,`stagione`);

--
-- Indici per le tabelle `KULOVIC`
--
ALTER TABLE `KULOVIC`
  ADD PRIMARY KEY (`STAGIONE`,`ID_SQUADRA`);

--
-- Indici per le tabelle `NAZIONI`
--
ALTER TABLE `NAZIONI`
  ADD PRIMARY KEY (`nazione`);

--
-- Indici per le tabelle `NEW_ALLENATORI`
--
ALTER TABLE `NEW_ALLENATORI`
  ADD PRIMARY KEY (`id`,`stagione`);

--
-- Indici per le tabelle `NEW_CALENDARIO_CHAMP`
--
ALTER TABLE `NEW_CALENDARIO_CHAMP`
  ADD PRIMARY KEY (`stagione`,`giornata`,`giornata_camp`,`posizione`,`girone`);

--
-- Indici per le tabelle `NEW_CALENDARIO_CK`
--
ALTER TABLE `NEW_CALENDARIO_CK`
  ADD PRIMARY KEY (`STAGIONE`,`GIORNATA`);

--
-- Indici per le tabelle `NEW_CALENDARIO_SERIE_A`
--
ALTER TABLE `NEW_CALENDARIO_SERIE_A`
  ADD PRIMARY KEY (`stagione`,`giornata`,`n_partita`),
  ADD KEY `idx_data` (`stagione`,`data_partita`);

--
-- Indici per le tabelle `NEW_FLOP11`
--
ALTER TABLE `NEW_FLOP11`
  ADD KEY `stagione` (`stagione`),
  ADD KEY `id_squadra` (`id_squadra`),
  ADD KEY `id_giocatore` (`id_giocatore`),
  ADD KEY `id_giocatore_2` (`id_giocatore`);

--
-- Indici per le tabelle `NEW_FORMAZIONI`
--
ALTER TABLE `NEW_FORMAZIONI`
  ADD PRIMARY KEY (`STAGIONE`,`ID_SQUADRA`,`ID_GIOCATORE`,`GIORNATA`);

--
-- Indici per le tabelle `NEW_FORMAZIONI_CHAMP`
--
ALTER TABLE `NEW_FORMAZIONI_CHAMP`
  ADD PRIMARY KEY (`STAGIONE`,`ID_SQUADRA`,`GIORNATA`,`ID_GIOCATORE`);

--
-- Indici per le tabelle `NEW_GENERALE`
--
ALTER TABLE `NEW_GENERALE`
  ADD PRIMARY KEY (`id_squadra`,`stagione`);

--
-- Indici per le tabelle `NEW_GENERALE_CHAMP`
--
ALTER TABLE `NEW_GENERALE_CHAMP`
  ADD PRIMARY KEY (`stagione`,`squadra`,`girone`);

--
-- Indici per le tabelle `NEW_GIOCATORI`
--
ALTER TABLE `NEW_GIOCATORI`
  ADD PRIMARY KEY (`id`,`stagione`,`squadra`) USING BTREE;

--
-- Indici per le tabelle `NEW_GIOCATORI_BASE_ASTA`
--
ALTER TABLE `NEW_GIOCATORI_BASE_ASTA`
  ADD PRIMARY KEY (`id_giocatore`,`stagione`);

--
-- Indici per le tabelle `NEW_GIOCATORI_SVINCOLATI`
--
ALTER TABLE `NEW_GIOCATORI_SVINCOLATI`
  ADD PRIMARY KEY (`id`,`stagione`);

--
-- Indici per le tabelle `NEW_KULOVIC`
--
ALTER TABLE `NEW_KULOVIC`
  ADD PRIMARY KEY (`STAGIONE`,`ID_SQUADRA`);

--
-- Indici per le tabelle `NEW_NAZIONI`
--
ALTER TABLE `NEW_NAZIONI`
  ADD PRIMARY KEY (`nazione`);

--
-- Indici per le tabelle `NEW_PARAMETRI_STAGIONE`
--
ALTER TABLE `NEW_PARAMETRI_STAGIONE`
  ADD PRIMARY KEY (`stagione`,`codice`);

--
-- Indici per le tabelle `NEW_REGOLE_ALGORITMI`
--
ALTER TABLE `NEW_REGOLE_ALGORITMI`
  ADD PRIMARY KEY (`stagione`,`tipo`);

--
-- Indici per le tabelle `NEW_REGOLE_BONUS`
--
ALTER TABLE `NEW_REGOLE_BONUS`
  ADD PRIMARY KEY (`stagione`,`codice`);

--
-- Indici per le tabelle `NEW_RISULTATI`
--
ALTER TABLE `NEW_RISULTATI`
  ADD PRIMARY KEY (`giornata`,`stagione`,`id_squadra`,`id_squadra_a`),
  ADD KEY `gior_stag_id` (`giornata`,`stagione`,`id_squadra`);

--
-- Indici per le tabelle `NEW_RISULTATI_CHAMP`
--
ALTER TABLE `NEW_RISULTATI_CHAMP`
  ADD PRIMARY KEY (`stagione`,`giornata`,`squadra`);

--
-- Indici per le tabelle `NEW_SIMULAZIONE_EDIT`
--
ALTER TABLE `NEW_SIMULAZIONE_EDIT`
  ADD PRIMARY KEY (`stagione`,`giornata`,`id_giocatore`);

--
-- Indici per le tabelle `NEW_SIMULAZIONE_RISULTATI`
--
ALTER TABLE `NEW_SIMULAZIONE_RISULTATI`
  ADD PRIMARY KEY (`stagione`,`giornata`,`id_squadra`,`id_squadra_a`);

--
-- Indici per le tabelle `NEW_SIMULAZIONE_RISULTATI_CHAMP`
--
ALTER TABLE `NEW_SIMULAZIONE_RISULTATI_CHAMP`
  ADD PRIMARY KEY (`stagione`,`giornata`,`id_squadra`,`id_squadra_a`);

--
-- Indici per le tabelle `NEW_SIMULAZIONE_VOTI`
--
ALTER TABLE `NEW_SIMULAZIONE_VOTI`
  ADD PRIMARY KEY (`stagione`,`giornata`,`id_squadra`,`id_giocatore`);

--
-- Indici per le tabelle `NEW_SIMULAZIONE_VOTI_CHAMP`
--
ALTER TABLE `NEW_SIMULAZIONE_VOTI_CHAMP`
  ADD PRIMARY KEY (`stagione`,`giornata`,`id_squadra`,`id_giocatore`);

--
-- Indici per le tabelle `NEW_SQUADRE`
--
ALTER TABLE `NEW_SQUADRE`
  ADD PRIMARY KEY (`id`,`stagione`);

--
-- Indici per le tabelle `NEW_STAT_RANGE`
--
ALTER TABLE `NEW_STAT_RANGE`
  ADD PRIMARY KEY (`STAGIONE`,`GIORNATA`,`ID_SQUADRA`);

--
-- Indici per le tabelle `NEW_UTENZE`
--
ALTER TABLE `NEW_UTENZE`
  ADD PRIMARY KEY (`utenza`,`PASSWORD`,`stagione`);

--
-- Indici per le tabelle `NEW_VOTI`
--
ALTER TABLE `NEW_VOTI`
  ADD PRIMARY KEY (`id_giocatore`,`stagione`,`giornata`,`id_squadra`) USING BTREE;

--
-- Indici per le tabelle `NEW_VOTI_CHAMP`
--
ALTER TABLE `NEW_VOTI_CHAMP`
  ADD PRIMARY KEY (`stagione`,`giornata`,`id_squadra`,`id_giocatore`);

--
-- Indici per le tabelle `NEW_VOTI_SERIE_A`
--
ALTER TABLE `NEW_VOTI_SERIE_A`
  ADD PRIMARY KEY (`stagione`,`giornata`,`id_giocatore`);

--
-- Indici per le tabelle `RISULTATI`
--
ALTER TABLE `RISULTATI`
  ADD PRIMARY KEY (`giornata`,`stagione`,`id_squadra`,`id_squadra_a`),
  ADD KEY `gior_stag_id` (`giornata`,`stagione`,`id_squadra`);

--
-- Indici per le tabelle `RISULTATI_CHAMP`
--
ALTER TABLE `RISULTATI_CHAMP`
  ADD PRIMARY KEY (`stagione`,`giornata`,`squadra`);

--
-- Indici per le tabelle `risultati_old`
--
ALTER TABLE `risultati_old`
  ADD PRIMARY KEY (`giornata`,`stagione`,`id_squadra`,`id_squadra_a`);

--
-- Indici per le tabelle `SQUADRE`
--
ALTER TABLE `SQUADRE`
  ADD PRIMARY KEY (`id`,`stagione`);

--
-- Indici per le tabelle `STAT_RANGE`
--
ALTER TABLE `STAT_RANGE`
  ADD PRIMARY KEY (`STAGIONE`,`GIORNATA`,`ID_SQUADRA`);

--
-- Indici per le tabelle `UTENZE`
--
ALTER TABLE `UTENZE`
  ADD PRIMARY KEY (`utenza`,`PASSWORD`,`stagione`);

--
-- Indici per le tabelle `VOTI`
--
ALTER TABLE `VOTI`
  ADD PRIMARY KEY (`id_giocatore`,`stagione`,`giornata`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
