-- ============================================================
-- Migrazione: raccordo per id dei giocatori tra le stagioni
--
-- NEW_GIOCATORI.id vale solo dentro la propria stagione. Le due tabelle
-- assegnano ad ogni (stagione, id_giocatore) una persona stabile
-- (id_anagrafica), cosi' la scheda giocatore ricostruisce lo storico
-- senza dipendere dal nome. NON usa NEW_API_MAPPING_GIOCATORI.
--
-- Dopo l'esecuzione: menu Admin -> Avvio stagione -> "Raccordo giocatori"
-- -> "Ricostruisci raccordo" (carico iniziale di tutte le stagioni) e
-- verifica dei collegamenti incerti. Per le stagioni future l'allineamento
-- parte da solo al termine di "Inserimento rose".
-- ============================================================

CREATE TABLE IF NOT EXISTS `NEW_GIOCATORI_ANAGRAFICA` (
  `id_anagrafica` int NOT NULL AUTO_INCREMENT COMMENT 'Persona reale, stabile tra le stagioni',
  `nome` varchar(50) NOT NULL DEFAULT '' COMMENT 'Nome canonico',
  `creato_il` datetime NOT NULL,
  PRIMARY KEY (`id_anagrafica`),
  KEY `idx_nome` (`nome`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

CREATE TABLE IF NOT EXISTS `NEW_GIOCATORI_RACCORDO` (
  `stagione` int NOT NULL,
  `id_giocatore` int NOT NULL COMMENT 'NEW_GIOCATORI.id (valido solo nella stagione)',
  `id_anagrafica` int NOT NULL COMMENT 'NEW_GIOCATORI_ANAGRAFICA.id_anagrafica',
  `metodo` varchar(10) NOT NULL DEFAULT 'AUTO' COMMENT 'AUTO | MANUALE',
  `da_verificare` char(1) NOT NULL DEFAULT 'N' COMMENT 'Y = collegamento incerto o omonimia',
  `nota` varchar(100) DEFAULT NULL,
  `aggiornato_il` datetime NOT NULL,
  PRIMARY KEY (`stagione`,`id_giocatore`),
  KEY `idx_anagrafica` (`id_anagrafica`,`stagione`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;
