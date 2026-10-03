// ── Champions ──────────────────────────────────────────────────────────────
// Struttura della coppa:
//   Fase 1 · due gironi da 4 squadre, andata e ritorno
//   Fase 2 · due gironi da 3 squadre (via le ultime della fase 1)
//   Fase finale · semifinali A/R (1A-2B, 1B-2A), finale con replay,
//                 supplementari e calci di rigore in caso di parità
import { useState } from 'react'
import { useApp } from '../context/AppContext'
import { useFetch } from '../hooks/useFetch'
import { getChampions } from '../api/client'
import { PageHeader, LoadingState, ErrorState, EmptyState } from '../components/ui'
import TeamLogo from '../components/TeamLogo'

const FASI = [
  { id: 'fase1', label: 'Fase 1', sub: 'Gironi' },
  { id: 'fase2', label: 'Fase 2', sub: 'Gironi' },
  { id: 'finale', label: 'Fase finale', sub: 'Semifinali e finale' },
]

// ── Fase 1 ────────────────────────────────────────────────────────────────
function ClassificaGirone({ squadre }) {
  return (
    <div className="overflow-x-auto">
      <table className="fanta-table min-w-[420px]">
        <thead>
          <tr>
            <th>#</th><th>Squadra</th>
            <th className="text-center">G</th>
            <th className="text-center">V</th>
            <th className="text-center">N</th>
            <th className="text-center">P</th>
            <th className="text-center">GF</th>
            <th className="text-center">GS</th>
            <th className="text-center">Pts</th>
          </tr>
        </thead>
        <tbody>
          {squadre.map((sq, i) => {
            const qualificata = i < 3          // l'ultima viene eliminata
            return (
              <tr key={sq.id_squadra} className={i === 3 ? 'opacity-60' : ''}>
                <td className="px-4 py-2.5 text-slate-600 font-mono text-xs">
                  <span className={qualificata ? 'text-grass-400' : 'text-red-400'}>{i + 1}</span>
                </td>
                <td className="px-4 py-2.5">
                  <div className="flex items-center gap-2">
                    <TeamLogo logo={sq.logo} nome={sq.nome} size="sm" />
                    <span className="text-slate-200 font-medium text-sm">{sq.nome}</span>
                  </div>
                </td>
                <td className="px-2 py-2.5 text-center text-slate-500 text-sm">{sq.giocate}</td>
                <td className="px-2 py-2.5 text-center text-slate-400 text-sm">{sq.vinte}</td>
                <td className="px-2 py-2.5 text-center text-slate-400 text-sm">{sq.nulle}</td>
                <td className="px-2 py-2.5 text-center text-slate-400 text-sm">{sq.perse}</td>
                <td className="px-2 py-2.5 text-center text-slate-400 text-sm">{sq.golf}</td>
                <td className="px-2 py-2.5 text-center text-slate-500 text-sm">{sq.gols}</td>
                <td className="px-2 py-2.5 text-center">
                  <span className="font-bold text-display text-lg text-white">{sq.punti}</span>
                </td>
              </tr>
            )
          })}
        </tbody>
      </table>
    </div>
  )
}

function Partita({ p }) {
  const { casa, ospite, giocata } = p
  const fmt = v => (v === null || v === undefined ? '–' : Number(v).toFixed(1))
  return (
    <div className="px-4 py-2.5">
      <div className="flex items-center gap-2">
        <div className="flex-1 flex items-center justify-end gap-2 min-w-0">
          <span className="text-sm text-slate-200 truncate text-right">{casa.nome}</span>
          <TeamLogo logo={casa.logo} nome={casa.nome} size="sm" />
        </div>
        <div className="w-16 text-center font-bold text-white text-display">
          {giocata ? `${casa.golf} - ${ospite.golf}` : 'vs'}
        </div>
        <div className="flex-1 flex items-center gap-2 min-w-0">
          <TeamLogo logo={ospite.logo} nome={ospite.nome} size="sm" />
          <span className="text-sm text-slate-200 truncate">{ospite.nome}</span>
        </div>
      </div>
      {giocata && (
        <div className="text-center text-[11px] text-slate-600 mt-0.5">
          fantapunti {fmt(casa.ftotale)} – {fmt(ospite.ftotale)}
        </div>
      )}
    </div>
  )
}

function CalendarioGirone({ turni }) {
  // andata = prima metà dei turni, ritorno = seconda metà
  const meta = Math.ceil(turni.length / 2)
  return (
    <div>
      {turni.map((t, idx) => (
        <div key={`${t.giornata_camp}-${t.giornata}`} className="border-t border-white/5">
          <div className="px-4 py-1.5 bg-pitch-900/60 text-[11px] uppercase tracking-wider text-slate-500 flex justify-between">
            <span>Turno {t.giornata_camp} · {idx < meta ? 'andata' : 'ritorno'}</span>
            <span>Giornata {t.giornata}</span>
          </div>
          {t.partite.map((p, i) => <Partita key={i} p={p} />)}
        </div>
      ))}
    </div>
  )
}

function Fase1({ stagione }) {
  const { data, loading, error, refetch } = useFetch(
    () => getChampions(stagione, 'fase1'),
    [stagione]
  )
  const gironi = Array.isArray(data) ? data : []

  if (loading) return <LoadingState />
  if (error) return <ErrorState message={error} onRetry={refetch} />
  if (!gironi.length) return <EmptyState label="Nessun girone della prima fase per questa stagione" />

  return (
    <div className="grid gap-6 lg:grid-cols-2">
      {gironi.map(g => (
        <div key={g.girone} className="card overflow-hidden">
          <div className="px-4 py-3 border-b border-white/5">
            <h3 className="font-semibold text-slate-300">Girone {g.girone}</h3>
          </div>
          <ClassificaGirone squadre={g.squadre} />
          <CalendarioGirone turni={g.turni} />
        </div>
      ))}
      <p className="text-xs text-slate-600 lg:col-span-2">
        Le prime tre di ogni girone accedono alla Fase 2; l'ultima classificata è eliminata.
        Parità in classifica: differenza reti, gol fatti, fantapunti totali.
      </p>
    </div>
  )
}

// ── Fase 2 e finale (dati non ancora collegati) ───────────────────────────
function Segnaposto({ titolo, righe }) {
  return (
    <div className="card p-5 space-y-2">
      <h3 className="font-semibold text-slate-300">{titolo}</h3>
      <ul className="text-sm text-slate-400 space-y-1 list-disc pl-5">
        {righe.map((r, i) => <li key={i}>{r}</li>)}
      </ul>
      <p className="text-xs text-slate-600 pt-2">Dati di questa fase non ancora disponibili.</p>
    </div>
  )
}

function Fase2() {
  return (
    <Segnaposto
      titolo="Fase 2 · Gironi"
      righe={[
        'Due gironi da 3 squadre ciascuno.',
        "Accedono le prime tre classificate di ciascun girone della Fase 1.",
        'Le prime due di ogni girone passano alla fase finale.',
      ]}
    />
  )
}

function FaseFinale() {
  return (
    <Segnaposto
      titolo="Fase finale"
      righe={[
        'Semifinali di andata e ritorno: 1ª girone A vs 2ª girone B e 1ª girone B vs 2ª girone A.',
        'Finale in gara unica.',
        'In caso di parità: replay della finale; se ancora pari, supplementari e calci di rigore.',
      ]}
    />
  )
}

// ── Note ──────────────────────────────────────────────────────────────────
function Note({ stagione }) {
  const { data } = useFetch(() => getChampions(stagione, 'note'), [stagione])
  const note = Array.isArray(data) ? data : []
  if (!note.length) return null
  return (
    <div className="card p-4 mt-6 space-y-1">
      <h3 className="font-semibold text-slate-300 text-sm">Note</h3>
      {note.map((n, i) => <p key={i} className="text-sm text-slate-400">{n.LABEL ?? n.label}</p>)}
    </div>
  )
}

// ── Pagina ────────────────────────────────────────────────────────────────
export default function Champions() {
  const { stagione } = useApp()
  const [fase, setFase] = useState('fase1')

  return (
    <div className="animate-fade-up">
      <PageHeader label="Coppa" title="Champions League" />

      <div className="flex gap-2 mb-6 overflow-x-auto">
        {FASI.map(f => (
          <button
            key={f.id}
            onClick={() => setFase(f.id)}
            className={`px-4 py-1.5 rounded-lg text-sm font-medium transition-all whitespace-nowrap ${
              fase === f.id ? 'bg-grass-500 text-pitch-950' : 'bg-pitch-900 text-slate-400 hover:text-slate-200 border border-white/5'
            }`}
          >
            {f.label} <span className="hidden sm:inline opacity-70">· {f.sub}</span>
          </button>
        ))}
      </div>

      {fase === 'fase1' && <Fase1 stagione={stagione} />}
      {fase === 'fase2' && <Fase2 />}
      {fase === 'finale' && <FaseFinale />}

      <Note stagione={stagione} />
    </div>
  )
}
