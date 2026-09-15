// ── Marcatori ──────────────────────────────────────────────────────────────
import { useState } from 'react'
import { useApp } from '../context/AppContext'
import { useFetch } from '../hooks/useFetch'
import { getMarcatori } from '../api/client'
import { PageHeader, LoadingState, ErrorState, EmptyState, RoleBadge, TabBar } from '../components/ui'

const TABS = [
  { value: 'marcatori', label: '⚽ Gol' },
  { value: 'assist',    label: '🎯 Assist' },
  { value: 'migliori',  label: '⭐ Migliori' },
  { value: 'peggiori',  label: '📉 Peggiori' },
]

export default function Marcatori() {
  const { stagione } = useApp()
  const [tipo, setTipo] = useState('marcatori')

  const { data, loading, error, refetch } = useFetch(
    () => getMarcatori(stagione, tipo, 30),
    [stagione, tipo]
  )

  const colLabel = { marcatori: 'Gol', assist: 'Assist', migliori: 'Media', peggiori: 'Media' }
  const colKey   = { marcatori: 'gols', assist: 'assist', migliori: 'media', peggiori: 'media' }

  return (
    <div className="animate-fade-up">
      <PageHeader label="Classifiche individuali" title="Marcatori" />

      <div className="mb-6 overflow-x-auto">
        <TabBar tabs={TABS} active={tipo} onChange={setTipo} />
      </div>

      {loading ? <LoadingState /> : error ? <ErrorState message={error} onRetry={refetch} /> : !data?.length ? <EmptyState /> : (
        <div className="card overflow-hidden">
          <div className="overflow-x-auto">
          <table className="fanta-table min-w-[560px]">
            <thead>
              <tr>
                <th className="w-10">#</th>
                <th>Giocatore</th>
                <th>Squadra</th>
                <th className="text-center">R</th>
                <th className="text-center">Pres.</th>
                <th className="text-center">{colLabel[tipo]}</th>
              </tr>
            </thead>
            <tbody>
              {data.map((g, i) => (
                <tr key={i}>
                  <td className="px-4 py-3 text-center">
                    <span className={`text-display font-bold ${i < 3 ? 'text-gold-400 text-lg' : 'text-slate-600 text-sm'}`}>{i + 1}</span>
                  </td>
                  <td className="px-4 py-3 font-medium text-slate-200 text-sm">{g.giocatore}</td>
                  <td className="px-4 py-3 text-xs text-slate-500">{g.squadra}</td>
                  <td className="px-4 py-3 text-center"><RoleBadge ruolo={g.ruolo} /></td>
                  <td className="px-4 py-3 text-center font-mono text-xs text-slate-500">{g.giocate}</td>
                  <td className="px-4 py-3 text-center">
                    <span className="font-mono text-sm font-semibold text-grass-400">
                      {colKey[tipo] === 'media' ? Number(g[colKey[tipo]]).toFixed(2) : g[colKey[tipo]]}
                    </span>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
          </div>
        </div>
      )}
    </div>
  )
}
