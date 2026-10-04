import { useState, useRef, useEffect } from 'react'
import { useApp } from '../context/AppContext'
import { useFetch } from '../hooks/useFetch'
import {
  getVotiSerieAInfo, adminCaricaVotiSerieA, adminCalcolaGiornata,
  getStatoChiusuraGiornata, adminChiudiGiornata,
} from '../api/client'
import { PageHeader, Spinner, ErrorState } from '../components/ui'
import {
  UploadCloud, FileSpreadsheet, CheckCircle2, AlertTriangle, X, Lock, CalendarCheck, Calculator, Flag, XCircle,
} from 'lucide-react'

const RUOLI = [
  { key: 'P',   label: 'Portieri' },
  { key: 'D',   label: 'Difensori' },
  { key: 'C',   label: 'Centrocampisti' },
  { key: 'A',   label: 'Attaccanti' },
  { key: 'ALL', label: 'Allenatori' },
]

export default function GestioneVoti() {
  const { stagione } = useApp()

  const { data: info, loading: loadingInfo, error: errorInfo, refetch } = useFetch(
    stagione ? () => getVotiSerieAInfo(stagione) : null,
    [stagione],
  )

  // Giornata su cui caricare i voti: di default la giornata corrente
  // (ultima non chiusa), modificabile dall'utente.
  const [giornata, setGiornata] = useState(null)
  useEffect(() => {
    if (info?.giornata_corrente) setGiornata(info.giornata_corrente)
  }, [info?.stagione, info?.giornata_corrente])

  // Modalità automatica: la fantagiornata si ricava dalla giornata di Serie A del file
  const [auto, setAuto] = useState(true)
  const [file,     setFile]     = useState(null)
  const [loading,  setLoading]  = useState(false)
  const [errors,   setErrors]   = useState([])
  const [mismatch, setMismatch] = useState(null)   // giornata indicata nel file
  const [result,   setResult]   = useState(null)
  const fileInputRef = useRef(null)

  // Fase 2: calcolo voti fantacalcio
  const [calcolando,  setCalcolando]  = useState(false)
  const [calcErrore,  setCalcErrore]  = useState(null)
  const [calcRisultato, setCalcRisultato] = useState(null)

  // Fase 3: chiusura giornata
  const [chiudendo,         setChiudendo]         = useState(false)
  const [chiusuraErrore,    setChiusuraErrore]    = useState(null)
  const [chiusuraRisultato, setChiusuraRisultato] = useState(null)
  const [confermaChiusura,  setConfermaChiusura]  = useState(false)
  const { data: stato, refetch: refetchStato } = useFetch(
    stagione && giornata ? () => getStatoChiusuraGiornata(Number(stagione), giornata) : null,
    [stagione, giornata],
  )

  const resetFile = () => {
    setFile(null)
    if (fileInputRef.current) fileInputRef.current.value = ''
  }

  const resetFeedback = () => { setErrors([]); setMismatch(null); setResult(null) }
  const resetFeedbackCalcolo = () => { setCalcErrore(null); setCalcRisultato(null) }
  const resetFeedbackChiusura = () => { setChiusuraErrore(null); setChiusuraRisultato(null); setConfermaChiusura(false) }

  const handleFileChange = (e) => {
    resetFeedback()
    setFile(e.target.files?.[0] ?? null)
  }

  const handleGiornataChange = (e) => {
    resetFeedback()
    resetFeedbackCalcolo()
    resetFeedbackChiusura()
    setGiornata(Number(e.target.value))
  }

  const upload = async (forza = false) => {
    resetFeedback()
    if (!file) {
      setErrors([{ riga: 0, campo: 'file', messaggio: 'Seleziona un file Excel (.xlsx)' }])
      return
    }
    setLoading(true)
    try {
      const res = await adminCaricaVotiSerieA(Number(stagione), auto ? null : giornata, file, forza)
      setResult(res)
      // Le fasi successive (calcolo, chiusura) lavorano sulla giornata dei voti appena caricati
      if (res.giornata && res.giornata !== giornata) {
        resetFeedbackCalcolo()
        resetFeedbackChiusura()
        setGiornata(res.giornata)
      }
      resetFile()
      refetch()
    } catch (err) {
      if (err.code === 'GIORNATA_MISMATCH') {
        setMismatch(err.details?.giornata_file ?? '?')
      } else {
        // err.details = elenco { riga, campo, messaggio } in caso di VALIDATION_ERROR
        setErrors(Array.isArray(err.details) ? err.details : [{ riga: 0, campo: '', messaggio: err.message }])
      }
    } finally {
      setLoading(false)
    }
  }

  const handleSubmit = (e) => { e.preventDefault(); upload(false) }

  const chiudiGiornata = async () => {
    setChiusuraErrore(null)
    setChiusuraRisultato(null)
    setChiudendo(true)
    try {
      const res = await adminChiudiGiornata(Number(stagione), giornata)
      setChiusuraRisultato(res)
      setConfermaChiusura(false)
      if (res.ok) {
        // La giornata corrente diventa la successiva: ricarico info e stato
        refetch()
        if (res.prossima_giornata) setGiornata(res.prossima_giornata)
        else refetchStato()
      }
    } catch (err) {
      setChiusuraErrore(err.message)
    } finally {
      setChiudendo(false)
    }
  }

  const calcolaVoti = async () => {
    resetFeedbackCalcolo()
    setCalcolando(true)
    try {
      const res = await adminCalcolaGiornata(Number(stagione), giornata)
      setCalcRisultato(res)
      refetch()
      refetchStato()
    } catch (err) {
      setCalcErrore(err.message)
    } finally {
      setCalcolando(false)
    }
  }

  const giornate       = info?.giornate ?? []
  const inizioSerieA   = info?.giornata_serie_a_inizio ?? 1
  const serieADi       = (g) => g + inizioSerieA - 1
  const giornataInfo   = giornate.find(g => g.giornata === giornata)
  const isCorrente     = giornata !== null && giornata === info?.giornata_corrente
  const giaChiusa      = !!giornataInfo?.chiusa
  const votiPresenti   = giornataInfo?.voti_caricati ?? 0

  return (
    <div className="animate-fade-up">
      <PageHeader
        label="Gestione"
        title="Gestione voti"
        subtitle="Carica i voti della Serie A di una giornata e calcola i voti fantacalcio."
      />

      {/* Fasi del flusso */}
      <div className="flex flex-wrap gap-3 max-w-2xl mb-6">
        {[
          { n: 1, label: 'Caricamento voti Serie A', done: votiPresenti > 0 },
          { n: 2, label: 'Calcolo voti fantacalcio', done: !!stato && stato.partite_attese > 0 && stato.partite_calcolate >= stato.partite_attese },
          { n: 3, label: 'Chiusura giornata',        done: giaChiusa },
        ].map(f => (
          <div
            key={f.n}
            className={`flex items-center gap-2 px-3 py-2 rounded-lg text-sm font-medium ${
              f.done ? 'bg-green-500/10 text-green-300' : 'bg-gold-500/10 text-gold-400'
            }`}
          >
            <span className={`w-5 h-5 rounded-full text-xs flex items-center justify-center ${
              f.done ? 'bg-green-500/20' : 'bg-gold-500/20'
            }`}>
              {f.done ? <CheckCircle2 className="w-3.5 h-3.5" /> : f.n}
            </span>
            {f.label}
          </div>
        ))}
      </div>

      {errorInfo ? (
        <ErrorState message={errorInfo} onRetry={refetch} />
      ) : loadingInfo || !info ? (
        <div className="flex justify-center py-16"><Spinner size="lg" /></div>
      ) : (
        <>
          <div className="card p-6 max-w-2xl">
            <form onSubmit={handleSubmit}>
              {/* Giornata di riferimento */}
              <div className="mb-5">
                <label className="flex items-start gap-2 text-sm text-slate-300 mb-1 cursor-pointer">
                  <input
                    type="checkbox" checked={auto}
                    onChange={e => { resetFeedback(); setAuto(e.target.checked) }}
                    className="w-4 h-4 mt-0.5"
                  />
                  <span>
                    Ricava la giornata dal file <span className="text-slate-500">(consigliato)</span>
                  </span>
                </label>
                <p className="text-xs text-slate-600 mb-4 ml-6">
                  I voti vengono associati alla fantagiornata corrispondente alla giornata di Serie A indicata nel file:
                  la giornata 1 di fantacampionato corrisponde alla giornata {inizioSerieA} di Serie A
                  (parametro "Giornata di Serie A di partenza" in Gestisci regole di calcolo).
                </p>
                <label className="text-xs text-slate-600 mb-1 block">
                  {auto ? 'Giornata di riferimento (fasi successive)' : 'Giornata di fantacampionato su cui caricare i voti'}
                </label>
                <div className="flex flex-wrap items-center gap-3">
                  <select
                    value={giornata ?? ''}
                    onChange={handleGiornataChange}
                    className="fanta-input sm:w-auto"
                  >
                    {giornate.map(g => (
                      <option key={g.giornata} value={g.giornata}>
                        Giornata {g.giornata} (Serie A {g.giornata_serie_a ?? serieADi(g.giornata)})
                        {g.giornata === info.giornata_corrente ? ' (corrente)' : ''}
                        {g.chiusa ? ' · chiusa' : ''}
                        {g.voti_caricati > 0 ? ` · ${g.voti_caricati} voti` : ''}
                      </option>
                    ))}
                  </select>
                  {isCorrente ? (
                    <span className="inline-flex items-center gap-1.5 text-xs text-grass-400">
                      <CalendarCheck className="w-3.5 h-3.5" /> Giornata corrente (ultima non chiusa)
                    </span>
                  ) : (
                    <button
                      type="button"
                      onClick={() => { resetFeedback(); setGiornata(info.giornata_corrente) }}
                      className="text-xs text-slate-500 hover:text-slate-300 underline underline-offset-2"
                    >
                      Torna alla giornata corrente ({info.giornata_corrente})
                    </button>
                  )}
                </div>

                {giornata !== null && (
                  <p className="text-xs text-slate-500 mt-2">
                    Giornata {giornata} di fantacampionato = giornata {serieADi(giornata)} di Serie A.
                  </p>
                )}
                {giaChiusa && (
                  <p className="flex items-center gap-1.5 text-xs text-gold-400 mt-2">
                    <Lock className="w-3.5 h-3.5" /> La giornata {giornata} risulta già chiusa.
                  </p>
                )}
                {votiPresenti > 0 && !auto && (
                  <p className="text-xs text-slate-500 mt-2">
                    Per questa giornata sono già presenti {votiPresenti} voti: un nuovo caricamento li sostituirà.
                  </p>
                )}
              </div>

              {/* File */}
              <div className="mb-5">
                <label className="text-xs text-slate-600 mb-1 block">File Excel (sheet "Italia")</label>
                {!file ? (
                  <label
                    htmlFor="file-voti"
                    className="flex flex-col items-center justify-center gap-2 px-4 py-8 rounded-lg
                               border border-dashed border-white/15 text-slate-500
                               hover:border-grass-500/40 hover:text-slate-300 cursor-pointer transition-colors"
                  >
                    <UploadCloud className="w-6 h-6" />
                    <span className="text-sm">Trascina qui il file o clicca per selezionarlo</span>
                    <span className="text-[10px] text-mono uppercase tracking-widest text-slate-700">Solo .xlsx</span>
                    <input
                      id="file-voti"
                      ref={fileInputRef}
                      type="file"
                      accept=".xlsx"
                      onChange={handleFileChange}
                      className="hidden"
                    />
                  </label>
                ) : (
                  <div className="flex items-center gap-3 px-4 py-3 rounded-lg bg-pitch-900 border border-white/10">
                    <FileSpreadsheet className="w-4 h-4 text-grass-400 flex-shrink-0" />
                    <span className="text-sm text-slate-300 truncate flex-1">{file.name}</span>
                    <button type="button" onClick={resetFile} className="p-1 rounded text-slate-600 hover:text-red-400 transition-colors">
                      <X className="w-3.5 h-3.5" />
                    </button>
                  </div>
                )}
              </div>

              <button type="submit" disabled={loading || (!auto && giornata === null)} className="btn-primary disabled:opacity-40">
                {loading
                  ? <><Spinner size="sm" /> Caricamento in corso...</>
                  : auto ? 'Carica voti (giornata dal file)' : `Carica voti giornata ${giornata ?? ''}`}
              </button>
            </form>
          </div>

          {/* Giornata nel file diversa da quella selezionata */}
          {mismatch !== null && (
            <div className="card p-6 max-w-2xl mt-6">
              <div className="rounded-lg px-4 py-3 flex gap-3 bg-gold-500/5 border border-gold-500/20 text-gold-100 text-sm mb-4">
                <AlertTriangle className="w-4 h-4 text-gold-400 flex-shrink-0 mt-0.5" />
                <span>
                  Il file si riferisce alla <strong>giornata {mismatch} di Serie A</strong>, ma la{' '}
                  <strong>giornata {giornata}</strong> di fantacampionato corrisponde alla giornata{' '}
                  <strong>{serieADi(giornata)}</strong> di Serie A. Nessun dato è stato salvato.
                </span>
              </div>
              <div className="flex flex-wrap gap-3">
                <button
                  type="button"
                  disabled={loading}
                  onClick={() => upload(true)}
                  className="btn-primary disabled:opacity-40"
                >
                  Carica comunque sulla giornata {giornata}
                </button>
                <button type="button" onClick={() => setMismatch(null)} className="text-sm text-slate-500 hover:text-slate-300">
                  Annulla
                </button>
              </div>
            </div>
          )}

          {errors.length > 0 && (
            <div className="card p-6 max-w-2xl mt-6">
              <div className="mb-4 rounded-lg px-4 py-3 flex gap-3 bg-red-500/5 border border-red-500/20 text-red-200 text-sm">
                <AlertTriangle className="w-4 h-4 text-red-400 flex-shrink-0 mt-0.5" />
                <span>
                  {errors.length === 1 && !errors[0].riga
                    ? errors[0].messaggio
                    : `Il file contiene ${errors.length} errore/i. Nessun dato è stato salvato: correggi il file e ricarica.`}
                </span>
              </div>
              {!(errors.length === 1 && !errors[0].riga) && (
                <div className="overflow-x-auto">
                  <table className="w-full text-sm">
                    <thead>
                      <tr className="text-left text-xs text-slate-600 uppercase tracking-widest">
                        <th className="py-2 pr-4">Riga</th>
                        <th className="py-2 pr-4">Campo</th>
                        <th className="py-2">Messaggio</th>
                      </tr>
                    </thead>
                    <tbody>
                      {errors.map((er, idx) => (
                        <tr key={idx} className="border-t border-white/[0.03]">
                          <td className="py-2 pr-4 text-slate-500">{er.riga || '-'}</td>
                          <td className="py-2 pr-4 text-slate-400">{er.campo || '-'}</td>
                          <td className="py-2 text-slate-300">{er.messaggio}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </div>
          )}

          {result && (
            <div className="card p-6 max-w-2xl mt-6">
              <div className="flex items-center gap-2 mb-4 text-green-300">
                <CheckCircle2 className="w-4 h-4" />
                <span className="font-medium">
                  Voti caricati sulla giornata {result.giornata} di fantacampionato
                  {result.giornata_serie_a ? ` (Serie A ${result.giornata_serie_a})` : ''} · {result.squadre} squadre
                </span>
              </div>
              <div className="grid grid-cols-3 sm:grid-cols-6 gap-4 text-center">
                <div>
                  <p className="text-display text-2xl font-bold text-white">{result.totale}</p>
                  <p className="text-[10px] text-mono uppercase tracking-widest text-slate-600 mt-1">Totale</p>
                </div>
                {RUOLI.map(r => (
                  <div key={r.key}>
                    <p className="text-display text-2xl font-bold text-grass-400">{result.per_ruolo?.[r.key] ?? 0}</p>
                    <p className="text-[10px] text-mono uppercase tracking-widest text-slate-600 mt-1">{r.label}</p>
                  </div>
                ))}
              </div>
              {result.senza_voto > 0 && (
                <p className="text-xs text-slate-500 mt-4">
                  {result.senza_voto} giocatori entrati ma senza voto (asterisco nel file, es. 6*): salvati con voto vuoto e SV = Y.
                </p>
              )}
            </div>
          )}

          {/* Fase 2: calcolo voti fantacalcio */}
          <div className="card p-6 max-w-2xl mt-8">
            <h3 className="text-sm font-semibold text-slate-300 mb-1">Fase 2 — Calcolo voti fantacalcio</h3>
            <p className="text-xs text-slate-500 mb-4">
              Calcola il punteggio di ogni titolare e il risultato di ogni partita della giornata {giornata ?? ''},
              usando i voti Serie A caricati sopra e le regole configurate in "Gestisci regole di calcolo".
              Ripetibile finché la giornata non è chiusa.
            </p>

            {votiPresenti === 0 ? (
              <p className="text-xs text-gold-400">
                Carica prima i voti Serie A della giornata {giornata ?? ''} (fase 1) per poter calcolare i voti fantacalcio.
              </p>
            ) : (
              <button
                type="button"
                onClick={calcolaVoti}
                disabled={calcolando || giaChiusa}
                className="btn-primary disabled:opacity-40"
              >
                {calcolando
                  ? <><Spinner size="sm" /> Calcolo in corso...</>
                  : <><Calculator className="w-4 h-4" /> Calcola voti fantacalcio</>}
              </button>
            )}

            {calcErrore && (
              <div className="rounded-lg px-4 py-3 flex gap-3 bg-red-500/5 border border-red-500/20 text-red-200 text-sm mt-4">
                <AlertTriangle className="w-4 h-4 text-red-400 flex-shrink-0 mt-0.5" /> {calcErrore}
              </div>
            )}

            {calcRisultato && (
              <div className="mt-4">
                <div className="flex items-center gap-2 text-green-300 mb-2">
                  <CheckCircle2 className="w-4 h-4" />
                  <span className="font-medium text-sm">
                    {calcRisultato.partite_elaborate} partite calcolate per la giornata {calcRisultato.giornata}
                  </span>
                </div>

                {calcRisultato.partite_saltate?.length > 0 && (
                  <div className="rounded-lg px-4 py-3 bg-red-500/5 border border-red-500/20 text-red-200 text-sm mb-2">
                    <p className="font-medium mb-1">Partite non calcolate:</p>
                    <ul className="list-disc list-inside space-y-0.5">
                      {calcRisultato.partite_saltate.map((m, i) => <li key={i}>{m}</li>)}
                    </ul>
                  </div>
                )}

                {calcRisultato.warning?.length > 0 && (
                  <div className="rounded-lg px-4 py-3 bg-gold-500/5 border border-gold-500/20 text-gold-100 text-sm">
                    <p className="font-medium mb-1">Avvisi:</p>
                    <ul className="list-disc list-inside space-y-0.5">
                      {calcRisultato.warning.map((w, i) => <li key={i}>{w}</li>)}
                    </ul>
                  </div>
                )}
              </div>
            )}
          </div>

          {/* Fase 3: chiusura giornata */}
          <div className="card p-6 max-w-2xl mt-8">
            <h3 className="text-sm font-semibold text-slate-300 mb-1">Fase 3 — Chiusura giornata</h3>
            <p className="text-xs text-slate-500 mb-4">
              Conferma in modo definitivo i voti della giornata {giornata ?? ''}: aggiorna statistiche giocatori,
              classifica generale e Top/Flop 11, poi la giornata corrente diventa la successiva.
              L'operazione non è reversibile.
            </p>

            {!stato ? (
              <div className="flex justify-center py-4"><Spinner size="sm" /></div>
            ) : (
              <>
                <div className="grid grid-cols-3 gap-4 text-center mb-4">
                  <div>
                    <p className="text-display text-2xl font-bold text-grass-400">{stato.voti_serie_a}</p>
                    <p className="text-[10px] text-mono uppercase tracking-widest text-slate-600 mt-1">Voti Serie A</p>
                  </div>
                  <div>
                    <p className="text-display text-2xl font-bold text-grass-400">{stato.partite_calcolate}/{stato.partite_attese}</p>
                    <p className="text-[10px] text-mono uppercase tracking-widest text-slate-600 mt-1">Partite calcolate</p>
                  </div>
                  <div>
                    <p className="text-display text-2xl font-bold text-grass-400">{stato.senza_contributo}</p>
                    <p className="text-[10px] text-mono uppercase tracking-widest text-slate-600 mt-1">Titolari senza voto</p>
                  </div>
                </div>

                {stato.motivi_blocco.length > 0 && (
                  <div className="rounded-lg px-4 py-3 flex gap-3 bg-gold-500/5 border border-gold-500/20 text-gold-100 text-sm mb-4">
                    <Lock className="w-4 h-4 text-gold-400 flex-shrink-0 mt-0.5" />
                    <ul className="space-y-0.5">{stato.motivi_blocco.map((m, i) => <li key={i}>{m}</li>)}</ul>
                  </div>
                )}

                {stato.chiudibile && stato.avvisi.length > 0 && (
                  <div className="rounded-lg px-4 py-3 flex gap-3 bg-gold-500/5 border border-gold-500/20 text-gold-100 text-sm mb-4">
                    <AlertTriangle className="w-4 h-4 text-gold-400 flex-shrink-0 mt-0.5" />
                    <ul className="space-y-0.5">{stato.avvisi.map((a, i) => <li key={i}>{a}</li>)}</ul>
                  </div>
                )}

                {stato.chiudibile && (
                  <>
                    <label className="flex items-start gap-2 text-xs text-slate-400 mb-4 cursor-pointer">
                      <input
                        type="checkbox"
                        checked={confermaChiusura}
                        onChange={e => setConfermaChiusura(e.target.checked)}
                        className="mt-0.5"
                      />
                      Ho verificato i risultati e confermo la chiusura definitiva della giornata {giornata}
                      {stato.ultima_della_stagione ? ' (ultima della stagione)' : ''}.
                    </label>
                    <button
                      type="button"
                      onClick={chiudiGiornata}
                      disabled={chiudendo || !confermaChiusura}
                      className="btn-primary disabled:opacity-40"
                    >
                      {chiudendo
                        ? <><Spinner size="sm" /> Chiusura in corso...</>
                        : <><Flag className="w-4 h-4" /> Chiudi giornata {giornata}</>}
                    </button>
                  </>
                )}
              </>
            )}

            {chiusuraErrore && (
              <div className="rounded-lg px-4 py-3 flex gap-3 bg-red-500/5 border border-red-500/20 text-red-200 text-sm mt-4">
                <AlertTriangle className="w-4 h-4 text-red-400 flex-shrink-0 mt-0.5" /> {chiusuraErrore}
              </div>
            )}

            {chiusuraRisultato && (
              <div className="mt-4">
                <div className={`flex items-center gap-2 mb-2 ${chiusuraRisultato.ok ? 'text-green-300' : 'text-red-300'}`}>
                  {chiusuraRisultato.ok ? <CheckCircle2 className="w-4 h-4" /> : <XCircle className="w-4 h-4" />}
                  <span className="font-medium text-sm">
                    {chiusuraRisultato.ok
                      ? (chiusuraRisultato.stagione_conclusa
                          ? `Giornata ${chiusuraRisultato.giornata} chiusa — stagione conclusa`
                          : `Giornata ${chiusuraRisultato.giornata} chiusa — giornata corrente: ${chiusuraRisultato.prossima_giornata}`)
                      : chiusuraRisultato.errore}
                  </span>
                </div>
                <ul className="space-y-1 text-sm">
                  {chiusuraRisultato.passi.map(p => (
                    <li key={p.codice} className="flex items-center gap-2 text-slate-300">
                      {p.ok ? <CheckCircle2 className="w-4 h-4 text-green-400" /> : <XCircle className="w-4 h-4 text-red-400" />}
                      {p.etichetta}
                      <span className="text-xs text-slate-500">{p.ok ? p.dettaglio : p.errore}</span>
                    </li>
                  ))}
                </ul>
                {chiusuraRisultato.avvisi?.length > 0 && (
                  <div className="rounded-lg px-4 py-3 bg-gold-500/5 border border-gold-500/20 text-gold-100 text-sm mt-3">
                    <p className="font-medium mb-1">Avvisi:</p>
                    <ul className="list-disc list-inside space-y-0.5">
                      {chiusuraRisultato.avvisi.map((w, i) => <li key={i}>{w}</li>)}
                    </ul>
                  </div>
                )}
              </div>
            )}
          </div>
        </>
      )}
    </div>
  )
}
