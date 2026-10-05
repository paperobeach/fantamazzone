import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useApp } from '../context/AppContext'
import { useFetch } from '../hooks/useFetch'
import {
  getLiveGiornata, getLiveDettaglio, getLiveGiocatori,
  simulaGiornata, salvaEditLive, eliminaEditLive, eliminaSimulazionePartita,
} from '../api/client'
import { PageHeader, LoadingState, ErrorState, EmptyState, Spinner, RoleBadge } from '../components/ui'
import { MatchDetailPanel } from '../components/MatchDetail'
import TeamLogo from '../components/TeamLogo'
import {
  ChevronDown, Radio, Play, Trash2, Clock, Pencil, Save, Undo2, AlertTriangle, CheckCircle2, User, CalendarClock, MinusCircle, Calculator,
} from 'lucide-react'

// Nome dell'utente registrato come autore delle simulazioni
function nomeUtente(utente) {
  return utente?.descrizione || utente?.utenza || 'Ospite'
}

function fmtDataOra(v) {
  if (!v) return null
  const d = new Date(String(v).replace(' ', 'T'))
  return isNaN(d) ? String(v) : d.toLocaleString('it-IT', { dateStyle: 'short', timeStyle: 'medium' })
}

// ── Punteggio (gol) di una partita; null = non disponibile ──
function Gol({ r }) {
  if (!r) return <span className="text-slate-700 font-mono text-sm">—</span>
  const cls = (win, draw) =>
    win ? 'bg-green-500/15 text-green-400' : draw ? 'bg-yellow-500/15 text-yellow-400' : 'bg-pitch-800 text-slate-400'
  const pari = r.golf === r.gols
  return (
    <div className="flex items-center gap-1.5">
      <span className={`w-8 h-8 rounded-lg flex items-center justify-center text-sm font-bold ${cls(r.golf > r.gols, pari)}`}>{r.golf}</span>
      <span className="text-slate-600 text-xs font-mono">:</span>
      <span className={`w-8 h-8 rounded-lg flex items-center justify-center text-sm font-bold ${cls(r.gols > r.golf, pari)}`}>{r.gols}</span>
    </div>
  )
}

function Punti({ r }) {
  if (!r) return null
  return (
    <span className="font-mono text-[11px] text-slate-500">
      {Number(r.ftotale_casa).toFixed(1)} <span className="text-slate-700">vs</span> {Number(r.ftotale_ospite).toFixed(1)}
    </span>
  )
}

// ── Editor voti (admin) ─────────────────────────────────────
const CAMPI = [
  ['gf', '⚽'], ['ass', 'Ass'], ['gs', 'GS'], ['au', 'AG'],
  ['rp', 'Rp'], ['rf', 'Rf'], ['rs', 'Rs'], ['amm', '🟨'], ['esp', '🟥'],
]
const TITOLI_CAMPI = {
  gf: 'Gol fatti', ass: 'Assist', gs: 'Gol subiti', au: 'Autogol',
  rp: 'Rigori parati', rf: 'Rigori realizzati', rs: 'Rigori sbagliati', amm: 'Ammonizioni', esp: 'Espulsioni',
}

function RigaEdit({ g, stagione, onChanged }) {
  const { utente } = useApp()
  const [form, setForm] = useState(() => ({
    voto: g.voto ?? 6, gf: g.gf, gs: g.gs, rp: g.rp, rs: g.rs, rf: g.rf, au: g.au, amm: g.amm, esp: g.esp, ass: g.ass,
  }))
  const [busy, setBusy] = useState(false)
  const [err, setErr]   = useState(null)

  const set = (k, v) => setForm(f => ({ ...f, [k]: v }))

  const run = async (fn) => {
    setBusy(true); setErr(null)
    try {
      await fn()   // il backend ricalcola e salva la sola partita del giocatore
      onChanged()
    } catch (e) {
      setErr(e.message)
    } finally {
      setBusy(false)
    }
  }

  const salva = () => run(() => salvaEditLive(Number(stagione), {
    id_giocatore: g.id_giocatore,
    utente: nomeUtente(utente),
    voto: Number(form.voto),
    ...Object.fromEntries(CAMPI.map(([k]) => [k, Number(form[k]) || 0])),
  }))
  const reset = () => run(async () => {
    await eliminaEditLive(Number(stagione), g.id_giocatore, nomeUtente(utente))
    setForm(f => ({ ...f, voto: 6, gf: 0, gs: 0, rp: 0, rs: 0, rf: 0, au: 0, amm: 0, esp: 0, ass: 0 }))
  })

  const badge = {
    reale:       <span className="text-[10px] font-mono uppercase text-slate-500">voto reale</span>,
    senza_voto:  <span className="text-[10px] font-mono uppercase text-red-400">senza voto</span>,
    provvisorio: <span className="text-[10px] font-mono uppercase text-sky-400">6 provvisorio</span>,
    manuale:     <span className="text-[10px] font-mono uppercase text-violet-400">manuale</span>,
  }[g.stato_dato]

  return (
    <tr className={`border-b border-white/[0.03] ${g.modificabile ? '' : 'opacity-60'}`}>
      <td className="px-2 py-2"><RoleBadge ruolo={g.ruolo} /></td>
      <td className="px-2 py-2 text-slate-300 text-xs min-w-[150px]">
        <div className="font-medium">{g.giocatore} {!g.titolare && <span className="text-slate-600">(panchina)</span>}</div>
        <div className="flex items-center gap-2 mt-0.5">
          {badge}
          {g.squadra_serie_a && <span className="text-[10px] text-slate-600">{g.squadra_serie_a}</span>}
        </div>
        {err && <div className="text-[10px] text-red-400 mt-1">{err}</div>}
      </td>
      {g.modificabile ? (
        <>
          <td className="px-1 py-2">
            <input type="number" step="0.5" min="0" max="10" value={form.voto}
              onChange={e => set('voto', e.target.value)}
              className="fanta-input !px-2 !py-1 !w-16 text-center" aria-label="Voto" />
          </td>
          {CAMPI.map(([k, label]) => (
            <td key={k} className="px-1 py-2">
              <input type="number" min="0" max="20" value={form[k]}
                onChange={e => set(k, e.target.value)} title={TITOLI_CAMPI[k]} aria-label={TITOLI_CAMPI[k]}
                className="fanta-input !px-1 !py-1 !w-12 text-center" />
            </td>
          ))}
          <td className="px-2 py-2 whitespace-nowrap">
            <button onClick={salva} disabled={busy} className="btn-ghost !px-2 !py-1 text-green-400" title="Salva e ricalcola">
              {busy ? <Spinner size="sm" /> : <Save className="w-4 h-4" />}
            </button>
            {g.stato_dato === 'manuale' && (
              <button onClick={reset} disabled={busy} className="btn-ghost !px-2 !py-1" title="Ripristina il 6 provvisorio">
                <Undo2 className="w-4 h-4" />
              </button>
            )}
          </td>
        </>
      ) : (
        <td colSpan={CAMPI.length + 2} className="px-2 py-2 text-[11px] text-slate-600 italic">{g.motivo_blocco}</td>
      )}
    </tr>
  )
}

function EditorSquadra({ stagione, squadra, onChanged }) {
  const { data, loading, error, refetch } = useFetch(
    () => getLiveGiocatori(Number(stagione), squadra.id), [stagione, squadra.id])

  if (loading && !data) return <div className="py-6 text-center"><Spinner /></div>
  if (error) return <p className="px-3 py-4 text-xs text-red-400">{error}</p>
  const giocatori = data?.giocatori ?? []
  if (!giocatori.length) return <p className="px-3 py-4 text-xs text-slate-600">Nessuna formazione inserita per questa squadra.</p>

  return (
    <div className="overflow-x-auto">
      <table className="w-full text-xs min-w-[640px]">
        <thead>
          <tr className="border-b border-white/5 text-slate-700 font-mono uppercase tracking-widest">
            <th className="px-2 py-2 text-left font-normal">R</th>
            <th className="px-2 py-2 text-left font-normal">Giocatore</th>
            <th className="px-1 py-2 font-normal">Voto</th>
            {CAMPI.map(([k, l]) => <th key={k} className="px-1 py-2 font-normal" title={TITOLI_CAMPI[k]}>{l}</th>)}
            <th />
          </tr>
        </thead>
        <tbody>
          {giocatori.map(g => (
            <RigaEdit key={`${g.id_giocatore}-${g.stato_dato}-${g.voto}`} g={g} stagione={stagione}
              onChanged={() => { refetch(); onChanged() }} />
          ))}
        </tbody>
      </table>
    </div>
  )
}

function EditorPartita({ stagione, partita, onChanged }) {
  const [lato, setLato] = useState('casa')
  const sq = partita[lato]
  return (
    <div className="border-t border-white/5 bg-violet-400/[0.02]">
      <div className="px-3 py-2 flex items-center gap-2 flex-wrap">
        <Pencil className="w-3.5 h-3.5 text-violet-400" />
        <span className="text-xs text-slate-400">Editing voti (solo giocatori la cui partita di Serie A non è ancora stata giocata):</span>
        {['casa', 'ospite'].map(l => (
          <button key={l} onClick={() => setLato(l)}
            className={`px-2.5 py-1 rounded-md text-xs ${lato === l ? 'bg-violet-500/20 text-violet-300' : 'text-slate-500 hover:text-slate-300'}`}>
            {partita[l].nome}
          </button>
        ))}
      </div>
      <EditorSquadra key={sq.id} stagione={stagione} squadra={sq} onChanged={onChanged} />
    </div>
  )
}

// ── Riga partita ────────────────────────────────────────────
function MatchRow({ partita, stagione, versione, onChanged }) {
  const { utente } = useApp()
  const { casa, ospite, fonte, risultato, simulazione, provvisori = 0, manuali = 0 } = partita
  const [open, setOpen]     = useState(false)
  const [editing, setEdit]  = useState(false)
  const [busy, setBusy]    = useState(false)
  const [msg, setMsg]      = useState(null)   // { tipo: 'ok' | 'err', testo }
  const isReale = fonte === 'reale'
  const isSim   = fonte === 'simulazione'

  // Rappresentazione unica: il backend sceglie NEW_RISULTATI se presente, altrimenti la simulazione
  const { data: det, loading, error } = useFetch(
    () => (open && fonte) ? getLiveDettaglio(Number(stagione), casa.id, 'auto') : Promise.resolve(null),
    [open, fonte, stagione, casa.id, versione]
  )

  const stop = (e) => e.stopPropagation()

  // Azioni sulla SINGOLA partita: simula/salva e cancella
  const esegui = async (fn, okMsg) => {
    setBusy(true); setMsg(null)
    try {
      const res = await fn()
      setMsg({ tipo: 'ok', testo: okMsg(res) })
      onChanged()
    } catch (e) {
      setMsg({ tipo: 'err', testo: e.message })
    } finally {
      setBusy(false)
    }
  }
  const simulaQui = () => esegui(
    () => simulaGiornata(Number(stagione), casa.id, nomeUtente(utente)),
    (r) => r.partite_elaborate > 0 ? 'Partita simulata e salvata' : (r.partite_saltate?.[0] ?? 'Nessuna partita elaborata'))
  const eliminaQui = () => {
    if (!window.confirm(`Cancellare la simulazione di ${casa.nome} - ${ospite.nome}?\nVerranno rimossi anche i voti inseriti manualmente per questa partita.`)) return
    esegui(() => eliminaSimulazionePartita(Number(stagione), casa.id),
      (r) => `Simulazione cancellata${r.edit_rimossi ? ` (${r.edit_rimossi} voti manuali rimossi)` : ''}`)
  }

  return (
    <div className="border-b border-white/[0.03] last:border-0">
      <div role="button" tabIndex={0}
        onClick={() => setOpen(o => !o)}
        onKeyDown={(e) => (e.key === 'Enter' || e.key === ' ') && setOpen(o => !o)}
        className="flex items-center gap-2 sm:gap-4 py-3 px-4 cursor-pointer hover:bg-white/[0.02] transition-colors">
        <Link onClick={stop} to={`/squadre/${casa.id}`} className="flex items-center gap-2 flex-1 justify-end group min-w-0">
          <span className="text-sm text-slate-300 group-hover:text-grass-400 font-medium text-right truncate">{casa.nome}</span>
          <TeamLogo logo={casa.logo} nome={casa.nome} size="sm" />
        </Link>

        {/* Risultato unico: calcolato se presente, altrimenti simulato */}
        <div className="flex-shrink-0 flex items-center gap-2"
             title={isReale ? 'Risultato calcolato (non definitivo)' : isSim ? 'Risultato simulato con i voti disponibili' : 'Nessun risultato disponibile'}>
          <span className={`flex items-center justify-end gap-1 w-10 text-[9px] font-mono uppercase tracking-wider ${isReale ? 'text-grass-400' : 'text-sky-400'}`}>
            {isReale && <><Calculator className="w-3 h-3" aria-hidden="true" /><span className="hidden sm:inline">Calc.</span></>}
            {isSim && <><Play className="w-3 h-3" aria-hidden="true" /><span className="hidden sm:inline">Sim.</span></>}
          </span>
          <Gol r={risultato} />
          <span className="hidden sm:inline w-24 text-left"><Punti r={risultato} /></span>
          {isSim && (provvisori > 0 || manuali > 0) && (
            <span className="flex items-center gap-1.5 text-[10px] font-mono">
              {provvisori > 0 && (
                <span className="flex items-center gap-0.5 text-sky-400" title={`${provvisori} giocatori con 6 provvisorio`}>
                  <Clock className="w-3 h-3" aria-hidden="true" />{provvisori}
                </span>
              )}
              {manuali > 0 && (
                <span className="flex items-center gap-0.5 text-violet-400" title={`${manuali} voti inseriti manualmente`}>
                  <Pencil className="w-3 h-3" aria-hidden="true" />{manuali}
                </span>
              )}
            </span>
          )}
        </div>

        <Link onClick={stop} to={`/squadre/${ospite.id}`} className="flex items-center gap-2 flex-1 group min-w-0">
          <TeamLogo logo={ospite.logo} nome={ospite.nome} size="sm" />
          <span className="text-sm text-slate-300 group-hover:text-grass-400 font-medium truncate">{ospite.nome}</span>
        </Link>

        <ChevronDown className={`w-4 h-4 flex-shrink-0 text-slate-600 transition-transform ${open ? 'rotate-180' : ''}`} />
      </div>

      {/* Data/ora e utente dell'ultima simulazione: solo se si sta mostrando la simulazione */}
      {isSim && simulazione && (
        <p className="px-4 pb-2 -mt-1 flex items-center justify-center gap-x-3 gap-y-0.5 flex-wrap text-[10px] font-mono text-slate-500"
           title="Ultima simulazione di questa partita">
          <span className="flex items-center gap-1 text-sky-400">
            <CalendarClock className="w-3 h-3" aria-hidden="true" />{fmtDataOra(simulazione.calcolato_il) ?? '—'}
          </span>
          <span className="flex items-center gap-1 text-slate-400">
            <User className="w-3 h-3" aria-hidden="true" />{simulazione.simulato_da || 'utente non registrato'}
          </span>
        </p>
      )}

      {open && (
        <>
          <div className="px-4 py-2 flex items-center gap-2 flex-wrap border-t border-white/5">
            <span className={`flex items-center gap-1.5 text-xs font-semibold ${isReale ? 'text-grass-400' : 'text-sky-400'}`}>
              {isReale ? <Calculator className="w-3.5 h-3.5" /> : <Play className="w-3.5 h-3.5" />}
              {isReale ? 'Risultato calcolato' : isSim ? 'Risultato simulato' : 'Nessun risultato'}
            </span>
            {/* Con dati calcolati la simulazione non viene mostrata: simula/modifica solo se serve */}
            <div className="ml-auto flex items-center gap-2 flex-wrap">
              {!isReale && (
                <button onClick={simulaQui} disabled={busy}
                  className="px-3 py-1 rounded-md text-xs flex items-center gap-1.5 bg-sky-500/15 text-sky-300 hover:bg-sky-500/25 disabled:opacity-40">
                  {busy ? <Spinner size="sm" /> : <Play className="w-3 h-3" />} Simula partita
                </button>
              )}
              <button onClick={eliminaQui} disabled={busy || !simulazione}
                className="px-3 py-1 rounded-md text-xs flex items-center gap-1.5 bg-pitch-800 text-slate-400 hover:text-red-400 disabled:opacity-30">
                <Trash2 className="w-3 h-3" /> Cancella simulazione
              </button>
              {!isReale && (
                <button onClick={() => setEdit(e => !e)}
                  className={`px-3 py-1 rounded-md text-xs flex items-center gap-1.5 ${
                    editing ? 'bg-violet-500/20 text-violet-300' : 'bg-pitch-800 text-slate-400 hover:text-slate-200'}`}>
                  <Pencil className="w-3 h-3" /> Modifica voti
                </button>
              )}
            </div>
          </div>
          {isReale && simulazione && (
            <p className="px-4 pb-2 text-[11px] text-slate-600">
              Esistono dati calcolati per questa partita: la simulazione salvata non viene mostrata.
            </p>
          )}
          {msg && (
            <p className={`px-4 pb-2 text-xs ${msg.tipo === 'ok' ? 'text-green-400' : 'text-red-400'}`}>{msg.testo}</p>
          )}
          {fonte
            ? <MatchDetailPanel casa={det?.casa} ospite={det?.ospite} loading={loading} error={error} />
            : <p className="px-4 py-6 text-center text-xs text-slate-600">Nessun calcolo disponibile: usare "Simula partita" o "Simula giornata".</p>}
          {!isReale && editing && <EditorPartita stagione={stagione} partita={partita} onChanged={onChanged} />}
        </>
      )}
    </div>
  )
}

// ── Pagina ──────────────────────────────────────────────────
export default function LiveGiornata() {
  const { stagione, utente } = useApp()
  const [versione, setVersione] = useState(0)
  const [simulando, setSimulando] = useState(false)
  const [esito, setEsito] = useState(null)
  const [errSim, setErrSim] = useState(null)

  const { data, loading, error, refetch } = useFetch(
    stagione ? () => getLiveGiornata(Number(stagione)) : null, [stagione])

  const aggiorna = () => { setVersione(v => v + 1); refetch() }

  const simula = async () => {
    setSimulando(true); setErrSim(null); setEsito(null)
    try {
      setEsito(await simulaGiornata(Number(stagione), null, nomeUtente(utente)))
      aggiorna()
    } catch (e) {
      setErrSim(e.message)
    } finally {
      setSimulando(false)
    }
  }

  if (loading && !data) return <LoadingState label="Caricamento giornata in corso..." />
  if (error) return <ErrorState message={error} onRetry={refetch} />
  if (!data) return null

  const { stato, partite } = data

  if (!stato.in_corso) {
    return (
      <div className="animate-fade-up">
        <PageHeader label="Campionato" title="LIVE Giornata in corso" />
        <EmptyState label={stato.motivo ?? 'Nessuna giornata in corso'} />
      </div>
    )
  }

  const calcolatoIl = fmtDataOra(data.simulazione_calcolata_il)

  return (
    <div className="animate-fade-up">
      <PageHeader label="Campionato" title="LIVE Giornata in corso"
        subtitle={`Giornata ${stato.giornata} — risultati non ancora definitivi`}>
        {/* La simulazione è libera: disponibile a qualsiasi utente */}
        <button onClick={simula} disabled={simulando} className="btn-primary">
          {simulando ? <Spinner size="sm" /> : <Play className="w-4 h-4" />} Simula giornata
        </button>
      </PageHeader>

      {errSim && (
        <div className="card p-3 mb-4 border-red-500/30 text-xs text-red-400 flex items-start gap-2">
          <AlertTriangle className="w-4 h-4 shrink-0" /> {errSim}
        </div>
      )}
      {esito && (
        <div className="card p-3 mb-4 text-xs text-slate-400">
          <p className="flex items-center gap-2 text-green-400">
            <CheckCircle2 className="w-4 h-4" /> Simulazione completata: {esito.partite_elaborate} partite
            {esito.provvisori > 0 && `, ${esito.provvisori} voti provvisori`}
            {esito.manuali > 0 && `, ${esito.manuali} manuali`}.
          </p>
          {esito.partite_saltate?.length > 0 && (
            <ul className="mt-2 text-amber-400 list-disc pl-5">
              {esito.partite_saltate.map((m, i) => <li key={i}>{m}</li>)}
            </ul>
          )}
        </div>
      )}

      {/* Legenda */}
      <div className="flex flex-wrap items-center gap-x-5 gap-y-1 mb-4 text-[11px] text-slate-500">
        <span className="flex items-center gap-1.5"><Calculator className="w-3.5 h-3.5 text-grass-400" /> Calc. = risultato già calcolato (se presente)</span>
        <span className="flex items-center gap-1.5"><Play className="w-3.5 h-3.5 text-sky-400" /> Sim. = simulazione, mostrata solo se manca il calcolato</span>
        <span className="flex items-center gap-1.5"><Clock className="w-3.5 h-3.5 text-sky-400" /> 6 provvisorio: partita di Serie A da giocare</span>
        <span className="flex items-center gap-1.5"><Pencil className="w-3.5 h-3.5 text-violet-400" /> voto inserito manualmente</span>
        <span className="flex items-center gap-1.5"><Radio className="w-3.5 h-3.5 text-grass-400" /> voto reale (Serie A)</span>
        <span className="flex items-center gap-1.5"><MinusCircle className="w-3.5 h-3.5 text-slate-500" /> senza voto / sostituito</span>
      </div>
      {calcolatoIl && <p className="text-[11px] font-mono text-slate-600 mb-3">Ultima simulazione (qualsiasi partita): {calcolatoIl}</p>}

      <div className="card overflow-hidden">
        <div className="px-4 py-3 border-b border-white/5">
          <h2 className="text-sm font-semibold text-slate-300">Giornata {stato.giornata}</h2>
        </div>
        {partite.length === 0
          ? <EmptyState label="Nessuna partita a calendario" />
          : partite.map(p => (
              <MatchRow key={p.casa.id} partita={p} stagione={stagione}
                versione={versione} onChanged={aggiorna} />
            ))}
      </div>
    </div>
  )
}
