-- ============================================================
-- Migrazione: "Aggiorna lista giocatori" (menu Admin -> Avvio stagione)
--
-- NEW_GIOCATORI_BASE_ASTA e' il listone della stagione. La nuova colonna
-- `in_lista` dice se il giocatore e' ancora nel listone ufficiale:
--   'Y' = in lista (default, anche per tutte le righe gia' presenti)
--   'N' = non piu' in lista ("giocatore ceduto" dalla Serie A):
--         resta in NEW_GIOCATORI / NEW_GIOCATORI_SVINCOLATI (le statistiche
--         restano consultabili) ma
--           - se e' svincolato non e' acquistabile da nessuna squadra;
--           - se e' in una rosa e' mostrato come "giocatore ceduto" e non
--             e' utilizzabile in formazione (resta fisso in tribuna).
-- Il flag sta in un'unica tabella, quindi segue il giocatore anche se un
-- mercato lo sposta tra rosa e svincolati.
-- ============================================================

ALTER TABLE `NEW_GIOCATORI_BASE_ASTA`
  ADD COLUMN `in_lista` char(1) NOT NULL DEFAULT 'Y'
  COMMENT 'Y = nel listone; N = non piu'' in lista (giocatore ceduto)';

ALTER TABLE `NEW_GIOCATORI_BASE_ASTA`
  ADD KEY `idx_base_asta_stagione_id` (`stagione`,`id_giocatore`);
