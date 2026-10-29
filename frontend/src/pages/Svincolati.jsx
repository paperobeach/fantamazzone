import { Fragment, useMemo, useState } from 'react'
import { useApp } from '../context/AppContext'
import { useFetch } from '../hooks/useFetch'
import { getMercatoAnalisiSvincolati } from '../api/client'
import { PageHeader, LoadingState, ErrorState, EmptyState, RoleBadge, TabBar, Select } from '../components/ui'
import { NomeConBandiera } from '../components/Bandiera'
import { Wallet, Search, ChevronDown, ChevronRight, Info, SlidersHorizontal, RotateCcw } from 'lucide-react'

const RUOLI = [
  { value: '',  label: 'Tutti' },
  { value: '1', label: 'Portieri' },
  { value: '2', label: 'Difensori' },
  { value: '3', label: 'Centrocampisti' },
  { value: '4', label: 'Attaccanti' },
]

// Criteri di ordinamento: dir = verso "migliore" (desc = valore alto è meglio)
const ORDINI = [
  { value: 'indice',      label: 'Indice di valore',     dir: 'desc' },
  { value: 'fantamedia',  label: 'Fantamedia',           dir: 'desc' },
  { value: 'forma',       label: 'Forma (ultime 5)',     dir: 'desc' },
  { value: 'media_voto',  label: 'Media voto',           dir: 'desc' },
  { value: 'presenze',    label: 'Presenze',             dir: 'desc' },
  { value: 'gol',         label: 'Gol',                  dir: 'desc' },
  { value: 'assist',      label: 'Assist',               dir: 'desc' },
  { value: 'gol_subiti',  label: 'Meno gol subiti',      dir: 'asc'  },
  { value: 'ammonizioni', label: 'Meno ammonizioni',     dir: 'asc'  },
]

const MIN_PRESENZE = [
  { value: '0', label: 'Qualsiasi presenza' },
  { value: '1', label: 'Almeno 1 presenza' },
  { value: '3', label: 'Almeno 3 presenze' },
  { value: '5', label: 'Almeno 5 presenze' },
  { value: '8', label: 'Almeno 8 presenze' },
]

// Filtri rapidi: impostano ordinamento e soglie con un clic
const PRESET = [
  { id: 'migliori',  label: 'Migliori in assoluto', set: { ordine: 'indice',     minPres: '3' } },
  { id: 'forma',     label: 'In forma',             set: { ordine: 'forma',      minPres: '3' } },
  { id: 'titolari',  label: 'Titolari fissi',       set: { ordine: 'presenze',   minPres: '5' } },
  { id: 'bonus',     label: 'Gol e assist',         set: { ordine: 'gol',        minPres: '1' } },
  { id: 'muro',      label: 'Poche reti subite',    set: { ordine: 'gol_subiti', minPres: '3', ruolo: '1' } },
]

const DEFAULT_FILTRI = {
  ruolo: '', q: '', serieA: '', ordine: 'indice', minPres: '0',
  soloAcquistabili: true, soloPortata: false, preset: null,
}

const f2 = (n) => (n === null || n === undefined ? '—' : Number(n).toFixed(2).replace('.', ','))
const f1 = (n) => (n === null || n === undefined ? '—' : Number(n).toFixed(1).replace('.', ','))

function fmtData(d) {
  if (!d) return ''
  const x = new Date(String(d).replace(' ', 'T'))
  return isNaN(x) ? d : x.toLocaleDateString('it-IT', { day: '2-digit', month: '2-digit' })
}

// ── Mini grafico della forma: ultimi fantavoti ───────────────
function Forma({ ultimi }) {
  if (!ultimi?.length) return <span className="text-slate-700">—</span>
  return (
    <div className="flex items-end gap-0.5 h-5" title={ultimi.map(u => `G${u.giornata}: ${f1(u.fantavoto)}`).join(' · ')}>
      {ultimi.map(u => {
        const v = Number(u.fantavoto)
        const h = Math.max(4, Math.min(20, ((v - 3) / 7) * 20))
        const cls = v >= 7 ? 'bg-grass-500' : v >= 6 ? 'bg-slate-500' : 'bg-red-500/70'
        return <span key={u.giornata} className={`w-1.5 rounded-sm ${cls}`} style={{ height: `${h}px` }} />
      })}
    </div>
  )
}

// ── Indicatore di sostenibilità economica ────────────────────
function Costo({ g, loggato }) {
  return (
    <div className="text-right whitespace-nowrap">
      <span className="text-mono text-slate-300">{g.prezzo} cr.</span>
      {loggato && g.alla_portata !== null && (
        <p className={`text-[10px] ${g.senza_cessione ? 'text-grass-400' : g.alla_portata ? 'text-gold-400' : 'text-red-400'}`}>
          {g.senza_cessione ? 'alla portata' : g.alla_portata ? 'solo con cessione' : 'oltre budget'}
        </p>
      )}
    </div>
  )
}

// ── Riquadro budget ──────────────────────────────────────────
function Budget({ budget, totale, loggato }) {
  if (!loggato || !budget) {
    return (
      <div className="card p-4 flex items-center gap-3 text-sm text-slate-400">
        <Wallet className="w-5 h-5 text-gold-400 flex-shrink-0" />
        <p>
          Budget di mercato: <b className="text-white">{totale} crediti</b> per squadra, al netto di quanto speso per la rosa.
          Accedi per vedere il tuo budget residuo e i giocatori alla tua portata.
        </p>
      </div>
    )
  }
  const voci = [
    { label: 'Budget di mercato', value: budget.totale },
    { label: 'Speso per la rosa iniziale', value: budget.speso_iniziale },
    { label: 'Saldo scambi di mercato', value: budget.saldo_mercato > 0 ? `−${budget.saldo_mercato}` : budget.saldo_mercato < 0 ? `+${-budget.saldo_mercato}` : '0' },
  ]
  return (
    <div className="grid grid-cols-2 lg:grid-cols-4 gap-3">
      {voci.map(v => (
        <div key={v.label} className="card p-3">
          <p className="text-[10px] text-mono tracking-widest uppercase text-slate-600">{v.label}</p>
          <p className="text-display text-2xl font-bold text-white mt-1">{v.value}</p>
        </div>
      ))}
      <div className={`card p-3 ${budget.residuo >= 0 ? 'border-grass-500/30 bg-grass-500/5' : 'border-red-500/30 bg-red-500/5'}`}>
        <p className="text-[10px] text-mono tracking-widest uppercase text-slate-600 flex items-center gap-1">
          <Wallet className="w-3 h-3" />Residuo disponibile
        </p>
        <p className={`text-display text-2xl font-bold mt-1 ${budget.residuo >= 0 ? 'text-grass-400' : 'text-red-400'}`}>{budget.residuo}</p>
        <p className="text-[11px] text-slate-600">{budget.nome}</p>
      </div>
    </div>
  )
}

// ── Dettaglio espanso di un giocatore ────────────────────────
function Dettaglio({ g, loggato, budget }) {
  const righe = [
    ['Gol', g.gol], ['Assist', g.assist], ['Ammonizioni', g.ammonizioni], ['Espulsioni', g.espulsioni],
    ...(g.ruolo <= 2 ? [['Gol subiti', g.gol_subiti]] : []),
    ...(g.ruolo === 1 ? [['Rigori parati', g.rigori_parati]] : []),
    ['Rigori segnati', g.rigori_segnati], ['Rigori sbagliati', g.rigori_sbagliati], ['Autogol', g.autogol],
    ['Entrato senza voto', g.senza_voto],
  ]
  return (
    <div className="px-4 py-3 bg-pitch-950/60 grid sm:grid-cols-3 gap-4 text-sm">
      <div>
        <p className="text-[10px] text-mono tracking-widest uppercase text-slate-600 mb-1.5">Ultime presenze (fantavoto)</p>
        {g.ultimi.length ? (
          <div className="flex gap-2 flex-wrap">
            {g.ultimi.map(u => (
              <div key={u.giornata} className="rounded-lg border border-white/5 px-2 py-1 text-center">
                <p className="text-[10px] text-slate-600">G{u.giornata}</p>
                <p className="text-mono text-slate-200">{f1(u.fantavoto)}</p>
                <p className="text-[10px] text-slate-600">voto {f1(u.voto)}</p>
              </div>
            ))}
          </div>
        ) : <p className="text-xs text-slate-600">Nessuna presenza con voto</p>}
        {g.ultima_presenza && <p className="text-[11px] text-slate-600 mt-2">Ultima presenza: giornata {g.ultima_presenza}</p>}
      </div>
      <div>
        <p className="text-[10px] text-mono tracking-widest uppercase text-slate-600 mb-1.5">Statistiche complete</p>
        <dl className="grid grid-cols-2 gap-x-4 gap-y-0.5 text-xs">
          {righe.map(([k, v]) => (
            <div key={k} className="flex justify-between"><dt className="text-slate-500">{k}</dt><dd className="text-mono text-slate-300">{v}</dd></div>
          ))}
        </dl>
      </div>
      <div>
        <p className="text-[10px] text-mono tracking-widest uppercase text-slate-600 mb-1.5">Operazione</p>
        <p className="text-xs text-slate-400">Prezzo di acquisto fisso: <b className="text-white">{g.prezzo} cr.</b></p>
        {loggato && budget && g.costo_netto_min !== null && (
          <>
            <p className="text-xs text-slate-400 mt-1">
              Cedendo il tuo {['', 'portiere', 'difensore', 'centrocampista', 'attaccante'][g.ruolo]} più pagato
              ({budget.max_cedibile[g.ruolo]} cr.) il costo netto è{' '}
              <b className="text-white">{g.costo_netto_min > 0 ? `${g.costo_netto_min} cr.` : g.costo_netto_min < 0 ? `+${-g.costo_netto_min} cr. recuperati` : '0 cr.'}</b>.
            </p>
            <p className="text-xs text-slate-500 mt-1">Residuo attuale: {budget.residuo} cr.</p>
          </>
        )}
        {!g.acquistabile && <p className="text-xs text-red-400 mt-1">Ceduto nella finestra: non acquistabile.</p>}
        {g.prossima && (
          <p className="text-xs text-slate-400 mt-1">
            Prossima: {g.prossima.casa ? 'in casa vs ' : 'in trasferta a '}<b className="text-white">{g.prossima.avversaria}</b>
            {g.prossima.data ? ` (${fmtData(g.prossima.data)})` : ''}
          </p>
        )}
      </div>
    </div>
  )
}

// ── Pagina ───────────────────────────────────────────────────
export default function Svincolati() {
  const { stagione, utente } = useApp()
  const [f, setF] = useState(DEFAULT_FILTRI)
  const [aperto, setAperto] = useState(null)
  const [limite, setLimite] = useState(50)

  const { data, loading, error, refetch } = useFetch(
    stagione ? () => getMercatoAnalisiSvincolati(stagione) : null, [stagione, utente?.id])

  const set = (patch) => { setF(p => ({ ...p, ...patch, preset: null })); setLimite(50) }
  const applicaPreset = (p) => { setF({ ...DEFAULT_FILTRI, soloAcquistabili: f.soloAcquistabili, ...p.set, preset: p.id }); setLimite(50) }

  const loggato = !!utente && !!data?.budget
  const ordine = ORDINI.find(o => o.value === f.ordine) ?? ORDINI[0]

  const righe = useMemo(() => {
    if (!data?.giocatori) return []
    const q = f.q.trim().toLowerCase()
    const min = Number(f.minPres)
    const out = data.giocatori.filter(g => {
      if (f.ruolo && String(g.ruolo) !== f.ruolo) return false
      if (q && !g.descrizione.toLowerCase().includes(q)) return false
      if (f.serieA && g.serie_a !== f.serieA) return false
      if (g.presenze < min) return false
      if (f.soloAcquistabili && !g.acquistabile) return false
      if (f.soloPortata && loggato && !g.alla_portata) return false
      return true
    })
    const k = f.ordine
    const verso = ordine.dir === 'asc' ? 1 : -1
    return out.sort((a, b) => {
      const va = a[k], vb = b[k]
      // chi non ha dati (null) o, per i criteri "meno è meglio", non ha presenze, va in fondo
      const na = va === null || va === undefined || (ordine.dir === 'asc' && a.presenze === 0)
      const nb = vb === null || vb === undefined || (ordine.dir === 'asc' && b.presenze === 0)
      if (na !== nb) return na ? 1 : -1
      if (!na && va !== vb) return (va - vb) * verso
      return (b.indice - a.indice) || a.descrizione.localeCompare(b.descrizione)
    })
  }, [data, f, ordine, loggato])

  if (loading && !data) return <LoadingState label="Caricamento svincolati..." />
  if (error) return <ErrorState message={error} onRetry={refetch} />
  if (!data) return null

  const filtriAttivi = JSON.stringify({ ...f, preset: null }) !== JSON.stringify({ ...DEFAULT_FILTRI, preset: null })
  const serieAOpts = [{ value: '', label: 'Tutte le squadre di Serie A' },
    ...[...data.squadre_serie_a].sort().map(s => ({ value: s, label: s }))]
  const mostrate = righe.slice(0, limite)

  return (
    <div className="animate-fade-up space-y-5">
      <PageHeader
        label="Mercato"
        title="Svincolati"
        subtitle={`${data.giocatori.length} giocatori liberi${data.ultima_giornata ? ` · dati fino alla giornata ${data.ultima_giornata}` : ''}`}
      />

      <Budget budget={data.budget} totale={data.budget_totale} loggato={loggato} />

      {/* Filtri rapidi */}
      <div className="flex flex-wrap gap-2 items-center">
        <span className="text-[10px] text-mono tracking-widest uppercase text-slate-600 mr-1">Scorciatoie</span>
        {PRESET.map(p => (
          <button
            key={p.id}
            onClick={() => applicaPreset(p)}
            className={`px-3 py-1.5 rounded-full text-xs border transition-colors ${
              f.preset === p.id
                ? 'bg-grass-500 text-pitch-950 border-grass-500 font-medium'
                : 'border-white/10 text-slate-400 hover:text-slate-200 hover:border-white/20'}`}
          >{p.label}</button>
        ))}
        {filtriAttivi && (
          <button onClick={() => { setF(DEFAULT_FILTRI); setLimite(50) }} className="btn-ghost text-xs">
            <RotateCcw className="w-3 h-3 inline mr-1" />Azzera filtri
          </button>
        )}
      </div>

      {/* Filtri */}
      <div className="card p-3 sm:p-4 space-y-3">
        <div className="flex flex-wrap gap-3 items-center">
          <div className="overflow-x-auto max-w-full">
            <TabBar tabs={RUOLI} active={f.ruolo} onChange={v => set({ ruolo: v })} />
          </div>
          <div className="relative flex-1 min-w-[12rem]">
            <Search className="w-4 h-4 text-slate-600 absolute left-3 top-1/2 -translate-y-1/2" />
            <input
              value={f.q}
              onChange={e => set({ q: e.target.value })}
              placeholder="Cerca giocatore…"
              className="fanta-input w-full pl-9"
            />
          </div>
        </div>
        <div className="flex flex-wrap gap-3 items-center">
          <SlidersHorizontal className="w-4 h-4 text-slate-600 hidden sm:block" />
          <Select value={f.serieA} onChange={v => set({ serieA: v })} options={serieAOpts} className="w-full sm:w-56" />
          <Select value={f.minPres} onChange={v => set({ minPres: v })} options={MIN_PRESENZE} className="w-full sm:w-48" />
          <Select value={f.ordine} onChange={v => set({ ordine: v })}
                  options={ORDINI.map(o => ({ value: o.value, label: `Ordina: ${o.label}` }))} className="w-full sm:w-56" />
        </div>
        <div className="flex flex-wrap gap-x-6 gap-y-2 text-sm text-slate-400">
          <label className="flex items-center gap-2 cursor-pointer">
            <input type="checkbox" checked={f.soloAcquistabili} onChange={e => set({ soloAcquistabili: e.target.checked })} className="accent-grass-500" />
            Solo acquistabili <span className="text-xs text-slate-600">(esclude i ceduti nella finestra)</span>
          </label>
          {loggato && (
            <label className="flex items-center gap-2 cursor-pointer">
              <input type="checkbox" checked={f.soloPortata} onChange={e => set({ soloPortata: e.target.checked })} className="accent-grass-500" />
              Solo alla mia portata <span className="text-xs text-slate-600">(considerando il budget residuo)</span>
            </label>
          )}
        </div>
      </div>

      <p className="text-xs text-slate-500">
        {righe.length} giocator{righe.length === 1 ? 'e' : 'i'} corrispond{righe.length === 1 ? 'e' : 'ono'} ai filtri
        {mostrate.length < righe.length ? ` · mostrati i primi ${mostrate.length}` : ''}
      </p>

      {!righe.length ? (
        <EmptyState label="Nessun giocatore corrisponde ai filtri selezionati" />
      ) : (
        <div className="card overflow-x-auto">
          <table className="fanta-table sticky-1 min-w-[880px]">
            <thead>
              <tr>
                <th>Giocatore</th>
                <th>Serie A</th>
                <th className="text-center" title="Presenze con voto">Pres.</th>
                <th className="text-center" title="Media voto base">Media</th>
                <th className="text-center" title="Media del fantavoto (voto + bonus/malus)">Fanta</th>
                <th>Forma</th>
                <th className="text-center" title="Gol">G</th>
                <th className="text-center" title="Assist">A</th>
                <th className="text-center" title="Ammonizioni / espulsioni">Amm/Esp</th>
                <th className="text-center" title="Gol subiti (portieri e difensori)">GS</th>
                <th className="text-center" title="Indice di valore: fantamedia corretta per le presenze">Indice</th>
                <th className="text-right">Costo</th>
              </tr>
            </thead>
            <tbody>
              {mostrate.map(g => {
                const open = aperto === g.id
                return (
                  <Fragment key={g.id}>
                    <tr
                      onClick={() => setAperto(open ? null : g.id)}
                      className={`cursor-pointer ${open ? 'bg-white/[0.03]' : ''} ${!g.acquistabile ? 'opacity-50' : ''}`}
                    >
                      <td>
                        <div className="flex items-center gap-2 min-w-0">
                          {open ? <ChevronDown className="w-3.5 h-3.5 text-slate-600 flex-shrink-0" /> : <ChevronRight className="w-3.5 h-3.5 text-slate-600 flex-shrink-0" />}
                          <RoleBadge ruolo={g.ruolo} />
                          <NomeConBandiera id={g.id} nome={g.descrizione} className="text-slate-200" />
                        </div>
                      </td>
                      <td className="text-xs text-slate-500">
                        {g.serie_a ?? '—'}
                        {g.prossima && <p className="text-[10px] text-slate-600">{g.prossima.casa ? 'vs' : '@'} {g.prossima.avversaria}</p>}
                      </td>
                      <td className="text-center text-mono">{g.presenze}</td>
                      <td className="text-center text-mono">{f2(g.media_voto)}</td>
                      <td className="text-center text-mono text-white">{f2(g.fantamedia)}</td>
                      <td><Forma ultimi={g.ultimi} /></td>
                      <td className="text-center text-mono">{g.gol}</td>
                      <td className="text-center text-mono">{g.assist}</td>
                      <td className="text-center text-mono text-slate-500">{g.ammonizioni}/{g.espulsioni}</td>
                      <td className="text-center text-mono text-slate-500">{g.ruolo <= 2 ? g.gol_subiti : '—'}</td>
                      <td className="text-center">
                        <span className="text-mono font-semibold text-grass-400">{f2(g.indice)}</span>
                        <p className="text-[10px] text-slate-600">#{g.pos_ruolo} nel ruolo</p>
                      </td>
                      <td><Costo g={g} loggato={loggato} /></td>
                    </tr>
                    {open && (
                      <tr className="hover:bg-transparent">
                        <td colSpan={12} className="!p-0"><Dettaglio g={g} loggato={loggato} budget={data.budget} /></td>
                      </tr>
                    )}
                  </Fragment>
                )
              })}
            </tbody>
          </table>
        </div>
      )}

      {mostrate.length < righe.length && (
        <div className="text-center">
          <button onClick={() => setLimite(l => l + 50)} className="btn-ghost text-sm">Mostra altri {Math.min(50, righe.length - mostrate.length)}</button>
        </div>
      )}

      <div className="flex items-start gap-2 text-[11px] text-slate-600 max-w-3xl">
        <Info className="w-3.5 h-3.5 mt-0.5 flex-shrink-0" />
        <p>
          Statistiche calcolate sui voti ufficiali della Serie A delle giornate chiuse{data.bonus_applicati ? ', con i bonus/malus di lega' : ' (solo voto base: regole di calcolo non configurate)'}.
          <b className="text-slate-500"> Fanta</b> è la media del fantavoto; l'<b className="text-slate-500">Indice</b> la corregge in base alle presenze,
          avvicinandola alla media del ruolo ({['', 'P', 'D', 'C', 'A'].slice(1).map((r, i) => `${r} ${f2(data.media_ruolo[i + 1])}`).join(' · ')}) per chi ha giocato poco.
          <b className="text-slate-500"> Forma</b> riporta il fantavoto delle ultime 5 presenze.
          Prezzi fissi: P {data.prezzi[1]} · D {data.prezzi[2]} · C {data.prezzi[3]} · A {data.prezzi[4]} crediti; i crediti del giocatore ceduto vengono recuperati.
        </p>
      </div>
    </div>
  )
}
