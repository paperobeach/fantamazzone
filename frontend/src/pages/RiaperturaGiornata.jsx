import { useState } from 'react'
import { useApp } from '../context/AppContext'
import { useFetch } from '../hooks/useFetch'
import { getStatoRiapertura, adminRiapriGiornata } from '../api/client'
import { PageHeader, Spinner, ErrorState } from '../components/ui'
import { RotateCcw, CheckCircle2, XCircle, AlertTriangle, Lock } from 'lucide-react'

export default function RiaperturaGiornata() {
  const { stagione } = useApp()

  const { data: stato, loading, error, refetch } = useFetch(
    stagione ? () => getStatoRiapertura(Number(stagione)) : null,
    [stagione],
  )

  const [conferma,  setConferma]  = useState(false)
  const [riaprendo, setRiaprendo] = useState(false)
  const [errore,    setErrore]    = useState(null)
  const [risultato, setRisultato] = useState(null)

  const riapri = async () => {
    setErrore(null)
    setRisultato(null)
    setRiaprendo(true)
    try {
      const res = await adminRiapriGiornata(Number(stagione), stato.ultima_chiusa)
      setRisultato(res)
      setConferma(false)
      refetch()
    } catch (err) {
      setErrore(err.message)
    } finally {
      setRiaprendo(false)
    }
  }

  const g    = stato?.ultima_chiusa
  const prec = stato?.giornata_precedente

  return (
    <div className="animate-fade-up">
      <PageHeader
        label="Gestione"
        title="Riapri giornata"
        subtitle="Annulla la chiusura dell'ultima giornata chiusa, per correggere voti o risultati e richiuderla."
      />

      {error ? (
        <ErrorState message={error} onRetry={refetch} />
      ) : loading && !stato ? (
        <div className="flex justify-center py-16"><Spinner size="lg" /></div>
      ) : stato && (
        <div className="card p-6 max-w-2xl">
          {stato.motivi_blocco.length > 0 ? (
            <div className="rounded-lg px-4 py-3 flex gap-3 bg-gold-500/5 border border-gold-500/20 text-gold-100 text-sm">
              <Lock className="w-4 h-4 text-gold-400 flex-shrink-0 mt-0.5" />
              <ul className="space-y-0.5">{stato.motivi_blocco.map((m, i) => <li key={i}>{m}</li>)}</ul>
            </div>
          ) : (
            <>
              <h3 className="text-sm font-semibold text-slate-300 mb-1">
                Ultima giornata chiusa: {g}
              </h3>
              <p className="text-xs text-slate-500 mb-4">
                La riapertura ricostruisce statistiche giocatori, classifica generale e Top/Flop 11
                {prec > 0 ? ` considerando i dati fino alla giornata ${prec}` : ' (non esistono giornate precedenti: verranno svuotate)'},
                poi rimuove il flag di chiusura: la giornata corrente torna ad essere la {g}.
                I voti e i risultati della giornata {g} non vengono cancellati: potrai correggerli,
                ricalcolare (fase 2) e chiudere di nuovo.
              </p>

              {stato.avvisi.length > 0 && (
                <div className="rounded-lg px-4 py-3 flex gap-3 bg-gold-500/5 border border-gold-500/20 text-gold-100 text-sm mb-4">
                  <AlertTriangle className="w-4 h-4 text-gold-400 flex-shrink-0 mt-0.5" />
                  <ul className="space-y-0.5">{stato.avvisi.map((a, i) => <li key={i}>{a}</li>)}</ul>
                </div>
              )}

              <label className="flex items-start gap-2 text-xs text-slate-400 mb-4 cursor-pointer">
                <input
                  type="checkbox"
                  checked={conferma}
                  onChange={e => setConferma(e.target.checked)}
                  className="mt-0.5"
                />
                Confermo la riapertura della giornata {g}: le classifiche torneranno allo stato della giornata {prec ?? 0}.
              </label>
              <button
                type="button"
                onClick={riapri}
                disabled={riaprendo || !conferma}
                className="btn-primary disabled:opacity-40"
              >
                {riaprendo
                  ? <><Spinner size="sm" /> Riapertura in corso...</>
                  : <><RotateCcw className="w-4 h-4" /> Riapri giornata {g}</>}
              </button>
            </>
          )}

          {errore && (
            <div className="rounded-lg px-4 py-3 flex gap-3 bg-red-500/5 border border-red-500/20 text-red-200 text-sm mt-4">
              <AlertTriangle className="w-4 h-4 text-red-400 flex-shrink-0 mt-0.5" /> {errore}
            </div>
          )}

          {risultato && (
            <div className="mt-4">
              <div className={`flex items-center gap-2 mb-2 ${risultato.ok ? 'text-green-300' : 'text-red-300'}`}>
                {risultato.ok ? <CheckCircle2 className="w-4 h-4" /> : <XCircle className="w-4 h-4" />}
                <span className="font-medium text-sm">
                  {risultato.ok
                    ? `Giornata ${risultato.giornata} riaperta: è di nuovo la giornata corrente`
                    : risultato.errore}
                </span>
              </div>
              <ul className="space-y-1 text-sm">
                {risultato.passi.map(p => (
                  <li key={p.codice} className="flex items-center gap-2 text-slate-300">
                    {p.ok ? <CheckCircle2 className="w-4 h-4 text-green-400" /> : <XCircle className="w-4 h-4 text-red-400" />}
                    {p.etichetta}
                    <span className="text-xs text-slate-500">{p.ok ? p.dettaglio : p.errore}</span>
                  </li>
                ))}
              </ul>
              {risultato.avvisi?.length > 0 && (
                <div className="rounded-lg px-4 py-3 bg-gold-500/5 border border-gold-500/20 text-gold-100 text-sm mt-3">
                  <p className="font-medium mb-1">Avvisi:</p>
                  <ul className="list-disc list-inside space-y-0.5">
                    {risultato.avvisi.map((w, i) => <li key={i}>{w}</li>)}
                  </ul>
                </div>
              )}
            </div>
          )}
        </div>
      )}
    </div>
  )
}
