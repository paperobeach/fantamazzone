import { useState } from 'react'
import { Trophy, Crown, ClipboardList, Swords, Home, Plane, EyeOff, LayoutGrid, Timer } from 'lucide-react'
import { useFetch } from '../../hooks/useFetch'
import { getDashboardPrepara } from '../../api/client'
import { LoadingState, ErrorState, EmptyState, RoleBadge, TabBar } from '../ui'
import TeamLogo from '../TeamLogo'
import { PitchLineup } from '../PitchLineup'
import PreparaTop11 from './PreparaTop11'
import PreparaSerieA from './PreparaSerieA'
import {
  Section, Vuoto, BarraPerc, PallinoForma, RigaRisultato, EsitoIcon, CoachPhoto, fmt, fmtDataOra,
} from './shared'

// ── Formazioni (elenco) ──────────────────────────────────────

function Lineup({ giocatori, conVoti = false }) {
  const tit = giocatori.filter(g => g.titolare)
  const pan = giocatori.filter(g => !g.titolare)
  const riga = g => (
    <div key={g.id_giocatore} className="flex items-center gap-3 px-4 py-2 border-b border-white/[0.03] last:border-0">
      <RoleBadge ruolo={g.ruolo} />
      <span className={`flex-1 text-sm truncate ${g.titolare ? 'text-slate-300' : 'text-slate-500'}`}>{g.giocatore}</span>
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
      {m.scadenza && (
        <p className="text-xs text-slate-500 mt-3 flex items-center gap-1.5">
          <Timer className="w-3.5 h-3.5" /> Limite formazione: {fmtDataOra(m.scadenza)}
        </p>
      )}
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

function FormazioniScontro({ p, m, d }) {
  const f = p.formazioni
  const colonne = [
    { nome: d.squadra.nome, logo: d.squadra.logo, lista: f.mia, nascosta: false },
    { nome: m.avversario.nome, logo: m.avversario.logo, lista: f.avversario, nascosta: f.avversario_nascosta },
  ]
  return (
    <Section title="Formazioni per lo scontro diretto" icon={Swords} right={`Giornata ${m.giornata}`}>
      <div className="grid grid-cols-1 md:grid-cols-2 gap-6 p-4">
        {colonne.map(({ nome, logo, lista, nascosta }) => (
          <div key={nome}>
            <div className="flex items-center gap-2 mb-3">
              <TeamLogo logo={logo} nome={nome} size="sm" />
              <p className="text-sm font-semibold text-slate-300 truncate">{nome}</p>
            </div>
            {lista ? <PitchLineup players={lista} /> : (
              <div className="rounded-xl border border-dashed border-white/10 aspect-[3/4] flex flex-col items-center justify-center gap-2 px-4 text-center">
                {nascosta && <EyeOff className="w-5 h-5 text-slate-600" />}
                <p className="text-sm text-slate-600">
                  {nascosta ? 'Formazione inserita: sarà visibile dopo il limite di inserimento' : 'Formazione non inserita'}
                </p>
              </div>
            )}
          </div>
        ))}
      </div>
    </Section>
  )
}

function SezioneAvversario({ p, d, champ }) {
  const m = p.match
  if (!m) return <EmptyState label={champ ? 'Nessun match di Champions in programma.' : 'Prossimo incontro non disponibile.'} />
  const a = m.avversario
  const pr = !champ ? d.prossima : null
  const uf = pr?.ultima_formazione_avversario

  return (
    <div className="space-y-6">
      <SchedaAvversario m={m} d={d} champ={champ} />

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <FormaAvversario forma={p.forma_avversario} nome={a.nome} champ={champ} />
        <ModuliAvversario moduli={p.moduli_avversario} nome={a.nome} />
      </div>

      <FormazioniScontro p={p} m={m} d={d} />

      {pr && (
        <>
          <Section title="Ultima formazione schierata dall'avversario" icon={ClipboardList}
                   right={uf ? `G${uf.giornata} vs ${uf.partita.avversario} ${uf.partita.golf}-${uf.partita.gols}` : undefined}>
            {uf && uf.giocatori.length > 0
              ? <Lineup giocatori={uf.giocatori} conVoti />
              : <Vuoto>Nessuna formazione disponibile.</Vuoto>}
          </Section>
          <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <StoricoScontri dati={pr.storico.campionato} titolo="Storico in campionato" icona={Trophy} />
            <StoricoScontri dati={pr.storico.champions}  titolo="Storico in Champions"  icona={Crown} />
          </div>
        </>
      )}
    </div>
  )
}

// ── Area "Prepara la prossima partita" ───────────────────────

const SEZIONI = [
  { value: 'avversario', label: "L'avversario" },
  { value: 'top11',      label: 'Top 11' },
  { value: 'serie_a',    label: 'Serie A' },
]

export default function AreaPrepara({ d, stagione, idSquadra, competizione }) {
  const [sezione, setSezione] = useState('avversario')
  const champ = competizione === 'CHAMP'
  const { data: p, loading, error, refetch } = useFetch(
    () => getDashboardPrepara(stagione, idSquadra, competizione),
    [stagione, idSquadra, competizione]
  )

  if (d.formazione.stagione_conclusa) return <EmptyState label="Campionato concluso: nessuna prossima partita." />

  return (
    <div className="space-y-6">
      <div className="overflow-x-auto">
        <TabBar tabs={SEZIONI} active={sezione} onChange={setSezione} />
      </div>
      {loading || (!p && !error) ? <LoadingState label="Preparazione della partita..." />
        : error ? <ErrorState message={error} onRetry={refetch} />
        : p.competizione !== competizione ? <LoadingState label="Preparazione della partita..." />
        : (
          <>
            {sezione === 'avversario' && <SezioneAvversario p={p} d={d} champ={champ} />}
            {sezione === 'top11' && <PreparaTop11 rosa={p.rosa} competizione={competizione} />}
            {sezione === 'serie_a' && (
              <PreparaSerieA rosa={p.rosa} incontri={p.incontri_serie_a} giornataSerieA={p.giornata_serie_a} />
            )}
          </>
        )}
    </div>
  )
}
