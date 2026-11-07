// ============================================================
// Dashboard lega
// Stessa struttura della Dashboard squadra: intestazione con pannello a
// scomparsa (prossima giornata / LIVE della giornata in corso), navigazione
// a tab (nell'URL: ?tab=...) e barra fissa di navigazione per sezione
// all'interno del tab.
// Tab sviluppati: Record storici. Gli altri sono segnaposto.
// ============================================================
import { useCallback, useEffect, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { LayoutGrid, ListOrdered, Trophy as TrophyIcon, Users, Repeat } from 'lucide-react'
import { useApp } from '../context/AppContext'
import HeaderLega from '../components/lega/HeaderLega'
import AreaRecord, { SEZIONI_RECORD } from '../components/lega/AreaRecord'
import { BarraSezioni, CONFIG_DEFAULT } from '../components/dashboard/NavSezioni'
import { AreaInArrivo } from '../components/dashboard/AreeMinori'

const TAB = [
  { value: 'panoramica',  label: 'Panoramica',      icon: LayoutGrid },
  { value: 'classifiche', label: 'Classifiche',     icon: ListOrdered },
  { value: 'record',      label: 'Record storici',  icon: TrophyIcon },
  { value: 'squadre',     label: 'Squadre',         icon: Users },
  { value: 'mercato',     label: 'Mercato',         icon: Repeat },
]
const TAB_DEFAULT = 'panoramica'

// Sezioni della barra fissa di ogni tab (id = elemento con classe sezione-scroll)
const SEZIONI_TAB = {
  panoramica: [], classifiche: [], squadre: [], mercato: [],
  record: SEZIONI_RECORD,
}

function NavTab({ value, onChange }) {
  return (
    <div className="overflow-x-auto -mx-1 px-1 pb-1">
      <div className="flex gap-2 w-max">
        {TAB.map(({ value: v, label, icon: Icon }) => (
          <button key={v} onClick={() => onChange(v)}
                  className={`inline-flex items-center gap-2 px-3.5 py-2 rounded-xl border text-sm whitespace-nowrap transition-all duration-150 ${
                    value === v
                      ? 'bg-grass-500/15 border-grass-500/40 text-grass-300'
                      : 'bg-pitch-900 border-white/5 text-slate-400 hover:text-slate-200 hover:border-white/15'}`}>
            <Icon className="w-4 h-4" /> {label}
          </button>
        ))}
      </div>
    </div>
  )
}

const CHIAVE_CONFIG = 'fanta_lega_config'
function leggiConfig() {
  try {
    const c = JSON.parse(sessionStorage.getItem(CHIAVE_CONFIG) || 'null')
    return { ...CONFIG_DEFAULT, competizione: c?.competizione === 'CHAMP' ? 'CHAMP' : 'CAMP' }
  } catch { return CONFIG_DEFAULT }
}

export default function DashboardLega() {
  const { ultimaStagione } = useApp()

  // Il tab è nell'URL: il tasto indietro del browser torna al tab precedente
  const [params, setParams] = useSearchParams()
  const tabUrl = params.get('tab')
  const tab = TAB.some(t => t.value === tabUrl) ? tabUrl : TAB_DEFAULT
  const setTab = useCallback(v => setParams(v === TAB_DEFAULT ? {} : { tab: v }), [setParams])
  useEffect(() => { window.scrollTo(0, 0) }, [tab])

  const [config, setConfigState] = useState(leggiConfig)
  const setConfig = useCallback(c => {
    setConfigState(c)
    try { sessionStorage.setItem(CHIAVE_CONFIG, JSON.stringify(c)) } catch { /* storage non disponibile */ }
  }, [])

  const titolo = TAB.find(t => t.value === tab)?.label

  return (
    <div className="animate-fade-up min-w-0 max-w-full [&_.grid>*]:min-w-0">
      <HeaderLega stagione={ultimaStagione} />

      <div className="mt-4 mb-3"><NavTab value={tab} onChange={setTab} /></div>

      <BarraSezioni sezioni={SEZIONI_TAB[tab] ?? []} config={config} onConfig={setConfig} soloCompetizione />

      {tab === 'record' ? <AreaRecord competizione={config.competizione} /> : <AreaInArrivo titolo={titolo} />}
    </div>
  )
}
