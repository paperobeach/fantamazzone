-- ============================================================
-- Tabella NEW_PARAMETRI_STAGIONE
-- Sostituisce NEW_REGOLE_SOSTITUZIONI: contenitore generico per i
-- parametri di stagione usati dal calcolo voti che NON sono bonus/malus
-- del giocatore (NEW_REGOLE_BONUS) né parametri di un algoritmo di
-- modificatore (NEW_REGOLE_ALGORITMI). `valore` è testo libero: un
-- numero (letto come stringa) per i parametri scalari, oppure JSON per
-- un parametro strutturato come le fasce gol-da-punteggio.
--
-- Codici noti, usati da backend/admin/calcolo_giornata.php e dalla
-- pagina "Gestisci regole di calcolo" (vedi backend/admin/regole_calcolo.php
-- per i valori di default e il significato di ciascuno):
--   SOSTITUZIONI_MAX_MOVIMENTO      numero massimo di sostituzioni per i
--                                   ruoli diversi dal portiere
--   SOSTITUZIONI_MAX_PORTIERE       numero massimo di sostituzioni per il
--                                   portiere (non intacca il limite sopra)
--   ULTIMA_GIORNATA_FATTORE_CASA    ultima giornata in cui si applica il
--                                   bonus "Fattore casa" (NEW_REGOLE_BONUS);
--                                   vuoto = si applica sempre (nessun
--                                   campo neutro)
--   NUMERO_GIORNATE                 numero di giornate della stagione
--                                   (riservato per un'eventuale futura
--                                   integrazione con la creazione calendario)
--   FASCE_GOL_PUNTEGGIO             JSON con le fasce [{da,a,gol}, ...]
--                                   usate per derivare i gol fatti di
--                                   una squadra (NEW_RISULTATI.golf) dal
--                                   suo punteggio totale
-- ============================================================
CREATE TABLE IF NOT EXISTS `NEW_PARAMETRI_STAGIONE` (
  `stagione`  int          NOT NULL,
  `codice`    varchar(50)  NOT NULL,
  `etichetta` varchar(150) NOT NULL,
  `valore`    text         NOT NULL,
  PRIMARY KEY (`stagione`, `codice`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
