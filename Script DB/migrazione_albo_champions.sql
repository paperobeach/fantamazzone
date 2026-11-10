-- ============================================================
-- Aggiornamento completo dell'albo d'oro CHAMPIONS (NEW_ALBO_ORO)
--
-- Sostituisce TUTTE le righe competizione = 'CHAMP' con i vincitori e i
-- finalisti corretti (fonte: vittorie_champions.txt, anni 2002 .. 2025).
-- Le righe del CAMPIONATO non vengono toccate.
--
-- Parametro @SHIFT: stagione = anno del file + @SHIFT.
--   0 (default) -> la stagione e' l'anno di inizio, come nell'albo (2025 = 2025/26)
--   1           -> usare solo se gli anni del file indicano l'anno di inizio - 1
--
-- La squadra del file viene tradotta in NEW_UTENZE.utenza:
--   - Longobarda: 'longobarda' fino al 2013, 'longo' dal 2014 (cambio di login)
--   - Psg: cercata per nome in NEW_SQUADRE/NEW_UTENZE nella stagione della finale
--   - le altre hanno una utenza fissa (vedi CASE)
-- Eventuali squadre non risolte NON vengono inserite e sono elencate
-- dalla query di controllo in fondo.
--
-- Righe attese: 46 (24 vincitori + 22 finalisti; senza finalista: 2002 e 2024).
-- Tutto e' in transazione: se qualcosa non torna, ROLLBACK.
-- ============================================================
SET @SHIFT = 0;

START TRANSACTION;

DROP TEMPORARY TABLE IF EXISTS tmp_champ_corretto;
CREATE TEMPORARY TABLE tmp_champ_corretto (
  anno        int         NOT NULL,
  posizione   tinyint     NOT NULL COMMENT '1 = vincitore, 2 = finalista',
  squadra     varchar(30) NOT NULL,
  PRIMARY KEY (anno, posizione)
);

INSERT INTO tmp_champ_corretto (anno, posizione, squadra) VALUES
  (2002, 1, 'Chiavo'),
  (2003, 1, 'Patetico'),
  (2003, 2, 'Longobarda'),
  (2004, 1, 'Lucky'),
  (2004, 2, 'Leffe'),
  (2005, 1, 'Chiavo'),
  (2005, 2, 'Longobarda'),
  (2006, 1, 'Chiavo'),
  (2006, 2, 'Degio Team'),
  (2007, 1, 'Leffe'),
  (2007, 2, 'Lucky'),
  (2008, 1, 'Lucky'),
  (2008, 2, 'Degio Team'),
  (2009, 1, 'Drunks'),
  (2009, 2, 'Longobarda'),
  (2010, 1, 'Longobarda'),
  (2010, 2, 'Degio Team'),
  (2011, 1, 'AcDenti'),
  (2011, 2, 'Longobarda'),
  (2012, 1, 'AcDenti'),
  (2012, 2, 'Patetico'),
  (2013, 1, 'Patetico'),
  (2013, 2, 'AcDenti'),
  (2014, 1, 'Patetico'),
  (2014, 2, 'Chiavo'),
  (2015, 1, 'Papero'),
  (2015, 2, 'AcDenti'),
  (2016, 1, 'Chiavo'),
  (2016, 2, 'AcDenti'),
  (2017, 1, 'Chiavo'),
  (2017, 2, 'Papero'),
  (2018, 1, 'Lucky'),
  (2018, 2, 'Longobarda'),
  (2019, 1, 'Chiavo'),
  (2019, 2, 'AcDenti'),
  (2020, 1, 'Longobarda'),
  (2020, 2, 'Papero'),
  (2021, 1, 'Leffe'),
  (2021, 2, 'Psg'),
  (2022, 1, 'Longobarda'),
  (2022, 2, 'Leffe'),
  (2023, 1, 'Leffe'),
  (2023, 2, 'AcDenti'),
  (2024, 1, 'Longobarda'),
  (2025, 1, 'Papero'),
  (2025, 2, 'Longobarda');

-- Svuota l'albo Champions attuale
DELETE FROM `NEW_ALBO_ORO` WHERE `competizione` = 'CHAMP';

-- Reinserisce l'albo corretto
INSERT INTO `NEW_ALBO_ORO` (`stagione`,`competizione`,`posizione`,`utenza`)
SELECT x.stagione, 'CHAMP', x.posizione, x.utenza
FROM (
  SELECT t.anno + @SHIFT AS stagione,
         t.posizione,
         CASE t.squadra
           WHEN 'AcDenti'    THEN 'acdenti'
           WHEN 'Chiavo'     THEN 'chiavo'
           WHEN 'Degio Team' THEN 'degio'
           WHEN 'Drunks'     THEN 'drunks'
           WHEN 'Leffe'      THEN 'leffe'
           WHEN 'Longobarda' THEN IF(t.anno + @SHIFT >= 2014, 'longo', 'longobarda')
           WHEN 'Lucky'      THEN 'lucky'
           WHEN 'Papero'     THEN 'papero'
           WHEN 'Patetico'   THEN 'patetico'
           WHEN 'Psg'        THEN (SELECT MIN(u.utenza)
                                   FROM NEW_SQUADRE s
                                   JOIN NEW_UTENZE  u ON u.stagione = s.stagione AND u.id = s.id
                                   WHERE s.stagione = t.anno + @SHIFT
                                     AND LOWER(s.nome) LIKE '%psg%')
         END AS utenza
  FROM tmp_champ_corretto t
) x
WHERE x.utenza IS NOT NULL;

-- Controllo 1: squadre non risolte (devono essere 0 righe)
SELECT t.anno + @SHIFT AS stagione, t.posizione, t.squadra AS squadra_non_risolta
FROM tmp_champ_corretto t
LEFT JOIN `NEW_ALBO_ORO` a
       ON a.competizione = 'CHAMP'
      AND a.stagione  = t.anno + @SHIFT
      AND a.posizione = t.posizione
WHERE a.utenza IS NULL;

-- Controllo 2: totale righe CHAMP (attese 46)
SELECT COUNT(*) AS righe_champ FROM `NEW_ALBO_ORO` WHERE `competizione` = 'CHAMP';

-- Se i controlli sono corretti:
COMMIT;
-- altrimenti: ROLLBACK;

DROP TEMPORARY TABLE IF EXISTS tmp_champ_corretto;
