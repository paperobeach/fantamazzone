import { useState } from 'react'
import {
  ResponsiveContainer, BarChart, Bar, LineChart, Line, XAxis, YAxis, CartesianGrid, Tooltip, Cell, Legend,
} from 'recharts'
import { Goal, Activity, Users, Gauge, Grid3x3, SlidersHorizontal } from 'lucide-react'
import { useFetch } from '../../hooks/useFetch'
import { getDashboardRuoli } from '../../api/client'
import { LoadingState, ErrorState, EmptyState, TabBar } from '../ui'
import { Section, Vuoto, Indicatore, BarraPerc, fmt } from './shared'
import { Bandiera } from '../Bandiera'

const REPARTI = [
  { value: '1', label: 'Portieri' },
  { value: '2', label: 'Difensori' },
  { value: '3', label: 'Centrocampisti' },
  { value: '4', label: 'Attaccanti' },
]

const VISTE = [
  { value: 'SCHIERATI', label: 'Schierati' },
  { value: 'ROSA', label: 'Tutta la rosa' },
]

const TOOLTIP_STYLE = { background: '#0f1f2e', border: '1px solid #ffffff1a', borderRadius: 8, fontSize: 12 }
const ASSE = { stroke: '#64748b', tick: { fontSize: 11 } }

const fmtMod = v => (v > 0 ? `+${fmt(v, 1)}` : fmt(v, 1))
const fmtGiornata = g => `G${g}`

// ── Grafici ──────────────────────────────────────────────────

/** Istogramma semplice: data = [{ etichetta, n }] */
function Istogramma({ data, colore = '#4ade80', unita = 'partite', altezza = 'h-52' }) {
  if (!data?.length) return <Vuoto>Nessun dato.</Vuoto>
  return (
    <div className={`p-4 ${altezza}`}>
      <ResponsiveContainer width="100%" height="100%">
        <BarChart data={data} margin={{ top: 8, right: 8, bottom: 0, left: -20 }}>
          <CartesianGrid stroke="#ffffff10" vertical={false} />
          <XAxis dataKey="etichetta" {...ASSE} interval={0} />
          <YAxis allowDecimals={false} {...ASSE} />
          <Tooltip contentStyle={TOOLTIP_STYLE} cursor={{ fill: '#ffffff08' }} formatter={v => [v, unita]} />
          <Bar dataKey="n" fill={colore} radius={[4, 4, 0, 0]} />
        </BarChart>
      </ResponsiveContainer>
    </div>
  )
}

/** Barre per giornata; con `segno` colora in verde i valori positivi e in rosso i negativi. */
function BarreGiornata({ giornate, campo, nome, colore = '#4ade80', segno = false }) {
  if (!giornate?.length) return <Vuoto>Nessuna partita.</Vuoto>
  const dati = giornate.map(g => ({ giornata: g.giornata, valore: g[campo] }))
  return (
    <div className="p-4 h-56">
      <ResponsiveContainer width="100%" height="100%">
        <BarChart data={dati} margin={{ top: 8, right: 8, bottom: 0, left: -20 }}>
          <CartesianGrid stroke="#ffffff10" vertical={false} />
          <XAxis dataKey="giornata" {...ASSE} tickFormatter={fmtGiornata} />
          <YAxis allowDecimals={segno} {...ASSE} />
          <Tooltip contentStyle={TOOLTIP_STYLE} cursor={{ fill: '#ffffff08' }}
                   labelFormatter={g => `Giornata ${g}`} formatter={v => [segno ? fmtMod(v) : v, nome]} />
          <Bar dataKey="valore" fill={colore} radius={[4, 4, 0, 0]}>
            {segno && dati.map((d, i) => (
              <Cell key={i} fill={d.valore > 0 ? '#4ade80' : d.valore < 0 ? '#f87171' : '#64748b'} />
            ))}
          </Bar>
        </BarChart>
      </ResponsiveContainer>
    </div>
  )
}

function LineaVoti({ giornate }) {
  if (!giornate?.length) return <Vuoto>Nessuna partita.</Vuoto>
  return (
    <div className="p-4 h-60">
      <ResponsiveContainer width="100%" height="100%">
        <LineChart data={giornate} margin={{ top: 8, right: 12, bottom: 0, left: -16 }}>
          <CartesianGrid stroke="#ffffff10" vertical={false} />
          <XAxis dataKey="giornata" {...ASSE} tickFormatter={fmtGiornata} />
          <YAxis domain={['auto', 'auto']} {...ASSE} />
          <Tooltip contentStyle={TOOLTIP_STYLE} labelFormatter={g => `Giornata ${g}`}
                   formatter={(v, n) => [fmt(v, 2), n]} />
          <Legend wrapperStyle={{ fontSize: 11 }} />
          <Line type="monotone" name="Voto netto" dataKey="media_netto" stroke="#4ade80" strokeWidth={2.5} dot={{ r: 3 }} />
          <Line type="monotone" name="Voto lordo" dataKey="media_lordo" stroke="#fbbf24" strokeWidth={2.5} dot={{ r: 3 }} />
        </LineChart>
      </ResponsiveContainer>
    </div>
  )
}

// ── Matrice giocatore × giornata ─────────────────────────────

function MatriceGiocatori({ giornate, giocatori, portiere, rosa }) {
  if (!giocatori?.length) {
    return <Vuoto>{rosa ? 'Nessun giocatore del reparto ha preso voto.' : 'Nessun giocatore del reparto ha concorso al punteggio.'}</Vuoto>
  }
  const tinta = portiere ? 'bg-red-500/25 text-red-300' : 'bg-green-500/25 text-green-300'
  return (
    <div className="overflow-x-auto">
      <table className="text-xs border-separate border-spacing-0 min-w-full">
        <thead>
          <tr>
            <th className="sticky left-0 z-10 bg-pitch-900 text-left font-medium text-slate-500 px-3 py-2 border-b border-white/5 min-w-[9rem]">Giocatore</th>
            {giornate.map(g => (
              <th key={g.giornata} className="font-mono font-normal text-slate-600 px-1.5 py-2 border-b border-white/5 text-center">{g.giornata}</th>
            ))}
            <th className="font-mono text-slate-400 px-3 py-2 border-b border-white/5 text-center">Tot</th>
          </tr>
        </thead>
        <tbody>
          {giocatori.map(p => (
            <tr key={p.id_giocatore ?? 'ufficio'}>
              <td className="sticky left-0 z-10 bg-pitch-900 px-3 py-1.5 border-b border-white/[0.03] text-slate-300 whitespace-nowrap">
                <span className="inline-flex items-center gap-1.5 align-middle"><Bandiera id={p.id_giocatore} />{p.nome}</span> <span className="text-slate-600 font-mono">· {p.presenze} pres.</span>
              </td>
              {giornate.map(g => {
                const v = p.per_giornata[g.giornata]
                return (
                  <td key={g.giornata} className="px-1 py-1 border-b border-white/[0.03] text-center">
                    {v === undefined ? null : (
                      <span className={`inline-flex w-6 h-6 items-center justify-center rounded font-mono ${
                        v > 0 ? `${tinta} font-bold` : 'text-slate-600'}`}>
                        {v > 0 ? v : '·'}
                      </span>
                    )}
                  </td>
                )
              })}
              <td className="px-3 py-1.5 border-b border-white/[0.03] text-center font-mono font-bold text-slate-200">{p.gol}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

// ── Reparto ──────────────────────────────────────────────────

function Reparto({ r, ruolo, vista }) {
  const rosa = vista === 'ROSA'
  // Nella vista rosa "schierati" = giocatori con voto (chiunque in rosa abbia preso voto)
  const nSchierati = rosa ? 'Con voto' : 'Schierati'
  const portiere = ruolo === 1
  const nomeGol = portiere ? 'Gol subiti' : 'Gol fatti'
  const nomeReparto = REPARTI.find(x => x.value === String(ruolo))?.label.toLowerCase()
  const m = r.modificatore
  const rs = r.riepilogo

  if (r.non_disponibile) return <EmptyState label={r.non_disponibile} />
  if (r.partite === 0) {
    return <EmptyState label={`Nessun dato sui ${nomeReparto} per questa competizione.`} />
  }

  const fasce = f => f.map(x => ({ etichetta: `${fmt(x.da)}–${fmt(x.a)}`, n: x.n }))
  const distGol = r.distribuzione_gol.map(x => ({ etichetta: String(x.valore), n: x.n }))
  const distN = r.schierati.map(x => ({ etichetta: String(x.schierati), n: x.partite }))
  const distMod = m ? m.distribuzione.map(x => ({ etichetta: fmtMod(x.valore), n: x.n })) : []

  return (
    <div className="space-y-6">
      {/* Indicatori */}
      <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
        <Indicatore label="Partite" value={r.partite} />
        <Indicatore label="Media voto netto" value={fmt(rs.media_netto, 2)} sub="base, senza bonus/malus" tono="text-grass-400" />
        <Indicatore label="Media voto lordo" value={fmt(rs.media_lordo, 2)} sub="con bonus/malus" tono="text-gold-400" />
        <Indicatore label={`${nomeGol} (tot.)`} value={rs.gol_totali} tono={portiere ? 'text-red-400' : 'text-white'} />
        <Indicatore label={`${nomeGol} a partita`} value={fmt(rs.media_gol, 2)} />
        {portiere
          ? <Indicatore label="Clean sheet del portiere"
                        value={r.distribuzione_gol.find(x => x.valore === 0)?.n ?? 0} sub="partite senza gol subiti" />
          : <Indicatore label={`${nSchierati} (media)`} value={fmt(rs.media_schierati, 1)} sub="giocatori a partita" />}
      </div>

      {/* Gol per giornata */}
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <Section title={`${nomeGol} per giornata`} icon={Goal} right="totale del reparto">
          <BarreGiornata giornate={r.giornate} campo="gol" nome={nomeGol} colore={portiere ? '#f87171' : '#4ade80'} />
        </Section>
        <Section title={`Partite per numero di ${nomeGol.toLowerCase()}`} icon={Goal}>
          <Istogramma data={distGol} colore={portiere ? '#f87171' : '#4ade80'} />
        </Section>
      </div>

      {/* Matrice */}
      <Section title={`${nomeGol} per giocatore e per giornata`} icon={Grid3x3}
               right={portiere ? 'gol subiti dal portiere' : 'gol segnati'}>
        <MatriceGiocatori giornate={r.giornate} giocatori={r.giocatori} portiere={portiere} rosa={rosa} />
      </Section>

      {/* Voti */}
      <Section title="Media voto del reparto per giornata" icon={Activity}>
        <LineaVoti giornate={r.giornate} />
      </Section>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <Section title="Partite per fascia di media voto netto" icon={Gauge}>
          <Istogramma data={fasce(r.fasce_netto)} colore="#4ade80" />
        </Section>
        <Section title="Partite per fascia di media voto lordo" icon={Gauge}>
          <Istogramma data={fasce(r.fasce_lordo)} colore="#fbbf24" />
        </Section>
      </div>

      {/* Numero di giocatori schierati (solo reparti di movimento) */}
      {!portiere && (
        <Section title={rosa ? 'Giocatori con voto e media voto' : 'Giocatori schierati e media voto'} icon={Users}
                 right="partite per numero di giocatori">
          <div className="grid grid-cols-1 lg:grid-cols-2">
            <Istogramma data={distN} colore="#38bdf8" />
            <div className="overflow-x-auto p-4">
              <table className="fanta-table">
                <thead>
                  <tr>
                    <th className="text-center">{nSchierati}</th>
                    <th className="text-center">Partite</th>
                    <th className="text-center">Voto netto</th>
                    <th className="text-center">Voto lordo</th>
                  </tr>
                </thead>
                <tbody>
                  {r.schierati.map(x => (
                    <tr key={x.schierati}>
                      <td className="text-center font-mono font-bold text-slate-200">{x.schierati}</td>
                      <td className="text-center font-mono text-slate-400">{x.partite}</td>
                      <td className="text-center font-mono text-grass-400">{fmt(x.media_netto, 2)}</td>
                      <td className="text-center font-mono text-gold-400">{fmt(x.media_lordo, 2)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        </Section>
      )}

      {/* Modificatore (solo reparti di movimento; dipende dallo schieramento: non nella vista rosa) */}
      {!portiere && !rosa && (
        m ? (
          <>
            <div className="grid grid-cols-2 lg:grid-cols-4 gap-3">
              <Indicatore label="Modificatore medio" value={fmtMod(m.medio)} sub={`${m.campione} partite`}
                          tono={m.medio > 0 ? 'text-green-400' : m.medio < 0 ? 'text-red-400' : 'text-white'} />
              <Indicatore label="Modificatore totale" value={fmtMod(m.totale)} />
              <Indicatore label="Positivo" value={`${fmt(m.perc_positivi, 0)}%`} sub={`${m.positivi} partite`} tono="text-green-400" />
              <Indicatore label="Negativo" value={`${fmt(m.perc_negativi, 0)}%`} sub={`${m.negativi} partite`} tono="text-red-400" />
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
              <Section title="Modificatore per giornata" icon={SlidersHorizontal} right="verde = favorevole">
                <BarreGiornata giornate={r.giornate.filter(g => g.modificatore !== null)} campo="modificatore"
                               nome="Modificatore" segno />
              </Section>
              <Section title="Partite per valore del modificatore" icon={SlidersHorizontal}>
                <Istogramma data={distMod} colore="#a78bfa" />
                <div className="px-4 pb-4 space-y-1.5">
                  <BarraPerc label="Positivo" valore={m.positivi} totale={m.campione} colore="bg-green-500" />
                  <BarraPerc label="Nullo"    valore={m.nulli}    totale={m.campione} colore="bg-slate-500" />
                  <BarraPerc label="Negativo" valore={m.negativi} totale={m.campione} colore="bg-red-500" />
                </div>
              </Section>
            </div>
          </>
        ) : (
          <Section title="Modificatore" icon={SlidersHorizontal}>
            <Vuoto>Nessun modificatore registrato per questo reparto.</Vuoto>
          </Section>
        )
      )}

      <p className="text-[11px] text-slate-600 leading-relaxed">
        Voto netto = voto base senza bonus/malus; voto lordo = fantavoto con bonus/malus. La media del reparto è la media
        delle medie di partita. {portiere && 'I gol subiti sono quelli del portiere (malus “gol subito”), non il punteggio fanta della partita. '}
        {!portiere && !rosa && 'Il modificatore è visto dalla tua squadra: positivo = favorevole. '}
        {rosa
          ? 'Conta ogni giocatore della rosa che ha preso voto nella giornata, anche se non schierato o sostituito: un giocatore compare solo nelle giornate in cui faceva parte della tua rosa. Il voto lordo dei non schierati è calcolato con le regole bonus/malus della stagione; il modificatore, che dipende dallo schieramento, non è mostrato.'
          : 'Conta chi ha concorso al punteggio, riserve d\'ufficio comprese.'}
      </p>
    </div>
  )
}

// ── Area ─────────────────────────────────────────────────────

export default function AreaRuoli({ stagione, idSquadra, competizione }) {
  const [reparto, setReparto] = useState('1')
  const [vista, setVista] = useState('SCHIERATI')
  const { data: r, loading, error, refetch } = useFetch(
    () => getDashboardRuoli(stagione, idSquadra, Number(reparto), competizione, vista),
    [stagione, idSquadra, reparto, competizione, vista]
  )

  return (
    <div className="space-y-6">
      <div className="flex flex-col lg:flex-row lg:items-center gap-3">
        <div className="overflow-x-auto">
          <TabBar tabs={REPARTI} active={reparto} onChange={setReparto} />
        </div>
        <div className="overflow-x-auto lg:ml-auto">
          <TabBar tabs={VISTE} active={vista} onChange={setVista} />
        </div>
      </div>
      <p className="text-xs text-slate-500 -mt-3">
        {vista === 'ROSA'
          ? 'Tutti i giocatori della rosa, schierati o no (panchina, sostituiti, tribuna).'
          : 'Solo i giocatori che hanno concorso al punteggio della giornata.'}
      </p>
      {loading || (!r && !error) ? <LoadingState label="Analisi del reparto..." />
        : error ? <ErrorState message={error} onRetry={refetch} />
        : r.ruolo !== Number(reparto) || r.vista !== vista ? <LoadingState label="Analisi del reparto..." />
        : <Reparto r={r} ruolo={Number(reparto)} vista={vista} />}
    </div>
  )
}
