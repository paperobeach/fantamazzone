import { Flame } from 'lucide-react'
import { RoleBadge } from '../ui'
import { NomeConBandiera } from '../Bandiera'
import { IncontroSerieA } from '../PitchField'
import { useApp } from '../../context/AppContext'
import { Section, Vuoto, BadgeVista, fmt } from './shared'

// Stato di forma: i primi N giocatori (5 o 10) di una rosa con la fantamedia (media fantavoto) più alta,
// secondo il filtro giocatori (solo schierati / intera rosa) e le sole presenze con voto.
export const OPZIONI_N_FORMA = [5, 10]
export const N_FORMA_DEFAULT = 5

export function calcolaForma(rosa, vista, n = N_FORMA_DEFAULT) {
  const usaRosa = vista === 'ROSA' && rosa.length > 0 && rosa.every(g => g.tutta_rosa)
  const lista = rosa
    .map(g => {
      const dati = usaRosa ? g.tutta_rosa : g
      return { ...g, fantamedia: dati.media_fantavoto, pres: dati.presenze }
    })
    .filter(g => g.fantamedia !== null && g.fantamedia !== undefined && g.pres > 0)
    .sort((a, b) => b.fantamedia - a.fantamedia || b.pres - a.pres)
    .slice(0, n)
  return { lista, usaRosa, mappa: new Map(lista.map((g, i) => [g.id_giocatore, i + 1])) }
}

/** Selettore "primi 5 / primi 10". */
export function SelettoreN({ n, onChange }) {
  return (
    <span className="inline-flex gap-1">
      {OPZIONI_N_FORMA.map(k => (
        <button key={k} type="button" onClick={() => onChange(k)} aria-pressed={n === k}
                className={`px-2 py-0.5 rounded-md border text-[11px] ${
                  n === k ? 'border-grass-500/50 text-grass-400 bg-grass-500/10' : 'border-white/10 text-slate-500 hover:text-slate-300'}`}>
          primi {k}
        </button>
      ))}
    </span>
  )
}

/**
 * Sezione "Stato di forma" di una rosa (propria o dell'avversario).
 * `classifiche`: un elemento per vista attiva ({ lista, usaRosa }), calcolato con calcolaForma.
 */
export default function StatoForma({ classifiche, n, onN, soggetto, champ, duale, rosaNonDisponibile = null }) {
  const { ultimaStagione } = useApp()
  return (
    <div className="space-y-6">
      <div className={duale ? 'grid grid-cols-1 xl:grid-cols-2 gap-6 items-start' : ''}>
        {classifiche.map(c => (
          <Section key={c.usaRosa ? 'ROSA' : 'SCHIERATI'} title="Stato di forma" icon={Flame}
                   right={
                     <span className="inline-flex items-center gap-2 flex-wrap justify-end">
                       <SelettoreN n={n} onChange={onN} />
                       <BadgeVista vista={c.usaRosa ? 'ROSA' : 'SCHIERATI'} />
                     </span>
                   }>
            {c.lista.length === 0 ? <Vuoto>Nessun giocatore {soggetto.di} ha ancora preso voto.</Vuoto> : (
              c.lista.map((g, i) => (
                <div key={g.id_giocatore} className="flex items-center gap-3 px-4 py-2.5 border-b border-white/[0.03] last:border-0">
                  <span className="w-5 text-xs font-mono text-red-400 font-bold">{i + 1}</span>
                  <RoleBadge ruolo={g.ruolo} />
                  <div className="flex-1 min-w-0 flex flex-col">
                    <NomeConBandiera id={g.id_giocatore} nome={g.nome} stagione={ultimaStagione} className="text-sm text-slate-200 font-medium" />
                    <IncontroSerieA player={g} />
                  </div>
                  <span className="text-xs text-slate-600 font-mono">{g.pres} pres.</span>
                  <span className="font-mono text-sm font-bold text-red-300 w-12 text-right">{fmt(g.fantamedia, 2)}</span>
                </div>
              ))
            )}
            <p className="px-4 py-2 text-[11px] text-slate-600">
              I primi {n} giocatori {soggetto.di} con la fantamedia (media fantavoto) più alta
              {champ ? ' in Champions' : ' in campionato'}
              {soggetto.evidenzia ? '; sono evidenziati anche negli incontri qui sotto.' : '.'}
              {rosaNonDisponibile && !c.usaRosa ? ` ${rosaNonDisponibile}` : ''}
            </p>
          </Section>
        ))}
      </div>
    </div>
  )
}
