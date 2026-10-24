import { useEffect, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import {
  ChevronUp, ChevronDown, ClipboardList, Radio, CalendarClock, Home, Plane,
  Check, X, Minus, Crown, Trophy, Timer,
} from 'lucide-react'
import TeamLogo from '../TeamLogo'
import { CoachPhoto, fmtDataOra, parseDataOra } from './shared'
import { LiveChip, LivePanel, useLiveSquadra } from './LiveSquadra'

// ── Scadenza formazione ──────────────────────────────────────

/** Tempo mancante alla scadenza, aggiornato ogni 30 secondi. */
function useResiduo(scadenza) {
  const [now, setNow] = useState(() => Date.now())
  useEffect(() => {
    const t = setInterval(() => setNow(Date.now()), 30000)
    return () => clearInterval(t)
  }, [])
  const d = parseDataOra(scadenza)
  if (!d) return null
  const ms = d.getTime() - now
  if (ms <= 0) return { scaduta: true, testo: 'Scaduta', urgenza: 'scaduta' }
  const min = Math.floor(ms / 60000)
  const g = Math.floor(min / 1440)
  const h = Math.floor((min % 1440) / 60)
  const m = min % 60
  const testo = g > 0 ? `${g}g ${h}h` : h > 0 ? `${h}h ${m}m` : `${m}m`
  const urgenza = min < 180 ? 'alta' : min < 1440 ? 'media' : 'bassa'
  return { scaduta: false, testo, urgenza }
}

const URGENZA = {
  scaduta: 'text-slate-400 border-white/10 bg-white/[0.03]',
  alta:    'text-red-300 border-red-500/40 bg-red-500/10',
  media:   'text-gold-300 border-gold-500/40 bg-gold-500/10',
  bassa:   'text-grass-300 border-grass-500/30 bg-grass-500/10',
}

// ── Indicatore "formazione inserita" ─────────────────────────

/** stato = { inserita, titolari } */
function StatoFormazione({ stato, etichetta, compatto = false }) {
  if (!stato) return null
  const parziale = !stato.inserita && stato.titolari > 0
  const Icona = stato.inserita ? Check : parziale ? Minus : X
  const colore = stato.inserita
    ? 'bg-green-500/15 text-green-400 border-green-500/30'
    : parziale ? 'bg-gold-500/15 text-gold-400 border-gold-500/30'
               : 'bg-red-500/10 text-red-400 border-red-500/30'
  const testo = stato.inserita ? 'inserita' : parziale ? `incompleta (${stato.titolari}/11)` : 'non inserita'
  return (
    <span title={`${etichetta}: ${testo}`} className="inline-flex items-center gap-1.5 text-xs text-slate-400">
      <span className={`w-5 h-5 rounded-full border inline-flex items-center justify-center ${colore}`}>
        <Icona className="w-3 h-3" />
      </span>
      {!compatto && <span><span className="text-slate-500">{etichetta}</span> {testo}</span>}
    </span>
  )
}

// ── Card prossimo match ──────────────────────────────────────

function CardMatch({ tipo, app }) {
  const champ = tipo === 'champions'
  const Icona = champ ? Crown : Trophy
  const colore = champ ? 'text-sky-400' : 'text-grass-400'
  const bordo = champ ? 'border-sky-500/20' : 'border-grass-500/20'
  const titolo = champ ? 'Champions' : 'Campionato'

  if (!app) {
    return (
      <div className={`rounded-xl border ${bordo} bg-pitch-900/60 p-3 flex flex-col`}>
        <p className={`text-[10px] uppercase tracking-widest flex items-center gap-1.5 ${colore}`}>
          <Icona className="w-3 h-3" /> {titolo}
        </p>
        <p className="text-sm text-slate-600 my-auto py-3">
          {champ ? 'Nessun match di Champions in programma.' : 'Nessun match in programma.'}
        </p>
      </div>
    )
  }
  const a = app.avversario
  return (
    <div className={`rounded-xl border ${bordo} bg-pitch-900/60 p-3`}>
      <p className={`text-[10px] uppercase tracking-widest flex items-center gap-1.5 ${colore}`}>
        <Icona className="w-3 h-3" /> Prossimo {titolo.toLowerCase()}
        <span className="ml-auto text-slate-500 font-mono normal-case tracking-normal">
          G{app.giornata}{champ && app.turno ? ` · ${app.turno}` : ''}
        </span>
      </p>
      <div className="flex items-center gap-3 mt-2">
        <TeamLogo logo={a.logo} nome={a.nome} size="lg" />
        <div className="min-w-0">
          <p className="text-display text-lg font-bold text-white leading-tight truncate">{a.nome}</p>
          <p className="text-xs text-slate-500 flex items-center gap-1.5 mt-0.5">
            {app.casa ? <><Home className="w-3 h-3" /> In casa</> : <><Plane className="w-3 h-3" /> In trasferta</>}
            {a.allenatore && <span className="truncate">· {a.allenatore}</span>}
          </p>
        </div>
      </div>
      <div className="mt-3 pt-2 border-t border-white/5 flex flex-col gap-1">
        <StatoFormazione stato={app.formazione_mia} etichetta="La tua formazione" />
        <StatoFormazione stato={app.formazione_avv} etichetta={`Formazione ${a.nome}`} />
      </div>
    </div>
  )
}

// ── Intestazione ─────────────────────────────────────────────
//
// Due forme:
//  - SINTETICA: barra compatta, sempre agganciata in alto mentre si scorre;
//  - ESTESA: pannello nel flusso della pagina (NON agganciato: su mobile
//    occuperebbe lo schermo e renderebbe la pagina non navigabile). Quando
//    scorre fuori dallo schermo compare la barra sintetica agganciata.
// Su schermi stretti, senza preferenza salvata, parte sintetica.

const CHIAVE = 'lfm_dash_header_compressa'

function compressaIniziale() {
  try {
    const v = localStorage.getItem(CHIAVE)
    if (v === '1') return true
    if (v === '0') return false
  } catch { /* storage non disponibile */ }
  return typeof window !== 'undefined' && window.matchMedia('(max-width: 1023px)').matches
}

export default function HeaderSquadra({ d, stagione, idSquadra }) {
  const [compressa, setCompressa] = useState(compressaIniziale)
  const [pannelloVisibile, setPannelloVisibile] = useState(true)
  const pannelloRef = useRef(null)
  const live = useLiveSquadra(stagione, idSquadra)

  const imposta = (valore) => {
    setCompressa(valore)
    try { localStorage.setItem(CHIAVE, valore ? '1' : '0') } catch { /* storage non disponibile */ }
  }
  const espandi = () => { imposta(false); window.scrollTo({ top: 0, behavior: 'smooth' }) }

  // Il pannello esteso è "visibile" finché sta (anche in parte) sotto la barra del sito
  useEffect(() => {
    if (compressa || !pannelloRef.current || typeof IntersectionObserver === 'undefined') return undefined
    const obs = new IntersectionObserver(
      ([e]) => setPannelloVisibile(e.isIntersecting),
      { rootMargin: '-64px 0px 0px 0px', threshold: 0 }
    )
    obs.observe(pannelloRef.current)
    return () => obs.disconnect()
  }, [compressa])

  const f = d.formazione
  const ap = d.appuntamenti
  const cl = d.classifica
  const scadenza = f.inizio_giornata
  const residuo = useResiduo(f.stagione_conclusa ? null : scadenza)
  const scadenzaTesto = fmtDataOra(scadenza)

  const mieFormazioni = [ap.campionato?.formazione_mia, ap.champions?.formazione_mia].filter(Boolean)
  const tuttiInseriti = mieFormazioni.length > 0 && mieFormazioni.every(s => s.inserita)

  const bottoni = (piccoli) => (
    <div className={`flex ${piccoli ? 'gap-1.5' : 'flex-col sm:flex-row lg:flex-col gap-2'}`}>
      {!f.stagione_conclusa && (
        <Link to="/formazione" className={`btn-primary text-sm justify-center ${piccoli ? '!px-2.5 !py-1.5' : ''}`}
              title={f.inserita ? 'Modifica formazione' : 'Inserisci formazione'}>
          <ClipboardList className="w-4 h-4" />
          {!piccoli && (f.inserita ? 'Modifica formazione' : 'Inserisci formazione')}
        </Link>
      )}
      <Link to="/live" className={`btn-ghost text-sm justify-center ${piccoli ? '!px-2.5 !py-1.5' : ''}`} title="LIVE giornata">
        <Radio className="w-4 h-4" />
        {!piccoli && 'LIVE giornata'}
      </Link>
    </div>
  )

  // ── Barra sintetica ──
  const barra = (
    <div className="relative rounded-2xl border border-white/10 overflow-hidden
                    bg-gradient-to-br from-pitch-800 via-pitch-900 to-[#0a1a14] shadow-xl shadow-black/30">
      <div className="relative flex flex-wrap items-center gap-x-3 gap-y-2 px-3 py-2">
        <TeamLogo logo={d.squadra.logo} nome={d.squadra.nome} size="sm" />
        <CoachPhoto logo={d.squadra.logo_allenatore} nome={d.squadra.allenatore} className="w-8 h-8" />
        <p className="hidden sm:block text-display text-lg font-bold text-white truncate">{d.squadra.nome}</p>

        <div className="flex flex-wrap items-center justify-end gap-2 ml-auto min-w-0">
          {live && <LiveChip live={live} />}
          {!live && !f.stagione_conclusa && residuo && (
            <span className={`inline-flex items-center gap-1.5 px-2 py-1 rounded-lg border text-xs font-mono ${URGENZA[residuo.urgenza]}`}
                  title={scadenzaTesto ? `Limite formazione: ${scadenzaTesto}` : undefined}>
              <Timer className="w-3.5 h-3.5" /> {residuo.testo}
            </span>
          )}
          {!live && ap.campionato && (
            <span className="hidden md:inline-flex items-center gap-1.5" title={`Campionato: ${ap.campionato.avversario.nome}`}>
              <Trophy className="w-3.5 h-3.5 text-grass-400" />
              <TeamLogo logo={ap.campionato.avversario.logo} nome={ap.campionato.avversario.nome} size="sm" />
              <StatoFormazione compatto stato={ap.campionato.formazione_avv} etichetta="Avversario campionato" />
            </span>
          )}
          {!live && ap.champions && (
            <span className="hidden md:inline-flex items-center gap-1.5" title={`Champions: ${ap.champions.avversario.nome}`}>
              <Crown className="w-3.5 h-3.5 text-sky-400" />
              <TeamLogo logo={ap.champions.avversario.logo} nome={ap.champions.avversario.nome} size="sm" />
              <StatoFormazione compatto stato={ap.champions.formazione_avv} etichetta="Avversario Champions" />
            </span>
          )}
          {!live && !f.stagione_conclusa && (
            <span title={tuttiInseriti ? 'Formazione inserita' : 'Formazione da inserire'}
                  className={`w-6 h-6 rounded-full border inline-flex items-center justify-center ${
                    tuttiInseriti ? 'bg-green-500/15 text-green-400 border-green-500/30'
                                  : 'bg-red-500/10 text-red-400 border-red-500/30'}`}>
              {tuttiInseriti ? <Check className="w-3.5 h-3.5" /> : <X className="w-3.5 h-3.5" />}
            </span>
          )}
          {bottoni(true)}
          <button onClick={espandi} aria-label="Espandi intestazione" title="Espandi"
                  className="p-1.5 rounded-lg text-slate-400 hover:text-white hover:bg-white/[0.06] transition-colors">
            <ChevronDown className="w-4 h-4" />
          </button>
        </div>
      </div>
    </div>
  )

  // Contenitore a tutta larghezza (compensa il padding della pagina) per la barra agganciata
  const bleed = '-mx-4 sm:-mx-6 lg:-mx-8 px-4 sm:px-6 lg:px-8'
  const sfondoBarra = 'bg-pitch-950/90 backdrop-blur supports-[backdrop-filter]:bg-pitch-950/70'

  if (compressa) {
    return (
      <div className={`sticky top-14 lg:top-0 z-20 ${bleed} pt-2 pb-3 ${sfondoBarra}`}>{barra}</div>
    )
  }

  // ── Pannello esteso (nel flusso) ──
  return (
    <>
      <div ref={pannelloRef} className="pt-2 pb-3">
        <div className="relative rounded-2xl border border-white/10 overflow-hidden
                        bg-gradient-to-br from-pitch-800 via-pitch-900 to-[#0a1a14] shadow-xl shadow-black/30">
          <div aria-hidden className="pointer-events-none absolute inset-0 opacity-[0.07]
               bg-[radial-gradient(circle_at_85%_20%,#4ade80_0,transparent_45%)]" />
          <div className="relative p-4 sm:p-5">
            <button onClick={() => imposta(true)} aria-label="Comprimi intestazione" title="Comprimi"
                    className="absolute top-3 right-3 p-1.5 rounded-lg text-slate-400 hover:text-white hover:bg-white/[0.06] transition-colors z-10">
              <ChevronUp className="w-4 h-4" />
            </button>

            <div className="flex flex-col lg:flex-row gap-5">
              {/* Identità */}
              <div className="flex items-center gap-4 lg:w-80 flex-shrink-0 pr-8 lg:pr-0">
                <CoachPhoto logo={d.squadra.logo_allenatore} nome={d.squadra.allenatore}
                            className="w-20 h-20 sm:w-24 sm:h-24 ring-2 ring-grass-500/40" />
                <div className="min-w-0">
                  <TeamLogo logo={d.squadra.logo} nome={d.squadra.nome} size="lg" />
                  <h2 className="text-display text-2xl sm:text-3xl font-bold text-white truncate mt-2 leading-none">{d.squadra.nome}</h2>
                  <p className="text-xs text-slate-500 truncate mt-1">
                    <span className="uppercase tracking-widest text-[10px] text-slate-600">Allenatore</span>{' '}
                    {d.squadra.allenatore || '—'}
                  </p>
                  {cl && (
                    <p className="text-xs text-grass-400 font-mono mt-1">
                      {cl.posizione}° su {cl.squadre} · {cl.punti} pt
                    </p>
                  )}
                </div>
              </div>

              {/* Appuntamenti (sostituiti dal pannello LIVE quando è attivo) */}
              {!live && (
              <div className="flex-1 min-w-0 space-y-3">
                <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
                  <CardMatch tipo="campionato" app={ap.campionato} />
                  <CardMatch tipo="champions" app={ap.champions} />
                </div>

                <div className="flex flex-col sm:flex-row sm:items-center gap-3 rounded-xl border border-white/10 bg-pitch-900/60 px-4 py-3">
                  <CalendarClock className="w-5 h-5 text-gold-400 flex-shrink-0 hidden sm:block" />
                  <div className="flex-1 min-w-0">
                    {f.stagione_conclusa ? (
                      <p className="text-sm text-slate-400">Campionato concluso: nessuna formazione da inserire.</p>
                    ) : (
                      <>
                        <p className="text-[10px] uppercase tracking-widest text-slate-500">
                          Limite inserimento formazione · giornata {f.giornata}
                        </p>
                        <p className="text-sm text-slate-200 mt-0.5">
                          {scadenzaTesto
                            ? <>{scadenzaTesto} <span className="text-slate-500 text-xs">(1ª partita della {f.giornata_serie_a}ª di Serie A)</span></>
                            : <span className="text-slate-500">Orario non ancora disponibile</span>}
                        </p>
                      </>
                    )}
                  </div>
                  {!f.stagione_conclusa && residuo && (
                    <span className={`inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border text-sm font-mono font-bold ${URGENZA[residuo.urgenza]}`}>
                      <Timer className="w-4 h-4" /> {residuo.scaduta ? 'Scaduta' : `-${residuo.testo}`}
                    </span>
                  )}
                </div>
              </div>
              )}

              {/* Azioni */}
              <div className={`lg:w-48 flex-shrink-0 lg:self-end ${live ? 'lg:ml-auto' : ''}`}>{bottoni(false)}</div>
            </div>

            {/* LIVE: dalla prima partita di Serie A alla conclusione dell'ultima */}
            {live && <div className="mt-4"><LivePanel live={live} stagione={stagione} /></div>}
          </div>
        </div>
      </div>

      {/* Barra sintetica agganciata: compare solo quando il pannello è scorso fuori dallo schermo.
          Altezza zero nel flusso, quindi non sposta il contenuto quando appare. */}
      <div className={`sticky top-14 lg:top-0 z-20 h-0 ${bleed}`} aria-hidden={pannelloVisibile}>
        <div className={`absolute inset-x-0 top-0 ${bleed} pt-2 pb-3 ${sfondoBarra} transition-all duration-200 ${
          pannelloVisibile ? 'opacity-0 -translate-y-2 pointer-events-none' : 'opacity-100 translate-y-0'}`}>
          {barra}
        </div>
      </div>
    </>
  )
}
