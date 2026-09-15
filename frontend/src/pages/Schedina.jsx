import { useState } from 'react'
import { useApp } from '../context/AppContext'
import { useFetch } from '../hooks/useFetch'
import { getSchedina, getClassificaSchedina } from '../api/client'
import { PageHeader, LoadingState, ErrorState, EmptyState, TabBar } from '../components/ui'
import { Trophy } from 'lucide-react'

export default function Schedina() {
  const { stagione, sistemaParams } = useApp()
  const [tab, setTab] = useState('pronostici')
  const giornata = Number(sistemaParams?.giornata_corrente ?? 1)

  const { data: schedine, loading: lS, error: eS, refetch: rS } = useFetch(
    () => tab === 'pronostici' ? getSchedina(stagione, giornata) : getClassificaSchedina(stagione),
    [stagione, giornata, tab]
  )

  const TABS = [
    { value: 'pronostici', label: 'Pronostici' },
    { value: 'classifica', label: 'Classifica' },
  ]

  return (
    <div className="animate-fade-up">
      <PageHeader label="Mini-gioco" title="Schedina" subtitle={`Giornata ${giornata}`} />

      <div className="mb-6 overflow-x-auto">
        <TabBar tabs={TABS} active={tab} onChange={setTab} />
      </div>

      {lS ? <LoadingState /> : eS ? <ErrorState message={eS} onRetry={rS} /> :
        !schedine?.length ? <EmptyState label="Nessun pronostico per questa giornata" /> : (

          tab === 'classifica' ? (
            <div className="card overflow-hidden">
              <div className="overflow-x-auto">
              <table className="fanta-table min-w-[420px]">
                <thead><tr><th className="w-10">#</th><th>Allenatore</th><th className="text-center">Punti</th></tr></thead>
                <tbody>
                  {[...schedine].sort((a,b) => b.PUNTI - a.PUNTI).map((s, i) => (
                    <tr key={i}>
                      <td className="px-4 py-3 text-center">
                        {i < 3
                          ? <Trophy className={`w-4 h-4 mx-auto ${i===0?'text-gold-400':i===1?'text-slate-400':'text-amber-700'}`} />
                          : <span className="text-slate-600 text-sm font-mono">{i+1}</span>}
                      </td>
                      <td className="px-4 py-3 text-slate-200 font-medium text-sm">{s.allenatore}</td>
                      <td className="px-4 py-3 text-center">
                        <span className="font-bold text-display text-lg text-grass-400">{s.PUNTI}</span>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
              </div>
            </div>
          ) : (
            /* Raggruppa pronostici per allenatore */
            (() => {
              const byUser = schedine.reduce((acc, s) => {
                const k = s.allenatore ?? s.ID
                if (!acc[k]) acc[k] = []
                acc[k].push(s)
                return acc
              }, {})
              return (
                <div className="space-y-4">
                  {Object.entries(byUser).map(([nome, ps]) => (
                    <div key={nome} className="card overflow-hidden">
                      <div className="px-4 py-3 border-b border-white/5">
                        <h3 className="text-sm font-semibold text-slate-300">{nome}</h3>
                      </div>
                      <div className="divide-y divide-white/[0.03]">
                        {ps.map((p, i) => (
                          <div key={i} className="flex items-center gap-2 sm:gap-4 px-4 py-2.5 text-sm">
                            <span className="flex-1 min-w-0 truncate text-right text-slate-400">{p.nome_squadra_1}</span>
                            <span className={`flex-shrink-0 w-8 h-8 rounded-lg flex items-center justify-center font-bold text-display ${
                              p.PRONOSTICO === '1' ? 'bg-green-500/15 text-green-400' :
                              p.PRONOSTICO === 'X' ? 'bg-yellow-500/15 text-yellow-400' :
                                                     'bg-blue-500/15 text-blue-400'
                            }`}>{p.PRONOSTICO}</span>
                            <span className="flex-1 min-w-0 truncate text-slate-400">{p.nome_squadra_2}</span>
                          </div>
                        ))}
                      </div>
                    </div>
                  ))}
                </div>
              )
            })()
          )
        )
      }
    </div>
  )
}
