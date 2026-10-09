// ── Champions ──────────────────────────────────────────────────────────────
// Struttura della coppa:
//   Fase 1 · due gironi da 4 squadre, andata e ritorno
//   Fase 2 · due gironi da 3 squadre, andata e ritorno (via le ultime della fase 1)
//   Fase finale · semifinali A/R (1A-2B, 1B-2A), finale con replay,
//                 supplementari e calci di rigore in caso di parità
import { useState } from 'react'
import { useApp } from '../context/AppContext'
import { useFetch } from '../hooks/useFetch'
import { getChampions, getDettaglioPartita } from '../api/client'
import { PageHeader, LoadingState, ErrorState, EmptyState } from '../components/ui'
import TeamLogo from '../components/TeamLogo'
import { MatchResultRow } from '../components/MatchResult'
import { MatchDetailPanel } from '../components/MatchDetail'

const FASI = [
  { id: 'fase1', label: 'Fase 1', sub: 'Gironi' },
  { id: 'fase2', label: 'Fase 2', sub: 'Gironi' },
  { id: 'finale', label: 'Fase finale', sub: 'Semifinali e finale' },
]

// ── Fase 1 ────────────────────────────────────────────────────────────────
function ClassificaGirone({ squadre, qualificano }) {
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
            const qualificata = i < qualificano
            return (
              <tr key={sq.id_squadra} className={qualificata ? '' : 'opacity-60'}>
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

// Riga partita: stesso layout della pagina Calendario, espandibile sul
// dettaglio: voti se giocata, altrimenti la sola formazione.
function Partita({ p }) {
  const { stagione } = useApp()
  const { casa, ospite, giocata, giornata } = p
  const [open, setOpen] = useState(false)
  const espandibile = !!giornata

  const risultato = giocata ? {
    golf: casa.golf,
    gols: ospite.golf,
    ftotale_casa: casa.ftotale,
    ftotale_ospite: ospite.ftotale,
  } : null

  // Dettaglio caricato on-demand alla prima apertura
  const { data: dettaglio, loading, error } = useFetch(
    () => (open && espandibile)
      ? getDettaglioPartita(stagione, giornata, casa.id, 'CHAMP', ospite.id)
      : Promise.resolve(null),
    [open, espandibile, stagione, giornata, casa.id, ospite.id]
  )
  const match = dettaglio?.[0] ?? null

  return (
    <div className="border-b border-white/[0.03] last:border-0">
      <MatchResultRow
        casa={casa}
        ospite={ospite}
        risultato={risultato}
        expandable
        disabled={!espandibile}
        open={open}
        onToggle={() => setOpen(o => !o)}
      />
      {open && (
        <MatchDetailPanel casa={match?.casa} ospite={match?.ospite} loading={loading} error={error} />
      )}
    </div>
  )
}

function CalendarioGirone({ turni }) {
  return (
    <div>
      {turni.map(t => (
        <div key={`${t.giornata_camp}-${t.giornata}`} className="border-t border-white/5">
          <div className="px-4 py-1.5 bg-pitch-900/60 text-[11px] uppercase tracking-wider text-slate-500 flex justify-between">
            <span>{t.label}</span>
            <span>Giornata {t.giornata}</span>
          </div>
          {t.partite.map((p, i) => <Partita key={i} p={p} />)}
          {t.riposa?.map(r => (
            <div key={r.id} className="px-4 py-1.5 text-xs text-slate-600 text-center">
              Riposa: {r.nome}
            </div>
          ))}
        </div>
      ))}
    </div>
  )
}

// Fase a gironi (fase1 / fase2): stessa struttura, cambia la sezione richiesta.
function FaseGironi({ stagione, sezione, vuoto, nota }) {
  const { data, loading, error, refetch } = useFetch(
    () => getChampions(stagione, sezione),
    [stagione, sezione]
  )
  const gironi = Array.isArray(data) ? data : []

  if (loading) return <LoadingState />
  if (error) return <ErrorState message={error} onRetry={refetch} />
  if (!gironi.length) return <EmptyState label={vuoto} />

  return (
    <div className="grid gap-6 lg:grid-cols-2">
      {gironi.map(g => (
        <div key={g.girone} className="card overflow-hidden">
          <div className="px-4 py-3 border-b border-white/5">
            <h3 className="font-semibold text-slate-300">Girone {g.girone}</h3>
          </div>
          <ClassificaGirone squadre={g.squadre} qualificano={g.qualificano} />
          <CalendarioGirone turni={g.turni} />
        </div>
      ))}
      <p className="text-xs text-slate-600 lg:col-span-2">
        {nota} Parità in classifica: differenza reti, gol fatti, fantapunti totali.
      </p>
    </div>
  )
}

// ── Fase finale ───────────────────────────────────────────────────────────
function Semifinale({ t }) {
  const [a, b] = t.aggregato
  return (
    <div className="card overflow-hidden">
      <div className="px-4 py-2 bg-pitch-900/60 text-[11px] uppercase tracking-wider text-slate-500">Andata</div>
      {t.andata && <Partita p={t.andata} />}
      <div className="px-4 py-2 bg-pitch-900/60 text-[11px] uppercase tracking-wider text-slate-500 border-t border-white/5">Ritorno</div>
      {t.ritorno ? <Partita p={t.ritorno} /> : <p className="px-4 py-2.5 text-xs text-slate-600 text-center">Da definire</p>}
      {t.completa && a && b && (
        <div className="px-4 py-2.5 border-t border-white/5 text-center text-sm">
          <span className="text-slate-500">Aggregato </span>
          <span className="font-bold text-white text-display">{a.golf} - {b.golf}</span>
          {t.vincente
            ? <span className="text-grass-400"> · passa {t.vincente.nome}</span>
            : <span className="text-amber-400"> · parità</span>}
        </div>
      )}
    </div>
  )
}

function PartitaFinale({ titolo, p }) {
  if (!p) return null
  return (
    <div className="card overflow-hidden">
      <div className="px-4 py-2 bg-pitch-900/60 text-[11px] uppercase tracking-wider text-slate-500 flex justify-between">
        <span>{titolo}</span>
        {p.giornata && <span>Giornata {p.giornata}</span>}
      </div>
      <Partita p={p} />
    </div>
  )
}

function FaseFinale({ stagione }) {
  const { data, loading, error, refetch } = useFetch(
    () => getChampions(stagione, 'finale'),
    [stagione]
  )
  if (loading) return <LoadingState />
  if (error) return <ErrorState message={error} onRetry={refetch} />
  if (!data || (!data.semifinali?.length && !data.finale)) {
    return <EmptyState label="Fase finale non ancora definita per questa stagione" />
  }

  return (
    <div className="space-y-6">
      {data.campione && (
        <div className="card p-5 flex items-center gap-4 border border-grass-500/40">
          <TeamLogo logo={data.campione.logo} nome={data.campione.nome} size="lg" />
          <div>
            <div className="text-[11px] uppercase tracking-wider text-slate-500">Vincitore Champions</div>
            <div className="text-xl font-bold text-white text-display">{data.campione.nome}</div>
          </div>
        </div>
      )}

      {data.semifinali?.length > 0 && (
        <section>
          <h3 className="font-semibold text-slate-300 mb-3">Semifinali</h3>
          <div className="grid gap-4 lg:grid-cols-2">
            {data.semifinali.map((t, i) => <Semifinale key={i} t={t} />)}
          </div>
        </section>
      )}

      {data.finale && (
        <section>
          <h3 className="font-semibold text-slate-300 mb-3">Finale</h3>
          <div className="grid gap-4 lg:grid-cols-2">
            <PartitaFinale titolo="Finale" p={data.finale} />
            <PartitaFinale titolo="Replay" p={data.replay} />
          </div>
          {data.nota && <p className="text-sm text-amber-400 mt-3">{data.nota}</p>}
        </section>
      )}

      <p className="text-xs text-slate-600">
        Semifinali andata e ritorno (1ª girone A vs 2ª girone B, 1ª girone B vs 2ª girone A). In caso di parità
        nella finale si gioca il replay; se persiste, supplementari e calci di rigore.
      </p>
    </div>
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

      {fase === 'fase1' && (
        <FaseGironi stagione={stagione} sezione="fase1"
          vuoto="Nessun girone della prima fase per questa stagione"
          nota="Le prime tre di ogni girone accedono alla Fase 2; l'ultima classificata è eliminata." />
      )}
      {fase === 'fase2' && (
        <FaseGironi stagione={stagione} sezione="fase2"
          vuoto="Nessun girone della seconda fase per questa stagione"
          nota="Le prime due di ogni girone accedono alle semifinali; la terza è eliminata." />
      )}
      {fase === 'finale' && <FaseFinale stagione={stagione} />}

      <Note stagione={stagione} />
    </div>
  )
}
