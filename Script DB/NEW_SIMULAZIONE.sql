-- ============================================================
-- Tabelle di appoggio per la pagina "LIVE Giornata in corso"
-- (simulazione del calcolo con i voti al momento disponibili).
--
-- Non toccano mai NEW_VOTI / NEW_RISULTATI: la simulazione vive
-- solo qui e viene ricostruita ad ogni "Simula giornata".
-- InnoDB per consentire la transazione di ricostruzione.
-- ============================================================

-- Voti simulati: stessa struttura di NEW_VOTI + flag
--   provvisorio = 1 -> voto 6 provvisorio (squadra di Serie A non ancora scesa in campo)
--   manuale     = 1 -> voto/dati inseriti a mano (NEW_SIMULAZIONE_EDIT)
CREATE TABLE IF NOT EXISTS `NEW_SIMULAZIONE_VOTI` (
  `stagione`     int           NOT NULL,
  `giornata`     int           NOT NULL,
  `id_squadra`   int           NOT NULL,
  `id_giocatore` int           NOT NULL COMMENT 'Negativo = riserva d''ufficio fittizia (come NEW_VOTI)',
  `voto`         decimal(3,1)  NOT NULL DEFAULT '0.0',
  `reti`         int           NOT NULL DEFAULT '0',
  `ammonizioni`  int           NOT NULL DEFAULT '0',
  `espulsioni`   int           NOT NULL DEFAULT '0',
  `autogol`      int           NOT NULL DEFAULT '0',
  `retis`        int           NOT NULL DEFAULT '0',
  `rigores`      int           NOT NULL DEFAULT '0' COMMENT 'Rp + Rf (come NEW_VOTI)',
  `rigorep`      int           NOT NULL DEFAULT '0' COMMENT 'Rs (come NEW_VOTI)',
  `rufficio`     int           NOT NULL DEFAULT '0',
  `giocata`      int           NOT NULL DEFAULT '0',
  `totale`       decimal(6,2)  NOT NULL DEFAULT '0.00',
  `assist`       int           NOT NULL DEFAULT '0',
  `provvisorio`  tinyint       NOT NULL DEFAULT '0',
  `manuale`      tinyint       NOT NULL DEFAULT '0',
  PRIMARY KEY (`stagione`,`giornata`,`id_squadra`,`id_giocatore`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Risultati simulati: stessa struttura di NEW_RISULTATI (due righe per
-- partita, una per prospettiva) + data/ora della simulazione.
CREATE TABLE IF NOT EXISTS `NEW_SIMULAZIONE_RISULTATI` (
  `giornata`       int           NOT NULL,
  `stagione`       int           NOT NULL,
  `id_squadra`     int           NOT NULL,
  `id_squadra_a`   int           NOT NULL,
  `ftotale`        decimal(6,2)  NOT NULL DEFAULT '0.00',
  `ftotale_a`      decimal(6,2)  NOT NULL DEFAULT '0.00',
  `golf`           int           NOT NULL DEFAULT '0',
  `gols`           int           NOT NULL DEFAULT '0',
  `modificatore`   int           NOT NULL DEFAULT '0',
  `modificatore_a` int           NOT NULL DEFAULT '0',
  `punti`          int           NOT NULL DEFAULT '0',
  `fattore_campo`  int           NOT NULL DEFAULT '0',
  `segno`          char(1)       NOT NULL DEFAULT '',
  `mod_att`        decimal(5,2)  NOT NULL DEFAULT '0.00',
  `num_cc`         int           NOT NULL DEFAULT '0',
  `tot_cc`         decimal(6,2)  NOT NULL DEFAULT '0.00',
  `mod_cc`         decimal(5,2)  NOT NULL DEFAULT '0.00',
  `calcolato_il`   datetime(3)   NOT NULL,
  `simulato_da`    varchar(50)   DEFAULT NULL COMMENT 'Utente che ha eseguito la simulazione',
  PRIMARY KEY (`stagione`,`giornata`,`id_squadra`,`id_squadra_a`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Editing manuale dei voti (e relativi dati) dei giocatori la cui
-- squadra di Serie A non è ancora scesa in campo. Prevale sul 6
-- provvisorio; viene ignorato appena per quel giocatore esiste il
-- voto reale in NEW_VOTI_SERIE_A. Chiavi/campi come NEW_VOTI_SERIE_A.
CREATE TABLE IF NOT EXISTS `NEW_SIMULAZIONE_EDIT` (
  `stagione`     int           NOT NULL,
  `giornata`     int           NOT NULL,
  `id_giocatore` int           NOT NULL,
  `voto`         decimal(3,1)  NOT NULL,
  `gf`           int           NOT NULL DEFAULT '0',
  `gs`           int           NOT NULL DEFAULT '0',
  `rp`           int           NOT NULL DEFAULT '0',
  `rs`           int           NOT NULL DEFAULT '0',
  `rf`           int           NOT NULL DEFAULT '0',
  `au`           int           NOT NULL DEFAULT '0',
  `amm`          int           NOT NULL DEFAULT '0',
  `esp`          int           NOT NULL DEFAULT '0',
  `ass`          int           NOT NULL DEFAULT '0',
  `aggiornato_il` datetime(3)  NOT NULL,
  PRIMARY KEY (`stagione`,`giornata`,`id_giocatore`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
