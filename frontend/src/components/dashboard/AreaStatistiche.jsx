import { useEffect, useRef, useState } from 'react'
import {
  ClipboardList, Swords, Star, ThumbsDown, Home, Plane, Target, BarChart3, Hash, Shield, X, MousePointerClick,
} from 'lucide-react'
import { useFetch } from '../../hooks/useFetch'
import { getDashboardStatistiche } from '../../api/client'
import { LoadingState, ErrorState, EmptyState, RoleBadge } from '../ui'
import {
  Section, Vuoto, BarraPerc, PallinoForma, MatriceScontri, LogoCompatto, fmt,
} from './shared'
import AreaRuoli from './AreaRuoli'
import { NomeConBandiera } from '../Bandiera'
import { useApp } from '../../context/AppContext'
import { CardMetrica, RigaMetrica, ChipMetrica } from './SelezioneMetriche'

// ── Casa / trasferta ─────────────────────────────────────────

function BloccoCasaTrasferta({ titolo, icona: Icona, r, chips }) {
  return (
    <div className="rounded-xl bg-pitch-900 border border-white/5 p-4">
      <p className="text-xs uppercase tracking-widest text-slate-500 flex items-center gap-2 mb-3">
        <Icona className="w-3.5 h-3.5" /> {titolo}
        <span className="ml-auto font-mono normal-case tracking-normal text-slate-600">{r.giocate} partite</span>
      </p>
      {r.giocate === 0 ? (
        <p className="text-sm text-slate-600">Nessuna partita.</p>
      ) : (
        <>
          <div className="grid grid-cols-3 gap-2 text-center">
            <div><p className="text-display text-2xl font-bold text-green-400">{r.vinte}</p><p className="text-[10px] uppercase text-slate-600">Vinte</p></div>
            <div><p className="text-display text-2xl font-bold text-yellow-400">{r.nulle}</p><p className="text-[10px] uppercase text-slate-600">Pari</p></div>
            <div><p className="text-display text-2xl font-bold text-red-400">{r.perse}</p><p className="text-[10px] uppercase text-slate-600">Perse</p></div>
          </div>
          <p className="text-xs text-slate-500 font-mono mt-3 text-center">
            {r.punti} pt · {fmt(r.punti_per_partita, 2)} a partita · gol {r.golf}-{r.gols}
          </p>
          {chips && <div className="flex flex-wrap justify-center gap-2 mt-3">{chips}</div>}
        </>
      )}
    </div>
  )
}

// ── Pagina unica: sezioni ────────────────────────────────────

const NAV = [
  { id: 'risultati', label: 'Risultati' },
  { id: 'gol',       label: 'Gol' },
  { id: 'reparti',   label: 'Reparti' },
  { id: 'giocatori', label: 'Top / Flop' },
]

const vaiA = id => document.getElementById(`stat-${id}`)?.scrollIntoView({ behavior: 'smooth', block: 'start' })

/**
 * Barra delle sezioni: resta agganciata sotto l'intestazione della squadra mentre si scorre
 * (la posizione segue l'altezza dell'intestazione, vedi --barra-squadra-h in HeaderSquadra)
 * e evidenzia la sezione attualmente in vista.
 */
function BarraNavigazione() {
  const [attiva, setAttiva] = useState(NAV[0].id)
  const barraRef = useRef(null)

  useEffect(() => {
    let raf = 0
    const calcola = () => {
      raf = 0
      const soglia = (barraRef.current?.getBoundingClientRect().bottom ?? 0) + 24
      let corrente = NAV[0].id
      NAV.forEach(n => {
        const el = document.getElementById(`stat-${n.id}`)
        if (el && el.getBoundingClientRect().top <= soglia) corrente = n.id
      })
      // In fondo alla pagina l'ultima sezione può non raggiungere la soglia
      if (window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - 4) {
        const ultima = [...NAV].reverse().find(n => document.getElementById(`stat-${n.id}`))
        if (ultima) corrente = ultima.id
      }
      setAttiva(corrente)
    }
    const richiedi = () => { if (!raf) raf = requestAnimationFrame(calcola) }
    window.addEventListener('scroll', richiedi, { passive: true })
    window.addEventListener('resize', richiedi)
    calcola()
    return () => {
      window.removeEventListener('scroll', richiedi)
      window.removeEventListener('resize', richiedi)
      if (raf) cancelAnimationFrame(raf)
    }
  }, [])

  return (
    <div ref={barraRef}
         className="nav-sezioni-sticky sticky z-[15] -mx-4 sm:-mx-6 lg:-mx-8 px-4 sm:px-6 lg:px-8 py-2
                    bg-pitch-950/90 backdrop-blur supports-[backdrop-filter]:bg-pitch-950/70">
      <div className="overflow-x-auto -mx-1 px-1">
        <div className="flex gap-1.5 w-max">
          {NAV.map(n => (
            <button key={n.id} type="button" onClick={() => vaiA(n.id)} aria-current={attiva === n.id ? 'true' : undefined}
                    className={`px-3 py-1.5 rounded-full border text-xs transition-colors whitespace-nowrap ${
                      attiva === n.id
                        ? 'border-grass-500/50 text-grass-300 bg-grass-500/10'
                        : 'border-white/10 text-slate-400 hover:text-white hover:border-white/25'}`}>
              {n.label}
            </button>
          ))}
        </div>
      </div>
    </div>
  )
}

function Intro({ ctx }) {
  return (
    <div className="flex items-start gap-3 rounded-xl border border-white/5 bg-pitch-900/60 px-4 py-3 text-xs text-slate-500">
      <MousePointerClick className="w-4 h-4 text-grass-400 flex-shrink-0 mt-0.5" />
      <p className="leading-relaxed">
        Tocca una statistica per selezionarla: poi puoi <b className="text-slate-300">confrontarla</b> con le altre squadre e con
        il benchmark di lega, oppure vederne l’<b className="text-slate-300">andamento</b> giornata per giornata.
        {ctx.errore && <span className="text-red-400"> Dati di lega non disponibili: {ctx.errore}</span>}
        {!ctx.data && !ctx.errore && <span> Carico i dati di lega…</span>}
      </p>
    </div>
  )
}

function SezioneRisultati({ s, ctx }) {
  const [nForma, setNForma] = useState(5)
  const forma = s.forma.slice(0, nForma)

  return (
    <div className="space-y-6">
      <Section title="Fantapunti" icon={BarChart3} right="tocca per selezionare">
        <div className="p-4 grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3">
          <CardMetrica k="fanta_media" ctx={ctx} label="Media fantapunti" tono="text-grass-400" />
          <CardMetrica k="fanta_subiti" ctx={ctx} label="Media avversari" sub="fantapunti subiti" />
          <CardMetrica k="fanta_diff" ctx={ctx} />
          <CardMetrica k="fanta_max" ctx={ctx} tono="text-grass-400" />
          <CardMetrica k="fanta_min" ctx={ctx} />
        </div>
      </Section>

      <Section title="Forma" icon={ClipboardList}
               right={
                 <span className="inline-flex gap-1">
                   {[5, 10].map(k => (
                     <button key={k} onClick={() => setNForma(k)}
                             className={`px-2 py-0.5 rounded-md border text-[11px] ${
                               nForma === k ? 'border-grass-500/50 text-grass-400 bg-grass-500/10' : 'border-white/10 text-slate-500 hover:text-slate-300'}`}>
                       ultime {k}
                     </button>
                   ))}
                 </span>
               }>
        <div className="p-4 space-y-4">
          <div className="flex flex-wrap gap-2">
            {[...forma].reverse().map(p => (
              <PallinoForma key={`${p.giornata}-${p.turno ?? ''}`} segno={p.segno} size="w-9 h-9 text-sm"
                            title={`G${p.giornata} · ${p.avversario} ${p.golf}-${p.gols}`} />
            ))}
            <span className="text-xs text-slate-600 self-center ml-1">dal meno al più recente</span>
          </div>
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-x-6">
            {forma.map(p => (
              <div key={`${p.giornata}-${p.turno ?? ''}`} className="flex items-center gap-2 py-1.5 border-b border-white/[0.03] text-sm">
                <PallinoForma segno={p.segno} size="w-5 h-5 text-[10px]" />
                <span className="text-xs font-mono text-slate-600 w-7">G{p.giornata}</span>
                <LogoCompatto logo={p.logo_avv} nome={p.avversario} />
                <span className="flex-1 truncate text-slate-300">{p.avversario}</span>
                <span className="font-mono font-bold text-slate-200">{p.golf}-{p.gols}</span>
              </div>
            ))}
          </div>
        </div>
      </Section>

      <Section title="Rendimento casa / trasferta" icon={Home}>
        <div className="p-4 grid grid-cols-1 sm:grid-cols-2 gap-4">
          <BloccoCasaTrasferta titolo="In casa" icona={Home} r={s.casa_trasferta.casa}
                               chips={<><ChipMetrica k="ppp_casa" ctx={ctx} label="Punti/partita" /><ChipMetrica k="fanta_casa" ctx={ctx} label="Fantapunti" /></>} />
          <BloccoCasaTrasferta titolo="In trasferta" icona={Plane} r={s.casa_trasferta.trasferta}
                               chips={<><ChipMetrica k="ppp_trasferta" ctx={ctx} label="Punti/partita" /><ChipMetrica k="fanta_trasferta" ctx={ctx} label="Fantapunti" /></>} />
        </div>
        {s.casa_trasferta.non_definite > 0 && (
          <p className="px-4 pb-3 text-xs text-slate-600">
            {s.casa_trasferta.non_definite} partite senza indicazione casa/trasferta non incluse.
          </p>
        )}
      </Section>

      <Section title="Matrice dei risultati" icon={Swords} right="contro ogni avversario">
        <MatriceScontri matrice={s.matrice} vuota={s.competizione === 'CHAMP' ? 'Mai affrontata' : 'Non ancora giocato'} />
      </Section>
    </div>
  )
}

function SezioneGol({ s, ctx }) {
  const n = s.riepilogo.giocate
  const g = s.gol
  return (
    <div className="space-y-6">
      <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
        <CardMetrica k="gol_fatti" ctx={ctx} tono="text-green-400" />
        <CardMetrica k="gol_subiti" ctx={ctx} tono="text-red-400" />
        <CardMetrica k="gol_fatti_tot" ctx={ctx} />
        <CardMetrica k="gol_subiti_tot" ctx={ctx} />
      </div>

      <div className="grid grid-cols-1 gap-6">
        <Section title="Gol segnati e subiti" icon={Target} right={`${n} partite`}>
          <div className="p-3 space-y-1">
            <RigaMetrica k="clean_sheet" ctx={ctx} colore="bg-green-500" />
            <RigaMetrica k="senza_segnare" ctx={ctx} label="Senza segnare" colore="bg-red-500" />
            <RigaMetrica k="gol_segnato" ctx={ctx} label="Gol segnato" colore="bg-grass-400" />
            <RigaMetrica k="gol_subito" ctx={ctx} label="Gol subito" colore="bg-orange-400" />
            <RigaMetrica k="segnato_e_subito" ctx={ctx} colore="bg-sky-400" />
            <p className="text-[11px] text-slate-600 pt-2 px-2">
              Media gol: {fmt(s.riepilogo.media_golf, 2)} fatti · {fmt(s.riepilogo.media_gols, 2)} subiti a partita.
              Il numero a destra è la posizione in lega.
            </p>
          </div>
        </Section>
      </div>

      <Section title="Distribuzione per punteggio finale" icon={Hash} right="gol fatti - subiti">
        <div className="p-4 space-y-2 max-h-72 overflow-y-auto">
          {s.punteggi.map(p => (
            <BarraPerc key={`${p.golf}-${p.gols}`} label={`${p.golf} - ${p.gols}`}
                       valore={p.n} totale={n}
                       colore={p.segno === 'V' ? 'bg-green-500' : p.segno === 'N' ? 'bg-yellow-400' : 'bg-red-500'}
                       dx={`${p.n}×`} />
          ))}
        </div>
      </Section>
    </div>
  )
}

// ── Reparti ──────────────────────────────────────────────────

const REPARTI = [
  { ruolo: 1, nome: 'Portieri' },
  { ruolo: 2, nome: 'Difensori' },
  { ruolo: 3, nome: 'Centrocampisti' },
  { ruolo: 4, nome: 'Attaccanti' },
]

function CardReparto({ reparto, ctx, aperto, onDettaglio }) {
  const kv = `reparto_${reparto.ruolo}_voto`
  const kf = `reparto_${reparto.ruolo}_fantavoto`
  return (
    <div className={`rounded-xl border p-3 space-y-2 transition-colors ${aperto ? 'border-grass-500/40 bg-pitch-900' : 'border-white/5 bg-pitch-900'}`}>
      <p className="text-xs uppercase tracking-widest text-slate-400 flex items-center gap-2">
        <RoleBadge ruolo={reparto.ruolo} /> {reparto.nome}
      </p>
      <div className="grid grid-cols-2 gap-2">
        <CardMetrica k={kv} ctx={ctx} label="Voto" tono="text-grass-400" />
        <CardMetrica k={kf} ctx={ctx} label="Fantavoto" tono="text-gold-400" />
      </div>
      <button type="button" onClick={onDettaglio}
              className="w-full text-xs rounded-lg border border-white/10 py-1.5 text-slate-400 hover:text-white hover:border-white/25 transition-colors">
        {aperto ? 'Chiudi dettaglio' : 'Apri dettaglio'}
      </button>
    </div>
  )
}

function SezioneReparti({ ctx, stagione, idSquadra, competizione }) {
  const [aperto, setAperto] = useState(null)
  const rep = REPARTI.find(r => r.ruolo === aperto)
  return (
    <div className="space-y-6">
      <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-3">
        {REPARTI.map(r => (
          <CardReparto key={r.ruolo} reparto={r} ctx={ctx} aperto={aperto === r.ruolo}
                       onDettaglio={() => setAperto(a => (a === r.ruolo ? null : r.ruolo))} />
        ))}
      </div>
      <p className="text-[11px] text-slate-600 -mt-3">
        Voto = voto base senza bonus/malus; fantavoto = voto con bonus/malus. Media dei giocatori schierati, calcolata
        come media delle medie di partita.
      </p>
      {rep && (
        <Section title={`Dettaglio ${rep.nome.toLowerCase()}`} icon={Shield}
                 right={
                   <button type="button" onClick={() => setAperto(null)} className="inline-flex items-center gap-1 text-slate-500 hover:text-slate-300">
                     <X className="w-3.5 h-3.5" /> Chiudi
                   </button>
                 }>
          <div className="p-4">
            <AreaRuoli stagione={stagione} idSquadra={idSquadra} competizione={competizione} repartoFisso={rep.ruolo} />
          </div>
        </Section>
      )}
    </div>
  )
}

// ── Top / Flop 11 propri ─────────────────────────────────────

function ElencoGiocatori({ giocatori, tipo }) {
  const { ultimaStagione } = useApp()
  if (!giocatori?.length) {
    return <Vuoto>{tipo === 'top' ? 'Nessun tuo giocatore in Top 11.' : 'Nessun tuo giocatore in Flop 11.'}</Vuoto>
  }
  return (
    <div>
      {giocatori.map(g => (
        <div key={g.id_giocatore} className="flex items-center gap-3 px-4 py-2.5 border-b border-white/[0.03] last:border-0">
          <RoleBadge ruolo={g.ruolo} />
          <NomeConBandiera id={g.id_giocatore} nome={g.giocatore} stagione={ultimaStagione} className="flex-1 text-sm text-slate-300 font-medium" />
          <span className="text-xs text-slate-600 font-mono">{g.giocate} pres.</span>
          <span className={`font-mono text-sm font-bold w-12 text-right ${tipo === 'top' ? 'text-green-400' : 'text-red-400'}`}>
            {fmt(g.media, 2)}
          </span>
        </div>
      ))}
    </div>
  )
}

function TopFlopPropri({ top, flop }) {
  return (
    <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
      <Section title="I tuoi giocatori in Top 11" icon={Star} right={`${top?.length ?? 0}`}>
        <ElencoGiocatori giocatori={top} tipo="top" />
      </Section>
      <Section title="I tuoi giocatori in Flop 11" icon={ThumbsDown} right={`${flop?.length ?? 0}`}>
        <ElencoGiocatori giocatori={flop} tipo="flop" />
      </Section>
    </div>
  )
}

// ── Area ─────────────────────────────────────────────────────

export default function AreaStatistiche({ d, stagione, idSquadra, competizione, ctx }) {
  const champ = competizione === 'CHAMP'
  const { data: s, loading, error, refetch } = useFetch(
    () => getDashboardStatistiche(stagione, idSquadra, competizione),
    [stagione, idSquadra, competizione]
  )

  const caricamento = loading || (!s && !error)
  const nessunaPartita = s && s.riepilogo.giocate === 0

  return (
    <div className="space-y-6">
      <BarraNavigazione />
      <Intro ctx={ctx} />

      <div id="stat-risultati" className="sezione-scroll space-y-3">
        <h2 className="text-xs uppercase tracking-widest text-slate-500">Risultati</h2>
        {caricamento ? <LoadingState label="Calcolo statistiche..." />
          : error ? <ErrorState message={error} onRetry={refetch} />
          : nessunaPartita ? <EmptyState label={champ ? 'Nessuna partita di Champions ancora giocata.' : 'Nessuna giornata ancora giocata.'} />
          : <SezioneRisultati s={{ ...s, competizione }} ctx={ctx} />}
      </div>

      {s && !nessunaPartita && (
        <>
          <div id="stat-gol" className="sezione-scroll space-y-3">
            <h2 className="text-xs uppercase tracking-widest text-slate-500">Gol</h2>
            <SezioneGol s={s} ctx={ctx} />
          </div>
        </>
      )}

      <div id="stat-reparti" className="sezione-scroll space-y-3">
        <h2 className="text-xs uppercase tracking-widest text-slate-500">Reparti</h2>
        <SezioneReparti ctx={ctx} stagione={stagione} idSquadra={idSquadra} competizione={competizione} />
      </div>

      <div id="stat-giocatori" className="sezione-scroll space-y-3">
        <h2 className="text-xs uppercase tracking-widest text-slate-500">Top / Flop 11</h2>
        <TopFlopPropri
          top={champ ? d.champions.top11 : d.campionato.top11}
          flop={champ ? d.champions.flop11 : d.campionato.flop11}
        />
      </div>
    </div>
  )
}
