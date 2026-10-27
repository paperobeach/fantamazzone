-- ============================================================
-- Migrazione: statistiche dei giocatori da API (API-Football /
-- TheSportsDB) nella SIMULAZIONE della giornata in corso.
--
-- Aggiunge a NEW_SIMULAZIONE_VOTI e NEW_SIMULAZIONE_VOTI_CHAMP due
-- flag che indicano l'origine del dato di ogni giocatore:
--   api      = 1  dato ricavato dalle statistiche scaricate dal fornitore
--                 (tabelle NEW_API_STATISTICHE / NEW_API_MAPPING_GIOCATORI)
--   parziale = 1  la partita di Serie A del giocatore è ancora in corso:
--                 dato provvisorio, aggiornato a ogni sincronizzazione
--
-- Priorità dei dati nella simulazione: voto reale (xlsx) > editing manuale
-- > dato API > 6 provvisorio. Le colonne sono facoltative per il codice
-- (se mancano la simulazione funziona come prima, senza i flag).
--
-- Da eseguire UNA VOLTA sul database esistente (es. da phpMyAdmin)
-- PRIMA di pubblicare il nuovo codice. Richiede le tabelle NEW_API_*
-- (Script DB/NEW_API_FOOTBALL.sql).
-- ============================================================
ALTER TABLE `NEW_SIMULAZIONE_VOTI`
  ADD COLUMN `api` tinyint NOT NULL DEFAULT '0' COMMENT '1 = dato delle statistiche API',
  ADD COLUMN `parziale` tinyint NOT NULL DEFAULT '0' COMMENT '1 = partita di Serie A ancora in corso (dato parziale)';

ALTER TABLE `NEW_SIMULAZIONE_VOTI_CHAMP`
  ADD COLUMN `api` tinyint NOT NULL DEFAULT '0' COMMENT '1 = dato delle statistiche API',
  ADD COLUMN `parziale` tinyint NOT NULL DEFAULT '0' COMMENT '1 = partita di Serie A ancora in corso (dato parziale)';
