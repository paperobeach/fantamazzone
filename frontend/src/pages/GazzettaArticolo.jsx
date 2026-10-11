import { Link, useParams } from 'react-router-dom'
import { ArrowLeft } from 'lucide-react'
import { useFetch } from '../hooks/useFetch'
import { getArticolo } from '../api/client'
import { LoadingState, ErrorState } from '../components/ui'
import ArticoloTesto from '../components/ArticoloTesto'
import { dataEstesa } from './Gazzetta'

// ============================================================
// Dettaglio di un articolo pubblicato della Gazzetta.
// ============================================================

export default function GazzettaArticolo() {
  const { id } = useParams()
  const { data, loading, error, refetch } = useFetch(() => getArticolo(Number(id)), [id])

  if (loading) return <LoadingState label="Caricamento articolo..." />
  if (error)   return <ErrorState message={error} onRetry={refetch} />
  if (!data)   return null

  return (
    <div className="animate-fade-up max-w-3xl">
      <Link to="/gazzetta" className="inline-flex items-center gap-1.5 text-sm text-slate-500 hover:text-slate-300 mb-5">
        <ArrowLeft className="w-4 h-4" /> Tutti gli articoli
      </Link>

      <article className="card p-6 sm:p-8">
        <p className="text-[11px] text-mono tracking-widest uppercase text-slate-600">
          Stagione {data.stagione} · Giornata {data.giornata}
          {data.data_pubblicazione && ` · ${dataEstesa(data.data_pubblicazione)}`}
        </p>
        <h1 className="text-2xl font-bold text-slate-100 leading-snug mt-2">{data.titolo}</h1>
        {data.sommario && <p className="text-base italic text-slate-400 mt-3">{data.sommario}</p>}
        <div className="mt-6 border-t border-white/5 pt-6">
          <ArticoloTesto testo={data.testo} />
        </div>
        <p className="mt-8 text-[11px] text-slate-600">
          Articolo scritto con l'aiuto dell'intelligenza artificiale a partire dai dati della giornata:
          citazioni e battute sono di fantasia.
        </p>
      </article>
    </div>
  )
}
