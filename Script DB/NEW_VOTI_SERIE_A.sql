-- ============================================================
-- Tabella NEW_VOTI_SERIE_A
-- Voti ufficiali della Serie A (file "Voti Fantacalcio", sheet "Italia"),
-- caricati per stagione e giornata. Input della fase 2 (calcolo voti
-- fantacalcio). InnoDB per consentire transazioni nel caricamento.
-- ============================================================
CREATE TABLE IF NOT EXISTS `NEW_VOTI_SERIE_A` (
  `stagione`     int           NOT NULL,
  `giornata`     int           NOT NULL,
  `id_giocatore` int           NOT NULL COMMENT 'Colonna "Cod." del file',
  `squadra`      varchar(50)   NOT NULL COMMENT 'Squadra di Serie A (intestazione del blocco)',
  `ruolo`        varchar(3)    NOT NULL COMMENT 'P, D, C, A oppure ALL (allenatore)',
  `nome`         varchar(60)   NOT NULL,
  `voto`         decimal(3,1)  NOT NULL,
  `politico`     tinyint       NOT NULL DEFAULT 0 COMMENT '1 se il voto nel file era marcato con asterisco (es. 6*)',
  `gf`           int           NOT NULL DEFAULT 0,
  `gs`           int           NOT NULL DEFAULT 0,
  `rp`           int           NOT NULL DEFAULT 0,
  `rs`           int           NOT NULL DEFAULT 0,
  `rf`           int           NOT NULL DEFAULT 0,
  `au`           int           NOT NULL DEFAULT 0,
  `amm`          int           NOT NULL DEFAULT 0,
  `esp`          int           NOT NULL DEFAULT 0,
  `ass`          int           NOT NULL DEFAULT 0,
  PRIMARY KEY (`stagione`,`giornata`,`id_giocatore`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
