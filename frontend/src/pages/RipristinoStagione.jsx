import { useState } from 'react'
import { useApp } from '../context/AppContext'
import { useFetch } from '../hooks/useFetch'
import { getBackupStagioni, getDettaglioBackup, adminRipristinaStagione, adminEliminaBackup } from '../api/client'
import { PageHeader, Spinner, ErrorState } from '../components/ui'
import {
  ArchiveRestore, CheckCircle2, XCircle, AlertTriangle, Lock, DatabaseBackup, ArrowLeft, RefreshCw, Trash2,
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
//
// Dall'elenco si possono anche eliminare definitivamente i backup vecchi
// (selezione multipla, frase "ELIMINA <n> BACKUP" e password). Un backup
// di reset interrotto non si elimina; eliminare l'ultima copia di una
// stagione senza più dati richiede una conferma esplicita.
// ============================================================

const fmt = (n) => Number(n).toLocaleString('it-IT')

// Stati dei backup completi (ripristinabili): gli altri sono incompleti
const STATI_COMPLETI = ['RESET_COMPLETATO', 'RESET_PARZIALE', 'RESET_ANNULLATO', 'PRE_RIPRISTINO']

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

function ElencoBackup({ backup, selezionati, onToggle, onSeleziona }) {
  if (backup.length === 0) {
    return (
      <div className="card p-6 max-w-3xl text-sm text-slate-500">
        Nessun backup disponibile. I backup vengono creati dal "Reset stagione".
      </div>
    )
  }
  return (
    <ul className="space-y-3 max-w-3xl">
      {backup.map(b => {
        const scelto = selezionati.has(b.bk_id)
        return (
          <li key={b.bk_id} className="flex items-stretch gap-3">
            <label
              className={`flex items-center px-1 ${b.eliminabile ? 'cursor-pointer' : 'cursor-not-allowed opacity-40'}`}
              title={b.eliminabile ? 'Seleziona per eliminare' : `Non eliminabile: ${b.motivo_eliminazione}`}
            >
              <input
                type="checkbox"
                className="w-4 h-4 accent-red-500"
                checked={scelto}
                disabled={!b.eliminabile}
                onChange={() => onToggle(b.bk_id)}
                aria-label={`Seleziona il backup n. ${b.bk_id} per l'eliminazione`}
              />
            </label>
            <button
              type="button"
              disabled={!b.ripristinabile}
              onClick={() => onSeleziona(b.bk_id)}
              className={`card flex-1 min-w-0 text-left p-4 transition-colors enabled:hover:bg-white/5
                          disabled:opacity-50 disabled:cursor-not-allowed ${scelto ? 'ring-1 ring-red-500/40' : ''}`}
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
              {b.ultima_copia && (
                <div className="text-xs text-gold-300/90 mt-1 flex items-center gap-1">
                  <AlertTriangle className="w-3 h-3" /> Unica copia dei dati della stagione, che oggi non ha dati
                </div>
              )}
              {!b.ripristinabile && (
                <div className="text-xs text-red-300/80 mt-1 flex items-center gap-1">
                  <Lock className="w-3 h-3" /> Non ripristinabile: {b.motivo_blocco}
                </div>
              )}
            </button>
          </li>
        )
      })}
    </ul>
  )
}

/** Conferma ed esecuzione dell'eliminazione dei backup selezionati. */
function ConfermaEliminazione({ backup, selezionati, onAnnulla, onFatto }) {
  const { utente } = useApp()
  const scelti = backup.filter(b => selezionati.has(b.bk_id))
  const n      = scelti.length
  const frase  = `ELIMINA ${n} BACKUP`

  // Stagioni che resterebbero senza dati né backup ripristinabili
  const perdite = [...new Set(scelti.map(b => b.stagione))].filter(st => {
    const dellaStagione = backup.filter(b => b.stagione === st)
    if (!dellaStagione[0].stagione_vuota) return false
    const ripristinabili = dellaStagione.filter(b => STATI_COMPLETI.includes(b.stato))
    return ripristinabili.length > 0 &&
           ripristinabili.every(b => selezionati.has(b.bk_id)) &&
           scelti.some(b => b.stagione === st && STATI_COMPLETI.includes(b.stato))
  })

  const [utenza,    setUtenza]    = useState(utente?.utenza ?? '')
  const [password,  setPassword]  = useState('')
  const [conferma,  setConferma]  = useState('')
  const [accetta,   setAccetta]   = useState(false)
  const [eseguendo, setEseguendo] = useState(false)
  const [errore,    setErrore]    = useState(null)
  const [risultato, setRisultato] = useState(null)

  const pronto = utenza.trim() !== '' && password !== '' && conferma.trim().toUpperCase() === frase &&
                 (perdite.length === 0 || accetta) && !eseguendo
  const righeTotali = scelti.reduce((t, b) => t + b.righe_backup, 0)

  const esegui = async () => {
    setErrore(null)
    setEseguendo(true)
    try {
      const res = await adminEliminaBackup(scelti.map(b => b.bk_id), {
        utenza: utenza.trim(), password, conferma: conferma.trim(), accetta_perdita: accetta,
      })
      setRisultato(res)
      setPassword('')
      setConferma('')
      onFatto(res)
    } catch (err) {
      setErrore(err.message)
    } finally {
      setEseguendo(false)
    }
  }

  if (risultato) {
    return (
      <div className="card p-6 max-w-3xl">
        <div className={`flex items-center gap-2 mb-3 ${risultato.ok ? 'text-green-300' : 'text-gold-300'}`}>
          {risultato.ok ? <CheckCircle2 className="w-5 h-5" /> : <AlertTriangle className="w-5 h-5" />}
          <span className="font-medium">
            {risultato.eliminati.length === 1 ? '1 backup eliminato' : `${risultato.eliminati.length} backup eliminati`}
            {risultato.errori.length > 0 && `, ${risultato.errori.length} non riusciti`}
          </span>
        </div>
        {risultato.errori.length > 0 && (
          <div className="mb-3">
            <Avviso tono="red" icona={XCircle}>
              <ul className="space-y-0.5">
                {risultato.errori.map(e => <li key={e.bk_id}>Backup n. {e.bk_id}: {e.errore}</li>)}
              </ul>
              <p className="text-xs mt-1 opacity-80">
                I backup non riusciti risultano ora incompleti: si possono eliminare di nuovo.
              </p>
            </Avviso>
          </div>
        )}
        {risultato.avvisi.length > 0 && (
          <div className="mb-3"><Avviso>{risultato.avvisi.join(' ')}</Avviso></div>
        )}
        <button type="button" onClick={onAnnulla} className="btn-primary">
          <ArrowLeft className="w-4 h-4" /> Torna all'elenco
        </button>
      </div>
    )
  }

  return (
    <div className="max-w-3xl space-y-6">
      <button type="button" onClick={onAnnulla}
              className="inline-flex items-center gap-1.5 text-sm text-slate-400 hover:text-slate-200 transition-colors">
        <ArrowLeft className="w-4 h-4" /> Tutti i backup
      </button>

      <div className="card p-6 border border-red-500/20">
        <h3 className="font-semibold text-red-300 mb-1 flex items-center gap-2">
          <Trash2 className="w-4 h-4" /> Elimina {n === 1 ? '1 backup' : `${n} backup`}
        </h3>
        <p className="text-xs text-slate-500 mb-4">
          L'eliminazione è definitiva ({fmt(righeTotali)} righe) e non si può annullare. I dati attuali
          delle stagioni non vengono toccati.
        </p>

        <ul className="divide-y divide-white/5 rounded-lg border border-white/5 text-sm mb-4">
          {scelti.map(b => (
            <li key={b.bk_id} className="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 px-3 py-2">
              <span className="text-slate-300">n. {b.bk_id} · stagione {b.stagione} · {b.creato_il}</span>
              <span className="flex items-center gap-2">
                <span className="text-xs text-slate-500">{fmt(b.righe_backup)} righe</span>
                <BadgeStato stato={b.stato} />
              </span>
            </li>
          ))}
        </ul>

        {perdite.length > 0 && (
          <div className="mb-4">
            <Avviso tono="red">
              <p>
                {perdite.length === 1 ? `La stagione ${perdite[0]} non ha` : `Le stagioni ${perdite.join(', ')} non hanno`} più
                dati e questi sono gli ultimi backup ripristinabili: eliminandoli i dati andranno persi per sempre.
              </p>
              <label className="flex items-start gap-2 mt-2 cursor-pointer">
                <input type="checkbox" className="w-4 h-4 mt-0.5 accent-red-500"
                       checked={accetta} onChange={e => setAccetta(e.target.checked)} />
                <span>Ho capito: voglio perdere definitivamente questi dati</span>
              </label>
            </Avviso>
          </div>
        )}

        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">
          <div>
            <label htmlFor="el-utenza" className="text-xs text-slate-600 mb-1 block">Utenza amministratore</label>
            <input id="el-utenza" type="text" autoComplete="username"
                   value={utenza} onChange={e => setUtenza(e.target.value)} className="fanta-input" />
          </div>
          <div>
            <label htmlFor="el-password" className="text-xs text-slate-600 mb-1 block">Password</label>
            <input id="el-password" type="password" autoComplete="current-password"
                   value={password} onChange={e => setPassword(e.target.value)} className="fanta-input" />
          </div>
          <div className="sm:col-span-2">
            <label htmlFor="el-conferma" className="text-xs text-slate-600 mb-1 block">Digita {frase} per confermare</label>
            <input id="el-conferma" type="text" autoComplete="off"
                   value={conferma} onChange={e => setConferma(e.target.value)}
                   placeholder={frase} className="fanta-input font-mono" />
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
            ? <><Spinner size="sm" /> Eliminazione in corso...</>
            : <><Trash2 className="w-4 h-4" /> Elimina definitivamente</>}
        </button>

        {errore && <div className="mt-4"><Avviso tono="red" icona={XCircle}>{errore}</Avviso></div>}
      </div>
    </div>
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
  const [selezionato,  setSelezionato]  = useState(null)       // backup aperto in dettaglio
  const [scelti,       setScelti]       = useState(new Set())  // backup spuntati per l'eliminazione
  const [eliminando,   setEliminando]   = useState(false)

  const backup = data?.backup ?? []

  const toggle = (id) => setScelti(prev => {
    const nuovo = new Set(prev)
    nuovo.has(id) ? nuovo.delete(id) : nuovo.add(id)
    return nuovo
  })

  // Seleziona i vecchi: per ogni stagione si tiene il backup completo più recente
  // (l'elenco è già dal più recente) e si selezionano gli altri eliminabili,
  // compresi i backup incompleti.
  const selezionaVecchi = () => {
    const tenuti = new Map()
    for (const b of backup) {
      if (STATI_COMPLETI.includes(b.stato) && !tenuti.has(b.stagione)) tenuti.set(b.stagione, b.bk_id)
    }
    setScelti(new Set(backup.filter(b => b.eliminabile && tenuti.get(b.stagione) !== b.bk_id).map(b => b.bk_id)))
  }

  const chiudiEliminazione = () => { setEliminando(false); setScelti(new Set()); refetch() }

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
      ) : eliminando ? (
        <ConfermaEliminazione
          backup={backup}
          selezionati={scelti}
          onAnnulla={chiudiEliminazione}
          onFatto={() => {}}
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

          {backup.length > 0 && (
            <div className="flex flex-wrap items-center gap-3 mb-4 max-w-3xl">
              <button type="button" onClick={selezionaVecchi}
                      className="text-xs px-3 py-1.5 rounded-lg border border-white/10 text-slate-300 hover:bg-white/5 transition-colors">
                Seleziona i vecchi
              </button>
              {scelti.size > 0 && (
                <>
                  <button type="button" onClick={() => setScelti(new Set())}
                          className="text-xs px-3 py-1.5 rounded-lg border border-white/10 text-slate-400 hover:bg-white/5 transition-colors">
                    Deseleziona
                  </button>
                  <button type="button" onClick={() => setEliminando(true)}
                          className="inline-flex items-center gap-1.5 text-xs px-3 py-1.5 rounded-lg bg-red-500 hover:bg-red-400 text-white font-semibold transition-colors">
                    <Trash2 className="w-3.5 h-3.5" /> Elimina selezionati ({scelti.size})
                  </button>
                </>
              )}
              <span className="text-xs text-slate-600">
                "Seleziona i vecchi" tiene il backup più recente di ogni stagione.
              </span>
            </div>
          )}

          <ElencoBackup backup={backup} selezionati={scelti} onToggle={toggle} onSeleziona={setSelezionato} />
        </>
      )}
    </div>
  )
}
