import { useState } from 'react'
import { useApp } from '../context/AppContext'
import { useFetch } from '../hooks/useFetch'
import { getBackupStagioni, getDettaglioBackup, adminRipristinaStagione } from '../api/client'
import { PageHeader, Spinner, ErrorState } from '../components/ui'
import {
  ArchiveRestore, CheckCircle2, XCircle, AlertTriangle, Lock, DatabaseBackup, ArrowLeft, RefreshCw,
} from 'lucide-react'

// ============================================================
// "Ripristino stagione" — rimette una stagione com'era al momento di un
// backup creato dal "Reset stagione". Se la stagione contiene già dati
// (es. è stata reinizializzata dopo il reset) il backend li salva prima
// in un backup di sicurezza e poi li sostituisce: ripristinando quel
// backup si annulla il ripristino.
//
// Protezioni: si ripristina solo la stagione più recente (controllo
// server), serve digitare "RIPRISTINA <stagione>" e riscrivere la
// password dell'amministratore (verificata lato server).
// ============================================================

const fmt = (n) => Number(n).toLocaleString('it-IT')

const STATI = {
  RESET_COMPLETATO: { testo: 'Reset completato',            classe: 'text-green-300 bg-green-500/10' },
  RESET_PARZIALE:   { testo: 'Reset interrotto',            classe: 'text-gold-300 bg-gold-500/10' },
  RESET_ANNULLATO:  { testo: 'Reset annullato',             classe: 'text-slate-300 bg-white/5' },
  PRE_RIPRISTINO:   { testo: 'Sicurezza pre-ripristino',    classe: 'text-sky-300 bg-sky-500/10' },
  BACKUP_IN_CORSO:  { testo: 'Incompleto',                  classe: 'text-red-300 bg-red-500/10' },
}

function BadgeStato({ stato }) {
  const s = STATI[stato] ?? { testo: stato, classe: 'text-slate-300 bg-white/5' }
  return <span className={`text-[11px] px-2 py-0.5 rounded-full whitespace-nowrap ${s.classe}`}>{s.testo}</span>
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

function ElencoBackup({ backup, onSeleziona }) {
  if (backup.length === 0) {
    return (
      <div className="card p-6 max-w-3xl text-sm text-slate-500">
        Nessun backup disponibile. I backup vengono creati dal "Reset stagione".
      </div>
    )
  }
  return (
    <ul className="space-y-3 max-w-3xl">
      {backup.map(b => (
        <li key={b.bk_id}>
          <button
            type="button"
            disabled={!b.ripristinabile}
            onClick={() => onSeleziona(b.bk_id)}
            className="card w-full text-left p-4 transition-colors enabled:hover:bg-white/5
                       disabled:opacity-50 disabled:cursor-not-allowed"
          >
            <div className="flex flex-wrap items-center justify-between gap-2 mb-1">
              <span className="font-semibold text-slate-200">
                Stagione {b.stagione} <span className="text-slate-500 font-normal">· backup n. {b.bk_id}</span>
              </span>
              <BadgeStato stato={b.stato} />
            </div>
            <div className="text-xs text-slate-500">
              {b.creato_il}{b.creato_da ? ` · ${b.creato_da}` : ''}
              {b.righe_backup > 0 && ` · ${fmt(b.righe_backup)} righe in ${b.tabelle_backup} tabelle`}
            </div>
            {b.ripristini.length > 0 && (
              <div className="text-xs text-slate-500 mt-1">
                Ripristinato {b.ripristini.length === 1 ? '1 volta' : `${b.ripristini.length} volte`}, ultima il {b.ripristini[b.ripristini.length - 1].data}
              </div>
            )}
            {!b.ripristinabile && (
              <div className="text-xs text-red-300/80 mt-1 flex items-center gap-1">
                <Lock className="w-3 h-3" /> Non ripristinabile: {b.motivo_blocco}
              </div>
            )}
          </button>
        </li>
      ))}
    </ul>
  )
}

function Dettaglio({ bkId, onIndietro, onEseguito }) {
  const { utente } = useApp()
  const { data, loading, error, refetch } = useFetch(() => getDettaglioBackup(bkId), [bkId])

  const [utenza,    setUtenza]    = useState(utente?.utenza ?? '')
  const [password,  setPassword]  = useState('')
  const [conferma,  setConferma]  = useState('')
  const [eseguendo, setEseguendo] = useState(false)
  const [errore,    setErrore]    = useState(null)
  const [risultato, setRisultato] = useState(null)

  const frase  = data?.frase_conferma ?? ''
  const pronto = !!data && data.ripristinabile && utenza.trim() !== '' && password !== '' &&
                 conferma.trim().toUpperCase() === frase && !eseguendo

  const esegui = async () => {
    setErrore(null)
    setRisultato(null)
    setEseguendo(true)
    try {
      const res = await adminRipristinaStagione(bkId, {
        utenza: utenza.trim(), password, conferma: conferma.trim(),
      })
      setRisultato(res)
      setPassword('')
      setConferma('')
      onEseguito()
      if (!res.ok) refetch()
    } catch (err) {
      setErrore(err.message)
    } finally {
      setEseguendo(false)
    }
  }

  // Ricarica completa: l'elenco stagioni dell'app (da NEW_SQUADRE) è cambiato
  const ricarica = () => window.location.assign(import.meta.env.BASE_URL)

  const righe = data?.tabelle.filter(t => t.righe_backup > 0 || t.righe_attuali > 0) ?? []

  return (
    <div className="max-w-3xl space-y-6">
      <button type="button" onClick={onIndietro}
              className="inline-flex items-center gap-1.5 text-sm text-slate-400 hover:text-slate-200 transition-colors">
        <ArrowLeft className="w-4 h-4" /> Tutti i backup
      </button>

      {error ? (
        <ErrorState message={error} onRetry={refetch} />
      ) : loading && !data ? (
        <div className="flex justify-center py-16"><Spinner size="lg" /></div>
      ) : risultato?.ok ? (
        /* ── Esito: ripristino completato ── */
        <div className="card p-6">
          <div className="flex items-center gap-2 mb-3 text-green-300">
            <CheckCircle2 className="w-5 h-5" />
            <span className="font-medium">
              Stagione {risultato.stagione} ripristinata dal backup n. {risultato.bk_id}.
            </span>
          </div>
          <ul className="space-y-1 text-sm mb-4">
            {risultato.passi.map(p => (
              <li key={p.codice} className="flex items-start gap-2 text-slate-300">
                <CheckCircle2 className="w-4 h-4 text-green-400 flex-shrink-0 mt-0.5" />
                <span>{p.etichetta} <span className="text-xs text-slate-500">{p.dettaglio}</span></span>
              </li>
            ))}
          </ul>
          {risultato.bk_sicurezza && (
            <p className="text-xs text-slate-500 mb-4">
              I dati che c'erano prima sono nel backup di sicurezza n. {risultato.bk_sicurezza}:
              ripristinandolo si annulla questa operazione.
            </p>
          )}
          {risultato.avvisi.length > 0 && (
            <div className="mb-4">
              <Avviso>
                <ul className="space-y-0.5">{risultato.avvisi.map((a, i) => <li key={i}>{a}</li>)}</ul>
              </Avviso>
            </div>
          )}
          <button type="button" onClick={ricarica} className="btn-primary">
            <RefreshCw className="w-4 h-4" /> Ricarica l'app
          </button>
        </div>
      ) : data && (
        <>
          {/* ── Cosa viene ripristinato ── */}
          <div className="card p-6">
            <div className="flex flex-wrap items-center justify-between gap-2 mb-1">
              <h3 className="font-semibold text-slate-200">
                Stagione {data.stagione} <span className="text-slate-500 font-normal">· backup n. {data.backup.bk_id}</span>
              </h3>
              <BadgeStato stato={data.backup.stato} />
            </div>
            <p className="text-xs text-slate-500 mb-4">
              {data.backup.creato_il}{data.backup.creato_da ? ` · ${data.backup.creato_da}` : ''}
              {' · '}{fmt(data.righe_backup)} righe nel backup, {fmt(data.righe_attuali)} oggi presenti nella stagione
            </p>

            {data.motivi_blocco.length > 0 && (
              <div className="mb-4">
                <Avviso tono="red" icona={Lock}>
                  <ul className="space-y-0.5">{data.motivi_blocco.map((m, i) => <li key={i}>{m}</li>)}</ul>
                </Avviso>
              </div>
            )}

            <div className="rounded-lg border border-white/5 overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="text-xs text-slate-500 text-left">
                    <th className="px-3 py-2 font-medium">Tabella</th>
                    <th className="px-3 py-2 font-medium text-right">Nel backup</th>
                    <th className="px-3 py-2 font-medium text-right">Oggi</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-white/5">
                  {righe.map(t => (
                    <tr key={t.tabella}>
                      <td className="px-3 py-2 text-slate-300">{t.descrizione}</td>
                      <td className="px-3 py-2 text-right tabular-nums text-slate-300">{fmt(t.righe_backup)}</td>
                      <td className={`px-3 py-2 text-right tabular-nums ${t.righe_attuali > 0 ? 'text-gold-300' : 'text-slate-600'}`}>
                        {fmt(t.righe_attuali)}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <p className="text-xs text-slate-600 mt-2">
              Simulazioni LIVE e registro accessi non vengono salvati nei backup.
            </p>

            {data.avvisi.length > 0 && (
              <div className="mt-5">
                <Avviso>
                  <ul className="space-y-0.5">{data.avvisi.map((a, i) => <li key={i}>{a}</li>)}</ul>
                </Avviso>
              </div>
            )}
          </div>

          {/* ── Conferma ── */}
          {data.ripristinabile && (
            <div className="card p-6 border border-gold-500/20">
              <h3 className="font-semibold text-gold-300 mb-1 flex items-center gap-2">
                <ArchiveRestore className="w-4 h-4" /> Conferma il ripristino
              </h3>
              <p className="text-xs text-slate-500 mb-5">
                Riscrivi la tua password di amministratore e digita{' '}
                <span className="font-mono text-slate-300">{frase}</span>.
              </p>

              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">
                <div>
                  <label htmlFor="rp-utenza" className="text-xs text-slate-600 mb-1 block">Utenza amministratore</label>
                  <input id="rp-utenza" type="text" autoComplete="username"
                         value={utenza} onChange={e => setUtenza(e.target.value)} className="fanta-input" />
                </div>
                <div>
                  <label htmlFor="rp-password" className="text-xs text-slate-600 mb-1 block">Password</label>
                  <input id="rp-password" type="password" autoComplete="current-password"
                         value={password} onChange={e => setPassword(e.target.value)} className="fanta-input" />
                </div>
                <div className="sm:col-span-2">
                  <label htmlFor="rp-conferma" className="text-xs text-slate-600 mb-1 block">
                    Digita {frase} per confermare
                  </label>
                  <input id="rp-conferma" type="text" autoComplete="off"
                         value={conferma} onChange={e => setConferma(e.target.value)}
                         placeholder={frase} className="fanta-input font-mono" />
                </div>
              </div>

              <button type="button" onClick={esegui} disabled={!pronto}
                      className="btn-primary disabled:opacity-40 disabled:cursor-not-allowed">
                {eseguendo
                  ? <><Spinner size="sm" /> Ripristino in corso, non chiudere la pagina...</>
                  : <><ArchiveRestore className="w-4 h-4" />
                      {data.sostituisce ? `Sostituisci la stagione ${data.stagione} con il backup` : `Ripristina la stagione ${data.stagione}`}</>}
              </button>

              {errore && (
                <div className="mt-4"><Avviso tono="red" icona={XCircle}>{errore}</Avviso></div>
              )}

              {risultato && !risultato.ok && (
                <div className="mt-4 space-y-3">
                  <Avviso tono="red" icona={XCircle}>{risultato.errore}</Avviso>
                  <ul className="space-y-1 text-sm">
                    {risultato.passi.map(p => (
                      <li key={p.codice} className="flex items-start gap-2 text-slate-300">
                        {p.ok
                          ? <CheckCircle2 className="w-4 h-4 text-green-400 flex-shrink-0 mt-0.5" />
                          : <XCircle className="w-4 h-4 text-red-400 flex-shrink-0 mt-0.5" />}
                        <span>{p.etichetta} <span className="text-xs text-slate-500">{p.ok ? p.dettaglio : p.errore}</span></span>
                      </li>
                    ))}
                  </ul>
                </div>
              )}
            </div>
          )}
        </>
      )}
    </div>
  )
}

export default function RipristinoStagione() {
  const { data, loading, error, refetch } = useFetch(() => getBackupStagioni(), [])
  const [selezionato, setSelezionato] = useState(null)

  return (
    <div className="animate-fade-up">
      <PageHeader
        label="Gestione"
        title="Ripristino stagione"
        subtitle="Rimette la stagione com'era al momento di un backup creato dal Reset stagione. Se la stagione contiene già dati, vengono salvati in un backup di sicurezza e sostituiti."
      />

      {selezionato ? (
        <Dettaglio
          bkId={selezionato}
          onIndietro={() => { setSelezionato(null); refetch() }}
          onEseguito={refetch}
        />
      ) : error ? (
        <ErrorState message={error} onRetry={refetch} />
      ) : loading && !data ? (
        <div className="flex justify-center py-16"><Spinner size="lg" /></div>
      ) : data && (
        <>
          <p className="text-xs text-slate-500 mb-4 flex items-center gap-2 max-w-3xl">
            <DatabaseBackup className="w-4 h-4 flex-shrink-0" />
            Scegli il backup da ripristinare. Si può ripristinare solo la stagione più recente.
          </p>
          <ElencoBackup backup={data.backup} onSeleziona={setSelezionato} />
        </>
      )}
    </div>
  )
}
