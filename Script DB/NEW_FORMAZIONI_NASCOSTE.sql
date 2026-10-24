-- ============================================================
-- Tabella NEW_FORMAZIONI_NASCOSTE
-- Squadre che, per una giornata, hanno scelto di NASCONDERE la propria
-- formazione agli altri utenti fino all'inizio della prima partita di
-- Serie A della giornata (vedi backend/lib/FormazioniBlocco.php).
-- Una riga = formazione nascosta; nessuna riga = visibile.
-- Ha effetto solo se il parametro di stagione
-- NASCONDI_FORMAZIONE_CONSENTITO (NEW_PARAMETRI_STAGIONE) vale '1'.
--
-- Parametri di stagione correlati (nessun INSERT necessario: se assenti
-- valgono "no"; si impostano da "Gestisci regole di calcolo" -> Regole di gioco):
--   BLOCCO_FORMAZIONE_ATTIVO        '1' = formazione modificabile fino a un
--                                   minuto prima della prima partita di Serie A
--   NASCONDI_FORMAZIONE_CONSENTITO  '1' = l'utente puo' nascondere la formazione
--
-- Eseguire una volta sul database prima di rilasciare il backend.
-- ============================================================
CREATE TABLE IF NOT EXISTS `NEW_FORMAZIONI_NASCOSTE` (
  `STAGIONE`   int NOT NULL DEFAULT '0',
  `GIORNATA`   int NOT NULL DEFAULT '0',
  `ID_SQUADRA` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`STAGIONE`, `GIORNATA`, `ID_SQUADRA`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
