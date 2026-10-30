import { useState } from 'react'
import { Link } from 'react-router-dom'
import { ChevronDown, ChevronRight, ArrowUp, ArrowDown, Minus, Info } from 'lucide-react'
import { useApp } from '../context/AppContext'
import { useFetch } from '../hooks/useFetch'
import { getFortunaCalendario } from '../api/client'
import { PageHeader, LoadingState, ErrorState, EmptyState, StatCard } from '../components/ui'
import TeamLogo from '../components/TeamLogo'

const fmtSigned = (v, d = 1) => `${v > 0 ? '+' : ''}${Number(v).toFixed(d)}`

// Verde = fortunata, rosso = sfortunata, grigio = neutra (entro ±0,5 punti)
function colFortuna(v) {
  if (v > 0.5)  return 'text-green-400'
  if (v < -0.5) return 'text-red-400'
  return 'text-slate-400'
}

function DeltaPosizione({ reale, senza }) {
  const d = reale - senza   // > 0: la classifica reale è PEGGIORE di quella "senza fortuna"
  if (d === 0) return <span className="inline-flex items-center text-slate-500"><Minus className="w-3 h-3" /></span>
  const sale = d > 0
  return (
    <span className={`inline-flex items-center gap-0.5 text-xs font-mono ${sale ? 'text-red-400' : 'text-green-400'}`}
          title={sale ? 'Sfavorita dal calendario' : 'Favorita dal calendario'}>
      {sale ? <ArrowDown className="w-3 h-3" /> : <ArrowUp className="w-3 h-3" />}
      {Math.abs(d)}
    </span>
  )
}

// Distribuzione delle posizioni finali nei calendari simulati
function DistribuzionePosizioni({ dist, reale }) {
  const max = Math.max(...dist, 0.0001)
  return (
    <div>
      <p className="text-xs text-slate-500 mb-2">Probabilità di chiudere in ciascuna posizione (calendari simulati)</p>
      <div className="flex items-end gap-1 h-24">
        {dist.map((p, i) => (
          <div key={i} className="flex-1 flex flex-col items-center justify-end h-full min-w-0">
            <span className="text-[9px] text-slate-500 font-mono mb-0.5">{p >= 0.005 ? Math.round(p * 100) : ''}</span>
            <div
              className={`w-full rounded-t ${i + 1 === reale ? 'bg-gold-400' : 'bg-grass-500/60'}`}
              style={{ height: `${Math.max(2, (p / max) * 70)}%` }}
              title={`${i + 1}° posto: ${(p * 100).toFixed(1)}%`}
            />
            <span className={`text-[10px] font-mono mt-1 ${i + 1 === reale ? 'text-gold-400 font-bold' : 'text-slate-600'}`}>{i + 1}</span>
          </div>
        ))}
      </div>
      <p className="text-[10px] text-slate-600 mt-1">In giallo la posizione reale. Valori in %.</p>
    </div>
  )
}

function DettaglioGiornate({ righe, nomi }) {
  return (
    <div className="overflow-x-auto">
      <table className="w-full text-xs">
        <thead>
          <tr className="text-slate-600 uppercase font-mono tracking-wider">
            <th className="text-left py-1 pr-3">G</th>
            <th className="text-left py-1 pr-3">Avversaria</th>
            <th className="text-center py-1 px-2">Pts</th>
            <th className="text-center py-1 px-2" title="Punti medi ottenibili contro tutte le avversarie">Media vs tutte</th>
            <th className="text-center py-1 px-2" title="Quota di avversarie battibili con quel punteggio">% vittorie</th>
            <th className="text-center py-1 pl-2">Fortuna</th>
          </tr>
        </thead>
        <tbody>
          {righe.map(r => {
            const f = r.punti_medi == null ? null : r.punti - r.punti_medi
            return (
              <tr key={r.giornata} className="border-t border-white/[0.04]">
                <td className="py-1 pr-3 font-mono text-slate-500">{r.giornata}</td>
                <td className="py-1 pr-3 text-slate-300">
                  <span className="text-slate-600 mr-1">{r.casa ? 'vs' : '@'}</span>{nomi?.[r.avversaria] ?? r.avversaria}
                </td>
                <td className="py-1 px-2 text-center font-mono text-slate-200">{r.punti}</td>
                <td className="py-1 px-2 text-center font-mono text-slate-400">{r.punti_medi == null ? '–' : r.punti_medi.toFixed(2)}</td>
                <td className="py-1 px-2 text-center font-mono text-slate-400">{r.vittorie_pct == null ? '–' : `${Math.round(r.vittorie_pct)}%`}</td>
                <td className={`py-1 pl-2 text-center font-mono ${f == null ? 'text-slate-600' : colFortuna(f)}`}>
                  {f == null ? '–' : fmtSigned(f, 2)}
                </td>
              </tr>
            )
          })}
        </tbody>
      </table>
    </div>
  )
}

export default function FortunaCalendario() {
  const { stagione } = useApp()
  const [aperta, setAperta] = useState(null)
  const [info, setInfo] = useState(false)

  const { data, loading, error, refetch } = useFetch(
    () => getFortunaCalendario(stagione),
    [stagione]
  )

  if (loading) return <LoadingState label="Simulazione dei calendari in corso..." />
  if (error)   return <ErrorState message={error} onRetry={refetch} />
  if (!data?.disponibile || !data?.squadre?.length) {
    return <EmptyState label="Nessuna giornata chiusa con risultati: impossibile misurare la fortuna del calendario" />
  }

  const squadre = data.squadre
  const piuFortunata  = [...squadre].sort((a, b) => b.fortuna - a.fortuna)[0]
  const piuSfortunata = [...squadre].sort((a, b) => a.fortuna - b.fortuna)[0]
  const maxAbs = Math.max(1, ...squadre.map(s => Math.abs(s.fortuna)))
  const verifica = data.verifica?.partite
    ? Math.round((100 * data.verifica.coerenti) / data.verifica.partite)
    : null

  return (
    <div className="animate-fade-up">
      <PageHeader
        label="Campionato"
        title="Fortuna del calendario"
        subtitle={`Stagione ${stagione} / ${stagione + 1} · ${data.giornate} giornate chiuse`}
      />

      <div className="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-6">
        <StatCard label="Più fortunata" value={fmtSigned(piuFortunata.fortuna)} sub={piuFortunata.squadra} accent />
        <StatCard label="Più sfortunata" value={fmtSigned(piuSfortunata.fortuna)} sub={piuSfortunata.squadra} />
        <StatCard label="Calendari simulati" value={data.simulazioni.toLocaleString('it-IT')} sub="permutazioni casuali" />
        <StatCard
          label="Ricostruzione risultati"
          value={verifica == null ? '–' : `${verifica}%`}
          sub={verifica == null ? '' : `${data.verifica.coerenti}/${data.verifica.partite} partite riprodotte`}
        />
      </div>

      <div className="card overflow-hidden">
        <div className="flex items-center justify-between gap-2 px-4 py-3 border-b border-white/5">
          <h2 className="text-sm font-semibold text-slate-300">Punti reali e punti attesi</h2>
          <button
            onClick={() => setInfo(v => !v)}
            className="flex items-center gap-1 text-xs text-slate-500 hover:text-slate-300 transition-colors"
          >
            <Info className="w-3.5 h-3.5" /> Come si legge
          </button>
        </div>

        {info && (
          <div className="px-4 py-3 text-xs text-slate-400 leading-relaxed border-b border-white/5 space-y-1.5 bg-white/[0.02]">
            <p><b className="text-slate-300">Punti attesi</b>: media dei punti che la squadra avrebbe ottenuto con le stesse formazioni schierate, assegnando le avversarie incontrate alle giornate in ogni ordine possibile.</p>
            <p><b className="text-slate-300">Fortuna</b> = punti reali − punti attesi. Positiva: il calendario ha favorito la squadra. Negativa: l'ha penalizzata.</p>
            <p><b className="text-slate-300">σ</b>: di quante deviazioni standard la squadra si discosta dai punti attesi (oltre ±1 è un caso poco comune, oltre ±2 molto raro).</p>
            <p><b className="text-slate-300">Percentile</b>: quota di calendari simulati in cui la squadra avrebbe fatto meno punti di quelli reali.</p>
            <p><b className="text-slate-300">Senza fortuna</b>: posizione che avrebbe la squadra in una classifica ordinata per punti attesi. La freccia indica di quanti posti il calendario l'ha spostata (verde: favorita, rossa: sfavorita).</p>
            <p><b className="text-slate-300">Titolo</b>: percentuale di calendari simulati in cui la squadra vince il campionato.</p>
            <p>Il fattore campo segue il campo realmente giocato dalla squadra in ogni giornata.</p>
          </div>
        )}

        <div className="overflow-x-auto">
          <table className="fanta-table min-w-[820px]">
            <thead>
              <tr>
                <th className="w-8"></th>
                <th className="w-10">#</th>
                <th>Squadra</th>
                <th className="text-center">Pts</th>
                <th className="text-center">Attesi</th>
                <th className="text-center">Fortuna</th>
                <th className="text-center">σ</th>
                <th className="text-center">Percentile</th>
                <th className="text-center">Senza fortuna</th>
                <th className="text-center">Titolo</th>
              </tr>
            </thead>
            <tbody>
              {squadre.map(s => {
                const open = aperta === s.id_squadra
                const barra = Math.min(100, (Math.abs(s.fortuna) / maxAbs) * 100)
                return [
                  <tr
                    key={s.id_squadra}
                    onClick={() => setAperta(open ? null : s.id_squadra)}
                    className="border-b border-white/[0.03] cursor-pointer hover:bg-white/[0.02] transition-colors"
                  >
                    <td className="pl-3 text-slate-600">
                      {open ? <ChevronDown className="w-4 h-4" /> : <ChevronRight className="w-4 h-4" />}
                    </td>
                    <td className="px-2 py-3 text-center text-display font-bold text-lg text-slate-500">{s.posizione_reale}</td>
                    <td className="px-4 py-3">
                      <Link
                        to={`/squadre/${s.id_squadra}`}
                        onClick={e => e.stopPropagation()}
                        className="flex items-center gap-3 group"
                      >
                        <TeamLogo logo={s.logo} nome={s.squadra} size="sm" />
                        <span className="font-medium text-slate-200 group-hover:text-grass-400 transition-colors text-sm">{s.squadra}</span>
                      </Link>
                    </td>
                    <td className="px-3 py-3 text-center text-display font-bold text-xl text-white">{s.punti_reali}</td>
                    <td className="px-3 py-3 text-center font-mono text-sm text-slate-400">{s.punti_attesi.toFixed(1)}</td>
                    <td className="px-3 py-3 min-w-[110px]">
                      <div className="flex flex-col items-center gap-1">
                        <span className={`font-mono text-sm font-bold ${colFortuna(s.fortuna)}`}>{fmtSigned(s.fortuna)}</span>
                        <div className="w-16 h-1 rounded bg-white/5 overflow-hidden">
                          <div
                            className={`h-full ${s.fortuna >= 0 ? 'bg-green-400/70' : 'bg-red-400/70'}`}
                            style={{ width: `${barra}%` }}
                          />
                        </div>
                      </div>
                    </td>
                    <td className={`px-3 py-3 text-center font-mono text-xs ${colFortuna(s.z)}`}>{fmtSigned(s.z, 2)}</td>
                    <td className="px-3 py-3 text-center font-mono text-xs text-slate-400">{Math.round(s.percentile)}°</td>
                    <td className="px-3 py-3 text-center">
                      <span className="inline-flex items-center gap-2">
                        <span className="font-mono text-sm text-slate-300">{s.posizione_senza_fortuna}°</span>
                        <DeltaPosizione reale={s.posizione_reale} senza={s.posizione_senza_fortuna} />
                      </span>
                    </td>
                    <td className="px-3 py-3 text-center font-mono text-xs text-slate-400">
                      {s.prob_titolo >= 0.005 ? `${Math.round(s.prob_titolo * 100)}%` : '–'}
                    </td>
                  </tr>,
                  open && (
                    <tr key={`${s.id_squadra}-d`} className="bg-white/[0.015]">
                      <td colSpan={10} className="px-4 py-4">
                        <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                          <div className="space-y-4">
                            <DistribuzionePosizioni dist={s.distribuzione_posizioni} reale={s.posizione_reale} />
                            <p className="text-xs text-slate-500 leading-relaxed">
                              Con questo calendario ha fatto <b className="text-slate-300">{s.punti_reali}</b> punti;
                              in media ne avrebbe fatti <b className="text-slate-300">{s.punti_attesi.toFixed(1)}</b> (±{s.dev_std.toFixed(1)}).
                              Posizione media nei calendari simulati: <b className="text-slate-300">{s.posizione_media.toFixed(1)}°</b>.
                            </p>
                          </div>
                          <DettaglioGiornate righe={s.dettaglio} nomi={data.nomi} />
                        </div>
                      </td>
                    </tr>
                  ),
                ]
              })}
            </tbody>
          </table>
        </div>
      </div>

      <p className="text-xs text-slate-600 mt-4 px-1">
        Considerate solo le giornate chiuse. Le simulazioni usano un seme fisso: a parità di dati il risultato non cambia fra una visita e l'altra.
      </p>
    </div>
  )
}
