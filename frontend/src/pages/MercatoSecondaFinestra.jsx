import { useEffect, useMemo, useState } from 'react'
import { useApp } from '../context/AppContext'
import { useFetch } from '../hooks/useFetch'
import {
  getMercato2, getMercato2Svincolati, getMercato2Storico,
  mercato2Avvia, mercato2Azzera, mercato2AnnullaUltima, mercato2Aggiudica, mercato2Passa,
} from '../api/client'
import { PageHeader, LoadingState, ErrorState, EmptyState, RoleBadge, TabBar } from '../components/ui'
import { Gavel, Play, SkipForward, CheckCircle2, AlertTriangle, Undo2, Search, Hourglass, Ban } from 'lucide-react'

const RUOLI = { 1: 'Portieri', 2: 'Difensori', 3: 'Centrocampisti', 4: 'Attaccanti' }
const POLL_MS = 10000

function fmt(dt) {
  if (!dt) return '—'
  const d = new Date(String(dt).replace(' ', 'T'))
  return isNaN(d) ? dt : d.toLocaleString('it-IT', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' })
}

// Budget in forma compatta; rosso se esaurito
function Budget({ b }) {
  if (!b) return <span className="text-slate-600">—</span>
  const cls = b.residuo > 0 ? 'text-grass-400' : b.residuo === 0 ? 'text-gold-400' : 'text-red-400'
  return <b className={`text-mono ${cls}`}>{b.residuo} cr.</b>
}

function etichettaOfferta(b) {
  if (!b || b.max_offerta === null) return 'budget esaurito'
  return b.residuo === 0 ? 'solo a 1 cr.' : `fino a ${b.max_offerta} cr.`
}

// ── Pannello: chiamata del giocatore + aggiudicazione ────────
function PannelloAsta({ stagione, m, onDone }) {
  const [ruolo, setRuolo] = useState(0)
  const [q, setQ] = useState('')
  const [sel, setSel] = useState(null)        // giocatore chiamato
  const [vincitore, setVincitore] = useState(null)
  const [prezzo, setPrezzo] = useState('')
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState(null)

  const { data: svinc, loading } = useFetch(() => getMercato2Svincolati(stagione), [stagione, m.ordine.length])

  const turnoOrd = m.ordine.find(o => o.in_turno)
  const lista = useMemo(() => {
    const t = q.trim().toLowerCase()
    return (svinc ?? []).filter(g => (!ruolo || g.ruolo === ruolo) && (!t || g.descrizione.toLowerCase().includes(t)))
  }, [svinc, ruolo, q])

  // Squadre che possono aggiudicarsi il giocatore selezionato
  const motivo = (o) => {
    if (o.stato !== 'ATTIVA') return 'ha passato'
    if (!o.budget || o.budget.max_offerta === null) return 'budget esaurito'
    if (sel?.ceduto_da?.includes(o.id_squadra)) return 'lo ha ceduto'
    return null
  }
  const vinc = vincitore ? m.ordine.find(o => o.id_squadra === vincitore) : null
  const max = vinc?.budget?.max_offerta ?? null
  const p = Number(prezzo)
  const prezzoOk = vinc && !motivo(vinc) && Number.isInteger(p) && p >= 1 && p <= max
  const chiamabile = (g) => m.ordine.some(o => o.stato === 'ATTIVA' && o.budget?.max_offerta != null && !g.ceduto_da.includes(o.id_squadra))

  const scegli = (g) => { setSel(g); setVincitore(null); setPrezzo(''); setErr(null) }

  const esegui = async (fn) => {
    setBusy(true); setErr(null)
    try { await fn(); setSel(null); setVincitore(null); setPrezzo(''); onDone() }
    catch (e) { setErr(e.message) }
    finally { setBusy(false) }
  }

  return (
    <div className="card p-4 sm:p-5 space-y-5 border-grass-500/30">
      <div className="flex items-center justify-between gap-3 flex-wrap">
        <div>
          <h2 className="text-sm font-semibold text-grass-400">Turno di {turnoOrd?.nome}: chiama un giocatore svincolato</h2>
          {turnoOrd?.budget && (
            <p className="text-xs text-slate-500 mt-0.5">
              Budget {turnoOrd.nome}: <Budget b={turnoOrd.budget} /> su {turnoOrd.budget.totale} · {turnoOrd.budget.giocatori} giocatori in rosa
            </p>
          )}
        </div>
        <button
          disabled={busy}
          onClick={() => window.confirm(`${turnoOrd?.nome} passa il turno? Terminerà la propria finestra di mercato.`) && esegui(() => mercato2Passa(stagione))}
          className="btn-ghost text-xs disabled:opacity-40"
        >
          <SkipForward className="w-3.5 h-3.5 inline mr-1" />Passa (termina la finestra)
        </button>
      </div>

      {/* 1. Chiamata */}
      <div>
        <p className="text-xs text-mono tracking-widest uppercase text-slate-600 mb-2">1 · Giocatore da mettere all'asta</p>
        <div className="flex flex-wrap gap-2 mb-2">
          <div className="flex gap-1">
            {[0, 1, 2, 3, 4].map(r => (
              <button key={r} onClick={() => setRuolo(r)}
                className={`px-3 py-1.5 rounded-lg text-xs font-medium transition-colors ${ruolo === r ? 'bg-grass-500 text-pitch-950' : 'bg-pitch-800 text-slate-400 hover:text-slate-200'}`}>
                {r === 0 ? 'Tutti' : RUOLI[r]}
              </button>
            ))}
          </div>
          <div className="relative flex-1 min-w-[10rem]">
            <Search className="w-4 h-4 text-slate-600 absolute left-2.5 top-1/2 -translate-y-1/2" />
            <input value={q} onChange={e => setQ(e.target.value)} placeholder="Cerca per nome…" className="fanta-input w-full pl-8" />
          </div>
        </div>
        <div className="max-h-72 overflow-y-auto rounded-lg border border-white/5 divide-y divide-white/5">
          {loading && !svinc && <p className="p-3 text-xs text-slate-600">Caricamento…</p>}
          {svinc && !lista.length && <p className="p-3 text-xs text-slate-600">Nessun giocatore trovato</p>}
          {lista.slice(0, 150).map(g => {
            const ok = chiamabile(g)
            return (
              <button key={g.id} disabled={!ok} onClick={() => scegli(g)}
                className={`w-full flex items-center gap-3 px-3 py-2 text-left text-sm transition-colors
                  ${sel?.id === g.id ? 'bg-grass-500/10 text-grass-400' : 'text-slate-300 hover:bg-white/[0.03]'}
                  ${!ok ? 'opacity-40 cursor-not-allowed' : ''}`}>
                <RoleBadge ruolo={g.ruolo} />
                <span className="flex-1 truncate">{g.descrizione}</span>
                <span className="text-[11px] text-slate-600 truncate max-w-[9rem]">{g.serie_a ?? ''}</span>
                {!ok && <span className="text-[10px] text-red-400">nessuna squadra può acquistarlo</span>}
              </button>
            )
          })}
        </div>
        {lista.length > 150 && <p className="text-[11px] text-slate-600 mt-1">Mostrati i primi 150 risultati: restringi la ricerca.</p>}
      </div>

      {/* 2. Aggiudicazione */}
      {sel && (
        <div className="space-y-3">
          <p className="text-xs text-mono tracking-widest uppercase text-slate-600">
            2 · Aggiudicazione di <span className="text-white normal-case">{sel.descrizione}</span>
          </p>
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-2">
            {m.ordine.map(o => {
              const mot = motivo(o)
              const attivo = vincitore === o.id_squadra
              return (
                <button key={o.id_squadra} disabled={!!mot} onClick={() => { setVincitore(o.id_squadra); setErr(null) }}
                  className={`text-left rounded-lg border px-3 py-2 transition-colors
                    ${attivo ? 'border-gold-500/60 bg-gold-500/10' : 'border-white/10 bg-pitch-800 hover:border-white/20'}
                    ${mot ? 'opacity-40 cursor-not-allowed' : ''}`}>
                  <p className={`text-sm font-medium truncate ${attivo ? 'text-gold-400' : 'text-slate-200'}`}>{o.nome}</p>
                  <p className="text-[11px] text-slate-500">
                    {mot ? <span className="text-red-400 flex items-center gap-1"><Ban className="w-3 h-3" />{mot}</span>
                         : <>Residuo <Budget b={o.budget} /> · {etichettaOfferta(o.budget)}</>}
                  </p>
                </button>
              )
            })}
          </div>

          {vinc && (
            <div className="rounded-lg bg-pitch-800 border border-white/10 p-3 space-y-3">
              <div className="flex flex-wrap items-end gap-3">
                <label className="text-xs text-slate-400">
                  Prezzo di aggiudicazione (crediti)
                  <input type="number" inputMode="numeric" min={1} max={max ?? undefined} step={1} value={prezzo}
                    onChange={e => setPrezzo(e.target.value)} autoFocus
                    className="fanta-input block mt-1 w-32 text-lg" />
                </label>
                <p className="text-xs text-slate-500 pb-2">
                  Massimo <b className="text-slate-300">{max}</b> cr.
                  {vinc.budget.residuo === 0 && ' (budget 0: solo un giocatore a 1 credito)'}
                </p>
              </div>
              {prezzo !== '' && !prezzoOk && (
                <p className="text-xs text-red-400">Inserisci un prezzo intero tra 1 e {max}.</p>
              )}
              {prezzoOk && (
                <p className="text-sm text-slate-300">
                  <b className="text-white">{sel.descrizione}</b> va a <b className="text-white">{vinc.nome}</b> per <b className="text-gold-400">{p} cr.</b>
                  {' '}· Residuo di {vinc.nome} dopo l'acquisto: <b className={vinc.budget.residuo - p >= 0 ? 'text-grass-400' : 'text-red-400'}>{vinc.budget.residuo - p} cr.</b>
                </p>
              )}
              <button disabled={busy || !prezzoOk}
                onClick={() => esegui(() => mercato2Aggiudica(stagione, sel.id, vincitore, p))}
                className="btn-primary disabled:opacity-40">
                <CheckCircle2 className="w-4 h-4 inline mr-1" />Conferma aggiudicazione
              </button>
            </div>
          )}
        </div>
      )}
      {err && <p className="text-sm text-red-400 flex items-start gap-2"><AlertTriangle className="w-4 h-4 mt-0.5 flex-shrink-0" />{err}</p>}
    </div>
  )
}

// ── Storico aste ─────────────────────────────────────────────
function Storico({ stagione, refreshKey }) {
  const { data, loading, error } = useFetch(() => getMercato2Storico(stagione), [stagione, refreshKey])
  if (loading && !data) return <LoadingState label="Caricamento aste..." />
  if (error) return <ErrorState message={error} />
  if (!data?.length) return <EmptyState label="Nessuna operazione registrata" />
  return (
    <div className="card overflow-x-auto">
      <table className="fanta-table min-w-[640px]">
        <thead><tr><th>Giro</th><th>Chiamata da</th><th>Giocatore</th><th>Aggiudicato a</th><th className="text-center">Crediti</th><th>Ora</th></tr></thead>
        <tbody>
          {data.map(t => (
            <tr key={t.id}>
              <td>{t.giro}</td>
              <td>{t.chiamante}</td>
              {t.tipo === 'PASSO'
                ? <td colSpan={3} className="text-slate-500 text-xs">Ha passato: finestra terminata</td>
                : <>
                    <td><span className="inline-flex items-center gap-2"><RoleBadge ruolo={t.ruolo} />{t.giocatore}</span></td>
                    <td>{t.aggiudicataria}</td>
                    <td className="text-center text-mono">{t.crediti}</td>
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
export default function MercatoSecondaFinestra() {
  const { stagione, isAdmin } = useApp()
  const [tab, setTab] = useState('finestra')
  const [refreshKey, setRefreshKey] = useState(0)
  const [busy, setBusy] = useState(false)
  const { data: m, loading, error, refetch } = useFetch(stagione ? () => getMercato2(stagione) : null, [stagione, refreshKey])

  const ricarica = () => setRefreshKey(k => k + 1)

  // Aggiornamento periodico: la finestra si svolge in presenza e tutti guardano la stessa pagina
  useEffect(() => {
    if (!m?.avviata || m.stato !== 'ATTIVA') return
    const t = setInterval(refetch, POLL_MS)
    return () => clearInterval(t)
  }, [m?.avviata, m?.stato, refetch])

  const azione = async (fn, conferma) => {
    if (conferma && !window.confirm(conferma)) return
    setBusy(true)
    try { await fn(); ricarica() } catch (e) { window.alert(e.message) } finally { setBusy(false) }
  }

  if (loading && !m) return <LoadingState label="Caricamento mercato..." />
  if (error && !m) return <ErrorState message={error} onRetry={refetch} />
  if (!m) return null

  const attiva = m.avviata && m.stato === 'ATTIVA'
  const turno = m.turno

  return (
    <>
      <PageHeader
        label="Mercato"
        title="Seconda finestra riparazione"
        subtitle={!m.avviata ? 'Finestra in presenza, ad asta: non ancora avviata'
          : attiva ? `In corso — giro ${m.giro}` : 'Finestra conclusa'}
      />

      {!m.ultima_stagione && <EmptyState label="La seconda finestra è disponibile solo per l'ultima stagione" />}

      {m.ultima_stagione && (
        <div className="space-y-6">
          {/* Avvio */}
          {!m.avviata && (
            <div className="card p-5 space-y-4">
              <div className="flex items-start gap-3 flex-wrap">
                <Gavel className="w-5 h-5 text-gold-400 mt-0.5" />
                <div className="flex-1 min-w-[14rem]">
                  <h2 className="text-sm font-semibold text-slate-200">Avvio della finestra</h2>
                  <p className="text-xs text-slate-500 mt-1">
                    Si salva la classifica attuale e si fissa l'ordine di chiamata (inverso della classifica).
                    A turno ogni squadra sottopone ad asta uno svincolato oppure passa, terminando la propria finestra.
                  </p>
                </div>
                {isAdmin
                  ? <button disabled={busy} className="btn-primary disabled:opacity-40"
                      onClick={() => azione(() => mercato2Avvia(stagione), "Avviare la seconda finestra? L'ordine di chiamata sarà fissato sulla classifica attuale.")}>
                      <Play className="w-4 h-4 inline mr-1" />Avvia la finestra
                    </button>
                  : <p className="text-xs text-slate-500">Solo l'amministratore può avviarla.</p>}
              </div>
              <OrdineTabella righe={m.anteprima.map(a => ({ ...a, stato: 'ATTIVA' }))} titolo="Ordine di chiamata previsto" />
            </div>
          )}

          {m.avviata && (
            <>
              {/* Turno corrente */}
              {attiva && turno && (
                <div className={`card p-4 flex items-center gap-4 flex-wrap ${m.puo_operare ? 'border-grass-500/40 bg-grass-500/5' : ''}`}>
                  <Hourglass className="w-5 h-5 text-gold-400 flex-shrink-0" />
                  <div className="min-w-0 flex-1">
                    <p className="text-xs text-mono tracking-widest uppercase text-slate-600">Giro {m.giro} · squadra di turno</p>
                    <p className="text-lg font-bold text-white truncate">{turno.nome}</p>
                  </div>
                  {!m.puo_operare && <p className="text-xs text-slate-500">In attesa della chiamata</p>}
                </div>
              )}
              {!attiva && <p className="text-sm text-slate-500">Tutte le squadre hanno terminato la finestra{m.chiusa_il ? ` (${fmt(m.chiusa_il)})` : ''}.</p>}

              {attiva && m.puo_operare && (
                <PannelloAsta key={`${turno.posizione}-${m.giro}`} stagione={stagione} m={m} onDone={ricarica} />
              )}

              {isAdmin && (
                <div className="flex gap-2 flex-wrap">
                  {m.puo_annullare && (
                    <button disabled={busy} className="btn-ghost text-xs"
                      onClick={() => azione(() => mercato2AnnullaUltima(stagione), "Annullare l'ultima operazione? Il giocatore tornerà svincolato e il turno sarà ripristinato.")}>
                      <Undo2 className="w-3.5 h-3.5 inline mr-1" />Annulla ultima operazione
                    </button>
                  )}
                  {!m.puo_annullare && (
                    <button disabled={busy} className="btn-ghost text-xs"
                      onClick={() => azione(() => mercato2Azzera(stagione), "Annullare l'avvio della finestra? Potrai riavviarla con la classifica aggiornata.")}>
                      <Undo2 className="w-3.5 h-3.5 inline mr-1" />Annulla avvio finestra
                    </button>
                  )}
                </div>
              )}

              <TabBar tabs={[{ value: 'finestra', label: 'Ordine e budget' }, { value: 'storico', label: 'Aste' }]} active={tab} onChange={setTab} />
              {tab === 'finestra' && <OrdineTabella righe={m.ordine} titolo={`Ordine di chiamata · budget ${m.budget_totale} crediti`} />}
              {tab === 'storico' && <Storico stagione={stagione} refreshKey={refreshKey} />}
            </>
          )}

          <p className="text-[11px] text-slate-700">
            Si acquista solo con budget già disponibile; con budget 0 solo un giocatore a 1 credito. Una squadra non può
            aggiudicarsi giocatori da lei ceduti in precedenti finestre di mercato. Chi passa non partecipa più alla finestra.
          </p>
        </div>
      )}
    </>
  )
}

function OrdineTabella({ righe, titolo }) {
  return (
    <div className="card overflow-x-auto">
      <div className="px-4 py-3 border-b border-white/5">
        <h2 className="text-sm font-semibold text-slate-300">{titolo}</h2>
      </div>
      <table className="fanta-table min-w-[520px]">
        <thead>
          <tr><th className="w-10">#</th><th>Squadra</th><th className="text-center">Class.</th><th className="text-center">Rosa</th><th className="text-center">Budget residuo</th><th>Stato</th></tr>
        </thead>
        <tbody>
          {righe.map(o => (
            <tr key={o.id_squadra} className={o.in_turno ? 'bg-grass-500/5' : o.stato === 'PASSATO' ? 'opacity-50' : ''}>
              <td>{o.posizione}</td>
              <td>{o.nome}{o.allenatore ? <span className="text-slate-600 text-xs"> · {o.allenatore}</span> : null}</td>
              <td className="text-center text-mono text-slate-500">{o.pos_classifica}°</td>
              <td className="text-center text-mono text-slate-500">{o.budget?.giocatori ?? '—'}</td>
              <td className="text-center"><Budget b={o.budget} /></td>
              <td className={`text-xs ${o.in_turno ? 'text-gold-400 font-medium' : 'text-slate-500'}`}>
                {o.in_turno ? 'Di turno' : o.stato === 'PASSATO' ? 'Ha passato' : 'In gioco'}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}
