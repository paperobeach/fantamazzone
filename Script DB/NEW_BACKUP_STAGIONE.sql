-- ============================================================
-- Tabella NEW_BACKUP_STAGIONE
-- Registro dei backup eseguiti da "Reset stagione"
-- (backend/admin/reset_stagione.php). Una riga per ogni reset:
-- i dati salvati stanno nelle tabelle BK_<nome tabella>
-- (es. BK_NEW_SQUADRE, BK_NEW_GIOCATORI, BK_NEW_FORMAZIONI), che hanno le
-- stesse colonne della tabella originale più:
--   bk_id    -> identificativo del backup (NEW_BACKUP_STAGIONE.bk_id)
--   bk_data  -> data/ora del backup
-- Le tabelle BK_* vengono create automaticamente dal reset al primo
-- utilizzo (nessuna chiave: più backup della stessa stagione possono
-- convivere). Anche questa tabella viene creata in automatico se
-- assente: lo script è qui per documentazione o per crearla a mano.
--
-- stato:
--   BACKUP_IN_CORSO   backup avviato e non terminato (da ignorare/pulire)
--   RESET_PARZIALE    backup completo, cancellazione interrotta: rilanciare il reset
--   RESET_COMPLETATO  backup completo e stagione cancellata
--
-- Ripristino manuale di una tabella (esempio, backup n. 3 della stagione 2026):
--   INSERT INTO NEW_SQUADRE (id, nome, logo, stagione, albo)
--   SELECT id, nome, logo, stagione, albo FROM BK_NEW_SQUADRE WHERE bk_id = 3;
-- ============================================================
CREATE TABLE IF NOT EXISTS `NEW_BACKUP_STAGIONE` (
  `bk_id`         int          NOT NULL AUTO_INCREMENT,
  `stagione`      int          NOT NULL,
  `creato_il`     datetime     NOT NULL,
  `creato_da`     varchar(50)  NOT NULL DEFAULT '',
  `stato`         varchar(20)  NOT NULL,
  `completato_il` datetime     NULL,
  `dettaglio`     text         NULL COMMENT 'JSON: righe salvate e cancellate per tabella',
  PRIMARY KEY (`bk_id`),
  KEY `idx_stagione` (`stagione`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
