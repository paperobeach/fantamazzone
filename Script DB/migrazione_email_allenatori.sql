-- ============================================================
-- Migrazione: aggiunge l'indirizzo email dell'allenatore, usato come
-- destinatario della mail di conferma formazione.
--
-- Da eseguire una tantum sul database esistente (es. da phpMyAdmin).
-- L'email è modificabile in qualsiasi momento da
-- "Inizializzazione stagione" (colonna "Email allenatore").
-- ============================================================

ALTER TABLE `NEW_ALLENATORI`
  ADD COLUMN `email` VARCHAR(120) NOT NULL DEFAULT '' AFTER `stagione`;

-- Se in precedenza era stata eseguita la vecchia migrazione che aveva
-- aggiunto la colonna a NEW_SQUADRE, rimuoverla:
--
-- ALTER TABLE `NEW_SQUADRE` DROP COLUMN `email`;
