-- ============================================================
-- Migrazione: aggiunge a NEW_UTENZE l'abilitazione specifica per le
-- funzioni di intelligenza artificiale (sezione di menu "AI": test
-- connessione Anthropic e, nelle fasi successive, generazione articoli).
--
-- Valori: 'Y' = utenza abilitata, 'N' = non abilitata (default).
-- L'abilitazione è INDIPENDENTE da "amministratore": un amministratore
-- non la ottiene in automatico.
--
-- Da eseguire una tantum sul database esistente (es. da phpMyAdmin)
-- PRIMA di pubblicare il nuovo codice. Il flag è poi modificabile da
-- "Inizializzazione stagione" (colonna "AI").
-- ============================================================

ALTER TABLE `NEW_UTENZE`
  ADD COLUMN `abilita_ai` CHAR(1) NOT NULL DEFAULT 'N' AFTER `amministratore`;

-- Esempio: abilitare una utenza per la stagione corrente
-- UPDATE `NEW_UTENZE` SET `abilita_ai` = 'Y' WHERE `stagione` = 2026 AND `utenza` = 'nome_utenza';
