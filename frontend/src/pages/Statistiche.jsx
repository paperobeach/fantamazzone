import { useState } from 'react'
import { useApp } from '../context/AppContext'
import { useFetch } from '../hooks/useFetch'
import { getStatistiche, getSquadre } from '../api/client'
import { PageHeader, LoadingState, ErrorState, EmptyState, RoleBadge, TabBar, Select } from '../components/ui'

const ORDINI = [
  { value: 'media',       label: 'Media voto' },
  { value: 'gols',        label: 'Gol' },
  { value: 'assist',      label: 'Assist' },
  { value: 'giocate',     label: 'Presenze' },
  { value: 'ammonizioni', label: 'Ammonizioni' },
]

const RUOLI = [
  { value: '',  label: 'Tutti i ruoli' },
  { value: '1', label: 'Portieri' },
  { value: '2', label: 'Difensori' },
  { value: '3', label: 'Centrocampisti' },
  { value: '4', label: 'Attaccanti' },
]

export default function Statistiche() {
  const { stagione } = useApp()
  const [ruolo,  setRuolo]  = useState('')
  const [ordine, setOrdine] = useState('media')
  const [squadraFilter, setSquadraFilter] = useState('')

  const { data: squadre } = useFetch(() => getSquadre(stagione), [stagione])

  const { data, loading, error, refetch } = useFetch(
    () => getStatistiche(stagione, {
      ...(ruolo  ? { ruolo }       : {}),
      ...(ordine ? { ordine }      : {}),
      ...(squadraFilter ? { id_squadra: squadraFilter } : {}),
    }),
    [stagione, ruolo, ordine, squadraFilter]
  )

  const squadreOpts = [
    { value: '', label: 'Tutte le squadre' },
    ...(squadre ?? []).map(s => ({ value: s.id, label: s.nome }))
  ]

  if (loading) return <LoadingState label="Caricamento statistiche..." />
  if (error)   return <ErrorState message={error} onRetry={refetch} />
  if (!data?.length) return (
    <>
      <PageHeader label="Giocatori" title="Statistiche" />
      <EmptyState label="Nessuna statistica disponibile" />
    </>
  )

  return (
    <div className="animate-fade-up">
      <PageHeader label="Giocatori" title="Statistiche" subtitle={`${data.length} giocatori`} />

      {/* Filters */}
      <div className="flex flex-wrap gap-3 mb-6">
        <div className="overflow-x-auto max-w-full">
          <TabBar
            tabs={RUOLI}
            active={ruolo}
            onChange={setRuolo}
          />
        </div>
        <Select
          value={ordine}
          onChange={setOrdine}
          options={ORDINI}
          className="w-full sm:w-44"
        />
        <Select
          value={squadraFilter}
          onChange={setSquadraFilter}
          options={squadreOpts}
          className="w-full sm:w-52"
        />
      </div>

      {/* Table */}
      <div className="card overflow-hidden">
        <div className="overflow-x-auto">
        <table className="fanta-table min-w-[720px]">
          <thead>
            <tr>
              <th className="w-8">#</th>
              <th>Giocatore</th>
              <th>Squadra</th>
              <th className="text-center">R</th>
              <th className="text-center">Pres.</th>
              <th className="text-center">Media</th>
              <th className="text-center">Gol</th>
              <th className="text-center">Assist</th>
              <th className="text-center">Amm.</th>
              <th className="text-center">Esp.</th>
            </tr>
          </thead>
          <tbody>
            {data.map((g, i) => (
              <tr key={`${g.id_giocatore}-${g.id_squadra}`}>
                <td className="px-4 py-2.5 text-center text-slate-700 font-mono text-xs">{i + 1}</td>
                <td className="px-4 py-2.5">
                  <span className="font-medium text-slate-200 text-sm">{g.giocatore}</span>
                </td>
                <td className="px-4 py-2.5">
                  <span className="text-xs text-slate-500">{g.squadra}</span>
                </td>
                <td className="px-4 py-2.5 text-center"><RoleBadge ruolo={g.ruolo} /></td>
                <td className="px-4 py-2.5 text-center text-slate-400 font-mono text-xs">{g.giocate}</td>
                <td className="px-4 py-2.5 text-center">
                  <span className={`font-mono text-xs font-semibold ${
                    Number(g.media) >= 7 ? 'text-green-400' :
                    Number(g.media) >= 6 ? 'text-slate-300' :
                    'text-red-400'
                  }`}>{Number(g.media).toFixed(2)}</span>
                </td>
                <td className="px-4 py-2.5 text-center">
                  {Number(g.gols) > 0
                    ? <span className="font-mono text-xs font-semibold text-grass-400">{g.gols}</span>
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
              </tr>
            ))}
          </tbody>
        </table>
        </div>
      </div>
    </div>
  )
}
