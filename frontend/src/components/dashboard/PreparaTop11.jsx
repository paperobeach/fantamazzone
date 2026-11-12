import { useMemo, useState } from 'react'
import { Star, Sparkles } from 'lucide-react'
import { RoleBadge, Select } from '../ui'
import { PitchLineup } from '../PitchLineup'
import { IncontroSerieA } from '../PitchField'
import { NomeConBandiera } from '../Bandiera'
import { useApp } from '../../context/AppContext'
import { MODULI, MODULI_VALIDI } from '../../lib/pitchLayout'
import { Section, Vuoto, BadgeVista, fmt } from './shared'

const PANCHINARI_PER_RUOLO = 3

/**
 * Miglior undici per un modulo: il portiere e i d/c/a migliori per la
 * metrica scelta tra chi ha almeno `minPres` presenze.
 * Panchina: per ogni ruolo i primi esclusi dopo i titolari (stessi filtri).
 * Restituisce { titolari, panchina, mancanti, somma }.
 */
function costruisciTop11(rosa, modulo, metrica, minPres) {
  const cfg = MODULI[modulo]
  const richiesti = { 1: 1, 2: cfg.d, 3: cfg.c, 4: cfg.a }
  const titolari = []
  const panchina = []
  let mancanti = 0
  let somma = 0
  for (const ruolo of [1, 2, 3, 4]) {
    const candidati = rosa
      .filter(g => g.ruolo === ruolo && g.presenze >= minPres && g[metrica] !== null)
      .sort((a, b) => b[metrica] - a[metrica] || b.presenze - a.presenze)
    const migliori = candidati.slice(0, richiesti[ruolo])
    mancanti += richiesti[ruolo] - migliori.length
    migliori.forEach(g => { titolari.push(g); somma += g[metrica] })
    candidati.slice(richiesti[ruolo], richiesti[ruolo] + PANCHINARI_PER_RUOLO).forEach(g => panchina.push(g))
  }
  return { titolari, panchina, mancanti, somma }
}

export default function PreparaTop11({ rosa: rosaBase, competizione, vista = 'ROSA', vistaAlt = null, rosaNonDisponibile = null }) {
  const { ultimaStagione } = useApp()
  const [metrica, setMetrica] = useState('media_voto')
  const [modulo, setModulo] = useState('4-3-3')
  const [minPres, setMinPres] = useState(1)

  const champ = competizione === 'CHAMP'
  // Modalità duale: due viste affiancate (principale + alternativa), con gli stessi controlli
  const viste = vistaAlt ? [vista, vistaAlt] : [vista]
  const duale = viste.length === 2

  // Per ogni vista: SCHIERATI = solo le giornate in cui il giocatore ha concorso al punteggio;
  // ROSA = tutte le giornate in cui era in rosa con un voto, schierato o no
  const risultati = useMemo(() => viste.map(v => {
    const usaRosa = v === 'ROSA' && !rosaNonDisponibile
    const rosa = usaRosa ? rosaBase.map(g => (g.tutta_rosa ? { ...g, ...g.tutta_rosa } : g)) : rosaBase
    // modulo con la somma delle medie più alta tra quelli completabili
    let migliore = null
    for (const m of MODULI_VALIDI) {
      const r = costruisciTop11(rosa, m, metrica, minPres)
      if (r.mancanti === 0 && (!migliore || r.somma > migliore.somma)) migliore = { modulo: m, somma: r.somma }
    }
    return { vista: v, usaRosa, rosa, migliore, top: costruisciTop11(rosa, modulo, metrica, minPres) }
  }), [rosaBase, rosaNonDisponibile, metrica, modulo, minPres, vista, vistaAlt]) // eslint-disable-line react-hooks/exhaustive-deps

  const maxPres = Math.max(1, ...risultati.flatMap(r => r.rosa.map(g => g.presenze)))

  // Incontro di Serie A del giocatore nella giornata (avversaria + casa/trasferta), come nell'inserimento formazione
  const conIncontro = g => ({
    serie_a: g.serie_a, avversaria: g.avversaria, casa: g.casa, data: g.data, ora: g.ora,
  })
  const campoDi = top => [
    ...top.titolari.map((g, i) => ({
      id_giocatore: g.id_giocatore, giocatore: g.nome, ruolo: g.ruolo, maglia: i + 1, titolare: true, ...conIncontro(g),
    })),
    ...top.panchina.map((g, i) => ({
      id_giocatore: g.id_giocatore, giocatore: g.nome, ruolo: g.ruolo, maglia: 12 + i, titolare: false, ...conIncontro(g),
    })),
  ]

  const rigaGiocatore = (g, panchina = false) => (
    <div key={g.id_giocatore} className="flex items-center gap-3 px-4 py-2.5 border-b border-white/[0.03] last:border-0">
      <RoleBadge ruolo={g.ruolo} />
      <div className="flex-1 min-w-0 flex flex-col">
        <NomeConBandiera id={g.id_giocatore} nome={g.nome} stagione={ultimaStagione}
                         className={`text-sm ${panchina ? 'text-slate-500' : 'text-slate-300'}`} />
        <IncontroSerieA player={conIncontro(g)} />
      </div>
      <span className="text-xs text-slate-600 font-mono">{g.presenze} pres.</span>
      <span className={`font-mono text-sm font-bold w-12 text-right ${panchina ? 'text-slate-400' : 'text-grass-400'}`}>{fmt(g[metrica], 2)}</span>
    </div>
  )

  // Campo + elenco di una vista
  const bloccoVista = r => {
    const { top, usaRosa } = r
    return (
      <div key={r.vista} className="min-w-0 space-y-6">
        {duale && (
          <div className="flex items-center gap-2 flex-wrap">
            <BadgeVista vista={usaRosa ? 'ROSA' : 'SCHIERATI'} />
            <span className="text-xs text-slate-500">
              {r.vista === 'ROSA' && rosaNonDisponibile ? `${rosaNonDisponibile} Vengono mostrati i soli schierati.`
                : usaRosa ? 'Tutta la rosa: conta ogni giocatore che ha preso voto, anche se non schierato.'
                : 'Conta solo le giornate in cui il giocatore ha concorso al punteggio.'}
            </span>
          </div>
        )}
        <div className={duale ? 'space-y-6' : 'grid grid-cols-1 lg:grid-cols-2 gap-6'}>
          <Section title={`Top 11 · ${modulo}`} icon={Star}
                   right={`media ${fmt(top.titolari.length ? top.somma / top.titolari.length : null, 2)}`}>
            <div className="p-4">
              {top.mancanti > 0 && (
                <p className="mb-3 text-xs text-gold-400">
                  Mancano {top.mancanti} giocator{top.mancanti === 1 ? 'e' : 'i'} con almeno {minPres} presenz{minPres === 1 ? 'a' : 'e'}
                  : riduci le presenze minime o cambia modulo.
                </p>
              )}
              <PitchLineup players={campoDi(top)} />
            </div>
          </Section>

          <Section title="Medie dei titolari e dei panchinari" icon={Star}
                   right={metrica === 'media_voto' ? 'voto' : 'fantavoto'}>
            {top.titolari.map(g => rigaGiocatore(g))}
            {top.panchina.length > 0 && (
              <>
                <p className="px-4 py-1.5 text-[10px] uppercase tracking-widest text-slate-600 bg-white/[0.02]">
                  Panchina · primi {PANCHINARI_PER_RUOLO} esclusi per ruolo
                </p>
                {top.panchina.map(g => rigaGiocatore(g, true))}
              </>
            )}
          </Section>
        </div>
      </div>
    )
  }

  return (
    <div className="space-y-6">
      {/* Controlli (condivisi dalle due viste) */}
      <div className="card p-4 grid grid-cols-1 sm:grid-cols-3 gap-4">
        <label className="space-y-1.5">
          <span className="text-[10px] uppercase tracking-widest text-slate-500">Voto</span>
          <Select value={metrica} onChange={setMetrica} options={[
            { value: 'media_voto', label: 'Voto (base, senza bonus/malus)' },
            { value: 'media_fantavoto', label: 'Fantavoto (con bonus/malus)' },
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

      {!duale && (
        <p className="text-xs text-slate-500 -mt-3 flex items-center gap-2 flex-wrap">
          <BadgeVista vista={risultati[0].usaRosa ? 'ROSA' : 'SCHIERATI'} />
          {vista === 'ROSA' && rosaNonDisponibile
            ? `${rosaNonDisponibile} Vengono mostrati i soli schierati.`
            : risultati[0].usaRosa
              ? 'Conta ogni giocatore della rosa che ha preso voto, anche se non schierato o sostituito, nelle giornate in cui era nella tua rosa.'
              : 'Conta solo le giornate in cui il giocatore ha concorso al punteggio.'}
        </p>
      )}

      {risultati.map(r => r.migliore && r.migliore.modulo !== modulo && (
        <button key={`best-${r.vista}`} onClick={() => setModulo(r.migliore.modulo)}
                className="w-full text-left rounded-xl border border-gold-500/30 bg-gold-500/10 px-4 py-3 text-sm text-gold-300 flex items-center gap-2 hover:bg-gold-500/15 transition-colors">
          <Sparkles className="w-4 h-4 flex-shrink-0" />
          {duale && <BadgeVista vista={r.usaRosa ? 'ROSA' : 'SCHIERATI'} />}
          Con questi filtri il modulo che rende di più è il <strong className="font-mono">{r.migliore.modulo}</strong>
          <span className="ml-auto text-xs text-gold-400/80">usalo</span>
        </button>
      ))}

      {risultati.every(r => r.rosa.every(g => g.presenze === 0)) ? (
        <Section title="Top 11 di squadra" icon={Star}>
          <Vuoto>
            {risultati.some(r => r.usaRosa)
              ? 'Nessun giocatore della rosa ha ancora preso voto.'
              : champ ? 'Nessuna presenza in Champions ancora registrata.' : 'Nessuna giornata ancora giocata.'}
          </Vuoto>
        </Section>
      ) : (
        <div className={duale ? 'grid grid-cols-1 xl:grid-cols-2 gap-6 items-start' : ''}>
          {risultati.map(bloccoVista)}
        </div>
      )}
    </div>
  )
}
