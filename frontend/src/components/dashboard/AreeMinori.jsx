import {
  ResponsiveContainer, LineChart, Line, XAxis, YAxis, CartesianGrid, Tooltip,
} from 'recharts'
import { TrendingUp, Activity, Swords, History, Clock3 } from 'lucide-react'
import { EmptyState } from '../ui'
import { Section, Vuoto, RigaRisultato, MatriceScontri } from './shared'
import { Trophy, Crown, Medal, ShieldX } from 'lucide-react'

const TOOLTIP_STYLE = { background: '#0f1f2e', border: '1px solid #ffffff1a', borderRadius: 8, fontSize: 12 }

// ── Analizza andamento ───────────────────────────────────────

function AndamentoPosizione({ andamento, nSquadre }) {
  if (!andamento?.length) return <Vuoto>Nessuna giornata chiusa.</Vuoto>
  const n = Math.max(nSquadre || 8, ...andamento.map(a => a.posizione))
  const ticks = Array.from({ length: n }, (_, i) => i + 1)
  return (
    <div className="p-4 h-64">
      <ResponsiveContainer width="100%" height="100%">
        <LineChart data={andamento} margin={{ top: 8, right: 12, bottom: 0, left: -16 }}>
          <CartesianGrid stroke="#ffffff10" vertical={false} />
          <XAxis dataKey="giornata" stroke="#64748b" tick={{ fontSize: 11 }} tickFormatter={g => `G${g}`} />
          <YAxis reversed domain={[1, n]} ticks={ticks} allowDecimals={false} stroke="#64748b" tick={{ fontSize: 11 }} tickFormatter={p => `${p}°`} />
          <Tooltip contentStyle={TOOLTIP_STYLE} labelFormatter={g => `Giornata ${g}`} formatter={v => [`${v}°`, 'Posizione']} />
          <Line type="monotone" dataKey="posizione" stroke="#4ade80" strokeWidth={2.5} dot={{ r: 3, fill: '#4ade80' }} activeDot={{ r: 5 }} />
        </LineChart>
      </ResponsiveContainer>
    </div>
  )
}

function AndamentoFantapunti({ serie }) {
  if (!serie?.length) return <Vuoto>Nessuna partita giocata.</Vuoto>
  return (
    <div className="p-4 h-64">
      <ResponsiveContainer width="100%" height="100%">
        <LineChart data={serie} margin={{ top: 8, right: 12, bottom: 0, left: -16 }}>
          <CartesianGrid stroke="#ffffff10" vertical={false} />
          <XAxis dataKey="giornata" stroke="#64748b" tick={{ fontSize: 11 }} tickFormatter={g => `G${g}`} />
          <YAxis domain={['auto', 'auto']} stroke="#64748b" tick={{ fontSize: 11 }} />
          <Tooltip contentStyle={TOOLTIP_STYLE} labelFormatter={g => `Giornata ${g}`} formatter={v => [Number(v).toFixed(1), 'Fantapunti']} />
          <Line type="monotone" dataKey="fantapunti" stroke="#fbbf24" strokeWidth={2.5} dot={{ r: 3, fill: '#fbbf24' }} activeDot={{ r: 5 }} />
        </LineChart>
      </ResponsiveContainer>
    </div>
  )
}

export function AreaAndamento({ d, competizione }) {
  const champ = competizione === 'CHAMP'
  const nSquadre = d.classifica?.squadre
  const fanta = champ
    ? d.champions.partite.map(p => ({ giornata: p.giornata, fantapunti: p.ftotale }))
    : (d.campionato.andamento ?? []).map(a => ({ giornata: a.giornata, fantapunti: a.fantapunti }))
  return (
    <div className="space-y-6">
      {!champ && (
        <Section title="Andamento in classifica" icon={TrendingUp} right="posizione per giornata">
          <AndamentoPosizione andamento={d.campionato.andamento} nSquadre={nSquadre} />
        </Section>
      )}
      <Section title="Fantapunti per giornata" icon={Activity}>
        <AndamentoFantapunti serie={fanta} />
      </Section>
    </div>
  )
}

// ── Confronta ────────────────────────────────────────────────

export function AreaConfronta({ d, competizione }) {
  const champ = competizione === 'CHAMP'
  const matrice = champ ? d.champions.matrice : d.campionato.matrice
  return (
    <Section title={champ ? 'Scontri Champions con le altre squadre' : 'Scontri con le altre squadre'}
             icon={Swords} right="stagione in corso">
      <MatriceScontri matrice={matrice} vuota={champ ? 'Mai affrontata' : 'Non ancora giocato'} />
    </Section>
  )
}

// ── Storia nella lega ────────────────────────────────────────

function AlboItem({ icon: Icon, colore, valore, label, stagioni }) {
  const attivo = valore > 0
  return (
    <div
      title={stagioni?.length ? `${label}: ${stagioni.join(', ')}` : label}
      className={`flex items-center gap-3 px-4 py-3 rounded-xl border ${
        attivo ? 'bg-white/[0.03] border-white/10' : 'border-white/5 opacity-40'
      }`}
    >
      <Icon className={`w-7 h-7 ${attivo ? colore : 'text-slate-600'}`} />
      <div className="leading-none">
        <p className="text-display text-3xl font-bold text-white">{valore}</p>
        <p className="text-[10px] uppercase tracking-wider text-slate-500 mt-1">{label}</p>
        {stagioni?.length > 0 && <p className="text-[10px] font-mono text-slate-600 mt-0.5">{stagioni.join(' · ')}</p>}
      </div>
    </div>
  )
}

export function AreaStoria({ d }) {
  const albo = d.albo
  return (
    <Section title="Albo d'oro" icon={History}>
      <div className="p-4 grid grid-cols-2 lg:grid-cols-4 gap-3">
        <AlboItem icon={Trophy}  colore="text-gold-400"  valore={albo.campionato.titoli}    label="Campionati"    stagioni={albo.campionato.stagioni} />
        <AlboItem icon={Crown}   colore="text-sky-400"   valore={albo.champions.titoli}     label="Champions"     stagioni={albo.champions.stagioni} />
        <AlboItem icon={Medal}   colore="text-slate-300" valore={albo.secondi_posti.totale} label="Secondi posti" stagioni={albo.secondi_posti.stagioni} />
        <AlboItem icon={ShieldX} colore="text-red-400"   valore={albo.finali_perse.totale}  label="Finali perse"  stagioni={albo.finali_perse.stagioni} />
      </div>
      {!albo.disponibile && <p className="px-4 pb-3 text-xs text-slate-600">Albo d'oro non ancora disponibile.</p>}
    </Section>
  )
}

// ── Analizza l'ultima partita (provvisoria) ──────────────────

export function AreaUltimaPartita({ d, competizione }) {
  const champ = competizione === 'CHAMP'
  const ultima = champ ? d.champions.ultimo : d.campionato.ultime5[0]
  return (
    <div className="space-y-6">
      <Section title="Ultimo risultato" icon={Clock3}>
        {ultima ? <RigaRisultato p={ultima} mostraTurno={champ} /> : <Vuoto>Nessuna partita ancora giocata.</Vuoto>}
      </Section>
      <EmptyState label="Analisi dettagliata dell'ultima partita: in arrivo." />
    </div>
  )
}

// ── Aree ancora da dettagliare ───────────────────────────────

export function AreaInArrivo({ titolo }) {
  return <EmptyState label={`${titolo}: area in corso di definizione.`} />
}
