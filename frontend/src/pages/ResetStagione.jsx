import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useApp } from '../context/AppContext'
import { useFetch } from '../hooks/useFetch'
import { getStatoResetStagione, adminResetStagione } from '../api/client'
import { PageHeader, Spinner, ErrorState } from '../components/ui'
import {
  Trash2, CheckCircle2, XCircle, AlertTriangle, Lock, DatabaseBackup, ArrowRight, ArchiveRestore,
} from 'lucide-react'

// ============================================================
// "Reset stagione" — elimina tutti i dati della stagione corrente per
// ripartire da zero. Prima della cancellazione il backend salva in
// tabelle di backup (BK_*) quasi tutta la stagione (squadre e utenze,
// rose, import Serie A, regole, calendari, formazioni, risultati,
// classifiche, ...): si ripristina dalla pagina "Ripristino stagione".
//
// Operazione distruttiva, quindi tre protezioni:
//   1. si può resettare solo la stagione più recente (controllo server);
//   2. serve digitare la frase "RESET <stagione>";
//   3. serve riscrivere la password dell'amministratore (verificata
//      lato server).
// ============================================================

const fmt = (n) => Number(n).toLocaleString('it-IT')

const ETICHETTE_STATO = {
  RESET_COMPLETATO: 'reset completato',
  RESET_PARZIALE:   'reset interrotto',
  RESET_ANNULLATO:  'reset annullato da un ripristino',
  PRE_RIPRISTINO:   'sicurezza prima di un ripristino',
  BACKUP_IN_CORSO:  'backup incompleto',
}

function ElencoTabelle({ titolo, descrizione, righe }) {
  const visibili = righe.filter(t => t.presente && t.righe > 0)
  const nascoste = righe.length - visibili.length
  return (
    <div>
      <h4 className="text-sm font-semibold text-slate-300">{titolo}</h4>
      <p className="text-xs text-slate-500 mb-3">{descrizione}</p>
      {visibili.length === 0 ? (
        <p className="text-xs text-slate-600">Nessun dato presente.</p>
      ) : (
        <ul className="divide-y divide-white/5 rounded-lg border border-white/5">
          {visibili.map(t => (
            <li key={t.tabella} className="flex items-center justify-between gap-3 px-3 py-2 text-sm">
              <span className="text-slate-300 min-w-0 truncate">{t.descrizione}</span>
              <span className="text-slate-400 tabular-nums flex-shrink-0">{fmt(t.righe)}</span>
            </li>
          ))}
        </ul>
      )}
      {nascoste > 0 && visibili.length > 0 && (
        <p className="text-xs text-slate-600 mt-2">
          Altre {nascoste} tabelle senza dati o non presenti sul database.
        </p>
      )}
    </div>
  )
}

function Avviso({ children, tono = 'gold', icona: Icona = AlertTriangle }) {
  const stili = tono === 'red'
    ? 'bg-red-500/5 border-red-500/20 text-red-200'
    : 'bg-gold-500/5 border-gold-500/20 text-gold-100'
  const colIcona = tono === 'red' ? 'text-red-400' : 'text-gold-400'
  return (
    <div className={`rounded-lg px-4 py-3 flex gap-3 border text-sm ${stili}`}>
      <Icona className={`w-4 h-4 flex-shrink-0 mt-0.5 ${colIcona}`} />
      <div className="min-w-0">{children}</div>
    </div>
  )
}

export default function ResetStagione() {
  const { stagione, utente } = useApp()

  const { data: stato, loading, error, refetch } = useFetch(
    stagione ? () => getStatoResetStagione(Number(stagione)) : null,
    [stagione],
  )

  const [utenza,    setUtenza]    = useState(utente?.utenza ?? '')
  const [password,  setPassword]  = useState('')
  const [conferma,  setConferma]  = useState('')
  const [eseguendo, setEseguendo] = useState(false)
  const [errore,    setErrore]    = useState(null)
  const [risultato, setRisultato] = useState(null)

  const frase = stato?.frase_conferma ?? `RESET ${stagione}`
  const pronto = utenza.trim() !== '' && password !== '' &&
                 conferma.trim().toUpperCase() === frase && !eseguendo

  const esegui = async () => {
    setErrore(null)
    setRisultato(null)
    setEseguendo(true)
    try {
      const res = await adminResetStagione(Number(stagione), {
        utenza: utenza.trim(), password, conferma: conferma.trim(),
      })
      setRisultato(res)
      setPassword('')
      setConferma('')
      if (!res.ok) refetch()   // reset a metà: riallinea stato e backup
    } catch (err) {
      setErrore(err.message)
    } finally {
      setEseguendo(false)
    }
  }

  // Ricarica completa: l'elenco stagioni (derivato da NEW_SQUADRE) cambia
  const vaiAInizializzazione = () => {
    window.location.assign(`${import.meta.env.BASE_URL}inizializzazione-stagione`)
  }

  const gruppoBackup   = stato?.tabelle.filter(t => t.gruppo === 'backup')   ?? []
  const gruppoNonSalvate = stato?.tabelle.filter(t => t.gruppo === 'non_salvate') ?? []
  const completato     = risultato?.ok === true

  return (
    <div className="animate-fade-up">
      <PageHeader
        label="Gestione"
        title="Reset stagione"
        subtitle="Elimina tutti i dati della stagione corrente per ripartire da zero, conservando un backup dei dati più onerosi da reinserire."
      />

      {error ? (
        <ErrorState message={error} onRetry={refetch} />
      ) : loading && !stato ? (
        <div className="flex justify-center py-16"><Spinner size="lg" /></div>
      ) : completato ? (
        /* ── Esito: reset completato ── */
        <div className="card p-6 max-w-2xl">
          <div className="flex items-center gap-2 mb-3 text-green-300">
            <CheckCircle2 className="w-5 h-5" />
            <span className="font-medium">
              Stagione {risultato.stagione} azzerata. Backup n. {risultato.bk_id} salvato.
            </span>
          </div>
          <ul className="space-y-1 text-sm mb-4">
            {risultato.passi.map(p => (
              <li key={p.codice} className="flex items-center gap-2 text-slate-300">
                <CheckCircle2 className="w-4 h-4 text-green-400 flex-shrink-0" />
                {p.etichetta}
                <span className="text-xs text-slate-500">{p.dettaglio}</span>
              </li>
            ))}
          </ul>
          <p className="text-xs text-slate-500 mb-4">
            La stagione non compare più nell'elenco. Per ripartire usa "Inizializzazione stagione":
            indicando l'anno, la tabella viene precompilata con i dati dell'ultima stagione disponibile.
            Per tornare indietro usa "Ripristino stagione".
          </p>
          <div className="flex flex-wrap gap-3">
            <button type="button" onClick={vaiAInizializzazione} className="btn-primary">
              Vai a Inizializzazione stagione <ArrowRight className="w-4 h-4" />
            </button>
            <Link to="/ripristino-stagione" className="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg
                  border border-white/10 text-slate-300 hover:bg-white/5 text-sm transition-colors">
              <ArchiveRestore className="w-4 h-4" /> Ripristino stagione
            </Link>
          </div>
        </div>
      ) : stato && (
        <div className="space-y-6 max-w-3xl">
          {stato.motivi_blocco.length > 0 ? (
            <div className="card p-6">
              <Avviso icona={Lock}>
                <ul className="space-y-0.5">{stato.motivi_blocco.map((m, i) => <li key={i}>{m}</li>)}</ul>
              </Avviso>
            </div>
          ) : (
            <>
              {/* ── Cosa succede ── */}
              <div className="card p-6">
                <h3 className="font-semibold text-slate-200 mb-1">
                  Stagione {stato.stagione} / {stato.stagione + 1}
                </h3>
                <p className="text-xs text-slate-500 mb-5">
                  {fmt(stato.totale_righe)} righe in totale. Il backup viene verificato prima di
                  cancellare: se non riesce, nessun dato viene toccato.
                </p>

                <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                  <ElencoTabelle
                    titolo="Salvati nel backup, poi cancellati"
                    descrizione="Quasi tutta la stagione: si rimette com'era da Ripristino stagione."
                    righe={gruppoBackup}
                  />
                  <ElencoTabelle
                    titolo="Solo cancellati"
                    descrizione="Dati temporanei o di log: simulazioni LIVE e registro accessi."
                    righe={gruppoNonSalvate}
                  />
                </div>

                {stato.avvisi.length > 0 && (
                  <div className="mt-5">
                    <Avviso>
                      <ul className="space-y-0.5">{stato.avvisi.map((a, i) => <li key={i}>{a}</li>)}</ul>
                    </Avviso>
                  </div>
                )}
              </div>

              {/* ── Backup precedenti ── */}
              {stato.backup.length > 0 && (
                <div className="card p-6">
                  <h3 className="text-sm font-semibold text-slate-300 mb-3 flex items-center gap-2">
                    <DatabaseBackup className="w-4 h-4 text-slate-500" /> Backup già eseguiti per questa stagione
                  </h3>
                  <ul className="divide-y divide-white/5 rounded-lg border border-white/5 text-sm">
                    {stato.backup.map(b => (
                      <li key={b.bk_id} className="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 px-3 py-2">
                        <span className="text-slate-300">n. {b.bk_id} — {b.creato_il}{b.creato_da ? ` (${b.creato_da})` : ''}</span>
                        <span className="text-xs text-slate-500">
                          {ETICHETTE_STATO[b.stato] ?? b.stato}
                          {b.righe_backup > 0 && ` · ${fmt(b.righe_backup)} righe`}
                        </span>
                      </li>
                    ))}
                  </ul>
                </div>
              )}

              {/* ── Conferma ── */}
              <div className="card p-6 border border-red-500/20">
                <h3 className="font-semibold text-red-300 mb-1 flex items-center gap-2">
                  <Trash2 className="w-4 h-4" /> Conferma il reset
                </h3>
                <p className="text-xs text-slate-500 mb-5">
                  L'operazione non si può annullare dall'app. Riscrivi la tua password di amministratore
                  e digita <span className="font-mono text-slate-300">{frase}</span>.
                </p>

                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">
                  <div>
                    <label htmlFor="rs-utenza" className="text-xs text-slate-600 mb-1 block">Utenza amministratore</label>
                    <input
                      id="rs-utenza" type="text" autoComplete="username"
                      value={utenza} onChange={e => setUtenza(e.target.value)}
                      className="fanta-input"
                    />
                  </div>
                  <div>
                    <label htmlFor="rs-password" className="text-xs text-slate-600 mb-1 block">Password</label>
                    <input
                      id="rs-password" type="password" autoComplete="current-password"
                      value={password} onChange={e => setPassword(e.target.value)}
                      className="fanta-input"
                    />
                  </div>
                  <div className="sm:col-span-2">
                    <label htmlFor="rs-conferma" className="text-xs text-slate-600 mb-1 block">
                      Digita {frase} per confermare
                    </label>
                    <input
                      id="rs-conferma" type="text" autoComplete="off"
                      value={conferma} onChange={e => setConferma(e.target.value)}
                      placeholder={frase}
                      className="fanta-input font-mono"
                    />
                  </div>
                </div>

                <button
                  type="button"
                  onClick={esegui}
                  disabled={!pronto}
                  className="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-red-500 hover:bg-red-400
                             text-white font-semibold text-sm transition-all duration-150 active:scale-95
                             disabled:opacity-40 disabled:cursor-not-allowed disabled:active:scale-100"
                >
                  {eseguendo
                    ? <><Spinner size="sm" /> Reset in corso, non chiudere la pagina...</>
                    : <><Trash2 className="w-4 h-4" /> Azzera la stagione {stato.stagione}</>}
                </button>

                {errore && (
                  <div className="mt-4">
                    <Avviso tono="red" icona={XCircle}>{errore}</Avviso>
                  </div>
                )}

                {risultato && !risultato.ok && (
                  <div className="mt-4 space-y-3">
                    <Avviso tono="red" icona={XCircle}>{risultato.errore}</Avviso>
                    <ul className="space-y-1 text-sm">
                      {risultato.passi.map(p => (
                        <li key={p.codice} className="flex items-center gap-2 text-slate-300">
                          {p.ok
                            ? <CheckCircle2 className="w-4 h-4 text-green-400 flex-shrink-0" />
                            : <XCircle className="w-4 h-4 text-red-400 flex-shrink-0" />}
                          {p.etichetta}
                          <span className="text-xs text-slate-500">{p.ok ? p.dettaglio : p.errore}</span>
                        </li>
                      ))}
                    </ul>
                  </div>
                )}
              </div>
            </>
          )}
        </div>
      )}
    </div>
  )
}
