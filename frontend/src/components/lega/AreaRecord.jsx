// ============================================================
// Dashboard lega > Record storici > giocatori.
// Ogni statistica è mostrata come RECORD ASSOLUTO (il primo posto, con
// eventuali pari merito) e come TOP N (3, 10, 20, 50), selezionabile in alto.
// Filtro per reparto; la competizione (Campionato / Champions) arriva dalla
// rotellina della barra di navigazione.
// ============================================================
import { useState } from 'react'
import { Link } from 'react-router-dom'
import { Crown, Medal, Award, History, CalendarRange, Zap, Wallet, Star } from 'lucide-react'
import { useFetch } from '../../hooks/useFetch'
import { getRecordStorici } from '../../api/client'
import { LoadingState, ErrorState, RoleBadge, EmptyState } from '../ui'
import TeamLogo from '../TeamLogo'
import { Section } from '../dashboard/shared'

/** Sezioni della barra di navigazione fissa del tab (id = elemento con classe sezione-scroll). */
export const SEZIONI_RECORD = [
  { id: 'rec-carriera', label: 'Carriera' },
  { id: 'rec-stagione', label: 'Stagione' },
  { id: 'rec-partita',  label: 'Partita' },
  { id: 'rec-mercato',  label: 'Mercato' },
]

const GRUPPI = {
  'Carriera':         { id: 'rec-carriera', icon: History,       titolo: 'Record di carriera' },
  'Singola stagione': { id: 'rec-stagione', icon: CalendarRange, titolo: 'Record in una stagione' },
  'Singola partita':  { id: 'rec-partita',  icon: Zap,           titolo: 'Record in una partita' },
  'Mercato':          { id: 'rec-mercato',  icon: Wallet,        titolo: 'Record di mercato' },
}

const MODI = [
  { value: 'record', label: 'Record' },
  { value: '3',      label: 'Top 3' },
  { value: '10',     label: 'Top 10' },
  { value: '20',     label: 'Top 20' },
  { value: '50',     label: 'Top 50' },
]
const REPARTI = [
  { value: 0, label: 'Tutti' }, { value: 1, label: 'Portieri' }, { value: 2, label: 'Difensori' },
  { value: 3, label: 'Centrocampisti' }, { value: 4, label: 'Attaccanti' },
]

const stagioneLabel = s => `${s}/${String(Number(s) + 1).slice(-2)}`
const fmtValore = (v, dec, unita) => `${Number(v).toFixed(dec)}${unita ? ` ${unita}` : ''}`
const linkGiocatore = r => `/giocatori/${r.id}?stagione=${r.stagione}`

function dettaglio(r) {
  const parti = []
  if (r.squadra) parti.push(r.squadra)
  if (r.stagione_rif) parti.push(stagioneLabel(r.stagione_rif))
  if (r.giornata) parti.push(`G${r.giornata}`)
  return parti.join(' · ')
}

function Chips({ opzioni, value, onChange, etichetta }) {
  return (
    <div className="flex gap-1 p-1 bg-pitch-950 rounded-xl border border-white/5 overflow-x-auto max-w-full" role="radiogroup" aria-label={etichetta}>
      {opzioni.map(o => (
        <button key={o.value} type="button" role="radio" aria-checked={value === o.value} onClick={() => onChange(o.value)}
                className={`px-3 py-1.5 rounded-lg text-xs font-medium whitespace-nowrap transition-colors ${
                  value === o.value ? 'bg-grass-500 text-pitch-950' : 'text-slate-400 hover:text-slate-200'}`}>
          {o.label}
        </button>
      ))}
    </div>
  )
}

/** Il record: primo posto (anche ex aequo), in evidenza. */
function CardRecord({ c }) {
  const rec = c.record ?? []
  if (!rec.length) return null
  const r = rec[0]
  return (
    <div className="m-3 rounded-xl border border-gold-500/30 bg-gold-500/[0.06] p-3">
      <p className="text-[10px] uppercase tracking-widest text-gold-400 flex items-center gap-1.5">
        <Crown className="w-3.5 h-3.5" /> Record{rec.length > 1 ? ` · ${rec.length} a pari merito` : ''}
      </p>
      <div className="mt-2 flex items-center gap-3">
        <p className="text-display text-4xl font-bold text-white leading-none">{fmtValore(r.valore, c.decimali, c.unita)}</p>
        <div className="min-w-0">
          <Link to={linkGiocatore(r)} className="text-sm font-semibold text-slate-100 hover:text-grass-300 flex items-center gap-1.5 min-w-0">
            <RoleBadge ruolo={r.ruolo} /> <span className="truncate">{r.nome}</span>
          </Link>
          <p className="text-[11px] text-slate-500 truncate">{dettaglio(r)}</p>
        </div>
      </div>
      {rec.length > 1 && (
        <p className="mt-2 text-[11px] text-slate-500">
          Insieme a {rec.slice(1).map((x, i) => (
            <span key={i}>{i > 0 && ', '}<Link to={linkGiocatore(x)} className="text-slate-300 hover:text-grass-300">{x.nome}</Link></span>
          ))}
        </p>
      )}
    </div>
  )
}

function RigaTop({ r, c }) {
  const medaglia = r.pos === 1 ? 'text-gold-400' : r.pos === 2 ? 'text-slate-300' : r.pos === 3 ? 'text-amber-600' : null
  return (
    <li className="flex items-center gap-2.5 px-3 py-2 border-b border-white/[0.04] last:border-0">
      <span className={`w-6 text-center font-mono text-xs flex-shrink-0 ${medaglia ?? 'text-slate-600'}`}>
        {medaglia ? <Medal className="w-4 h-4 inline" aria-label={`${r.pos}°`} /> : r.pos}
      </span>
      {r.logo && <div className="hidden sm:block flex-shrink-0"><TeamLogo logo={r.logo} nome={r.squadra} size="xs" /></div>}
      <div className="flex-1 min-w-0">
        <Link to={linkGiocatore(r)} className="text-sm text-slate-200 hover:text-grass-300 flex items-center gap-1.5 min-w-0">
          <RoleBadge ruolo={r.ruolo} /> <span className="truncate">{r.nome}</span>
        </Link>
        <p className="text-[11px] text-slate-600 truncate">{dettaglio(r)}</p>
      </div>
      <span className="font-mono font-bold text-sm text-white flex-shrink-0">{fmtValore(r.valore, c.decimali, c.unita)}</span>
    </li>
  )
}

function CardClassifica({ c, modo }) {
  // Elenchi senza un record unico (es. "più pagato per reparto") si mostrano sempre per intero
  const n = c.record.length === 0 ? c.righe.length : modo === 'record' ? 0 : Number(modo)
  const righe = c.righe.slice(0, n)
  return (
    <div className="card overflow-hidden min-w-0">
      <div className="px-4 pt-3 pb-1">
        <h3 className="text-sm font-semibold text-slate-200 flex items-center gap-2"><Award className="w-4 h-4 text-grass-400" /> {c.titolo}</h3>
        <p className="text-[11px] text-slate-600 mt-0.5">{c.descrizione}</p>
      </div>
      {c.righe.length === 0 ? (
        <p className="px-4 py-6 text-sm text-slate-600 text-center">Nessun dato.</p>
      ) : (
        <>
          <CardRecord c={c} />
          {n > 0 && <ol className="pb-1">{righe.map((r, i) => <RigaTop key={`${r.id}-${r.stagione}-${r.giornata ?? ''}-${i}`} r={r} c={c} />)}</ol>}
        </>
      )}
    </div>
  )
}

export default function AreaRecord({ competizione }) {
  const [modo, setModo] = useState('10')
  const [ruolo, setRuolo] = useState(0)
  const { data, loading, error, refetch } = useFetch(() => getRecordStorici(competizione, ruolo), [competizione, ruolo])

  const pronto = data && data.competizione === competizione && data.ruolo === ruolo

  return (
    <div className="space-y-6">
      <div className="card p-3 sm:p-4 space-y-3">
        <div className="flex flex-wrap items-center gap-3">
          <Star className="w-4 h-4 text-gold-400 flex-shrink-0" />
          <p className="text-sm text-slate-300">
            Record storici dei giocatori
            {data?.stagioni?.length > 0 && <span className="text-slate-500"> · {data.stagioni.length} stagioni ({stagioneLabel(data.stagioni[0])} – {stagioneLabel(data.stagioni[data.stagioni.length - 1])})</span>}
          </p>
          {loading && <span className="text-[11px] text-slate-600 ml-auto">aggiornamento…</span>}
        </div>
        <div className="flex flex-col lg:flex-row gap-3">
          <Chips etichetta="Vista" opzioni={MODI} value={modo} onChange={setModo} />
          <Chips etichetta="Reparto" opzioni={REPARTI} value={ruolo} onChange={setRuolo} />
        </div>
      </div>

      {error && !data && <ErrorState message={error} onRetry={refetch} />}
      {!pronto && !error && <LoadingState label="Calcolo dei record..." />}
      {pronto && data.classifiche.length === 0 && <EmptyState label="Nessun record disponibile." />}

      {pronto && Object.entries(GRUPPI).map(([nome, g]) => {
        const lista = data.classifiche.filter(c => c.gruppo === nome)
        if (!lista.length) return null
        return (
          <div key={nome} id={g.id} className="sezione-scroll">
            <Section title={g.titolo} icon={g.icon}
                     right={nome === 'Carriera' ? `media: min. ${data.min_presenze.carriera} presenze` : nome === 'Singola stagione' ? `media: min. ${data.min_presenze.stagione} presenze` : null}>
              <div className="p-3 grid grid-cols-1 lg:grid-cols-2 gap-3 [&>*]:min-w-0">
                {lista.map(c => <CardClassifica key={c.chiave} c={c} modo={modo} />)}
              </div>
            </Section>
          </div>
        )
      })}
    </div>
  )
}
