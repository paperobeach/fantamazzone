// ── Champions ──────────────────────────────────────────────────────────────
// Struttura della coppa:
//   Fase 1 · due gironi da 4 squadre, andata e ritorno
//   Fase 2 · due gironi da 3 squadre, andata e ritorno (via le ultime della fase 1)
//   Fase finale · semifinali A/R (1A-2B, 1B-2A), finale con replay,
//                 supplementari e calci di rigore in caso di parità
import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useApp } from '../context/AppContext'
import { useFetch } from '../hooks/useFetch'
import { getChampions, getDettaglioPartita } from '../api/client'
import { PageHeader, LoadingState, ErrorState, EmptyState, TrendIcon } from '../components/ui'
import TeamLogo from '../components/TeamLogo'
import { MatchResultRow } from '../components/MatchResult'
import { MatchDetailPanel } from '../components/MatchDetail'

const FASI = [
  { id: 'fase1', label: 'Fase 1', sub: 'Gironi' },
  { id: 'fase2', label: 'Fase 2', sub: 'Gironi' },
  { id: 'finale', label: 'Fase finale', sub: 'Semifinali e finale' },
]

// ── Fase a gironi ─────────────────────────────────────────────────────────
// Badge di posizione: stesso stile della classifica campionato. Le squadre
// che si qualificano hanno il badge verde, quelle eliminate il badge rosso.
function posizioneGirone(i, qualificano) {
  if (i < qualificano) {
    return {
      label: 'Qualificata',
      badgeClass: 'bg-gradient-to-br from-grass-400 to-grass-600 text-pitch-950 ring-1 ring-grass-300/60',
      rowBorderClass: 'border-l-2 border-l-grass-400',
    }
  }
  return {
    label: 'Eliminata',
    badgeClass: 'bg-red-500/20 text-red-300 ring-1 ring-red-400/40',
    rowBorderClass: 'border-l-2 border-l-red-400/60 bg-black/20',
  }
}

// Classifica del girone: stesso layout della Classifica Generale del
// campionato (card con intestazione, tabella con colonna Pts evidenziata).
function ClassificaGirone({ girone, squadre, qualificano }) {
  return (
    <div className="card overflow-hidden">
      <div className="px-4 py-3 border-b border-white/5">
        <h2 className="text-sm font-semibold text-slate-300">Classifica Girone {girone}</h2>
      </div>

      <div className="overflow-x-auto">
        <table className="fanta-table sticky-2 min-w-[1080px]">
          <thead>
            <tr>
              <th className="w-10">#</th>
              <th>Squadra</th>
              <th className="text-center">PG</th>
              <th className="text-center bg-grass-500/10">Pts</th>
              <th className="text-center">V</th>
              <th className="text-center">P</th>
              <th className="text-center">S</th>
              <th className="text-center">GF</th>
              <th className="text-center">GS</th>
              <th className="text-center">Media</th>
              <th className="text-center">Media A</th>
              <th className="text-center">Max</th>
              <th className="text-center">Min</th>
              <th className="text-center">Mod D</th>
              <th className="text-center">Mod C</th>
              <th className="text-center">Mod A</th>
              <th className="text-center">Trend</th>
            </tr>
          </thead>
          <tbody>
            {squadre.map((sq, i) => {
              const pos = posizioneGirone(i, qualificano)
              return (
                <tr
                  key={sq.id_squadra}
                  className={`border-b border-white/[0.03] transition-colors hover:bg-white/[0.02] ${pos.rowBorderClass}`}
                >
                  <td className="px-4 py-3 text-center">
                    <span
                      title={pos.label}
                      className={`inline-flex items-center justify-center w-7 h-7 rounded-full text-sm font-bold text-display ${pos.badgeClass}`}
                    >
                      {i + 1}
                    </span>
                  </td>
                  <td className="px-4 py-3">
                    <Link
                      to={`/squadre/${sq.id_squadra}`}
                      className="flex items-center gap-3 group"
                    >
                      <TeamLogo logo={sq.logo} nome={sq.nome} size="sm" />
                      <span className="font-medium text-slate-200 group-hover:text-grass-400 transition-colors text-sm">
                        {sq.nome}
                      </span>
                    </Link>
                  </td>
                  <td className="px-4 py-3 text-center text-slate-400 text-sm">{sq.giocate}</td>
                  <td className="px-3 py-3 text-center bg-grass-500/10 border-x border-grass-500/20">
                    <span className="text-display font-extrabold text-2xl text-grass-400">{sq.punti}</span>
                  </td>
                  <td className="px-4 py-3 text-center text-slate-400 text-sm">{sq.vinte}</td>
                  <td className="px-4 py-3 text-center text-slate-400 text-sm">{sq.nulle}</td>
                  <td className="px-4 py-3 text-center text-slate-400 text-sm">{sq.perse}</td>
                  <td className="px-4 py-3 text-center text-slate-300 text-sm">{sq.golf}</td>
                  <td className="px-4 py-3 text-center text-slate-500 text-sm">{sq.gols}</td>
                  {[
                    [sq.media, 1], [sq.media_a, 1], [sq.maxp, 1], [sq.minp, 1],
                    [sq.media_mod_dif, 2], [sq.media_mod_cc, 2], [sq.media_mod_att, 2],
                  ].map(([v, dec], k) => (
                    <td key={k} className="px-4 py-3 text-center">
                      <span className="font-mono text-xs text-slate-400">{Number(v ?? 0).toFixed(dec)}</span>
                    </td>
                  ))}
                  <td className="px-4 py-3 text-center">
                    <div className="flex justify-center">
                      <TrendIcon segno={sq.segno} />
                    </div>
                  </td>
                </tr>
              )
            })}
          </tbody>
        </table>
      </div>
    </div>
  )
}

// Riga partita: stesso layout della pagina Calendario, espandibile sul
// dettaglio: voti se giocata, altrimenti la sola formazione.
function Partita({ p }) {
  const { stagione } = useApp()
  const { casa, ospite, giocata, giornata } = p
  const [open, setOpen] = useState(false)
  const espandibile = !!giornata

  const risultato = giocata ? {
    golf: casa.golf,
    gols: ospite.golf,
    ftotale_casa: casa.ftotale,
    ftotale_ospite: ospite.ftotale,
    // supplementari e rigori (solo turni che li prevedono)
    supp: p.supplementari ? { casa: casa.supp_golf, ospite: ospite.supp_golf } : null,
    rig: p.rigori ? { casa: casa.rig_golf, ospite: ospite.rig_golf } : null,
  } : null

  // Dettaglio caricato on-demand alla prima apertura
  const { data: dettaglio, loading, error } = useFetch(
    () => (open && espandibile)
      ? getDettaglioPartita(stagione, giornata, casa.id, 'CHAMP', ospite.id)
      : Promise.resolve(null),
    [open, espandibile, stagione, giornata, casa.id, ospite.id]
  )
  const match = dettaglio?.[0] ?? null

  return (
    <div className="border-b border-white/[0.03] last:border-0">
      <MatchResultRow
        casa={casa}
        ospite={ospite}
        risultato={risultato}
        expandable
        disabled={!espandibile}
        open={open}
        onToggle={() => setOpen(o => !o)}
      />
      {open && (
        <MatchDetailPanel casa={match?.casa} ospite={match?.ospite} extra={match?.extra} loading={loading} error={error} />
      )}
    </div>
  )
}

function CalendarioGirone({ turni }) {
  return (
    <div>
      {turni.map(t => (
        <div key={`${t.giornata_camp}-${t.giornata}`} className="border-t border-white/5">
          <div className="px-4 py-1.5 bg-pitch-900/60 text-[11px] uppercase tracking-wider text-slate-500 flex justify-between">
            <span>{t.label}</span>
            <span>Giornata fantacampionato {t.giornata_camp}</span>
          </div>
          {t.partite.map((p, i) => <Partita key={i} p={p} />)}
          {t.riposa?.map(r => (
            <div key={r.id} className="px-4 py-1.5 text-xs text-slate-600 text-center">
              Riposa: {r.nome}
            </div>
          ))}
        </div>
      ))}
    </div>
  )
}

// Fase a gironi (fase1 / fase2): stessa struttura, cambia la sezione richiesta.
// Si mostra un solo girone per volta, scelto con le schede in alto.
function FaseGironi({ stagione, sezione, vuoto, nota }) {
  const [selezionato, setSelezionato] = useState(null)
  const { data, loading, error, refetch } = useFetch(
    () => getChampions(stagione, sezione),
    [stagione, sezione]
  )
  const gironi = Array.isArray(data) ? data : []

  if (loading) return <LoadingState />
  if (error) return <ErrorState message={error} onRetry={refetch} />
  if (!gironi.length) return <EmptyState label={vuoto} />

  // se la selezione non esiste più (es. cambio stagione) si torna al primo girone
  const g = gironi.find(x => x.girone === selezionato) ?? gironi[0]

  return (
    <div className="space-y-4">
      {gironi.length > 1 && (
        <div className="flex gap-2 overflow-x-auto">
          {gironi.map(x => (
            <button
              key={x.girone}
              onClick={() => setSelezionato(x.girone)}
              className={`px-4 py-1.5 rounded-lg text-sm font-medium transition-all whitespace-nowrap ${
                g.girone === x.girone ? 'bg-grass-500 text-pitch-950' : 'bg-pitch-900 text-slate-400 hover:text-slate-200 border border-white/5'
              }`}
            >
              Girone {x.girone}
            </button>
          ))}
        </div>
      )}

      <ClassificaGirone girone={g.girone} squadre={g.squadre} qualificano={g.qualificano} />

      {/* Il calendario ha una larghezza propria: non segue quella della classifica */}
      <div className="card overflow-hidden w-full max-w-3xl">
        <div className="px-4 py-3 border-b border-white/5">
          <h2 className="text-sm font-semibold text-slate-300">Calendario e risultati · Girone {g.girone}</h2>
        </div>
        <CalendarioGirone turni={g.turni} />
      </div>

      {/* Legend */}
      <div className="flex flex-wrap items-center gap-x-6 gap-y-2 px-1">
        <div className="flex items-center gap-2">
          <div className="w-3 h-3 rounded-full bg-gradient-to-br from-grass-400 to-grass-600 ring-1 ring-grass-300/60" />
          <span className="text-xs text-slate-600">Qualificata</span>
        </div>
        <div className="flex items-center gap-2">
          <div className="w-3 h-3 rounded-full bg-red-500/20 ring-1 ring-red-400/40" />
          <span className="text-xs text-slate-600">Eliminata</span>
        </div>
      </div>

      <p className="text-xs text-slate-600">
        {nota} Parità in classifica: differenza reti, gol fatti, fantapunti totali.
      </p>
    </div>
  )
}

// ── Fase finale ───────────────────────────────────────────────────────────
function Semifinale({ t }) {
  const [a, b] = t.aggregato
  return (
    <div className="card overflow-hidden">
      <div className="px-4 py-2 bg-pitch-900/60 text-[11px] uppercase tracking-wider text-slate-500 flex justify-between">
        <span>Andata</span>
        {t.andata?.giornata && <span>Giornata fantacampionato {t.andata.giornata}</span>}
      </div>
      {t.andata && <Partita p={t.andata} />}
      <div className="px-4 py-2 bg-pitch-900/60 text-[11px] uppercase tracking-wider text-slate-500 border-t border-white/5 flex justify-between">
        <span>Ritorno</span>
        {t.ritorno?.giornata && <span>Giornata fantacampionato {t.ritorno.giornata}</span>}
      </div>
      {t.ritorno ? <Partita p={t.ritorno} /> : <p className="px-4 py-2.5 text-xs text-slate-600 text-center">Da definire</p>}
      {t.completa && a && b && (
        <div className="px-4 py-2.5 border-t border-white/5 text-center text-sm">
          <span className="text-slate-500">Aggregato </span>
          <span className="font-bold text-white text-display">{a.golf} - {b.golf}</span>
          {t.vincente
            ? <span className="text-grass-400"> · passa {t.vincente.nome}</span>
            : <span className="text-amber-400"> · parità</span>}
          {t.rigori ? <span className="text-slate-500"> (ai rigori)</span> : t.supplementari ? <span className="text-slate-500"> (dopo i supplementari)</span> : null}
        </div>
      )}
    </div>
  )
}

function PartitaFinale({ titolo, p }) {
  if (!p) return null
  return (
    <div className="card overflow-hidden">
      <div className="px-4 py-2 bg-pitch-900/60 text-[11px] uppercase tracking-wider text-slate-500 flex justify-between">
        <span>{titolo}</span>
        {p.giornata && <span>Giornata fantacampionato {p.giornata}</span>}
      </div>
      <Partita p={p} />
    </div>
  )
}

function FaseFinale({ stagione }) {
  const { data, loading, error, refetch } = useFetch(
    () => getChampions(stagione, 'finale'),
    [stagione]
  )
  if (loading) return <LoadingState />
  if (error) return <ErrorState message={error} onRetry={refetch} />
  if (!data || (!data.semifinali?.length && !data.finale)) {
    return <EmptyState label="Fase finale non ancora definita per questa stagione" />
  }

  return (
    <div className="space-y-6">
      {data.campione && (
        <div className="card p-5 flex items-center gap-4 border border-grass-500/40">
          <TeamLogo logo={data.campione.logo} nome={data.campione.nome} size="lg" />
          <div>
            <div className="text-[11px] uppercase tracking-wider text-slate-500">Vincitore Champions</div>
            <div className="text-xl font-bold text-white text-display">{data.campione.nome}</div>
          </div>
        </div>
      )}

      {data.semifinali?.length > 0 && (
        <section>
          <h3 className="font-semibold text-slate-300 mb-3">Semifinali</h3>
          <div className="grid gap-4 lg:grid-cols-2">
            {data.semifinali.map((t, i) => <Semifinale key={i} t={t} />)}
          </div>
        </section>
      )}

      {data.finale && (
        <section>
          <h3 className="font-semibold text-slate-300 mb-3">Finale</h3>
          <div className="grid gap-4 lg:grid-cols-2">
            <PartitaFinale titolo="Finale" p={data.finale} />
            <PartitaFinale titolo="Replay" p={data.replay} />
          </div>
          {data.nota && <p className="text-sm text-amber-400 mt-3">{data.nota}</p>}
        </section>
      )}

      <p className="text-xs text-slate-600">
        Semifinali andata e ritorno (1ª girone A vs 2ª girone B, 1ª girone B vs 2ª girone A). In caso di parità
        nella finale si gioca il replay. Nei turni che lo prevedono (configurazione calendario Champions)
        la parità si risolve con supplementari e calci di rigore: nelle semifinali dopo il ritorno, se la
        somma dei gol è pari; nelle finali dopo la partita stessa.
      </p>
    </div>
  )
}

// ── Note ──────────────────────────────────────────────────────────────────
function Note({ stagione }) {
  const { data } = useFetch(() => getChampions(stagione, 'note'), [stagione])
  const note = Array.isArray(data) ? data : []
  if (!note.length) return null
  return (
    <div className="card p-4 mt-6 space-y-1">
      <h3 className="font-semibold text-slate-300 text-sm">Note</h3>
      {note.map((n, i) => <p key={i} className="text-sm text-slate-400">{n.LABEL ?? n.label}</p>)}
    </div>
  )
}

// ── Pagina ────────────────────────────────────────────────────────────────
export default function Champions() {
  const { stagione } = useApp()
  const [fase, setFase] = useState('fase1')

  return (
    <div className="animate-fade-up">
      <PageHeader label="Coppa" title="Champions League" />

      <div className="flex gap-2 mb-6 overflow-x-auto">
        {FASI.map(f => (
          <button
            key={f.id}
            onClick={() => setFase(f.id)}
            className={`px-4 py-1.5 rounded-lg text-sm font-medium transition-all whitespace-nowrap ${
              fase === f.id ? 'bg-grass-500 text-pitch-950' : 'bg-pitch-900 text-slate-400 hover:text-slate-200 border border-white/5'
            }`}
          >
            {f.label} <span className="hidden sm:inline opacity-70">· {f.sub}</span>
          </button>
        ))}
      </div>

      {fase === 'fase1' && (
        <FaseGironi key="fase1" stagione={stagione} sezione="fase1"
          vuoto="Nessun girone della prima fase per questa stagione"
          nota="Le prime tre di ogni girone accedono alla Fase 2; l'ultima classificata è eliminata." />
      )}
      {fase === 'fase2' && (
        <FaseGironi key="fase2" stagione={stagione} sezione="fase2"
          vuoto="Nessun girone della seconda fase per questa stagione"
          nota="Le prime due di ogni girone accedono alle semifinali; la terza è eliminata." />
      )}
      {fase === 'finale' && <FaseFinale stagione={stagione} />}

      <Note stagione={stagione} />
    </div>
  )
}
