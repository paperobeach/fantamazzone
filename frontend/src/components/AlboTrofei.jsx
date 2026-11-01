import { Trophy, Medal, Shield, Shirt } from 'lucide-react'

// Trofei dell'albo d'oro di una squadra (NEW_ALBO_ORO), mostrati in forma grafica.
// Colori e icone coerenti con la pagina "Albo d'oro" e con la classifica:
//   campionati vinti → coppa oro · secondi posti → medaglia argento
//   Champions        → scudo azzurro · maglie nere → maglia nera
//
// trofei: { campionati, secondi_posti, champions, maglie_nere }
// variant: 'chip'  → compatto (icona + numero), per le card del riepilogo
//          'tile'  → riquadri con etichetta, per l'intestazione del dettaglio
export const TROFEI = [
  { key: 'campionati',    label: 'Campionati vinti', corto: 'Campionati', Icon: Trophy, attivo: 'text-gold-400 bg-gold-500/10 border-gold-500/30' },
  { key: 'secondi_posti', label: 'Secondi posti',    corto: '2° posti',   Icon: Medal,  attivo: 'text-slate-200 bg-slate-300/10 border-slate-300/30' },
  { key: 'champions',     label: 'Champions vinte',  corto: 'Champions',  Icon: Shield, attivo: 'text-sky-400 bg-sky-500/10 border-sky-500/30' },
  { key: 'maglie_nere',   label: 'Maglie nere',      corto: 'Maglie nere', Icon: Shirt, attivo: 'text-white bg-black border-white/30' },
]

export default function AlboTrofei({ trofei, variant = 'chip', className = '' }) {
  const t = trofei ?? {}

  if (variant === 'tile') {
    return (
      <div className={`grid grid-cols-4 gap-2 ${className}`}>
        {TROFEI.map(({ key, label, corto, Icon, attivo }) => {
          const n = Number(t[key] ?? 0)
          return (
            <div
              key={key}
              title={`${label}: ${n}`}
              className={`flex flex-col items-center justify-center gap-0.5 rounded-xl border px-2 py-2 min-w-[4.25rem] ${
                n > 0 ? attivo : 'text-slate-600 bg-white/[0.02] border-white/5'
              }`}
            >
              <Icon className="w-5 h-5" aria-hidden="true" />
              <span className="text-display text-2xl font-bold leading-none">{n}</span>
              <span className="text-[9px] font-mono uppercase tracking-wider text-center leading-tight opacity-80">{corto}</span>
            </div>
          )
        })}
      </div>
    )
  }

  return (
    <div className={`flex items-center gap-1.5 flex-wrap ${className}`}>
      {TROFEI.map(({ key, label, Icon, attivo }) => {
        const n = Number(t[key] ?? 0)
        return (
          <span
            key={key}
            title={`${label}: ${n}`}
            className={`inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs font-mono font-semibold ${
              n > 0 ? attivo : 'text-slate-700 bg-white/[0.02] border-white/5'
            }`}
          >
            <Icon className="w-3.5 h-3.5" aria-hidden="true" />
            {n}
          </span>
        )
      })}
    </div>
  )
}
