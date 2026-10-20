import { useState } from 'react'
import {
  ClipboardList, Flame, Swords, Star, ThumbsDown, Home, Plane, Target, BarChart3, Hash,
} from 'lucide-react'
import { useFetch } from '../../hooks/useFetch'
import { getDashboardStatistiche } from '../../api/client'
import { LoadingState, ErrorState, EmptyState, RoleBadge, TabBar } from '../ui'
import {
  Section, Vuoto, Indicatore, BarraPerc, PallinoForma, MatriceScontri, LogoCompatto, fmt,
} from './shared'
import AreaRuoli from './AreaRuoli'

// ── Serie ────────────────────────────────────────────────────

const SERIE_DEF = [
  { key: 'vittorie',       label: 'Vittorie consecutive',    positiva: true  },
  { key: 'imbattibilita',  label: 'Partite senza sconfitte', positiva: true  },
  { key: 'sconfitte',      label: 'Sconfitte consecutive',   positiva: false },
  { key: 'senza_vittorie', label: 'Partite senza vittorie',  positiva: false },
]

function SerieTable({ serie }) {
  return (
    <div className="overflow-x-auto">
      <table className="fanta-table">
        <thead>
          <tr>
            <th>Serie</th>
            <th className="text-center">In corso</th>
            <th className="text-center">Record stagione</th>
          </tr>
        </thead>
        <tbody>
          {SERIE_DEF.map(({ key, label, positiva }) => {
            const s = serie[key]
            const colore = positiva ? 'text-green-400' : 'text-red-400'
            return (
              <tr key={key}>
                <td className="text-sm text-slate-300">{label}</td>
                <td className="text-center">
                  <span className={`font-mono font-bold ${s.corrente > 0 ? colore : 'text-slate-600'}`}>{s.corrente}</span>
                </td>
                <td className="text-center font-mono font-bold text-slate-300">{s.record}</td>
              </tr>
            )
          })}
        </tbody>
      </table>
    </div>
  )
}

// ── Casa / trasferta ─────────────────────────────────────────

function BloccoCasaTrasferta({ titolo, icona: Icona, r }) {
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
        </>
      )}
    </div>
  )
}

// ── Sezione "Risultati e classifica" ─────────────────────────

function RisultatiClassifica({ s, champ, kpi }) {
  const [nForma, setNForma] = useState(5)
  const r = s.riepilogo
  const n = r.giocate
  const g = s.gol
  const forma = s.forma.slice(0, nForma)

  if (n === 0) {
    return <EmptyState label={champ ? 'Nessuna partita di Champions ancora giocata.' : 'Nessuna giornata ancora giocata.'} />
  }

  const diff = r.diff_reti > 0 ? `+${r.diff_reti}` : String(r.diff_reti)

  return (
    <div className="space-y-6">
      {/* Indicatori principali */}
      <div className="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-8 gap-3">
        <Indicatore label={s.posizione?.etichetta ?? 'Posizione'}
                    value={s.posizione ? `${s.posizione.posizione}°` : '–'}
                    sub={s.posizione ? `su ${s.posizione.squadre}` : undefined} tono="text-grass-400" />
        <Indicatore label="Punti" value={r.punti} />
        <Indicatore label="Punti / partita" value={fmt(r.punti_per_partita, 2)} />
        <Indicatore label="Giocate" value={n} />
        <Indicatore label="Vittorie" value={r.vinte} tono="text-green-400" />
        <Indicatore label="Pareggi" value={r.nulle} tono="text-yellow-400" />
        <Indicatore label="Sconfitte" value={r.perse} tono="text-red-400" />
        <Indicatore label="Diff. reti" value={diff}
                    sub={`${r.golf} fatti · ${r.gols} subiti`}
                    tono={r.diff_reti > 0 ? 'text-green-400' : r.diff_reti < 0 ? 'text-red-400' : 'text-white'} />
      </div>

      {/* Fantapunteggi (solo campionato: dati di classifica generale) */}
      {kpi && (
        <div className="grid grid-cols-2 lg:grid-cols-4 gap-3">
          <Indicatore label="Miglior fantapunteggio" value={fmt(kpi.migliore)} tono="text-grass-400" />
          <Indicatore label="Peggior fantapunteggio" value={fmt(kpi.peggiore)} />
          <Indicatore label="Media fantapunti" value={fmt(kpi.media, 2)} />
          <Indicatore label="Media avversari" value={fmt(kpi.media_avversari, 2)} sub="fantapunti subiti" />
        </div>
      )}

      {/* Forma */}
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

      {/* Casa / trasferta */}
      <Section title="Rendimento casa / trasferta" icon={Home}>
        <div className="p-4 grid grid-cols-1 sm:grid-cols-2 gap-4">
          <BloccoCasaTrasferta titolo="In casa" icona={Home} r={s.casa_trasferta.casa} />
          <BloccoCasaTrasferta titolo="In trasferta" icona={Plane} r={s.casa_trasferta.trasferta} />
        </div>
        {s.casa_trasferta.non_definite > 0 && (
          <p className="px-4 pb-3 text-xs text-slate-600">
            {s.casa_trasferta.non_definite} partite senza indicazione casa/trasferta non incluse.
          </p>
        )}
      </Section>

      {/* Gol */}
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <Section title="Gol segnati e subiti" icon={Target} right={`${n} partite`}>
          <div className="p-4 space-y-3">
            <BarraPerc label="Clean sheet"      valore={g.clean_sheet}      totale={n} colore="bg-green-500" />
            <BarraPerc label="Senza segnare"    valore={g.senza_segnare}    totale={n} colore="bg-red-500" />
            <BarraPerc label="Gol segnato"      valore={g.con_gol_segnato}  totale={n} colore="bg-grass-400" />
            <BarraPerc label="Gol subito"       valore={g.con_gol_subito}   totale={n} colore="bg-orange-400" />
            <BarraPerc label="Segnato e subito" valore={g.segnato_e_subito} totale={n} colore="bg-sky-400" />
            <p className="text-[11px] text-slate-600 pt-1">
              Media gol: {fmt(r.media_golf, 2)} fatti · {fmt(r.media_gols, 2)} subiti a partita.
            </p>
          </div>
        </Section>

        <Section title="Over / Under" icon={BarChart3} right="gol totali della partita">
          <div className="p-4 space-y-4">
            {['1.5', '2.5', '3.5'].map(k => (
              <div key={k}>
                <p className="text-xs font-mono text-slate-400 mb-1.5">Soglia {k}</p>
                <div className="space-y-1.5">
                  <BarraPerc label={`Over ${k}`}  valore={s.over_under[k].over}  totale={n} colore="bg-grass-500" />
                  <BarraPerc label={`Under ${k}`} valore={s.over_under[k].under} totale={n} colore="bg-slate-500" />
                </div>
              </div>
            ))}
          </div>
        </Section>
      </div>

      {/* Serie e punteggi */}
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <Section title="Serie" icon={Flame}>
          <SerieTable serie={s.serie} />
        </Section>

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

      {/* Matrice */}
      <Section title="Matrice dei risultati" icon={Swords} right="contro ogni avversario">
        <MatriceScontri matrice={s.matrice} vuota={champ ? 'Mai affrontata' : 'Non ancora giocato'} />
      </Section>
    </div>
  )
}

// ── Top / Flop 11 propri ─────────────────────────────────────

function ElencoGiocatori({ giocatori, tipo }) {
  if (!giocatori?.length) {
    return <Vuoto>{tipo === 'top' ? 'Nessun tuo giocatore in Top 11.' : 'Nessun tuo giocatore in Flop 11.'}</Vuoto>
  }
  return (
    <div>
      {giocatori.map(g => (
        <div key={g.id_giocatore} className="flex items-center gap-3 px-4 py-2.5 border-b border-white/[0.03] last:border-0">
          <RoleBadge ruolo={g.ruolo} />
          <span className="flex-1 text-sm text-slate-300 font-medium truncate">{g.giocatore}</span>
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

const SOTTO_AREE = [
  { value: 'risultati', label: 'Risultati e classifica' },
  { value: 'ruoli',     label: 'Analisi per ruolo' },
  { value: 'topflop',   label: 'Top / Flop 11' },
]

export default function AreaStatistiche({ d, stagione, idSquadra, competizione }) {
  const [sotto, setSotto] = useState('risultati')
  const champ = competizione === 'CHAMP'

  const { data: s, loading, error, refetch } = useFetch(
    sotto === 'risultati' ? () => getDashboardStatistiche(stagione, idSquadra, competizione) : null,
    [stagione, idSquadra, competizione, sotto]
  )

  return (
    <div className="space-y-6">
      <div className="overflow-x-auto">
        <TabBar tabs={SOTTO_AREE} active={sotto} onChange={setSotto} />
      </div>

      {sotto === 'risultati' && (
        loading || (!s && !error) ? <LoadingState label="Calcolo statistiche..." />
          : error ? <ErrorState message={error} onRetry={refetch} />
          : <RisultatiClassifica s={s} champ={champ} kpi={champ ? null : d.kpi} />
      )}

      {sotto === 'ruoli' && (
        <AreaRuoli stagione={stagione} idSquadra={idSquadra} competizione={competizione} />
      )}

      {sotto === 'topflop' && (
        <TopFlopPropri
          top={champ ? d.champions.top11 : d.campionato.top11}
          flop={champ ? d.champions.flop11 : d.campionato.flop11}
        />
      )}
    </div>
  )
}
