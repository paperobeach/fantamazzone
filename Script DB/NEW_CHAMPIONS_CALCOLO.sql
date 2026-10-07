-- ============================================================
-- Calcolo del turno di CHAMPIONS e pagina LIVE
--
-- La Champions si gioca in contemporanea al campionato (stessa giornata,
-- vedi NEW_CALENDARIO_CHAMP). Il calcolo della giornata
-- (backend/admin/calcolo_giornata.php) calcola anche le partite Champions
-- usando la formazione Champions distinta, se ammessa e salvata
-- (NEW_FORMAZIONI_CHAMP), altrimenti quella di campionato.
--
-- I voti Champions stanno in tabelle SEPARATE da quelle del campionato:
-- statistiche giocatori, Top/Flop 11 e classifica generale (che leggono
-- NEW_VOTI / NEW_RISULTATI) non sono quindi alterate dalla Champions.
--
-- Eseguire UNA volta sul database prima di rilasciare il backend.
-- ============================================================

-- Voti dei giocatori nelle partite di Champions: stessa struttura di NEW_VOTI
CREATE TABLE IF NOT EXISTS `NEW_VOTI_CHAMP` (
  `id_squadra`   int          NOT NULL DEFAULT '0',
  `id_giocatore` int          NOT NULL DEFAULT '0' COMMENT 'Negativo = riserva d''ufficio fittizia (come NEW_VOTI)',
  `stagione`     int          NOT NULL DEFAULT '0',
  `voto`         decimal(3,1) NOT NULL DEFAULT '0.0',
  `giornata`     int          NOT NULL DEFAULT '0',
  `reti`         int          DEFAULT '0',
  `ammonizioni`  int          DEFAULT '0',
  `espulsioni`   int          DEFAULT '0',
  `autogol`      int          DEFAULT '0',
  `retis`        int          DEFAULT '0',
  `rigores`      int          DEFAULT '0',
  `rigorep`      int          DEFAULT '0',
  `rufficio`     int          DEFAULT '0',
  `giocata`      int          NOT NULL DEFAULT '0',
  `totale`       decimal(6,2) NOT NULL DEFAULT '0.00',
  `assist`       int          NOT NULL DEFAULT '0',
  PRIMARY KEY (`stagione`,`giornata`,`id_squadra`,`id_giocatore`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- NEW_RISULTATI_CHAMP (una riga per squadra: stagione, giornata, squadra,
-- ftotale, golf, gols, punti, segno, girone) viene arricchita con i campi
-- di NEW_RISULTATI necessari al dettaglio della partita (avversaria,
-- modificatori, centrocampo, fattore campo). I campi esistenti e le query
-- di champions.php non cambiano; per le righe storiche i nuovi campi
-- valgono 0.
-- NB: ALTER non ripetibile (MySQL non ha ADD COLUMN IF NOT EXISTS).
ALTER TABLE `NEW_RISULTATI_CHAMP`
  ADD COLUMN `id_squadra_a`   int          NOT NULL DEFAULT '0',
  ADD COLUMN `ftotale_a`      decimal(6,2) NOT NULL DEFAULT '0.00',
  ADD COLUMN `modificatore`   int          NOT NULL DEFAULT '0',
  ADD COLUMN `modificatore_a` int          NOT NULL DEFAULT '0',
  ADD COLUMN `fattore_campo`  int          NOT NULL DEFAULT '0',
  ADD COLUMN `mod_att`        decimal(5,2) NOT NULL DEFAULT '0.00',
  ADD COLUMN `num_cc`         int          NOT NULL DEFAULT '0',
  ADD COLUMN `tot_cc`         decimal(6,2) NOT NULL DEFAULT '0.00',
  ADD COLUMN `mod_cc`         decimal(5,2) NOT NULL DEFAULT '0.00';

-- Simulazione LIVE delle partite di Champions: stessa struttura di
-- NEW_SIMULAZIONE_VOTI / NEW_SIMULAZIONE_RISULTATI (vedi NEW_SIMULAZIONE.sql).
-- Tabelle distinte perché una squadra gioca nella stessa giornata sia il
-- campionato sia la Champions. L'editing manuale dei voti
-- (NEW_SIMULAZIONE_EDIT) resta unico: riguarda il giocatore, non la competizione.
CREATE TABLE IF NOT EXISTS `NEW_SIMULAZIONE_VOTI_CHAMP` (
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
  `rigores`      int           NOT NULL DEFAULT '0',
  `rigorep`      int           NOT NULL DEFAULT '0',
  `rufficio`     int           NOT NULL DEFAULT '0',
  `giocata`      int           NOT NULL DEFAULT '0',
  `totale`       decimal(6,2)  NOT NULL DEFAULT '0.00',
  `assist`       int           NOT NULL DEFAULT '0',
  `provvisorio`  tinyint       NOT NULL DEFAULT '0',
  `manuale`      tinyint       NOT NULL DEFAULT '0',
  PRIMARY KEY (`stagione`,`giornata`,`id_squadra`,`id_giocatore`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `NEW_SIMULAZIONE_RISULTATI_CHAMP` (
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
