import { useCallback, useEffect, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import {
  BarChart3, Target, Clock3, TrendingUp, Swords, Dices, History, Wallet,
} from 'lucide-react'
import { useApp } from '../context/AppContext'
import { useFetch } from '../hooks/useFetch'
import { getDashboardSquadra, getDashboardMetriche } from '../api/client'
import { LoadingState, ErrorState } from '../components/ui'
import HeaderSquadra from '../components/dashboard/HeaderSquadra'
import AreaStatistiche from '../components/dashboard/AreaStatistiche'
import AreaPrepara from '../components/dashboard/AreaPrepara'
import AreaAndamento from '../components/dashboard/AreaAndamento'
import AreaConfronta from '../components/dashboard/AreaConfronta'
import { BarraSezioni, SEZIONI_AREA, CONFIG_DEFAULT, visteAttive, vistaPrincipale, vistaAlternativa } from '../components/dashboard/NavSezioni'
import { BarraSelezione } from '../components/dashboard/SelezioneMetriche'
import { useSelezioneMetriche } from '../components/dashboard/metriche'
import {
  AreaStoria, AreaUltimaPartita, AreaInArrivo,
} from '../components/dashboard/AreeMinori'

// ── Aree ─────────────────────────────────────────────────────

const AREE = [
  { value: 'statistiche', label: 'Statistiche',               icon: BarChart3 },
  { value: 'prepara',     label: 'Prepara la prossima partita', icon: Target },
  { value: 'ultima',      label: "Analizza l'ultima partita", icon: Clock3 },
  { value: 'andamento',   label: 'Analizza andamento',        icon: TrendingUp },
  { value: 'confronta',   label: 'Confronta',                 icon: Swords },
  { value: 'fortuna',     label: 'Analizza fortuna/sfortuna', icon: Dices },
  { value: 'storia',      label: 'Storia nella lega',         icon: History },
  { value: 'finanza',     label: 'Analisi finanziaria',       icon: Wallet },
]

function NavAree({ value, onChange }) {
  return (
    <div className="overflow-x-auto -mx-1 px-1 pb-1">
      <div className="flex gap-2 w-max">
        {AREE.map(({ value: v, label, icon: Icon }) => (
          <button
            key={v}
            onClick={() => onChange(v)}
            className={`inline-flex items-center gap-2 px-3.5 py-2 rounded-xl border text-sm whitespace-nowrap transition-all duration-150 ${
              value === v
                ? 'bg-grass-500/15 border-grass-500/40 text-grass-300'
                : 'bg-pitch-900 border-white/5 text-slate-400 hover:text-slate-200 hover:border-white/15'
            }`}
          >
            <Icon className="w-4 h-4" /> {label}
          </button>
        ))}
      </div>
    </div>
  )
}

// ── Configurazione di visualizzazione (rotellina della barra) ──
// Persistita nella sessione, così tornando indietro da un'altra pagina resta quella scelta.

const CHIAVE_CONFIG = 'fanta_dashboard_config'

function leggiConfig() {
  try {
    const c = JSON.parse(sessionStorage.getItem(CHIAVE_CONFIG) || 'null')
    return {
      competizione: c?.competizione === 'CHAMP' ? 'CHAMP' : CONFIG_DEFAULT.competizione,
      // `viste` (elenco); le configurazioni salvate con il vecchio campo `vista` vengono migrate
      viste: visteAttive(Array.isArray(c?.viste) ? c : { viste: c?.vista ? [c.vista] : [] }),
    }
  } catch { return CONFIG_DEFAULT }
}

// Statistiche proposte all'ingresso in Confronta / Andamento quando non ne è stata scelta nessuna
const DEFAULT_CONFRONTA = ['punti', 'fanta_media', 'fanta_subiti']

// ── Pagina ───────────────────────────────────────────────────

export default function DashboardSquadra() {
  const { utente, ultimaStagione } = useApp()
  const idSquadra = utente?.id ?? null

  // L'area è nell'URL (?area=...): il tasto indietro del browser torna all'area precedente
  const [params, setParams] = useSearchParams()
  const areaUrl = params.get('area')
  const area = AREE.some(a => a.value === areaUrl) ? areaUrl : 'statistiche'
  const setArea = useCallback(v => {
    setParams(v === 'statistiche' ? {} : { area: v })   // nuova voce di cronologia
  }, [setParams])
  useEffect(() => { window.scrollTo(0, 0) }, [area])

  const [config, setConfigState] = useState(leggiConfig)
  const setConfig = useCallback(c => {
    setConfigState(c)
    try { sessionStorage.setItem(CHIAVE_CONFIG, JSON.stringify(c)) } catch { /* storage non disponibile */ }
  }, [])
  const competizione = config.competizione
  const vista = vistaPrincipale(config)        // in modalità duale: SCHIERATI (riferimento di lega)
  const vistaAlt = vistaAlternativa(config)    // in modalità duale: ROSA, affiancata alla principale

  const { data: d, loading, error, refetch } = useFetch(
    ultimaStagione && idSquadra ? () => getDashboardSquadra(ultimaStagione, idSquadra) : null,
    [ultimaStagione, idSquadra]
  )

  // Statistiche selezionabili di tutta la lega (aree Statistiche, Confronta, Andamento).
  // La selezione resta valida passando da un'area all'altra.
  const sel = useSelezioneMetriche()
  const met = useFetch(
    d?.stagione ? () => getDashboardMetriche(d.stagione, competizione, vista) : null,
    [d?.stagione, competizione, vista]
  )
  const metOk = met.data && met.data.competizione === competizione
    && (met.data.vista_richiesta ?? vista) === vista
  // Seconda vista (solo modalità duale): serve per i valori della sola mia squadra
  const metAlt = useFetch(
    d?.stagione && vistaAlt ? () => getDashboardMetriche(d.stagione, competizione, vistaAlt) : null,
    [d?.stagione, competizione, vistaAlt]
  )
  const altOk = vistaAlt && metAlt.data && metAlt.data.competizione === competizione
    && (metAlt.data.vista_richiesta ?? vistaAlt) === vistaAlt
  const ctx = {
    data: metOk ? met.data : null,
    vista,                                   // filtro giocatori scelto nella rotellina (principale)
    vistaAlt,                                // seconda vista da affiancare (modalità duale) o null
    dataAlt: altOk ? metAlt.data : null,     // metriche nella seconda vista
    vistaAltApplicata: altOk ? metAlt.data.vista : vistaAlt,
    vistaAltNota: altOk ? metAlt.data.vista_nota : null,
    vistaApplicata: metOk ? met.data.vista : vista,
    vistaNota: metOk ? met.data.vista_nota : null,
    errore: met.error,
    riprova: met.refetch,
    me: idSquadra,
    sel,
  }

  // Proposte di default: Andamento = posizione in classifica (solo campionato);
  // Confronta = punti, fantapunti e fantapunti subiti rispetto al benchmark di lega.
  useEffect(() => {
    if (area !== 'andamento' && area !== 'confronta') return
    const champ = competizione === 'CHAMP'
    const vuota = sel.chiavi.length === 0 || (champ && sel.chiavi.every(k => k === 'posizione'))
    if (!vuota) return
    if (sel.chiavi.length) sel.svuota()
    if (area === 'confronta') DEFAULT_CONFRONTA.forEach(sel.aggiungi)
    else sel.aggiungi(champ ? 'punti' : 'posizione')
  }, [area, competizione]) // eslint-disable-line react-hooks/exhaustive-deps

  if (loading || (!d && !error)) return <LoadingState label="Caricamento dashboard..." />
  if (error) return <ErrorState message={error} onRetry={refetch} />

  const titoloArea = AREE.find(a => a.value === area)?.label

  return (
    <div className="animate-fade-up min-w-0 max-w-full [&_.grid>*]:min-w-0">
      <HeaderSquadra d={d} stagione={d.stagione} idSquadra={idSquadra} />

      <div className="mt-4 mb-3">
        <NavAree value={area} onChange={setArea} />
      </div>

      <BarraSezioni sezioni={SEZIONI_AREA[area] ?? []} config={config} onConfig={setConfig} />

      {area === 'statistiche' && (
        <AreaStatistiche d={d} stagione={d.stagione} idSquadra={idSquadra} competizione={competizione} vista={vista} vistaAlt={vistaAlt} ctx={ctx} />
      )}
      {area === 'statistiche' && (
        <BarraSelezione ctx={ctx} onConfronta={() => setArea('confronta')} onAndamento={() => setArea('andamento')} />
      )}
      {area === 'prepara'   && <AreaPrepara d={d} stagione={d.stagione} idSquadra={idSquadra} competizione={competizione} vista={vista} vistaAlt={vistaAlt} />}
      {area === 'ultima'    && <AreaUltimaPartita d={d} competizione={competizione} />}
      {area === 'andamento' && <AreaAndamento d={d} competizione={competizione} ctx={ctx} />}
      {area === 'confronta' && <AreaConfronta d={d} competizione={competizione} ctx={ctx} />}
      {area === 'storia'    && <AreaStoria d={d} />}
      {(area === 'fortuna' || area === 'finanza') && <AreaInArrivo titolo={titoloArea} />}
    </div>
  )
}
