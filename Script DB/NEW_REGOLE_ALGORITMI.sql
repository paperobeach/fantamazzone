-- ============================================================
-- Tabella NEW_REGOLE_ALGORITMI
-- Configurazione, per stagione e per tipo di modificatore di squadra
-- (DIFESA, CENTROCAMPO, ATTACCO), del modello di calcolo da usare e
-- dei relativi parametri.
--
-- `algoritmo`  identifica QUALE implementazione usare (vedi
--              backend/lib/CalcolatoreVoti.php): cambia solo se cambia
--              il modello di calcolo (richiede sviluppo, non è una
--              semplice modifica di configurazione).
-- `parametri`  è JSON e contiene le variabili del modello (fasce di
--              punteggio, aggiustamenti per modulo, ecc.): cambia
--              liberamente da una stagione all'altra dalla pagina
--              admin "Gestisci regole di calcolo", senza toccare codice.
-- ============================================================
CREATE TABLE IF NOT EXISTS `NEW_REGOLE_ALGORITMI` (
  `stagione`    int           NOT NULL,
  `tipo`        enum('DIFESA','CENTROCAMPO','ATTACCO') NOT NULL,
  `algoritmo`   varchar(40)   NOT NULL COMMENT 'Codice del modello di calcolo implementato in CalcolatoreVoti.php',
  `parametri`   json          NOT NULL COMMENT 'Variabili parametrizzabili del modello (fasce, aggiustamenti, ecc.)',
  `descrizione` varchar(150)  DEFAULT NULL,
  PRIMARY KEY (`stagione`, `tipo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Esempio dei parametri di default (vedi anche
-- backend/admin/regole_calcolo.php → semina_default_algoritmi):
--
-- DIFESA, algoritmo "fasce_media_v1":
-- {
--   "fasce": [
--     { "da": 6.5,  "a": null, "bonus": 3  },
--     { "da": 6.0,  "a": 6.49, "bonus": 1  },
--     { "da": 5.5,  "a": 5.99, "bonus": 0  },
--     { "da": 5.0,  "a": 5.49, "bonus": -1 },
--     { "da": null, "a": 4.99, "bonus": -3 }
--   ],
--   "aggiustamento_modulo": {
--     "3": -1,   "// numero di difensori schierati dalla squadra che SUBISCE il modificatore"
--     "4": 0,
--     "5": 1
--   }
-- }
--
-- CENTROCAMPO, algoritmo "fasce_differenza_v1":
-- {
--   "fasce": [
--     { "da": 0,   "a": 0.99, "bonus": 0 },
--     { "da": 1.0, "a": 1.99, "bonus": 1 },
--     { "da": 2.0, "a": null, "bonus": 2 }
--   ]
-- }
--   (la fascia si individua sul VALORE ASSOLUTO della differenza fra le
--   somme dei voti dei centrocampisti delle due squadre; il bonus va
--   alla squadra con la somma maggiore, il malus di pari entità
--   all'altra)
--
-- ATTACCO, algoritmo "bonus_voto_netto_v1":
-- {
--   "fasce": [
--     { "da": 6.0, "a": 6.49, "bonus": 0.5 },
--     { "da": 6.5, "a": 6.99, "bonus": 1   },
--     { "da": 7.0, "a": null, "bonus": 1.5 }
--   ]
-- }
--   (si applica al voto dell'attaccante al netto di altri bonus/malus,
--   solo se non ha già un bonus da gol fatto)
