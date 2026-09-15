// ── Champions ──────────────────────────────────────────────────────────────
import { useApp } from '../context/AppContext'
import { useFetch } from '../hooks/useFetch'
import { getChampions } from '../api/client'
import { PageHeader, LoadingState, ErrorState, EmptyState } from '../components/ui'
import { useState } from 'react'

export default function Champions() {
  const { stagione } = useApp()
  const [sezione, setSezione] = useState('classifica')

  const { data, loading, error, refetch } = useFetch(
    () => getChampions(stagione, sezione),
    [stagione, sezione]
  )

  const list = Array.isArray(data) ? data : []

  // Raggruppa per girone
  const gironi = list.reduce((acc, sq) => {
    const g = sq.girone ?? 'A'
    if (!acc[g]) acc[g] = []
    acc[g].push(sq)
    return acc
  }, {})

  return (
    <div className="animate-fade-up">
      <PageHeader label="Coppa" title="Champions League" />

      <div className="flex gap-2 mb-6 overflow-x-auto">
        {['classifica', 'gironi', 'note'].map(s => (
          <button
            key={s}
            onClick={() => setSezione(s)}
            className={`px-4 py-1.5 rounded-lg text-sm font-medium transition-all capitalize ${
              sezione === s ? 'bg-grass-500 text-pitch-950' : 'bg-pitch-900 text-slate-400 hover:text-slate-200 border border-white/5'
            }`}
          >
            {s}
          </button>
        ))}
      </div>

      {loading ? <LoadingState /> : error ? <ErrorState message={error} onRetry={refetch} /> :
        !list.length ? <EmptyState /> : (
          <div className="space-y-6">
            {Object.entries(gironi).map(([g, squadre]) => (
              <div key={g} className="card overflow-hidden">
                <div className="px-4 py-3 border-b border-white/5">
                  <h3 className="font-semibold text-slate-300">Girone {g}</h3>
                </div>
                <div className="overflow-x-auto">
                <table className="fanta-table min-w-[420px]">
                  <thead>
                    <tr>
                      <th>#</th><th>Squadra</th>
                      <th className="text-center">GF</th><th className="text-center">GS</th>
                      <th className="text-center">Pts</th>
                    </tr>
                  </thead>
                  <tbody>
                    {[...squadre].sort((a,b) => b.punti - a.punti).map((sq, i) => (
                      <tr key={i}>
                        <td className="px-4 py-2.5 text-slate-600 font-mono text-xs">{i+1}</td>
                        <td className="px-4 py-2.5 text-slate-200 font-medium text-sm">{sq.nome}</td>
                        <td className="px-4 py-2.5 text-center text-slate-400 text-sm">{sq.golf}</td>
                        <td className="px-4 py-2.5 text-center text-slate-500 text-sm">{sq.gols}</td>
                        <td className="px-4 py-2.5 text-center">
                          <span className="font-bold text-display text-lg text-white">{sq.punti}</span>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
                </div>
              </div>
            ))}
          </div>
        )
      }
    </div>
  )
}
