import { useState } from 'react'
import { useApp } from '../context/AppContext'
import { useFetch } from '../hooks/useFetch'
import {
  getMercato, getMercatoSvincolati, getMercatoRosa, getMercatoStorico,
  mercatoAcquista, mercatoPassa, mercatoSalta,
} from '../api/client'
import { PageHeader, LoadingState, ErrorState, EmptyState, RoleBadge, TabBar } from '../components/ui'
import { Clock, Repeat, CheckCircle2, SkipForward, Hourglass, AlertTriangle } from 'lucide-react'

const RUOLI = { 1: 'Portiere', 2: 'Difensore', 3: 'Centrocampista', 4: 'Attaccante' }

function fmt(dt) {
  if (!dt) return '—'
  const d = new Date(String(dt).replace(' ', 'T'))
  return isNaN(d) ? dt : d.toLocaleString('it-IT', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' })
}

const STATO_ORDINE = {
  ATTESA:  { label: 'In attesa', cls: 'text-slate-500' },
  FATTO:   { label: 'Ha operato', cls: 'text-grass-400' },
  PASSATO: { label: 'Ha passato', cls: 'text-slate-400' },
}

// ── Pannello operativo della squadra di turno ────────────────
function PannelloOperazione({ stagione, idSquadra, mercato, onDone }) {
  const [acq, setAcq] = useState(null)       // svincolato selezionato
  const [ruolo, setRuolo] = useState(0)
  const [q, setQ] = useState('')
  const [ced, setCed] = useState(null)       // giocatore ceduto
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState(null)

  const { data: svinc, loading: lSv } = useFetch(
    () => getMercatoSvincolati(stagione, ruolo, q), [stagione, ruolo, q])
  const { data: rosa } = useFetch(() => getMercatoRosa(stagione, idSquadra), [stagione, idSquadra])

  const prezzo = acq ? mercato.prezzi[acq.ruolo] : 0
  const candidati = acq ? (rosa ?? []).filter(g => g.ruolo === acq.ruolo) : []
  const saldo = acq && ced ? prezzo - ced.crediti : null

  const esegui = async (fn) => {
    setBusy(true); setErr(null)
    try { await fn(); setAcq(null); setCed(null); onDone() }
    catch (e) { setErr(e.message) }
    finally { setBusy(false) }
  }

  return (
    <div className="card p-4 sm:p-5 space-y-5 border-grass-500/30">
      <div className="flex items-center justify-between gap-3 flex-wrap">
        <h2 className="text-sm font-semibold text-grass-400">È il tuo turno: scegli l'operazione</h2>
        <button
          disabled={busy}
          onClick={() => window.confirm('Vuoi passare il turno senza operare?') && esegui(() => mercatoPassa(stagione, idSquadra))}
          className="btn-ghost text-xs disabled:opacity-40"
        >
          <SkipForward className="w-3.5 h-3.5 inline mr-1" />Passa il turno
        </button>
      </div>

      {/* 1. Svincolato */}
      <div>
        <p className="text-xs text-mono tracking-widest uppercase text-slate-600 mb-2">1 · Giocatore da acquistare (svincolati)</p>
        <div className="flex flex-wrap gap-2 mb-2">
          <select value={ruolo} onChange={e => { setRuolo(Number(e.target.value)); setAcq(null); setCed(null) }} className="fanta-input w-auto">
            <option value={0}>Tutti i ruoli</option>
            {[1, 2, 3, 4].map(r => <option key={r} value={r}>{RUOLI[r]} — {mercato.prezzi[r]} cr.</option>)}
          </select>
          <input value={q} onChange={e => setQ(e.target.value)} placeholder="Cerca per nome…" className="fanta-input flex-1 min-w-[10rem]" />
        </div>
        <div className="max-h-64 overflow-y-auto rounded-lg border border-white/5 divide-y divide-white/5">
          {lSv && <p className="p-3 text-xs text-slate-600">Caricamento…</p>}
          {!lSv && !(svinc ?? []).length && <p className="p-3 text-xs text-slate-600">Nessun giocatore trovato</p>}
          {(svinc ?? []).slice(0, 200).map(g => (
            <button
              key={g.id}
              disabled={!g.acquistabile}
              onClick={() => { setAcq(g); setCed(null) }}
              className={`w-full flex items-center gap-3 px-3 py-2 text-left text-sm transition-colors
                ${acq?.id === g.id ? 'bg-grass-500/10 text-grass-400' : 'text-slate-300 hover:bg-white/[0.03]'}
                ${!g.acquistabile ? 'opacity-40 cursor-not-allowed' : ''}`}
            >
              <RoleBadge ruolo={g.ruolo} />
              <span className="flex-1 truncate">{g.descrizione}</span>
              {!g.acquistabile
                ? <span className="text-[10px] text-slate-600">ceduto: non acquistabile</span>
                : <span className="text-xs text-mono text-slate-500">{mercato.prezzi[g.ruolo]} cr.</span>}
            </button>
          ))}
        </div>
      </div>

      {/* 2. Cessione */}
      {acq && (
        <div>
          <p className="text-xs text-mono tracking-widest uppercase text-slate-600 mb-2">
            2 · Giocatore da cedere ({RUOLI[acq.ruolo].toLowerCase()} della tua rosa)
          </p>
          <div className="rounded-lg border border-white/5 divide-y divide-white/5">
            {!candidati.length && <p className="p-3 text-xs text-slate-600">Non hai giocatori di questo ruolo da cedere</p>}
            {candidati.map(g => (
              <button
                key={g.id}
                onClick={() => setCed(g)}
                className={`w-full flex items-center gap-3 px-3 py-2 text-left text-sm transition-colors
                  ${ced?.id === g.id ? 'bg-gold-500/10 text-gold-400' : 'text-slate-300 hover:bg-white/[0.03]'}`}
              >
                <RoleBadge ruolo={g.ruolo} />
                <span className="flex-1 truncate">{g.descrizione}</span>
                <span className="text-xs text-mono text-slate-500">recuperi {g.crediti} cr.</span>
              </button>
            ))}
          </div>
        </div>
      )}

      {/* 3. Conferma */}
      {acq && ced && (
        <div className="rounded-lg bg-pitch-800 border border-white/10 p-3 text-sm space-y-1">
          <p className="text-slate-300">Acquisti <b className="text-white">{acq.descrizione}</b> per <b>{prezzo} cr.</b></p>
          <p className="text-slate-300">Cedi <b className="text-white">{ced.descrizione}</b> e recuperi <b>{ced.crediti} cr.</b></p>
          <p className="text-xs text-slate-500">
            Saldo crediti: {saldo > 0 ? `−${saldo}` : saldo < 0 ? `+${-saldo}` : '0'}
          </p>
          <button
            disabled={busy}
            onClick={() => esegui(() => mercatoAcquista(stagione, idSquadra, acq.id, ced.id))}
            className="btn-primary mt-2 disabled:opacity-40"
          >
            <CheckCircle2 className="w-4 h-4 inline mr-1" />Conferma operazione
          </button>
        </div>
      )}
      {err && <p className="text-sm text-red-400 flex items-start gap-2"><AlertTriangle className="w-4 h-4 mt-0.5 flex-shrink-0" />{err}</p>}
    </div>
  )
}

// ── Storico transazioni ──────────────────────────────────────
function Storico({ stagione, refreshKey }) {
  const { data, loading, error } = useFetch(() => getMercatoStorico(stagione), [stagione, refreshKey])
  if (loading) return <LoadingState label="Caricamento transazioni..." />
  if (error) return <ErrorState message={error} />
  if (!data?.length) return <EmptyState label="Nessuna operazione effettuata" />
  return (
    <div className="card overflow-x-auto">
      <table className="fanta-table min-w-[640px]">
        <thead>
          <tr><th>Sess.</th><th>Squadra</th><th>Acquista</th><th>Cede</th><th className="text-center">Saldo</th><th>Data</th></tr>
        </thead>
        <tbody>
          {data.map(t => (
            <tr key={t.id}>
              <td>{t.sessione}</td>
              <td>{t.squadra}</td>
              {t.tipo === 'PASSO'
                ? <td colSpan={3} className="text-slate-500 text-xs">Ha passato il turno</td>
                : <>
                    <td><span className="inline-flex items-center gap-2"><RoleBadge ruolo={t.ruolo} />{t.giocatore_acquistato} <span className="text-slate-600 text-xs">{t.crediti_acquisto} cr.</span></span></td>
                    <td>{t.giocatore_ceduto} <span className="text-slate-600 text-xs">+{t.crediti_recuperati} cr.</span></td>
                    <td className="text-center text-mono">{t.crediti_acquisto - t.crediti_recuperati}</td>
                  </>}
              <td className="text-xs text-slate-500">{fmt(t.creato_il)}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

// ── Pagina ───────────────────────────────────────────────────
export default function MercatoRiparazione() {
  const { stagione, utente, isAdmin } = useApp()
  const [tab, setTab] = useState('sessione')
  const [refreshKey, setRefreshKey] = useState(0)
  const { data: m, loading, error, refetch } = useFetch(stagione ? () => getMercato(stagione) : null, [stagione, refreshKey])

  const ricarica = () => { setRefreshKey(k => k + 1); refetch() }

  if (loading && !m) return <LoadingState label="Caricamento mercato..." />
  if (error) return <ErrorState message={error} onRetry={refetch} />
  if (!m) return null

  const sess = m.sessione_corrente
  const aperta = sess?.stato === 'APERTA'

  return (
    <>
      <PageHeader
        label="Mercato di riparazione"
        title="Prima finestra"
        subtitle={m.attivo ? `4 sessioni — dopo la giornata ${m.giornata_inizio}` : 'Mercato non attivo per questa stagione'}
      />

      {!m.attivo && <EmptyState label="Il mercato di riparazione non è stato configurato per questa stagione" />}

      {m.attivo && (
        <div className="space-y-6">
          {/* Sessioni */}
          <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
            {m.sessioni.map(s => (
              <div key={s.sessione} className={`card p-3 ${s.stato === 'APERTA' ? 'border-grass-500/40 bg-grass-500/5' : ''}`}>
                <p className="text-[10px] text-mono tracking-widest uppercase text-slate-600">Sessione {s.sessione}</p>
                <p className={`text-sm font-semibold mt-1 ${s.stato === 'APERTA' ? 'text-grass-400' : s.stato === 'CHIUSA' ? 'text-slate-400' : 'text-slate-600'}`}>
                  {s.stato === 'APERTA' ? 'Aperta' : s.stato === 'CHIUSA' ? 'Conclusa' : 'Da aprire'}
                </p>
                <p className="text-[11px] text-slate-600 mt-0.5">
                  {s.stato === 'DA_APRIRE' ? `Dopo la giornata ${s.giornata_apertura}` : `Aperta il ${fmt(s.aperta_il)}`}
                </p>
              </div>
            ))}
          </div>

          <TabBar
            tabs={[{ value: 'sessione', label: 'Sessione' }, { value: 'storico', label: 'Transazioni' }]}
            active={tab} onChange={setTab}
          />

          {tab === 'storico' && <Storico stagione={stagione} refreshKey={refreshKey} />}

          {tab === 'sessione' && !sess && (
            <EmptyState label={`La prima sessione si aprirà alla chiusura della giornata ${m.giornata_inizio}`} />
          )}

          {tab === 'sessione' && sess && (
            <>
              {/* Turno corrente */}
              {aperta && m.turno && (
                <div className={`card p-4 flex items-center gap-4 flex-wrap ${m.tuo_turno ? 'border-grass-500/40 bg-grass-500/5' : ''}`}>
                  <Hourglass className="w-5 h-5 text-gold-400 flex-shrink-0" />
                  <div className="min-w-0 flex-1">
                    <p className="text-xs text-mono tracking-widest uppercase text-slate-600">Sessione {sess.sessione} · squadra chiamata ad agire</p>
                    <p className="text-lg font-bold text-white truncate">{m.turno.nome}</p>
                  </div>
                  <div className="text-right">
                    <p className="text-[11px] text-slate-600 flex items-center gap-1 justify-end"><Clock className="w-3 h-3" />Tempo limite</p>
                    <p className={`text-sm font-medium ${m.turno.scaduto ? 'text-red-400' : 'text-slate-300'}`}>
                      {fmt(m.turno.scadenza)}{m.turno.scaduto ? ' (scaduto)' : ''}
                    </p>
                  </div>
                  {isAdmin && (
                    <button
                      className="btn-ghost text-xs"
                      onClick={async () => {
                        if (!window.confirm(`Saltare il turno di ${m.turno.nome}?`)) return
                        try { await mercatoSalta(stagione, m.turno.id_squadra); ricarica() } catch (e) { window.alert(e.message) }
                      }}
                    >Salta turno</button>
                  )}
                </div>
              )}
              {aperta && !m.turno && <p className="text-sm text-slate-500">Tutte le squadre hanno agito: la sessione terminerà all'inizio della prossima giornata.</p>}
              {!aperta && <p className="text-sm text-slate-500">La sessione {sess.sessione} è conclusa{m.prossima_apertura ? `; la prossima si aprirà alla chiusura della giornata ${m.prossima_apertura}` : ''}.</p>}

              {aperta && m.tuo_turno && utente && (
                <PannelloOperazione stagione={stagione} idSquadra={utente.id} mercato={m} onDone={ricarica} />
              )}

              {/* Ordine di chiamata */}
              <div className="card overflow-hidden">
                <div className="px-4 py-3 border-b border-white/5 flex items-center gap-2">
                  <Repeat className="w-4 h-4 text-slate-500" />
                  <h2 className="text-sm font-semibold text-slate-300">Ordine di chiamata — sessione {sess.sessione}</h2>
                </div>
                <table className="fanta-table">
                  <thead><tr><th className="w-10">#</th><th>Squadra</th><th>Stato</th></tr></thead>
                  <tbody>
                    {m.ordine.map(o => (
                      <tr key={o.id_squadra} className={o.in_turno ? 'bg-grass-500/5' : ''}>
                        <td>{o.posizione}</td>
                        <td>{o.nome}{o.allenatore ? <span className="text-slate-600 text-xs"> · {o.allenatore}</span> : null}</td>
                        <td className={`text-xs ${o.in_turno ? 'text-gold-400 font-medium' : STATO_ORDINE[o.stato]?.cls}`}>
                          {o.in_turno ? 'Tocca a lui' : STATO_ORDINE[o.stato]?.label}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              <p className="text-[11px] text-slate-700">
                Prezzi fissi: portiere {m.prezzi[1]} · difensore {m.prezzi[2]} · centrocampista {m.prezzi[3]} · attaccante {m.prezzi[4]} crediti.
                I crediti pagati per il giocatore ceduto vengono recuperati. Non sono acquistabili i giocatori ceduti nel mercato.
              </p>
            </>
          )}
        </div>
      )}
    </>
  )
}
