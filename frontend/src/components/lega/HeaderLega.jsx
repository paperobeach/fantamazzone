// ============================================================
// Intestazione della Dashboard lega.
// Stessa impostazione della Dashboard squadra: una barra sintetica sempre
// agganciata in alto e un pannello esteso A SCOMPARSA (nel flusso della pagina)
// dedicato alla PROSSIMA giornata oppure, dalla prima partita di Serie A
// alla conclusione dell'ultima, al LIVE della giornata in corso.
// Pubblica --barra-squadra-h / --barra-squadra-hmax come HeaderSquadra,
// così la barra di navigazione delle sezioni (index.css) si aggancia sotto.
// ============================================================
import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import {
  ChevronUp, ChevronDown, Radio, CalendarClock, Timer, Trophy, Crown, Landmark, Moon,
} from 'lucide-react'
import TeamLogo from '../TeamLogo'
import { useFetch } from '../../hooks/useFetch'
import { getDashboardLega, getLiveGiornata } from '../../api/client'
import { fmtDataOra } from '../dashboard/shared'
import { useResiduo, URGENZA, StatoFormazione } from '../dashboard/HeaderSquadra'

// ── Dati ─────────────────────────────────────────────────────

/** Prossima giornata di lega + (se attiva) il LIVE, con aggiornamento periodico. */
export function useLega(stagione) {
  const { data, refetch } = useFetch(stagione ? () => getDashboardLega(stagione) : null, [stagione])
  const attiva = Boolean(data?.live?.attiva)
  const { data: live, refetch: refetchLive } = useFetch(
    stagione && attiva ? () => getLiveGiornata(stagione) : null, [stagione, attiva]
  )
  const rRef = useRef(refetch); rRef.current = refetch
  const lRef = useRef(refetchLive); lRef.current = refetchLive
  useEffect(() => {
    if (!stagione) return undefined
    const t = setInterval(() => { rRef.current(); if (attiva) lRef.current() }, attiva ? 60_000 : 300_000)
    return () => clearInterval(t)
  }, [stagione, attiva])
  return useMemo(() => ({ info: data, live: attiva ? live : null, attiva }), [data, live, attiva])
}

// ── Elementi grafici ─────────────────────────────────────────

function PuntoLive() {
  return (
    <span className="relative inline-flex w-2.5 h-2.5" aria-hidden>
      <span className="absolute inline-flex w-full h-full rounded-full bg-red-500 opacity-60 animate-ping" />
      <span className="relative inline-flex w-2.5 h-2.5 rounded-full bg-red-500" />
    </span>
  )
}

function Squadra({ s, destra = false, formazione = false }) {
  return (
    <div className={`flex flex-col items-center gap-1 min-w-0 sm:flex-row sm:gap-2 ${destra ? 'sm:justify-end' : ''}`}>
      <div className={`max-w-full min-w-0 ${destra ? 'sm:order-2' : ''}`}><TeamLogo logo={s.logo} nome={s.nome} size="sm" /></div>
      <span className={`text-xs sm:text-sm text-slate-200 text-center leading-tight line-clamp-2 break-words min-w-0 ${destra ? 'sm:text-right sm:order-1' : 'sm:text-left'}`}>
        {s.nome}
      </span>
      {formazione && <span className={destra ? 'sm:order-3' : ''}><StatoFormazione compatto stato={s.formazione} etichetta={`Formazione ${s.nome}`} /></span>}
    </div>
  )
}

/** Riga partita: casa - centro (vs / punteggio) - ospite. */
function RigaPartita({ casa, ospite, centro, sotto, formazione }) {
  return (
    <div className="px-3 py-2 border-b border-white/[0.04] last:border-0">
      <div className="grid grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)] items-center gap-2">
        <Squadra s={casa} formazione={formazione} />
        <div className="text-center min-w-[3.5rem]">{centro}</div>
        <Squadra s={ospite} destra formazione={formazione} />
      </div>
      {sotto && <div className="mt-1 text-center text-[10px] font-mono text-slate-600">{sotto}</div>}
    </div>
  )
}

function Blocco({ tipo, titolo, children, extra }) {
  const champ = tipo === 'champions'
  const Icona = champ ? Crown : Trophy
  return (
    <div className={`rounded-xl border ${champ ? 'border-sky-500/20' : 'border-grass-500/20'} bg-pitch-900/60 overflow-hidden min-w-0`}>
      <p className={`px-3 pt-2.5 pb-1 text-[10px] uppercase tracking-widest flex items-center gap-1.5 ${champ ? 'text-sky-400' : 'text-grass-400'}`}>
        <Icona className="w-3 h-3" /> {titolo}
        {extra && <span className="ml-auto normal-case tracking-normal text-slate-500 font-mono">{extra}</span>}
      </p>
      {children}
    </div>
  )
}

const punteggio = r => (r ? `${r.golf} - ${r.gols}` : null)

function Risultato({ p }) {
  const r = p.risultato
  if (!r) return <span className="text-xs text-slate-600">vs</span>
  return (
    <>
      <p className="text-display text-xl font-bold leading-none text-white">{punteggio(r)}</p>
      <p className="text-[10px] font-mono text-slate-600 mt-1">{Number(r.ftotale_casa).toFixed(1)} / {Number(r.ftotale_ospite).toFixed(1)}</p>
    </>
  )
}

// ── Contenuto del pannello ───────────────────────────────────

function PannelloLive({ live }) {
  const partite = live?.partite ?? []
  const champ = live?.champions?.partite ?? []
  if (!live) return <p className="text-sm text-slate-500 px-1">Caricamento LIVE...</p>
  return (
    <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
      <Blocco tipo="campionato" titolo="Campionato" extra={partite.some(p => p.fonte === 'simulazione') ? 'simulato' : 'calcolo reale'}>
        {partite.length ? partite.map((p, i) => (
          <RigaPartita key={i} casa={p.casa} ospite={p.ospite} centro={<Risultato p={p} />} />
        )) : <p className="px-3 py-4 text-sm text-slate-600">Nessuna partita.</p>}
      </Blocco>
      {live?.champions && (
        <Blocco tipo="champions" titolo="Champions" extra={live.champions.turno}>
          {champ.map((p, i) => (
            <RigaPartita key={i} casa={p.casa} ospite={p.ospite} centro={<Risultato p={p} />} sotto={p.girone_label} />
          ))}
        </Blocco>
      )}
    </div>
  )
}

function PannelloProssima({ info }) {
  return (
    <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
      <Blocco tipo="campionato" titolo="Campionato" extra={`G${info.giornata}`}>
        {info.partite.length ? info.partite.map((p, i) => (
          <RigaPartita key={i} casa={p.casa} ospite={p.ospite} formazione
                       centro={<span className="text-xs text-slate-600">vs</span>} />
        )) : <p className="px-3 py-4 text-sm text-slate-600">Calendario non disponibile.</p>}
      </Blocco>
      {info.champions && (
        <Blocco tipo="champions" titolo="Champions">
          {info.champions.partite.map((p, i) => (
            <RigaPartita key={i} casa={p.casa} ospite={p.ospite} formazione sotto={p.girone}
                         centro={<span className="text-xs text-slate-600">vs</span>} />
          ))}
          {info.champions.riposa?.length > 0 && (
            <p className="px-3 py-2 text-[11px] text-slate-500 flex items-center gap-1.5">
              <Moon className="w-3 h-3" /> Riposa: {info.champions.riposa.map(r => r.nome).join(', ')}
            </p>
          )}
        </Blocco>
      )}
    </div>
  )
}

// ── Intestazione ─────────────────────────────────────────────

const CHIAVE = 'lfm_lega_header_compressa'

function compressaIniziale() {
  try {
    const v = localStorage.getItem(CHIAVE)
    if (v === '1') return true
    if (v === '0') return false
  } catch { /* storage non disponibile */ }
  return typeof window !== 'undefined' && window.matchMedia('(max-width: 1023px)').matches
}

export default function HeaderLega({ stagione }) {
  const { info, live, attiva } = useLega(stagione)
  const [compressa, setCompressa] = useState(compressaIniziale)
  const [pannelloVisibile, setPannelloVisibile] = useState(true)
  const pannelloRef = useRef(null)
  const barraRef = useRef(null)

  const imposta = useCallback(v => {
    setCompressa(v)
    try { localStorage.setItem(CHIAVE, v ? '1' : '0') } catch { /* storage non disponibile */ }
  }, [])
  const espandi = () => { imposta(false); window.scrollTo({ top: 0, behavior: 'smooth' }) }

  useEffect(() => {
    if (compressa || !pannelloRef.current || typeof IntersectionObserver === 'undefined') return undefined
    const obs = new IntersectionObserver(([e]) => setPannelloVisibile(e.isIntersecting),
      { rootMargin: '-64px 0px 0px 0px', threshold: 0 })
    obs.observe(pannelloRef.current)
    return () => obs.disconnect()
  }, [compressa])

  useEffect(() => {
    const el = barraRef.current
    if (!el) return undefined
    const root = document.documentElement
    const misura = () => {
      const h = Math.ceil(el.getBoundingClientRect().height)
      root.style.setProperty('--barra-squadra-hmax', `${h}px`)
      root.style.setProperty('--barra-squadra-h', compressa || !pannelloVisibile ? `${h}px` : '0px')
    }
    misura()
    const ro = typeof ResizeObserver !== 'undefined' ? new ResizeObserver(misura) : null
    ro?.observe(el)
    return () => {
      ro?.disconnect()
      root.style.removeProperty('--barra-squadra-h')
      root.style.removeProperty('--barra-squadra-hmax')
    }
  }, [compressa, pannelloVisibile])

  const inCorso = Boolean(info?.in_corso)
  const residuo = useResiduo(inCorso && !attiva ? info?.inizio_giornata : null)
  const scadenzaTesto = fmtDataOra(info?.inizio_giornata)
  const titoloStato = attiva ? `LIVE · Giornata ${info?.giornata}` : inCorso ? `Prossima giornata · G${info?.giornata}` : 'Stagione'

  const barra = (
    <div className="relative rounded-2xl border border-white/10 overflow-hidden bg-gradient-to-br from-pitch-800 via-pitch-900 to-[#0a1a14] shadow-xl shadow-black/30">
      <div className="relative flex flex-wrap items-center gap-x-3 gap-y-2 px-3 py-2">
        <Landmark className="w-5 h-5 text-grass-400 flex-shrink-0" />
        <p className="text-display text-lg font-bold text-white truncate">Dashboard lega</p>
        <div className="flex flex-wrap items-center justify-end gap-2 ml-auto min-w-0">
          {attiva && (
            <Link to="/live" className="inline-flex items-center gap-1.5 px-2 py-1 rounded-lg border border-red-500/40 bg-red-500/10 text-xs font-mono text-red-300 hover:bg-red-500/20">
              <PuntoLive /> LIVE G{info?.giornata}
            </Link>
          )}
          {!attiva && inCorso && residuo && (
            <span className={`inline-flex items-center gap-1.5 px-2 py-1 rounded-lg border text-xs font-mono ${URGENZA[residuo.urgenza]}`}
                  title={scadenzaTesto ? `Limite formazioni: ${scadenzaTesto}` : undefined}>
              <Timer className="w-3.5 h-3.5" /> G{info.giornata} · {residuo.testo}
            </span>
          )}
          <button onClick={espandi} aria-label="Espandi intestazione" title="Espandi"
                  className="p-1.5 rounded-lg text-slate-400 hover:text-white hover:bg-white/[0.06] transition-colors">
            <ChevronDown className="w-4 h-4" />
          </button>
        </div>
      </div>
    </div>
  )

  const bleed = '-mx-4 sm:-mx-6 lg:-mx-8 px-4 sm:px-6 lg:px-8'
  const sfondoBarra = 'bg-pitch-950/90 backdrop-blur supports-[backdrop-filter]:bg-pitch-950/70'

  if (compressa) {
    return <div ref={barraRef} className={`sticky top-14 lg:top-0 z-20 ${bleed} pt-2 pb-3 ${sfondoBarra}`}>{barra}</div>
  }

  return (
    <>
      <div ref={pannelloRef} className="pt-2 pb-3">
        <div className="relative rounded-2xl border border-white/10 overflow-hidden bg-gradient-to-br from-pitch-800 via-pitch-900 to-[#0a1a14] shadow-xl shadow-black/30">
          <div aria-hidden className="pointer-events-none absolute inset-0 opacity-[0.07] bg-[radial-gradient(circle_at_85%_20%,#4ade80_0,transparent_45%)]" />
          <div className="relative p-4 sm:p-5">
            <button onClick={() => imposta(true)} aria-label="Comprimi intestazione" title="Comprimi"
                    className="absolute top-3 right-3 p-1.5 rounded-lg text-slate-400 hover:text-white hover:bg-white/[0.06] transition-colors z-10">
              <ChevronUp className="w-4 h-4" />
            </button>

            <div className="flex items-center gap-3 pr-10">
              <div className="w-10 h-10 rounded-xl bg-grass-500/15 border border-grass-500/30 flex items-center justify-center flex-shrink-0">
                <Landmark className="w-5 h-5 text-grass-400" />
              </div>
              <div className="min-w-0">
                <h2 className="text-display text-2xl sm:text-3xl font-bold text-white leading-none">Dashboard lega</h2>
                <p className="text-xs mt-1 flex items-center gap-2 text-slate-500">
                  {attiva && <PuntoLive />}
                  <span className={attiva ? 'text-red-300 font-mono' : ''}>{titoloStato}</span>
                  {stagione && <span className="font-mono text-slate-600">· {stagione}/{String(Number(stagione) + 1).slice(-2)}</span>}
                </p>
              </div>
              {attiva && (
                <Link to="/live" className="btn-ghost text-sm ml-auto hidden sm:inline-flex"><Radio className="w-4 h-4" /> LIVE completa</Link>
              )}
            </div>

            <div className="mt-4">
              {!info ? (
                <p className="text-sm text-slate-600">Caricamento...</p>
              ) : !inCorso ? (
                <p className="text-sm text-slate-400 rounded-xl border border-white/10 bg-pitch-900/60 px-4 py-3">
                  {info.motivo ?? 'Nessuna giornata in corso.'}
                </p>
              ) : attiva ? (
                <PannelloLive live={live} />
              ) : (
                <div className="space-y-3">
                  <div className="flex flex-col sm:flex-row sm:items-center gap-3 rounded-xl border border-white/10 bg-pitch-900/60 px-4 py-3">
                    <CalendarClock className="w-5 h-5 text-gold-400 flex-shrink-0 hidden sm:block" />
                    <div className="flex-1 min-w-0">
                      <p className="text-[10px] uppercase tracking-widest text-slate-500">Limite inserimento formazioni · giornata {info.giornata}</p>
                      <p className="text-sm text-slate-200 mt-0.5">
                        {scadenzaTesto
                          ? <>{scadenzaTesto} <span className="text-slate-500 text-xs">(1ª partita della {info.giornata_serie_a}ª di Serie A)</span></>
                          : <span className="text-slate-500">Orario non ancora disponibile</span>}
                      </p>
                    </div>
                    {residuo && (
                      <span className={`inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border text-sm font-mono font-bold ${URGENZA[residuo.urgenza]}`}>
                        <Timer className="w-4 h-4" /> {residuo.scaduta ? 'Scaduta' : `-${residuo.testo}`}
                      </span>
                    )}
                  </div>
                  <PannelloProssima info={info} />
                </div>
              )}
            </div>
          </div>
        </div>
      </div>

      <div className={`sticky top-14 lg:top-0 z-20 h-0 ${bleed}`} aria-hidden={pannelloVisibile}>
        <div ref={barraRef} className={`absolute inset-x-0 top-0 ${bleed} pt-2 pb-3 ${sfondoBarra} transition-all duration-200 ${
          pannelloVisibile ? 'opacity-0 -translate-y-2 pointer-events-none' : 'opacity-100 translate-y-0'}`}>
          {barra}
        </div>
      </div>
    </>
  )
}
