import { useMemo, useState } from 'react'
import { Trophy, Crown, ClipboardList, Home, Plane, LayoutGrid, Flame } from 'lucide-react'
import { useFetch } from '../../hooks/useFetch'
import { getDashboardPrepara } from '../../api/client'
import { LoadingState, ErrorState, EmptyState, RoleBadge } from '../ui'
import TeamLogo from '../TeamLogo'
import { NomeConBandiera } from '../Bandiera'
import { useApp } from '../../context/AppContext'
import { IncontroSerieA } from '../PitchField'
import PreparaTop11 from './PreparaTop11'
import PreparaSerieA, { IncontriSerieA, GiocatoriPerAvversaria } from './PreparaSerieA'
import {
  Section, Vuoto, BarraPerc, PallinoForma, RigaRisultato, EsitoIcon, CoachPhoto, BadgeVista, fmt,
} from './shared'

// ── Formazioni (elenco) ──────────────────────────────────────

function Lineup({ giocatori, conVoti = false }) {
  const { ultimaStagione } = useApp()
  const tit = giocatori.filter(g => g.titolare)
  const pan = giocatori.filter(g => !g.titolare)
  const riga = g => (
    <div key={g.id_giocatore} className="flex items-center gap-3 px-4 py-2 border-b border-white/[0.03] last:border-0">
      <RoleBadge ruolo={g.ruolo} />
      <NomeConBandiera id={g.id_giocatore} nome={g.giocatore} stagione={ultimaStagione} className={`flex-1 text-sm ${g.titolare ? 'text-slate-300' : 'text-slate-500'}`} />
      {conVoti && (
        <span className="text-right flex-shrink-0">
          {g.giocata === false || g.voto === null || g.voto === undefined
            ? <span className="text-xs text-slate-600 font-mono">s.v.</span>
            : <>
                <span className="text-xs text-slate-600 font-mono mr-2">{fmt(g.voto)}</span>
                <span className="text-sm font-mono font-bold text-slate-200">{fmt(g.totale)}</span>
              </>}
        </span>
      )}
    </div>
  )
  return (
    <div>
      {tit.map(riga)}
      {pan.length > 0 && (
        <>
          <p className="px-4 py-1.5 text-[10px] uppercase tracking-widest text-slate-600 bg-white/[0.02]">Panchina</p>
          {pan.map(riga)}
        </>
      )}
    </div>
  )
}

// ── Storico scontri ──────────────────────────────────────────

function StoricoScontri({ dati, titolo, icona }) {
  const r = dati?.riepilogo
  return (
    <Section title={titolo} icon={icona} right={r ? `${r.giocate} incontri` : undefined}>
      {!r || r.giocate === 0 ? (
        <Vuoto>Nessun precedente.</Vuoto>
      ) : (
        <>
          <div className="grid grid-cols-3 gap-px bg-white/5">
            {[['Vinte', r.vinte, 'text-green-400'], ['Pari', r.nulle, 'text-yellow-400'], ['Perse', r.perse, 'text-red-400']].map(([l, v, c]) => (
              <div key={l} className="bg-pitch-900 py-3 text-center">
                <p className={`text-display text-2xl font-bold ${c}`}>{v}</p>
                <p className="text-[10px] uppercase tracking-wider text-slate-600">{l}</p>
              </div>
            ))}
          </div>
          <p className="px-4 py-2 text-xs text-slate-500 font-mono border-b border-white/5">
            Gol {r.golf}-{r.gols} · Fantapunti medi {fmt(r.media_fp)} vs {fmt(r.media_fp_avv)}
          </p>
          <div className="max-h-72 overflow-y-auto">
            {dati.partite.map((p, i) => (
              <div key={i} className="flex items-center gap-3 px-4 py-2 border-b border-white/[0.03] last:border-0">
                <EsitoIcon segno={p.segno} className="w-4 h-4" />
                <span className="text-xs font-mono text-slate-600 w-20 flex-shrink-0">{p.stagione} · G{p.giornata}</span>
                {p.turno && <span className="text-xs text-slate-600 truncate">{p.turno}</span>}
                <span className="ml-auto font-mono text-sm font-bold text-slate-300">{p.golf} - {p.gols}</span>
                <span className="text-[10px] font-mono text-slate-600 w-20 text-right">{fmt(p.ftotale)} / {fmt(p.ftotale_a)}</span>
              </div>
            ))}
          </div>
        </>
      )}
    </Section>
  )
}

// ── Sezione "Avversario" ─────────────────────────────────────

function SchedaAvversario({ m, d, champ }) {
  const a = m.avversario
  const cl = !champ ? d.prossima?.avversario?.classifica : null
  return (
    <div className="card p-5">
      <p className="text-xs font-mono tracking-widest uppercase text-slate-600 mb-3 flex items-center gap-2 flex-wrap">
        {champ ? <Crown className="w-3.5 h-3.5 text-sky-400" /> : <Trophy className="w-3.5 h-3.5 text-grass-400" />}
        Giornata {m.giornata}{m.turno ? ` · ${m.turno}` : ''} ·
        {m.casa ? <><Home className="w-3.5 h-3.5" /> In casa</> : <><Plane className="w-3.5 h-3.5" /> In trasferta</>}
      </p>
      <div className="flex flex-col sm:flex-row sm:items-center gap-4">
        <div className="flex items-center gap-4 min-w-0">
          <TeamLogo logo={a.logo} nome={a.nome} size="lg" />
          <div className="min-w-0">
            <h3 className="text-display text-2xl font-bold text-white truncate">{a.nome}</h3>
            {a.allenatore && (
              <div className="flex items-center gap-2 mt-1">
                <CoachPhoto logo={a.logo_allenatore} nome={a.allenatore} className="w-7 h-7" />
                <span className="text-sm text-slate-500 truncate">{a.allenatore}</span>
              </div>
            )}
          </div>
        </div>
        {cl && (
          <div className="sm:ml-auto grid grid-cols-4 gap-4 text-center">
            {[
              ['Pos.', `${cl.posizione}°`], ['Punti', cl.punti],
              ['V-N-P', `${cl.vinte}-${cl.nulle}-${cl.perse}`], ['Media', fmt(cl.media, 2)],
            ].map(([l, v]) => (
              <div key={l}>
                <p className="text-display text-xl font-bold text-white">{v}</p>
                <p className="text-[10px] uppercase tracking-wider text-slate-600">{l}</p>
              </div>
            ))}
          </div>
        )}
      </div>
    </div>
  )
}

function FormaAvversario({ forma, nome, champ }) {
  const [n, setN] = useState(5)
  const lista = forma.slice(0, n)
  return (
    <Section title={`Forma di ${nome}`} icon={ClipboardList}
             right={
               <span className="inline-flex gap-1">
                 {[5, 10].map(k => (
                   <button key={k} onClick={() => setN(k)}
                           className={`px-2 py-0.5 rounded-md border text-[11px] ${
                             n === k ? 'border-grass-500/50 text-grass-400 bg-grass-500/10' : 'border-white/10 text-slate-500 hover:text-slate-300'}`}>
                     ultime {k}
                   </button>
                 ))}
               </span>
             }>
      {lista.length === 0 ? <Vuoto>{champ ? 'Nessuna partita di Champions giocata.' : 'Nessuna partita giocata.'}</Vuoto> : (
        <>
          <div className="px-4 pt-4 flex flex-wrap gap-2">
            {[...lista].reverse().map(p => (
              <PallinoForma key={`${p.giornata}-${p.turno ?? ''}`} segno={p.segno} size="w-9 h-9 text-sm"
                            title={`G${p.giornata} · ${p.avversario} ${p.golf}-${p.gols}`} />
            ))}
          </div>
          <div className="mt-2">
            {lista.map(p => <RigaRisultato key={`${p.giornata}-${p.turno ?? ''}`} p={p} mostraTurno={champ} />)}
          </div>
        </>
      )}
    </Section>
  )
}

function ModuliAvversario({ moduli, nome }) {
  return (
    <Section title={`Moduli di ${nome}`} icon={LayoutGrid} right={moduli ? `${moduli.campione} partite` : undefined}>
      {!moduli || moduli.campione === 0 ? <Vuoto>Nessuna formazione registrata.</Vuoto> : (
        <div className="p-4 space-y-2.5">
          {moduli.distribuzione.map(x => (
            <BarraPerc key={x.modulo} label={x.modulo} valore={x.n} totale={moduli.campione}
                       colore="bg-sky-500" dx={`${x.n} · ${Math.round(x.perc)}%`} />
          ))}
        </div>
      )}
    </Section>
  )
}

// Giocatori da temere: i 5 dell'avversario con la fantamedia (media fantavoto) più alta,
// secondo il filtro giocatori (solo schierati / intera rosa) e le sole presenze con voto.
const N_TEMUTI = 5

function calcolaTemuti(rosaAvv, vista) {
  const usaRosa = vista === 'ROSA' && rosaAvv.every(g => g.tutta_rosa)
  const lista = rosaAvv
    .map(g => {
      const dati = usaRosa ? g.tutta_rosa : g
      return { ...g, fantamedia: dati.media_fantavoto, pres: dati.presenze }
    })
    .filter(g => g.fantamedia !== null && g.fantamedia !== undefined && g.pres > 0)
    .sort((a, b) => b.fantamedia - a.fantamedia || b.pres - a.pres)
    .slice(0, N_TEMUTI)
  return { lista, usaRosa, mappa: new Map(lista.map((g, i) => [g.id_giocatore, i + 1])) }
}

// ── L'avversario - sintesi ───────────────────────────────────

function SintesiAvversario({ p, d, champ }) {
  const m = p.match
  if (!m) return <EmptyState label={champ ? 'Nessun match di Champions in programma.' : 'Prossimo incontro non disponibile.'} />
  const a = m.avversario
  return (
    <div className="space-y-6">
      <SchedaAvversario m={m} d={d} champ={champ} />

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <FormaAvversario forma={p.forma_avversario} nome={a.nome} champ={champ} />
        <ModuliAvversario moduli={p.moduli_avversario} nome={a.nome} />
      </div>

      {p.storico && (
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
          <StoricoScontri dati={p.storico.campionato} titolo="Storico in campionato" icona={Trophy} />
          <StoricoScontri dati={p.storico.champions}  titolo="Storico in Champions"  icona={Crown} />
        </div>
      )}
    </div>
  )
}

// ── L'avversario - Serie A ───────────────────────────────────

function SerieAAvversario({ p, champ, vista, vistaAlt = null }) {
  const { ultimaStagione } = useApp()
  const m = p.match
  const rosaAvv = p.rosa_avversario ?? []
  // Modalità duale: una classifica dei temuti per vista; nelle liste sono evidenziati i giocatori di entrambe
  const viste = vistaAlt ? [vista, vistaAlt] : [vista]
  const duale = viste.length === 2
  const classifiche = useMemo(() => viste.map(v => calcolaTemuti(rosaAvv, v)), [rosaAvv, vista, vistaAlt]) // eslint-disable-line react-hooks/exhaustive-deps
  const temuti = useMemo(() => {
    const mappa = new Map()
    classifiche.forEach(c => c.mappa.forEach((pos, id) => mappa.set(id, Math.min(pos, mappa.get(id) ?? pos))))
    return mappa
  }, [classifiche])
  if (!m) return <EmptyState label={champ ? 'Nessun match di Champions in programma.' : 'Prossimo incontro non disponibile.'} />
  const a = m.avversario

  return (
    <div className="space-y-6">
      <div className={duale ? 'grid grid-cols-1 xl:grid-cols-2 gap-6 items-start' : ''}>
        {classifiche.map(c => (
          <Section key={c.usaRosa ? 'ROSA' : 'SCHIERATI'} title="Giocatori da temere" icon={Flame}
                   right={<BadgeVista vista={c.usaRosa ? 'ROSA' : 'SCHIERATI'} />}>
            {c.lista.length === 0 ? <Vuoto>Nessun giocatore di {a.nome} ha ancora preso voto.</Vuoto> : (
              c.lista.map((g, i) => (
                <div key={g.id_giocatore} className="flex items-center gap-3 px-4 py-2.5 border-b border-white/[0.03] last:border-0">
                  <span className="w-5 text-xs font-mono text-red-400 font-bold">{i + 1}</span>
                  <RoleBadge ruolo={g.ruolo} />
                  <div className="flex-1 min-w-0 flex flex-col">
                    <NomeConBandiera id={g.id_giocatore} nome={g.nome} stagione={ultimaStagione} className="text-sm text-slate-200 font-medium" />
                    <IncontroSerieA player={g} />
                  </div>
                  <span className="text-xs text-slate-600 font-mono">{g.pres} pres.</span>
                  <span className="font-mono text-sm font-bold text-red-300 w-12 text-right">{fmt(g.fantamedia, 2)}</span>
                </div>
              ))
            )}
            <p className="px-4 py-2 text-[11px] text-slate-600">
              I {N_TEMUTI} giocatori di {a.nome} con la fantamedia (media fantavoto) più alta
              {champ ? ' in Champions' : ' in campionato'}; sono evidenziati anche negli incontri qui sotto.
            </p>
          </Section>
        ))}
      </div>

      <IncontriSerieA incontri={p.incontri_avversario ?? []} giornataSerieA={p.giornata_serie_a}
                      titolo={`Incontri di Serie A · giocatori di ${a.nome}`} temuti={temuti} />
      <GiocatoriPerAvversaria rosa={rosaAvv} temuti={temuti}
                              titolo={`Giocatori di ${a.nome} per squadra avversaria`}
                              vuoto={`Nessun giocatore di ${a.nome} ha una partita in questa giornata.`} />
    </div>
  )
}

// ── L'avversario - ultima formazione ─────────────────────────

function UltimaFormazioneAvversario({ p, champ }) {
  if (!p.match) return <EmptyState label={champ ? 'Nessun match di Champions in programma.' : 'Prossimo incontro non disponibile.'} />
  const uf = p.ultima_formazione_avversario
  return (
    <Section title="Ultima formazione schierata dall'avversario" icon={ClipboardList}
             right={uf ? `G${uf.giornata} vs ${uf.partita.avversario} ${uf.partita.golf}-${uf.partita.gols}` : undefined}>
      {uf && uf.giocatori.length > 0
        ? <Lineup giocatori={uf.giocatori} conVoti />
        : <Vuoto>Nessuna formazione disponibile.</Vuoto>}
    </Section>
  )
}

// ── Area "Prepara la prossima partita" ───────────────────────
// Una sola pagina con le sezioni L'avversario (sintesi, Serie A, ultima formazione), Top 11 e Serie A
// (navigabili dalla barra fissa).

function BloccoSezione({ id, titolo, children }) {
  return (
    <div id={id} className="sezione-scroll space-y-3">
      <h2 className="text-xs uppercase tracking-widest text-slate-500">{titolo}</h2>
      {children}
    </div>
  )
}

export default function AreaPrepara({ d, stagione, idSquadra, competizione, vista = 'ROSA', vistaAlt = null }) {
  const champ = competizione === 'CHAMP'
  const { data: p, loading, error, refetch } = useFetch(
    () => getDashboardPrepara(stagione, idSquadra, competizione),
    [stagione, idSquadra, competizione]
  )

  if (d.formazione.stagione_conclusa) return <EmptyState label="Campionato concluso: nessuna prossima partita." />

  if (loading || (!p && !error) || (p && p.competizione !== competizione)) return <LoadingState label="Preparazione della partita..." />
  if (error) return <ErrorState message={error} onRetry={refetch} />

  return (
    <div className="space-y-6">
      <BloccoSezione id="prep-avv-sintesi" titolo="L'avversario - sintesi">
        <SintesiAvversario p={p} d={d} champ={champ} />
      </BloccoSezione>
      <BloccoSezione id="prep-avv-serie_a" titolo="L'avversario - serie A">
        <SerieAAvversario p={p} champ={champ} vista={vista} vistaAlt={vistaAlt} />
      </BloccoSezione>
      <BloccoSezione id="prep-avv-formazione" titolo="L'avversario - ultima formazione">
        <UltimaFormazioneAvversario p={p} champ={champ} />
      </BloccoSezione>
      <BloccoSezione id="prep-top11" titolo="Top 11">
        <PreparaTop11 rosa={p.rosa} competizione={competizione} vista={vista} vistaAlt={vistaAlt} rosaNonDisponibile={p.rosa_non_disponibile} />
      </BloccoSezione>
      <BloccoSezione id="prep-serie_a" titolo="Serie A">
        <PreparaSerieA rosa={p.rosa} incontri={p.incontri_serie_a} giornataSerieA={p.giornata_serie_a} />
      </BloccoSezione>
    </div>
  )
}
