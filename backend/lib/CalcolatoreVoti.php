<?php
// ============================================================
// backend/lib/CalcolatoreVoti.php
//
// Motore di calcolo dei punteggi fantacalcio: bonus/malus del singolo
// giocatore e modificatori di squadra (difesa, centrocampo, attacco).
//
// Progettazione: la classe è VOLUTAMENTE priva di accesso al database
// e di conoscenza di "giornata", "stagione" o richieste HTTP. Riceve
// solo dati (statistiche dei giocatori, formazioni, moduli) e la
// configurazione (bonus/malus + parametri degli algoritmi, così come
// letta da NEW_REGOLE_BONUS / NEW_REGOLE_ALGORITMI). In questo modo può
// essere richiamata:
//   - da backend/admin/calcolo_giornata.php (fase 2 di "Gestione voti",
//     con i voti realmente inseriti per una giornata);
//   - da un'ipotetica funzione di simulazione, passando voti e
//     formazioni ipotetici, senza che nulla in questa classe debba
//     cambiare.
//
// Chi chiama la classe è responsabile di:
//   - leggere la configurazione della stagione da NEW_REGOLE_BONUS e
//     NEW_REGOLE_ALGORITMI (vedi RegoleCalcolo::carica() in
//     regole_calcolo.php) e passarla qui già pronta;
//   - salvare il risultato dove serve (NEW_VOTI, NEW_RISULTATI, o
//     nessun posto se è solo una simulazione).
// ============================================================

final class CalcolatoreVoti
{
    // ------------------------------------------------------------
    // 1) Punteggio del singolo giocatore (bonus/malus a voci fisse)
    // ------------------------------------------------------------
    //
    // $statistiche (int se non specificato altrimenti):
    //   voto      float|null  voto base (null se $sv = true)
    //   sv        bool        true se "senza voto" (entrato ma non
    //                         valutato): in questo caso il giocatore
    //                         non concorre al calcolo della squadra
    //   gf, gs    gol fatti, gol subiti (portiere/difensore)
    //   rp        rigori parati
    //   rf        rigori realizzati
    //   rs        rigori sbagliati
    //   au        autogol
    //   amm       ammonizioni
    //   esp       espulsioni
    //   ass       assist
    //
    // $bonusConfig: mappa codice => valore, come da NEW_REGOLE_BONUS
    // per la stagione (solo voci con attivo = 1), es.
    //   ['GOL_FATTO' => 3.0, 'AMMONIZIONE' => -0.5, ...]
    //
    // Ritorna null se il giocatore è "senza voto" (il chiamante decide
    // come trattarlo: escluso dal calcolo della media reparto, oppure
    // sostituito da un titolare in panchina, a seconda delle regole di
    // formazione, che esulano da questa classe).
    public static function puntiGiocatore(array $statistiche, array $bonusConfig): ?float
    {
        if (!empty($statistiche['sv']) || $statistiche['voto'] === null) {
            return null;
        }

        $mappaEventi = [
            'gf'  => 'GOL_FATTO',
            'gs'  => 'GOL_SUBITO',
            'rp'  => 'RIGORE_PARATO',
            'rf'  => 'RIGORE_REALIZZATO',
            'rs'  => 'RIGORE_SBAGLIATO',
            'au'  => 'AUTOGOL',
            'amm' => 'AMMONIZIONE',
            'esp' => 'ESPULSIONE',
            'ass' => 'ASSIST',
        ];

        $totale = (float) $statistiche['voto'];
        foreach ($mappaEventi as $campo => $codiceBonus) {
            $occorrenze = (int) ($statistiche[$campo] ?? 0);
            if ($occorrenze === 0) continue;
            $valoreBonus = (float) ($bonusConfig[$codiceBonus] ?? 0);
            $totale += $occorrenze * $valoreBonus;
        }

        return round($totale, 2);
    }

    // Somma dei bonus/malus applicati (esclude il voto base): utile
    // per calcolare il "voto netto al lordo del bonus gol" richiesto
    // dall'algoritmo attacco (vedi bonusAttaccoSenzaGol).
    public static function haBonusGolFatto(array $statistiche): bool
    {
        return (int) ($statistiche['gf'] ?? 0) > 0;
    }

    // ------------------------------------------------------------
    // 2) Modificatore DIFESA
    // ------------------------------------------------------------
    //
    // Applicato alla squadra AVVERSARIA in funzione della media voto
    // dei difensori titolari della squadra che difende, corretto da un
    // aggiustamento in base al modulo schierato da chi difende.
    //
    // $mediaVotoDifensori: media dei voti (bonus/malus esclusi, cioè i
    //   voti "puri") dei difensori titolari della squadra che difende
    // $numeroDifensoriModulo: numero di difensori del modulo schierato
    //   dalla squadra che difende (es. 3, 4, 5)
    // $parametri: dalla riga NEW_REGOLE_ALGORITMI (tipo=DIFESA), campo
    //   "parametri" già decodificato da JSON
    //
    // Ritorna il modificatore (intero o decimale) da sommare al
    // punteggio della squadra AVVERSARIA (non di chi difende).
    public static function modificatoreDifesa(
        float $mediaVotoDifensori,
        int $numeroDifensoriModulo,
        array $parametri
    ): float {
        $base = self::valoreFasciaPerSoglia($mediaVotoDifensori, $parametri['fasce'] ?? []);
        $aggiustamento = (float) ($parametri['aggiustamento_modulo'][(string) $numeroDifensoriModulo] ?? 0);
        return round($base + $aggiustamento, 2);
    }

    // ------------------------------------------------------------
    // 3) Modificatore CENTROCAMPO
    // ------------------------------------------------------------
    //
    // Confronta la somma dei voti (puri) dei centrocampisti titolari
    // delle due squadre. In base a fasce sulla differenza assoluta,
    // assegna un bonus alla squadra con centrocampo migliore e un
    // malus di pari entità all'altra.
    //
    // Ritorna ['casa' => modCasa, 'ospite' => modOspite], dove uno dei
    // due è sempre >= 0 e l'altro <= 0 (0/0 in caso di parità).
    public static function modificatoreCentrocampo(
        float $sommaVotiCasa,
        float $sommaVotiOspite,
        array $parametri
    ): array {
        $differenza = abs($sommaVotiCasa - $sommaVotiOspite);
        $bonus = self::valoreFasciaPerSoglia($differenza, $parametri['fasce'] ?? []);

        if ($sommaVotiCasa === $sommaVotiOspite) {
            return ['casa' => 0.0, 'ospite' => 0.0];
        }
        return $sommaVotiCasa > $sommaVotiOspite
            ? ['casa' => round($bonus, 2), 'ospite' => round(-$bonus, 2)]
            : ['casa' => round(-$bonus, 2), 'ospite' => round($bonus, 2)];
    }

    // ------------------------------------------------------------
    // 4) Modificatore ATTACCO
    // ------------------------------------------------------------
    //
    // Si applica al singolo attaccante titolare che NON ha già un
    // bonus da gol fatto: aggiunge un bonus in funzione del voto
    // "netto" (voto + bonus/malus già maturati, gol fatto escluso,
    // dato che qui per definizione non ce n'è).
    //
    // $votoNetto: risultato di puntiGiocatore() per quell'attaccante
    //   (già comprensivo di eventuali assist/ammonizioni/ecc.)
    public static function bonusAttaccoSenzaGol(float $votoNetto, array $parametri): float
    {
        return round(self::valoreFasciaPerSoglia($votoNetto, $parametri['fasce'] ?? []), 2);
    }

    // ------------------------------------------------------------
    // Helper: individua il bonus della fascia [da, a] (estremi inclusi,
    // null = illimitato) in cui ricade $valore. Le fasce non devono
    // necessariamente essere ordinate nella configurazione: vengono
    // valutate tutte e si usa la prima che contiene il valore.
    // ------------------------------------------------------------
    private static function valoreFasciaPerSoglia(float $valore, array $fasce): float
    {
        foreach ($fasce as $fascia) {
            $da = $fascia['da'] ?? null;
            $a  = $fascia['a']  ?? null;
            if ($da !== null && $valore < (float) $da) continue;
            if ($a  !== null && $valore > (float) $a)  continue;
            return (float) ($fascia['bonus'] ?? 0);
        }
        return 0.0;
    }

    // ------------------------------------------------------------
    // 5) Orchestratore: punteggio completo di una partita
    // ------------------------------------------------------------
    //
    // Calcola in un colpo solo i punteggi dei singoli giocatori e i tre
    // modificatori di squadra per le due squadre di una partita,
    // partendo da dati "grezzi". Pensato per essere l'unico punto
    // d'ingresso usato sia dal calcolo reale di una giornata sia da una
    // simulazione.
    //
    // $formazioneCasa / $formazioneOspite, ciascuna:
    //   [
    //     'modulo_difensori' => int,           // es. 4 per un 4-3-3
    //     'portiere'    => [ ...statistiche... ] | null,
    //     'difensori'   => [ [...statistiche...], ... ],
    //     'centrocampisti' => [ [...statistiche...], ... ],
    //     'attaccanti'  => [ [...statistiche...], ... ],
    //   ]
    //   ogni giocatore è un array di statistiche come da puntiGiocatore()
    //
    // $config:
    //   [
    //     'bonus'      => [...],  // NEW_REGOLE_BONUS della stagione
    //     'difesa'     => [...],  // parametri NEW_REGOLE_ALGORITMI tipo DIFESA
    //     'centrocampo'=> [...],  // idem CENTROCAMPO
    //     'attacco'    => [...],  // idem ATTACCO
    //   ]
    //
    // Ritorna una struttura con il dettaglio giocatore-per-giocatore, i
    // tre modificatori per squadra e il totale di squadra.
    public static function calcolaPartita(array $formazioneCasa, array $formazioneOspite, array $config): array
    {
        $casa   = self::calcolaSquadra($formazioneCasa, $config);
        $ospite = self::calcolaSquadra($formazioneOspite, $config);

        // Modificatore difesa: si applica in modo incrociato, la media
        // dei difensori di una squadra genera il modificatore per i
        // punti dell'AVVERSARIA.
        $modDifesaSuOspite = self::modificatoreDifesa(
            $casa['media_difensori'],
            $formazioneCasa['modulo_difensori'] ?? count($formazioneCasa['difensori'] ?? []),
            $config['difesa'] ?? []
        );
        $modDifesaSuCasa = self::modificatoreDifesa(
            $ospite['media_difensori'],
            $formazioneOspite['modulo_difensori'] ?? count($formazioneOspite['difensori'] ?? []),
            $config['difesa'] ?? []
        );

        $modCentrocampo = self::modificatoreCentrocampo(
            $casa['somma_centrocampisti'],
            $ospite['somma_centrocampisti'],
            $config['centrocampo'] ?? []
        );

        $casa['modificatori']   = ['difesa' => $modDifesaSuCasa,   'centrocampo' => $modCentrocampo['casa']];
        $ospite['modificatori'] = ['difesa' => $modDifesaSuOspite, 'centrocampo' => $modCentrocampo['ospite']];

        $casa['totale_squadra']   = round($casa['totale_giocatori']   + $modDifesaSuCasa   + $modCentrocampo['casa'],   2);
        $ospite['totale_squadra'] = round($ospite['totale_giocatori'] + $modDifesaSuOspite + $modCentrocampo['ospite'], 2);

        return ['casa' => $casa, 'ospite' => $ospite];
    }

    // Calcola i punteggi dei giocatori di UNA squadra e i dati intermedi
    // (medie/somme per reparto) necessari ai modificatori. Non applica i
    // modificatori (che richiedono il confronto con l'avversaria): per
    // quello vedi calcolaPartita().
    public static function calcolaSquadra(array $formazione, array $config): array
    {
        $bonusConfig     = $config['bonus'] ?? [];
        $parametriAttacco = $config['attacco'] ?? [];

        $giocatori = [];
        $totaleGiocatori = 0.0;

        // Portiere + difensori: media dei voti PURI (senza bonus/malus)
        // dei soli difensori titolari che hanno effettivamente un voto.
        $votiPuriDifensori = [];
        foreach ($formazione['difensori'] ?? [] as $stat) {
            if (!empty($stat['sv']) || $stat['voto'] === null) continue;
            $votiPuriDifensori[] = (float) $stat['voto'];
        }
        $mediaDifensori = $votiPuriDifensori ? array_sum($votiPuriDifensori) / count($votiPuriDifensori) : 0.0;

        $sommaCentrocampisti = 0.0;
        foreach ($formazione['centrocampisti'] ?? [] as $stat) {
            if (!empty($stat['sv']) || $stat['voto'] === null) continue;
            $sommaCentrocampisti += (float) $stat['voto'];
        }

        $ruoli = [
            'portiere'       => $formazione['portiere'] ? [$formazione['portiere']] : [],
            'difensori'      => $formazione['difensori'] ?? [],
            'centrocampisti' => $formazione['centrocampisti'] ?? [],
            'attaccanti'     => $formazione['attaccanti'] ?? [],
        ];

        foreach ($ruoli as $reparto => $lista) {
            foreach ($lista as $stat) {
                $punti = self::puntiGiocatore($stat, $bonusConfig);

                // Modificatore attacco: solo attaccanti titolari senza
                // gol fatto e con un voto (i "senza voto" non prendono
                // bonus/malus di alcun tipo).
                if ($reparto === 'attaccanti' && $punti !== null && !self::haBonusGolFatto($stat)) {
                    $punti = round($punti + self::bonusAttaccoSenzaGol($punti, $parametriAttacco), 2);
                }

                $giocatori[] = [
                    'id_giocatore' => $stat['id_giocatore'] ?? null,
                    'reparto'      => $reparto,
                    'sv'           => empty($stat['voto']) && ($stat['sv'] ?? false),
                    'punti'        => $punti,
                ];
                if ($punti !== null) $totaleGiocatori += $punti;
            }
        }

        return [
            'giocatori'            => $giocatori,
            'totale_giocatori'     => round($totaleGiocatori, 2),
            'media_difensori'      => round($mediaDifensori, 2),
            'somma_centrocampisti' => round($sommaCentrocampisti, 2),
        ];
    }
}
