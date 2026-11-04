import { useParams, Link } from 'react-router-dom'
import { useApp } from '../context/AppContext'
import { useFetch } from '../hooks/useFetch'
import { getSquadra } from '../api/client'
import { LoadingState, ErrorState, RoleBadge, PageHeader } from '../components/ui'
import { ArrowLeft, Wallet } from 'lucide-react'
import { Bandiera } from '../components/Bandiera'
import AlboTrofei from '../components/AlboTrofei'
import { CoachPhoto } from '../components/dashboard/shared'

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

  const crediti = data.crediti ?? null
  const residuoNegativo = crediti ? Number(crediti.residuo) < 0 : false

  return (
    <div className="animate-fade-up">
      <Link to="/squadre" className="btn-ghost mb-6 inline-flex">
        <ArrowLeft className="w-4 h-4" /> Tutte le squadre
      </Link>

      {/* Intestazione: nome squadra + albo (campionati, secondi posti, Champions, maglie nere) */}
      <PageHeader label="Dettaglio squadra" title={data.nome}>
        <AlboTrofei trofei={data.trofei} variant="tile" />
      </PageHeader>

      {/* Allenatore + crediti (per ruolo, totale, residuo) */}
      <div className="card p-4 sm:p-5 mb-6 flex flex-col lg:flex-row lg:items-center gap-5">
        <div className="flex items-center gap-4 lg:min-w-[16rem]">
          <CoachPhoto logo={data.logo_allenatore} nome={data.allenatore} className="w-20 h-20" />
          <div className="min-w-0">
            <p className="section-label !mb-0.5">Allenatore</p>
            <p className="text-base font-semibold text-slate-200 truncate">{data.allenatore || 'n.d.'}</p>
          </div>
        </div>

        {crediti && (
          <div className="flex-1 min-w-0 lg:border-l lg:border-white/5 lg:pl-5">
            <p className="flex items-center gap-1.5 text-xs font-mono tracking-widest uppercase text-slate-500 mb-2">
              <Wallet className="w-3.5 h-3.5" aria-hidden="true" /> Crediti
            </p>
            <div className="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-7 gap-2">
              {['1', '2', '3', '4'].map(r => (
                <div key={r} className="rounded-xl bg-white/[0.03] border border-white/5 px-3 py-2">
                  <div className="flex items-center gap-1.5 mb-0.5">
                    <RoleBadge ruolo={r} />
                    <span className="text-[10px] text-slate-500 truncate">{RUOLO_LABEL[r]}</span>
                  </div>
                  <p className="text-display text-2xl font-bold text-slate-200 leading-none">{crediti.per_ruolo?.[r] ?? 0}</p>
                </div>
              ))}
              <div className="rounded-xl bg-grass-500/10 border border-grass-500/20 px-3 py-2">
                <p className="text-[10px] font-mono uppercase tracking-wider text-slate-500 mb-0.5">Totale</p>
                <p className="text-display text-2xl font-bold text-grass-400 leading-none">{crediti.totale}</p>
                <p className="text-[10px] text-slate-600 mt-0.5">su {crediti.budget}</p>
              </div>
              <div className={`rounded-xl border px-3 py-2 ${
                residuoNegativo ? 'bg-red-500/10 border-red-500/30' : 'bg-gold-500/10 border-gold-500/20'}`}>
                <p className="text-[10px] font-mono uppercase tracking-wider text-slate-500 mb-0.5">Residuo</p>
                <p className={`text-display text-2xl font-bold leading-none ${residuoNegativo ? 'text-red-400' : 'text-gold-400'}`}>
                  {crediti.residuo}
                </p>
              </div>
            </div>
          </div>
        )}
      </div>

      {/* Rosa per ruolo */}
      <div className="space-y-6">
        {ruoli.map(r => (
          <div key={r} className="card overflow-hidden">
            <div className="px-4 py-3 border-b border-white/5 flex items-center gap-3">
              <RoleBadge ruolo={r} />
              <h3 className="text-sm font-semibold text-slate-300">{RUOLO_LABEL[r]}</h3>
              <span className="text-xs text-slate-600 font-mono">{grouped[r].length} giocatori</span>
              {crediti && (
                <span className="ml-auto text-xs font-mono text-grass-400">
                  {crediti.per_ruolo?.[r] ?? 0} crediti
                </span>
              )}
            </div>
            <div className="overflow-x-auto">
            <table className="fanta-table sticky-1 min-w-[900px]">
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
                  <th className="text-center whitespace-nowrap">{Number(r) === 1 ? 'Rigori parati' : 'Rigori segnati'}</th>
                  <th className="text-center whitespace-nowrap">Rigori sbagliati</th>
                </tr>
              </thead>
              <tbody>
                {grouped[r].map(g => (
                  <tr key={g.id} className={g.ceduto ? 'opacity-60' : ''}>
                    <td className="px-4 py-2.5">
                      <span className="inline-flex items-center gap-1.5">
                        <Bandiera id={g.id} />
                        <span className={`text-sm font-medium ${g.ceduto ? 'text-slate-500 line-through decoration-slate-600' : 'text-slate-300'}`}>{g.descrizione}</span>
                        {g.ceduto && (
                          <span
                            className="text-[10px] font-mono uppercase tracking-wider px-1.5 py-0.5 rounded bg-red-500/10 border border-red-500/20 text-red-400"
                            title="Non è più nel listone: non è utilizzabile in formazione"
                          >
                            Giocatore ceduto
                          </span>
                        )}
                      </span>
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
                      {Number(Number(r) === 1 ? g.rigori_parati : g.rigori_segnati) > 0
                        ? <span className="font-mono text-xs text-slate-300">{Number(r) === 1 ? g.rigori_parati : g.rigori_segnati}</span>
                        : <span className="text-slate-700 text-xs">—</span>}
                    </td>
                    <td className="px-4 py-2.5 text-center">
                      {Number(g.rigori_sbagliati) > 0
                        ? <span className="font-mono text-xs text-red-400">{g.rigori_sbagliati}</span>
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
