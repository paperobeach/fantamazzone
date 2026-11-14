-- ============================================================
-- Gazzetta AI - Immagini dei giocatori (ritratti da nome file).
--
-- Da eseguire UNA SOLA VOLTA (es. da phpMyAdmin) PRIMA di pubblicare il
-- nuovo codice, dopo Script DB/NEW_AI_GAZZETTA_EDIZIONE.sql.
--
-- Aggiunge a NEW_IMMAGINI_GAZZETTA il collegamento al giocatore reale
-- (NEW_GIOCATORI_BASE_ASTA) così l'AI può proporre il ritratto giusto
-- quando un calciatore è protagonista della giornata (gol, assist,
-- espulsione, rigore, miglior/peggior voto).
--
--   tipo               'gazzetta' (foto della redazione, come prima)
--                      | 'giocatore' (ritratto di un calciatore)
--   id_giocatore       NEW_GIOCATORI_BASE_ASTA.id_giocatore
--   stagione_giocatore NEW_GIOCATORI_BASE_ASTA.stagione (2026 = 2026/27)
--   nome_giocatore     nome letto dal nome file (es. "Alex Meret")
--   squadra_giocatore  squadra di Serie A letta dal nome file
--   stato_abbinamento  abbinata | da_verificare | non_trovata
--
-- Le immagini già presenti restano 'gazzetta' e non cambiano.
-- ============================================================

ALTER TABLE `NEW_IMMAGINI_GAZZETTA`
  ADD COLUMN `tipo` varchar(12) NOT NULL DEFAULT 'gazzetta' COMMENT 'gazzetta | giocatore',
  ADD COLUMN `id_giocatore` int DEFAULT NULL COMMENT 'NEW_GIOCATORI_BASE_ASTA.id_giocatore (solo tipo giocatore)',
  ADD COLUMN `stagione_giocatore` int DEFAULT NULL COMMENT 'NEW_GIOCATORI_BASE_ASTA.stagione (2026 = 2026/27)',
  ADD COLUMN `nome_giocatore` varchar(80) NOT NULL DEFAULT '' COMMENT 'nome letto dal nome file',
  ADD COLUMN `squadra_giocatore` varchar(60) NOT NULL DEFAULT '' COMMENT 'squadra di Serie A letta dal nome file',
  ADD COLUMN `stato_abbinamento` varchar(14) NOT NULL DEFAULT '' COMMENT 'abbinata | da_verificare | non_trovata',
  ADD KEY `idx_giocatore` (`stagione_giocatore`, `id_giocatore`),
  ADD KEY `idx_tipo_stato` (`tipo`, `stato_abbinamento`);
