import { History, Clock3 } from 'lucide-react'
import { EmptyState } from '../ui'
import { Section, Vuoto, RigaRisultato } from './shared'
import { Trophy, Crown, Medal, ShieldX } from 'lucide-react'

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
