-- ============================================================
-- Migrazione: supplementari e calci di rigore nella SIMULAZIONE
-- della Champions (pagina LIVE Giornata in corso).
--
-- Aggiunge a NEW_SIMULAZIONE_RISULTATI_CHAMP le stesse colonne di
-- esito di NEW_RISULTATI_CHAMP (vedi migrazione_supplementari_rigori.sql).
--
-- Da eseguire UNA VOLTA sul database esistente (es. da phpMyAdmin)
-- PRIMA di pubblicare il nuovo codice.
-- ============================================================
ALTER TABLE `NEW_SIMULAZIONE_RISULTATI_CHAMP`
  ADD COLUMN `supp_giocati` tinyint NOT NULL DEFAULT '0' COMMENT '1 = la partita ha avuto i supplementari',
  ADD COLUMN `supp_ftotale` decimal(6,2) NOT NULL DEFAULT '0.00' COMMENT 'Punti dei 3 panchinari dei supplementari (squadra)',
  ADD COLUMN `supp_golf` int NOT NULL DEFAULT '0' COMMENT 'Gol segnati ai supplementari',
  ADD COLUMN `supp_gols` int NOT NULL DEFAULT '0' COMMENT 'Gol subiti ai supplementari',
  ADD COLUMN `rig_giocati` tinyint NOT NULL DEFAULT '0' COMMENT '1 = la partita e'' stata decisa ai calci di rigore',
  ADD COLUMN `rig_golf` int NOT NULL DEFAULT '0' COMMENT 'Rigori realizzati',
  ADD COLUMN `rig_gols` int NOT NULL DEFAULT '0' COMMENT 'Rigori realizzati dall''avversaria',
  ADD COLUMN `passa` tinyint NOT NULL DEFAULT '0' COMMENT '1 = la squadra vince la sfida (solo turni con supplementari/rigori)',
  ADD COLUMN `dett_extra` text COMMENT 'JSON con il dettaglio di supplementari e rigori';
