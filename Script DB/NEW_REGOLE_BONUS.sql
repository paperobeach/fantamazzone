-- ============================================================
-- Tabella NEW_REGOLE_BONUS
-- Elenco (fisso) delle voci di bonus/malus applicate al voto del
-- singolo giocatore, con il valore in vigore per ciascuna stagione.
-- L'elenco delle voci può comunque cambiare da una stagione all'altra
-- (aggiunta/disattivazione), non solo i valori: per questo non è
-- hardcoded nel codice ma gestito da questa tabella.
-- ============================================================
CREATE TABLE IF NOT EXISTS `NEW_REGOLE_BONUS` (
  `stagione`  int           NOT NULL,
  `codice`    varchar(30)   NOT NULL COMMENT 'Identificativo stabile della voce, usato dal motore di calcolo',
  `etichetta` varchar(80)   NOT NULL COMMENT 'Descrizione mostrata in "Gestisci regole di calcolo"',
  `valore`    decimal(4,2)  NOT NULL COMMENT 'Punti applicati per occorrenza (positivo = bonus, negativo = malus)',
  `ordine`    int           NOT NULL DEFAULT 0 COMMENT 'Ordine di visualizzazione nella pagina admin',
  `attivo`    tinyint(1)    NOT NULL DEFAULT 1 COMMENT 'Se 0 la voce non viene applicata (ma resta a storico)',
  PRIMARY KEY (`stagione`, `codice`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Valori di default, usati da backend/admin/regole_calcolo.php per
-- pre-popolare automaticamente una stagione priva di configurazione
-- (vedi funzione semina_default nel medesimo file): qui a puro scopo
-- di documentazione/riferimento, non è necessario eseguirli a mano.
-- INSERT INTO NEW_REGOLE_BONUS (stagione, codice, etichetta, valore, ordine) VALUES
-- (2026, 'GOL_FATTO',          'Gol fatto',           3.00, 1),
-- (2026, 'GOL_SUBITO',        'Gol subito',         -1.00, 2),
-- (2026, 'RIGORE_REALIZZATO', 'Rigore realizzato',   2.00, 3),
-- (2026, 'RIGORE_SBAGLIATO',  'Rigore sbagliato',   -3.00, 4),
-- (2026, 'RIGORE_PARATO',     'Rigore parato',       3.00, 5),
-- (2026, 'ASSIST',            'Assist',              0.50, 6),
-- (2026, 'AMMONIZIONE',       'Ammonizione',        -0.50, 7),
-- (2026, 'ESPULSIONE',        'Espulsione',         -1.00, 8),
-- (2026, 'AUTOGOL',           'Autogol',            -3.00, 9);
