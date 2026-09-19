import { useParams, Link } from 'react-router-dom'
import { useApp } from '../context/AppContext'
import { useFetch } from '../hooks/useFetch'
import { getSquadra } from '../api/client'
import { LoadingState, ErrorState, RoleBadge, PageHeader } from '../components/ui'
import { ArrowLeft, Trophy } from 'lucide-react'

const RUOLO_ORDER = { '1': 0, '2': 1, '3': 2, '4': 3 }
const RUOLO_LABEL = { '1': 'Portieri', '2': 'Difensori', '3': 'Centrocampisti', '4': 'Attaccanti' }

export default function SquadraDetail() {
  const { id }     = useParams()
  const { stagione } = useApp()

  const { data, loading, error } = useFetch(
    () => getSquadra(stagione, id),
    [stagione, id]
  )

  if (loading) return <LoadingState label="Caricamento squadra..." />
  if (error)   return <ErrorState message={error} />
  if (!data)   return null

  // Raggruppa rosa per ruolo
  const grouped = (data.rosa ?? []).reduce((acc, g) => {
    const r = String(g.ruolo)
    if (!acc[r]) acc[r] = []
    acc[r].push(g)
    return acc
  }, {})

  const ruoli = Object.keys(grouped).sort((a, b) => RUOLO_ORDER[a] - RUOLO_ORDER[b])

  return (
    <div className="animate-fade-up">
      <Link to="/squadre" className="btn-ghost mb-6 inline-flex">
        <ArrowLeft className="w-4 h-4" /> Tutte le squadre
      </Link>

      <PageHeader
        label="Dettaglio squadra"
        title={data.nome}
        subtitle={data.allenatore ? `Allenatore: ${data.allenatore}` : undefined}
      >
        {data.albo && Number(data.albo) > 0 && (
          <div className="flex items-center gap-2 px-4 py-2 rounded-xl bg-gold-500/10 border border-gold-500/20">
            <Trophy className="w-4 h-4 text-gold-400" />
            <span className="text-sm font-medium text-gold-400">{data.albo} titoli</span>
          </div>
        )}
      </PageHeader>

      {/* Rosa per ruolo */}
      <div className="space-y-6">
        {ruoli.map(r => (
          <div key={r} className="card overflow-hidden">
            <div className="px-4 py-3 border-b border-white/5 flex items-center gap-3">
              <RoleBadge ruolo={r} />
              <h3 className="text-sm font-semibold text-slate-300">{RUOLO_LABEL[r]}</h3>
              <span className="text-xs text-slate-600 font-mono">{grouped[r].length} giocatori</span>
            </div>
            <div className="overflow-x-auto">
            <table className="fanta-table min-w-[820px]">
              <thead>
                <tr>
                  <th className="whitespace-nowrap">Giocatore</th>
                  <th className="text-center whitespace-nowrap">Crediti</th>
                  <th className="whitespace-nowrap">Nazione</th>
                  <th className="text-center whitespace-nowrap">Presenze</th>
                  <th className="text-center whitespace-nowrap">Media</th>
                  <th className="text-center whitespace-nowrap">Gol</th>
                  <th className="text-center whitespace-nowrap">Assist</th>
                  <th className="text-center whitespace-nowrap">Amm.</th>
                  <th className="text-center whitespace-nowrap">Esp.</th>
                  <th className="text-center whitespace-nowrap">Autogol</th>
                  <th className="text-center whitespace-nowrap">Rigori</th>
                </tr>
              </thead>
              <tbody>
                {grouped[r].map(g => (
                  <tr key={g.id}>
                    <td className="px-4 py-2.5">
                      <span className="text-sm text-slate-300 font-medium">{g.descrizione}</span>
                    </td>
                    <td className="px-4 py-2.5 text-center">
                      <span className="font-mono text-xs text-grass-400 font-semibold">{g.crediti}</span>
                    </td>
                    <td className="px-4 py-2.5">
                      <span className="text-xs text-slate-600">{g.nazione}</span>
                    </td>
                    <td className="px-4 py-2.5 text-center">
                      <span className="font-mono text-xs text-slate-400">{g.presenze}</span>
                    </td>
                    <td className="px-4 py-2.5 text-center">
                      <span className="font-mono text-xs text-slate-300 font-semibold">
                        {Number(g.presenze) > 0 ? Number(g.media).toFixed(2) : '—'}
                      </span>
                    </td>
                    <td className="px-4 py-2.5 text-center">
                      {Number(g.gol) > 0
                        ? <span className="font-mono text-xs font-semibold text-grass-400">{g.gol}</span>
                        : <span className="text-slate-700 text-xs">—</span>}
                    </td>
                    <td className="px-4 py-2.5 text-center">
                      {Number(g.assist) > 0
                        ? <span className="font-mono text-xs text-blue-400">{g.assist}</span>
                        : <span className="text-slate-700 text-xs">—</span>}
                    </td>
                    <td className="px-4 py-2.5 text-center">
                      {Number(g.ammonizioni) > 0
                        ? <span className="font-mono text-xs text-yellow-400">{g.ammonizioni}</span>
                        : <span className="text-slate-700 text-xs">—</span>}
                    </td>
                    <td className="px-4 py-2.5 text-center">
                      {Number(g.espulsioni) > 0
                        ? <span className="font-mono text-xs text-red-400">{g.espulsioni}</span>
                        : <span className="text-slate-700 text-xs">—</span>}
                    </td>
                    <td className="px-4 py-2.5 text-center">
                      {Number(g.autogol) > 0
                        ? <span className="font-mono text-xs text-red-400">{g.autogol}</span>
                        : <span className="text-slate-700 text-xs">—</span>}
                    </td>
                    <td className="px-4 py-2.5 text-center">
                      {Number(g.rigori) > 0
                        ? <span className="font-mono text-xs text-slate-300">{g.rigori}</span>
                        : <span className="text-slate-700 text-xs">—</span>}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
            </div>
          </div>
        ))}
      </div>
    </div>
  )
}
