import { useMemo, useState } from 'react'
import { Star, Sparkles } from 'lucide-react'
import { RoleBadge, Select } from '../ui'
import { PitchLineup } from '../PitchLineup'
import { NomeConBandiera } from '../Bandiera'
import { MODULI, MODULI_VALIDI } from '../../lib/pitchLayout'
import { Section, Vuoto, fmt } from './shared'

/**
 * Miglior undici per un modulo: il portiere e i d/c/a migliori per la
 * metrica scelta tra chi ha almeno `minPres` presenze.
 * Restituisce { titolari, mancanti, somma }.
 */
function costruisciTop11(rosa, modulo, metrica, minPres) {
  const cfg = MODULI[modulo]
  const richiesti = { 1: 1, 2: cfg.d, 3: cfg.c, 4: cfg.a }
  const titolari = []
  let mancanti = 0
  let somma = 0
  for (const ruolo of [1, 2, 3, 4]) {
    const migliori = rosa
      .filter(g => g.ruolo === ruolo && g.presenze >= minPres && g[metrica] !== null)
      .sort((a, b) => b[metrica] - a[metrica] || b.presenze - a.presenze)
      .slice(0, richiesti[ruolo])
    mancanti += richiesti[ruolo] - migliori.length
    migliori.forEach(g => { titolari.push(g); somma += g[metrica] })
  }
  return { titolari, mancanti, somma }
}

export default function PreparaTop11({ rosa: rosaBase, competizione, rosaNonDisponibile = null }) {
  const [metrica, setMetrica] = useState('media_netto')
  const [modulo, setModulo] = useState('4-3-3')
  const [minPres, setMinPres] = useState(1)
  const [vista, setVista] = useState('SCHIERATI')

  // Base dati: SCHIERATI = solo le giornate in cui il giocatore ha concorso al punteggio;
  // ROSA = tutte le giornate in cui era in rosa con un voto, schierato o no
  const usaRosa = vista === 'ROSA' && !rosaNonDisponibile
  const rosa = useMemo(
    () => usaRosa ? rosaBase.map(g => (g.tutta_rosa ? { ...g, ...g.tutta_rosa } : g)) : rosaBase,
    [rosaBase, usaRosa]
  )

  const champ = competizione === 'CHAMP'
  const migliore = useMemo(() => {
    // modulo con la somma delle medie più alta tra quelli completabili
    let best = null
    for (const m of MODULI_VALIDI) {
      const r = costruisciTop11(rosa, m, metrica, minPres)
      if (r.mancanti === 0 && (!best || r.somma > best.somma)) best = { modulo: m, somma: r.somma }
    }
    return best
  }, [rosa, metrica, minPres])

  const top = useMemo(() => costruisciTop11(rosa, modulo, metrica, minPres), [rosa, modulo, metrica, minPres])
  const maxPres = Math.max(1, ...rosa.map(g => g.presenze))

  const giocatoriCampo = top.titolari.map((g, i) => ({
    id_giocatore: g.id_giocatore, giocatore: g.nome, ruolo: g.ruolo, maglia: i + 1, titolare: true,
  }))

  return (
    <div className="space-y-6">
      {/* Controlli */}
      <div className="card p-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <label className="space-y-1.5">
          <span className="text-[10px] uppercase tracking-widest text-slate-500">Giocatori considerati</span>
          <Select value={vista} onChange={setVista} options={[
            { value: 'SCHIERATI', label: 'Solo schierati' },
            { value: 'ROSA', label: 'Tutta la rosa' },
          ]} />
        </label>
        <label className="space-y-1.5">
          <span className="text-[10px] uppercase tracking-widest text-slate-500">Voto</span>
          <Select value={metrica} onChange={setMetrica} options={[
            { value: 'media_netto', label: 'Netto (base, senza bonus/malus)' },
            { value: 'media_lordo', label: 'Lordo (con bonus/malus)' },
          ]} />
        </label>
        <label className="space-y-1.5">
          <span className="text-[10px] uppercase tracking-widest text-slate-500">Modulo</span>
          <Select value={modulo} onChange={setModulo}
                  options={MODULI_VALIDI.map(m => ({ value: m, label: m }))} />
        </label>
        <label className="space-y-1.5">
          <span className="text-[10px] uppercase tracking-widest text-slate-500">
            Presenze minime: <span className="font-mono text-slate-300">{minPres}</span>
          </span>
          <input type="range" min={1} max={maxPres} value={Math.min(minPres, maxPres)}
                 onChange={e => setMinPres(Number(e.target.value))}
                 className="w-full accent-grass-500" />
        </label>
      </div>

      <p className="text-xs text-slate-500 -mt-3">
        {vista === 'ROSA' && rosaNonDisponibile
          ? `${rosaNonDisponibile} Vengono mostrati i soli schierati.`
          : usaRosa
            ? 'Conta ogni giocatore della rosa che ha preso voto, anche se non schierato o sostituito, nelle giornate in cui era nella tua rosa.'
            : 'Conta solo le giornate in cui il giocatore ha concorso al punteggio.'}
      </p>

      {migliore && migliore.modulo !== modulo && (
        <button onClick={() => setModulo(migliore.modulo)}
                className="w-full text-left rounded-xl border border-gold-500/30 bg-gold-500/10 px-4 py-3 text-sm text-gold-300 flex items-center gap-2 hover:bg-gold-500/15 transition-colors">
          <Sparkles className="w-4 h-4 flex-shrink-0" />
          Con questi filtri il modulo che rende di più è il <strong className="font-mono">{migliore.modulo}</strong>
          <span className="ml-auto text-xs text-gold-400/80">usalo</span>
        </button>
      )}

      {rosa.every(g => g.presenze === 0) ? (
        <Section title="Top 11 di squadra" icon={Star}>
          <Vuoto>
            {usaRosa
              ? 'Nessun giocatore della rosa ha ancora preso voto.'
              : champ ? 'Nessuna presenza in Champions ancora registrata.' : 'Nessuna giornata ancora giocata.'}
          </Vuoto>
        </Section>
      ) : (
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
          <Section title={`Top 11 · ${modulo}`} icon={Star}
                   right={`media ${fmt(top.titolari.length ? top.somma / top.titolari.length : null, 2)}`}>
            <div className="p-4">
              {top.mancanti > 0 && (
                <p className="mb-3 text-xs text-gold-400">
                  Mancano {top.mancanti} giocator{top.mancanti === 1 ? 'e' : 'i'} con almeno {minPres} presenz{minPres === 1 ? 'a' : 'e'}
                  : riduci le presenze minime o cambia modulo.
                </p>
              )}
              <PitchLineup players={giocatoriCampo} />
            </div>
          </Section>

          <Section title="Medie dei titolari" icon={Star}
                   right={metrica === 'media_netto' ? 'voto netto' : 'voto lordo'}>
            {top.titolari.map(g => (
              <div key={g.id_giocatore} className="flex items-center gap-3 px-4 py-2.5 border-b border-white/[0.03] last:border-0">
                <RoleBadge ruolo={g.ruolo} />
                <NomeConBandiera id={g.id_giocatore} nome={g.nome} className="flex-1 text-sm text-slate-300" />
                <span className="text-xs text-slate-600 font-mono">{g.presenze} pres.</span>
                <span className="font-mono text-sm font-bold text-grass-400 w-12 text-right">{fmt(g[metrica], 2)}</span>
              </div>
            ))}
          </Section>
        </div>
      )}
    </div>
  )
}
