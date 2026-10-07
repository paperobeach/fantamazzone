-- ============================================================
-- Tabella NEW_FORMAZIONI_CHAMP
-- Formazione schierata in CHAMPIONS, quando differisce da quella di
-- campionato (NEW_FORMAZIONI). Stessa struttura e stessa codifica di
-- NEW_FORMAZIONI (MAGLIA: 1..11 = titolare, 12+ = panchina).
--
-- Viene usata SOLO se, per la stagione, il parametro
-- FORMAZIONE_CHAMPIONS_DIVERSA (NEW_PARAMETRI_STAGIONE) vale '1' e la
-- squadra gioca un turno di Champions nella giornata (vedi
-- backend/lib/FormazioniChampions.php).
-- Se per squadra+giornata non esiste una riga qui, per la Champions
-- vale la formazione di campionato (NEW_FORMAZIONI).
--
-- Eseguire una volta sul database prima di rilasciare il backend.
-- ============================================================
CREATE TABLE IF NOT EXISTS `NEW_FORMAZIONI_CHAMP` (
  `STAGIONE`     int NOT NULL DEFAULT '0',
  `ID_SQUADRA`   int NOT NULL DEFAULT '0',
  `ID_GIOCATORE` int NOT NULL DEFAULT '0',
  `GIORNATA`     int NOT NULL DEFAULT '0',
  `MAGLIA`       int NOT NULL DEFAULT '0',
  PRIMARY KEY (`STAGIONE`, `ID_SQUADRA`, `GIORNATA`, `ID_GIOCATORE`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Il parametro FORMAZIONE_CHAMPIONS_DIVERSA non richiede alcun INSERT:
-- se assente vale "no". Si attiva dalla pagina "Gestisci regole di
-- calcolo" → Parametri stagione → Regole di gioco.
