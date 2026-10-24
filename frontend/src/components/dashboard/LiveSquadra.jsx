import { useEffect, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import {
  ChevronDown, ChevronUp, Trophy, Crown, PlayCircle, Users, ArrowRightLeft, FlaskConical,
} from 'lucide-react'
import { useFetch } from '../../hooks/useFetch'
import { getDashboardLive, getLiveDettaglio } from '../../api/client'
import { RoleBadge } from '../ui'
import { MatchDetailPanel } from '../MatchDetail'
import TeamLogo from '../TeamLogo'
import { Bandiera } from '../Bandiera'
import { SEGNO, fmt, fmtDataOra } from './shared'

// ── Dati: si aggiornano ogni minuto durante la giornata ──────

/**
 * Stato LIVE della squadra. Fuori dalla finestra della giornata il backend
 * risponde subito { attiva: false }: si ricontrolla ogni 5 minuti per
 * accorgersi della partenza della prima partita.
 */
export function useLiveSquadra(stagione, idSquadra) {
  const { data, refetch } = useFetch(
    stagione && idSquadra ? () => getDashboardLive(stagione, idSquadra) : null,
    [stagione, idSquadra]
  )
  const attiva = Boolean(data?.attiva)
  // refetch cambia identità a ogni render: si tiene in un ref per non azzerare l'intervallo
  const refetchRef = useRef(refetch)
  refetchRef.current = refetch
  useEffect(() => {
    if (!stagione || !idSquadra) return undefined
    const t = setInterval(() => refetchRef.current(), attiva ? 60_000 : 300_000)
    return () => clearInterval(t)
  }, [attiva, stagione, idSquadra])
  return data?.attiva ? data : null
}

// ── Utilità ──────────────────────────────────────────────────

const oggiLocale = () => new Date().toLocaleDateString('sv-SE')   // YYYY-MM-DD, ora locale

/** "20:45" se è oggi, altrimenti "dom 18 ott, 20:45". */
function quando(data, ora) {
  if (!data) return ''
  if (data === oggiLocale()) return ora ? ora.slice(0, 5) : 'orario da definire'
  return fmtDataOra(`${data} ${ora ?? '00:00:00'}`) ?? data
}

/** Punteggio nell'ordine casa - ospite (il backend lo dà dal punto di vista della squadra). */
function punteggio(p) {
  if (!p.mio) return null
  const [a, b] = p.io_casa ? [p.mio.golf, p.mio.gols] : [p.mio.gols, p.mio.golf]
  const [fa, fb] = p.io_casa ? [p.mio.ftotale, p.mio.ftotale_a] : [p.mio.ftotale_a, p.mio.ftotale]
  return { gol: `${a} - ${b}`, fanta: `${fmt(fa)} / ${fmt(fb)}` }
}

const NOME_RUOLO = { 1: 'Portieri', 2: 'Difensori', 3: 'Centrocampisti', 4: 'Attaccanti' }

function PuntoLive({ className = '' }) {
  return (
    <span className={`relative inline-flex w-2.5 h-2.5 ${className}`} aria-hidden>
      <span className="absolute inline-flex w-full h-full rounded-full bg-red-500 opacity-60 animate-ping" />
      <span className="relative inline-flex w-2.5 h-2.5 rounded-full bg-red-500" />
    </span>
  )
}

// ── Chip sintetico (intestazione compressa) ──────────────────

export function LiveChip({ live }) {
  const punteggi = live.partite.map(punteggio)
  return (
    <Link to="/live" title="LIVE giornata: simulazione e dettaglio"
          className="inline-flex items-center gap-1.5 px-2 py-1 rounded-lg border border-red-500/40 bg-red-500/10 text-xs font-mono text-red-300 hover:bg-red-500/20 transition-colors">
      <PuntoLive />
      LIVE
      {punteggi.some(Boolean) && (
        <span className="text-slate-200 font-bold">
          {live.partite.map((p, i) => punteggi[i] && (
            <span key={p.competizione} className={i > 0 ? 'ml-2' : ''}>
              {p.competizione === 'CHAMP' && <Crown className="inline w-3 h-3 text-sky-400 mr-0.5 -mt-0.5" />}
              {punteggi[i].gol}
            </span>
          ))}
        </span>
      )}
    </Link>
  )
}

// ── Partita fanta con dettaglio espandibile ──────────────────

function PartitaLive({ stagione, p }) {
  const [aperto, setAperto] = useState(false)
  const champ = p.competizione === 'CHAMP'
  const pt = punteggio(p)
  const { data: det, loading, error } = useFetch(
    aperto && p.fonte ? () => getLiveDettaglio(stagione, p.id_casa, 'auto', p.competizione) : null,
    [aperto, stagione, p.id_casa, p.competizione, p.fonte]
  )
  const esito = SEGNO[p.mio?.segno]

  return (
    <div className={`rounded-xl border ${champ ? 'border-sky-500/20' : 'border-grass-500/20'} bg-pitch-900/60 overflow-hidden`}>
      <div className="px-3 py-2.5">
        <p className={`text-[10px] uppercase tracking-widest flex items-center gap-1.5 ${champ ? 'text-sky-400' : 'text-grass-400'}`}>
          {champ ? <Crown className="w-3 h-3" /> : <Trophy className="w-3 h-3" />}
          {champ ? 'Champions' : 'Campionato'}{p.turno ? ` · ${p.turno}` : ''}
          {p.fonte && (
            <span className={`ml-auto normal-case tracking-normal px-1.5 py-0.5 rounded border text-[10px] ${
              p.fonte === 'reale' ? 'border-green-500/30 text-green-400 bg-green-500/10'
                                  : 'border-gold-500/30 text-gold-400 bg-gold-500/10'}`}>
              {p.fonte === 'reale' ? 'calcolo reale' : 'simulato'}
            </span>
          )}
        </p>

        <div className="mt-2 grid grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)] items-center gap-2">
          <div className="flex flex-col items-center gap-1 min-w-0 sm:flex-row sm:gap-2">
            <div className="max-w-full min-w-0"><TeamLogo logo={p.casa.logo} nome={p.casa.nome} size="sm" /></div>
            <span className="text-xs sm:text-sm text-slate-200 text-center sm:text-left leading-tight line-clamp-2 break-words min-w-0">{p.casa.nome}</span>
          </div>
          <div className="text-center">
            {pt ? (
              <>
                <p className={`text-display text-xl font-bold leading-none ${esito?.cls ?? 'text-white'}`}>{pt.gol}</p>
                <p className="text-[10px] font-mono text-slate-600 mt-1">{pt.fanta}</p>
              </>
            ) : <span className="text-xs text-slate-600">vs</span>}
          </div>
          <div className="flex flex-col items-center gap-1 min-w-0 sm:flex-row sm:gap-2 sm:justify-end">
            <div className="max-w-full min-w-0 sm:order-2"><TeamLogo logo={p.ospite.logo} nome={p.ospite.nome} size="sm" /></div>
            <span className="text-xs sm:text-sm text-slate-200 text-center sm:text-right leading-tight line-clamp-2 break-words min-w-0 sm:order-1">{p.ospite.nome}</span>
          </div>
        </div>

        <div className="mt-2 flex items-center gap-2 text-xs">
          {p.fonte ? (
            <button onClick={() => setAperto(a => !a)}
                    className="inline-flex items-center gap-1 text-slate-400 hover:text-white transition-colors">
              {aperto ? <ChevronUp className="w-3.5 h-3.5" /> : <ChevronDown className="w-3.5 h-3.5" />}
              {aperto ? 'Nascondi dettaglio' : 'Dettaglio'}
            </button>
          ) : (
            <Link to="/live" className="inline-flex items-center gap-1 text-gold-400 hover:text-gold-300">
              <PlayCircle className="w-3.5 h-3.5" /> Nessuna simulazione: avviala
            </Link>
          )}
          {p.fonte === 'simulazione' && p.calcolato_il && (
            <span className="ml-auto text-[10px] font-mono text-slate-600 truncate">
              {fmtDataOra(p.calcolato_il)}{p.simulato_da ? ` · ${p.simulato_da}` : ''}
            </span>
          )}
        </div>
      </div>

      {aperto && (
        <MatchDetailPanel casa={det?.casa} ospite={det?.ospite} loading={loading || !det && !error} error={error} />
      )}
    </div>
  )
}

// ── Giocatori delle prossime partite e panchinari ────────────

function StatoCandidato({ c }) {
  if (c.voto !== null && c.voto !== undefined) return <span className="text-green-400">voto {fmt(c.voto, 1)}</span>
  if (c.stato_partita === 'in_corso') return <span className="text-gold-400">in campo ora</span>
  if (c.stato_partita === 'da_giocare') return <span className="text-slate-400">gioca {quando(c.data, c.ora)}</span>
  if (c.stato_partita === 'conclusa') return <span className="text-slate-500">attesa voto</span>
  return <span className="text-slate-600">nessuna partita</span>
}

/** Titolari senza voto (partita finita): al loro posto entra il panchinaro. */
function BloccoSenzaVoto({ giocatori }) {
  if (!giocatori?.length) return null
  return (
    <div>
      <p className="text-[10px] uppercase tracking-widest text-red-400">Senza voto: entra il panchinaro</p>
      <div className="mt-1.5 flex flex-wrap gap-1">
        {giocatori.map(g => (
          <span key={g.id_giocatore} className="inline-flex items-center gap-1 px-1.5 py-0.5 rounded-md bg-red-500/10 border border-red-500/20 text-[11px] text-red-300">
            <RoleBadge ruolo={g.ruolo} /><Bandiera id={g.id_giocatore} /> {g.nome}
          </span>
        ))}
      </div>
    </div>
  )
}

/** Primi panchinari che entrerebbero, per ruolo, se i titolari da verificare non prendessero voto. */
function BloccoPanchinari({ panchinari }) {
  if (!panchinari?.length) return null
  return (
    <div className="space-y-2">
      <p className="text-[10px] uppercase tracking-widest text-gold-400 flex items-center gap-1.5">
        <ArrowRightLeft className="w-3 h-3" /> Primi panchinari coinvolti se un titolare non prende voto
      </p>
      {panchinari.map(r => (
        <div key={r.ruolo}>
          <p className="text-[11px] text-slate-500">
            {NOME_RUOLO[r.ruolo]} <span className="text-slate-700">· {r.a_rischio} titolar{r.a_rischio === 1 ? 'e' : 'i'} da verificare</span>
          </p>
          {r.candidati.length === 0 ? (
            <p className="text-[11px] text-slate-700">Nessun panchinaro disponibile per il ruolo.</p>
          ) : r.candidati.map((c, i) => (
            <div key={c.id_giocatore} className="flex items-center gap-2 text-xs py-0.5">
              <span className="text-slate-700 font-mono w-3">{i + 1}</span>
              <RoleBadge ruolo={c.ruolo} />
              <Bandiera id={c.id_giocatore} />
              <span className="text-slate-300 truncate">{c.nome}</span>
              <span className="ml-auto font-mono text-[11px] flex-shrink-0"><StatoCandidato c={c} /></span>
            </div>
          ))}
        </div>
      ))}
    </div>
  )
}

/** Intestazione di una squadra (la tua o un avversario) con le icone delle competizioni. */
function TitoloSquadra({ nome, competizioni, tua = false }) {
  return (
    <p className="text-xs text-slate-400 flex items-center gap-1.5 flex-wrap">
      {competizioni?.includes('CAMP') && <Trophy className="w-3 h-3 text-grass-400" aria-label="Campionato" />}
      {competizioni?.includes('CHAMP') && <Crown className="w-3 h-3 text-sky-400" aria-label="Champions" />}
      <span className="text-[10px] uppercase tracking-widest text-slate-500">{tua ? 'La tua squadra' : 'Avversario'}</span>
      {nome && <span className="text-slate-200">{nome}</span>}
    </p>
  )
}

// Il pannello è sempre aperto: durante la LIVE serve a colpo d'occhio.
function GiocatoriLive({ live, nomeSquadra }) {
  const avversari = live.avversari ?? []
  const nGiocatori = live.oggi.reduce((n, m) => n + m.giocatori.length, 0)
  const nAvversari = live.oggi.reduce((n, m) => n + (m.avversari ?? []).reduce((k, a) => k + a.giocatori.length, 0), 0)
  const nPanch = live.panchinari.reduce((n, r) => n + r.candidati.length, 0)
  const comp = (live.partite ?? []).map(p => p.competizione)

  const sezioneTua = live.senza_voto.length > 0 || live.panchinari.length > 0
  const sezioniAvv = avversari.filter(a => a.senza_voto.length > 0 || a.panchinari.length > 0)

  return (
    <div className="rounded-xl border border-white/10 bg-pitch-900/60 overflow-hidden">
      <div className="flex items-center gap-2 px-3 py-2.5">
        <Users className="w-4 h-4 text-grass-400 flex-shrink-0" />
        <span className="text-sm text-slate-200">Giocatori nelle prossime partite di oggi</span>
        <span className="ml-auto text-xs font-mono text-slate-500">{nGiocatori} tuoi · {nAvversari} avversari · {nPanch} panchinari</span>
      </div>

      <div className="border-t border-white/5 divide-y divide-white/5">
        {live.oggi.length === 0 && (
          <p className="px-3 py-3 text-xs text-slate-600">Nessuna altra partita di Serie A in programma oggi.</p>
        )}
        {live.oggi.map(m => (
          <div key={m.n_partita} className="px-3 py-2.5">
            <p className="text-xs text-slate-400 flex items-center gap-2 flex-wrap">
              <span className={`font-mono ${m.stato === 'in_corso' ? 'text-red-300' : 'text-slate-500'}`}>
                {m.stato === 'in_corso' ? 'IN CORSO' : quando(m.data, m.ora)}
              </span>
              <span className="text-slate-300">{m.casa} – {m.ospite}</span>
            </p>
            {m.giocatori.length === 0 && !(m.avversari ?? []).length ? (
              <p className="text-[11px] text-slate-700 mt-1">Nessun titolare tuo né degli avversari.</p>
            ) : m.giocatori.length === 0 ? (
              <p className="text-[11px] text-slate-700 mt-1">Nessun tuo titolare.</p>
            ) : (
              <div className="mt-1.5 flex flex-wrap gap-1">
                {m.giocatori.map(g => (
                  <span key={g.id_giocatore} title={g.squadra_serie_a ?? ''}
                        className="inline-flex items-center gap-1 px-1.5 py-0.5 rounded-md bg-grass-500/10 border border-grass-500/20 text-[11px] text-grass-300">
                    <RoleBadge ruolo={g.ruolo} /><Bandiera id={g.id_giocatore} /> {g.nome}
                    {g.competizioni.includes('CHAMP') && <Crown className="w-3 h-3 text-sky-400" aria-label="Anche in Champions" />}
                  </span>
                ))}
              </div>
            )}

            {(m.avversari ?? []).map(a => (
              <div key={a.id_squadra} className="mt-2">
                <p className="text-[10px] uppercase tracking-widest text-slate-500 flex items-center gap-1.5">
                  {a.competizioni.includes('CAMP') && <Trophy className="w-3 h-3 text-grass-400" aria-label="Campionato" />}
                  {a.competizioni.includes('CHAMP') && <Crown className="w-3 h-3 text-sky-400" aria-label="Champions" />}
                  Avversario: <span className="text-slate-300 normal-case tracking-normal">{a.nome}</span>
                </p>
                <div className="mt-1 flex flex-wrap gap-1">
                  {a.giocatori.map(g => (
                    <span key={g.id_giocatore} title={g.squadra_serie_a ?? ''}
                          className="inline-flex items-center gap-1 px-1.5 py-0.5 rounded-md bg-white/[0.04] border border-white/10 text-[11px] text-slate-300">
                      <RoleBadge ruolo={g.ruolo} /><Bandiera id={g.id_giocatore} /> {g.nome}
                      {g.competizioni.includes('CAMP') && g.competizioni.includes('CHAMP') && (
                        <Crown className="w-3 h-3 text-sky-400" aria-label="Anche in Champions" />
                      )}
                    </span>
                  ))}
                </div>
              </div>
            ))}
          </div>
        ))}

        {sezioneTua && (
          <div className="px-3 py-2.5 space-y-2">
            <TitoloSquadra nome={nomeSquadra} competizioni={comp} tua />
            <BloccoSenzaVoto giocatori={live.senza_voto} />
            <BloccoPanchinari panchinari={live.panchinari} />
            <p className="text-[10px] text-slate-700">Indicativo: entra il primo panchinaro del ruolo che prende voto; non si considerano i limiti di sostituzioni.</p>
          </div>
        )}

        {sezioniAvv.map(a => (
          <div key={a.id_squadra} className="px-3 py-2.5 space-y-2">
            <TitoloSquadra nome={a.nome} competizioni={a.competizioni} />
            <BloccoSenzaVoto giocatori={a.senza_voto} />
            <BloccoPanchinari panchinari={a.panchinari} />
          </div>
        ))}
      </div>
    </div>
  )
}

// ── Blocco LIVE (intestazione estesa) ────────────────────────

export function LivePanel({ live, stagione, nomeSquadra }) {
  return (
    <div className="rounded-xl border border-red-500/30 bg-gradient-to-br from-red-500/[0.07] to-transparent p-3 sm:p-4 space-y-3">
      <div className="flex items-center gap-2 flex-wrap">
        <PuntoLive />
        <p className="text-sm font-semibold text-red-300">LIVE · giornata {live.giornata}</p>
        <Link to="/live" className="ml-auto btn-ghost text-xs !px-2.5 !py-1.5">
          <FlaskConical className="w-3.5 h-3.5" /> Simulazione giornata
        </Link>
      </div>

      {live.partite.length === 0 ? (
        <p className="text-xs text-slate-500">Nessuna tua partita in questa giornata.</p>
      ) : (
        <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
          {live.partite.map(p => <PartitaLive key={p.competizione} stagione={stagione} p={p} />)}
        </div>
      )}

      <GiocatoriLive live={live} nomeSquadra={nomeSquadra} />
    </div>
  )
}
