import { useState, useEffect, useCallback } from 'react'
import { Link } from 'react-router-dom'
import { CheckCircle2, AlertTriangle, RefreshCw, SlidersHorizontal } from 'lucide-react'
import { getStatoCalendariStagione, adminCreaCalendariStagione } from '../api/client'
import { Spinner } from './ui'

// ============================================================
// "Inizializzazione stagione" — PASSO 2: Creazione calendari
//
// Da eseguire dopo il passo 1 (creazione utenze) e dopo aver
// controllato tutti i parametri della stagione in "Gestisci regole
// di calcolo". Il numero di giornate e le giornate Champions non si
// inseriscono qui: sono lette dalla configurazione di stagione.
// Prima di creare i calendari l'utente deve confermare di aver
// verificato i parametri.
// ============================================================

export function CreazioneCalendari({ stagione: stagioneIniziale }) {
  const [stagione, setStagione] = useState(stagioneIniziale ? String(stagioneIniziale) : '')
  const [stato, setStato]       = useState(null)   // risposta GET ?fase=calendari
  const [loading, setLoading]   = useState(false)
  const [saving, setSaving]     = useState(false)
  const [confermato, setConfermato] = useState(false)
  const [result, setResult]     = useState(null)
  const [errorMsg, setErrorMsg] = useState(null)
  const [erroriVal, setErroriVal] = useState(null)

  const stagioneValida = /^\d{4}$/.test(stagione)

  // Allinea la stagione a quella lavorata nel passo 1
  useEffect(() => {
    if (stagioneIniziale) setStagione(String(stagioneIniziale))
  }, [stagioneIniziale])

  const verifica = useCallback(async () => {
    if (!/^\d{4}$/.test(stagione)) return
    setLoading(true)
    setErrorMsg(null)
    setErroriVal(null)
    setResult(null)
    setConfermato(false)
    try {
      setStato(await getStatoCalendariStagione(Number(stagione)))
    } catch (e) {
      setStato(null)
      setErrorMsg(e.message)
    } finally {
      setLoading(false)
    }
  }, [stagione])

  // Nuova stagione → lo stato mostrato non è più valido
  useEffect(() => { setStato(null); setResult(null); setConfermato(false) }, [stagione])

  const crea = async () => {
    setSaving(true)
    setErrorMsg(null)
    setErroriVal(null)
    setResult(null)
    try {
      const res = await adminCreaCalendariStagione(Number(stagione))
      setResult(res)
      setConfermato(false)
      setStato(await getStatoCalendariStagione(Number(stagione)))
    } catch (e) {
      if (e.code === 'VALIDATION_ERROR' && e.details) setErroriVal(e.details.map(d => d.messaggio))
      else setErrorMsg(e.message)
    } finally {
      setSaving(false)
    }
  }

  const sostituisce = stato && (stato.calendario_righe > 0 || stato.calendario_champ_righe > 0)

  return (
    <div className="card p-6">
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-5">
        <div>
          <h3 className="font-semibold text-slate-200 mb-1">Passo 2 · Creazione calendari</h3>
          <p className="text-xs text-slate-600">
            Genera il calendario del campionato e quello della Champions leggendo la configurazione della stagione.
          </p>
        </div>
        <div className="flex items-center gap-2">
          <input
            type="text" inputMode="numeric" maxLength={4}
            value={stagione}
            onChange={e => setStagione(e.target.value.replace(/\D/g, ''))}
            placeholder="Stagione (es. 2027)"
            className="fanta-input w-40"
          />
          <button onClick={verifica} disabled={!stagioneValida || loading} className="btn-primary text-sm disabled:opacity-40">
            {loading ? <Spinner size="sm" /> : <RefreshCw className="w-3.5 h-3.5" />} Verifica
          </button>
        </div>
      </div>

      {/* Avviso prima del passo 2 */}
      <div className="rounded-lg px-4 py-3 flex gap-3 bg-yellow-500/5 border border-yellow-500/20 text-yellow-200 text-sm mb-5">
        <AlertTriangle className="w-4 h-4 text-yellow-400 flex-shrink-0 mt-0.5" />
        <div className="space-y-2">
          <p>
            <strong>Attenzione:</strong> prima di creare i calendari assicurati di aver completato il passo 1 e di
            aver controllato tutti i parametri della stagione (numero di giornate, calendario Champions per girone,
            regole di gioco, ecc.). I calendari vengono costruiti a partire da questi parametri.
          </p>
          <p>
            Una volta inserite le formazioni i calendari non sono più modificabili.
          </p>
          <Link to="/regole-calcolo" className="inline-flex items-center gap-1.5 text-xs underline underline-offset-2 text-yellow-300 hover:text-yellow-100">
            <SlidersHorizontal className="w-3.5 h-3.5" /> Apri "Gestisci regole di calcolo"
          </Link>
        </div>
      </div>

      {errorMsg && (
        <div className="rounded-lg px-4 py-3 flex gap-3 bg-red-500/5 border border-red-500/20 text-red-200 text-sm mb-4">
          <AlertTriangle className="w-4 h-4 text-red-400 flex-shrink-0 mt-0.5" /> {errorMsg}
        </div>
      )}

      {!stato && !loading && !errorMsg && (
        <p className="text-xs text-slate-600">Premi "Verifica" per controllare la configurazione della stagione {stagioneValida ? stagione : ''}.</p>
      )}

      {stato && (
        <>
          <dl className="grid grid-cols-2 md:grid-cols-4 gap-3 mb-5 text-sm">
            <div className="rounded-lg border border-white/5 p-3">
              <dt className="text-[10px] uppercase tracking-widest text-slate-600">Giornate (da configurazione)</dt>
              <dd className="text-slate-200 font-semibold mt-1">{stato.numero_giornate ?? '—'}</dd>
            </div>
            <div className="rounded-lg border border-white/5 p-3">
              <dt className="text-[10px] uppercase tracking-widest text-slate-600">Squadre</dt>
              <dd className="text-slate-200 font-semibold mt-1">{stato.squadre_presenti}</dd>
            </div>
            <div className="rounded-lg border border-white/5 p-3">
              <dt className="text-[10px] uppercase tracking-widest text-slate-600">Calendario campionato</dt>
              <dd className="text-slate-200 font-semibold mt-1">{stato.calendario_righe > 0 ? `${stato.calendario_righe} righe` : 'non creato'}</dd>
            </div>
            <div className="rounded-lg border border-white/5 p-3">
              <dt className="text-[10px] uppercase tracking-widest text-slate-600">Calendario Champions</dt>
              <dd className="text-slate-200 font-semibold mt-1">{stato.calendario_champ_righe > 0 ? `${stato.calendario_champ_righe} righe` : 'non creato'}</dd>
            </div>
          </dl>

          {stato.errori?.length > 0 && (
            <div className="rounded-lg px-4 py-3 bg-red-500/5 border border-red-500/20 text-red-200 text-sm mb-4">
              <p className="font-medium mb-1">I calendari non possono essere creati:</p>
              <ul className="list-disc pl-5 space-y-0.5 text-xs">
                {stato.errori.map((m, i) => <li key={i}>{m}</li>)}
              </ul>
            </div>
          )}

          {stato.eseguibile && (
            <div className="pt-4 border-t border-white/5">
              {sostituisce && (
                <p className="text-xs text-yellow-300/90 mb-3">
                  Per la stagione {stagione} esistono già dei calendari: verranno sostituiti.
                </p>
              )}
              <label className="flex items-start gap-2 text-sm text-slate-300 mb-4 cursor-pointer">
                <input
                  type="checkbox" checked={confermato}
                  onChange={e => setConfermato(e.target.checked)}
                  className="w-4 h-4 mt-0.5"
                />
                <span>Ho controllato i parametri della stagione {stagione} e voglio creare i calendari.</span>
              </label>
              <button onClick={crea} disabled={!confermato || saving} className="btn-primary text-sm disabled:opacity-40">
                {saving ? <><Spinner size="sm" /> Creazione in corso...</> : 'Crea calendari'}
              </button>
            </div>
          )}
        </>
      )}

      {erroriVal && (
        <div className="mt-4 rounded-lg px-4 py-3 bg-red-500/5 border border-red-500/20 text-red-200 text-xs">
          <ul className="list-disc pl-5 space-y-0.5">{erroriVal.map((m, i) => <li key={i}>{m}</li>)}</ul>
        </div>
      )}

      {result?.ok && (
        <div className="mt-5 px-4 py-3 rounded-lg text-sm bg-green-500/10 border border-green-500/20 text-green-300">
          <div className="flex items-center gap-2 font-medium">
            <CheckCircle2 className="w-4 h-4" /> Calendari della stagione {result.stagione} creati
          </div>
          <p className="text-xs text-green-400/80 mt-1">
            Campionato: {result.numero_giornate} giornate ({result.calendario_righe} righe) · Champions: {result.calendario_champ_righe} righe.
          </p>
        </div>
      )}
    </div>
  )
}
