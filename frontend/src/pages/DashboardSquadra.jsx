import { useState } from 'react'
import { Link } from 'react-router-dom'
import {
  ResponsiveContainer, LineChart, Line, XAxis, YAxis, CartesianGrid, Tooltip,
} from 'recharts'
import {
  Trophy, Crown, Medal, ShieldX, CheckCircle2, MinusCircle, XCircle, User,
  ClipboardList, CalendarClock, Home, Plane, Swords, Flame, TrendingUp, Star, ThumbsDown,
} from 'lucide-react'
import { useApp } from '../context/AppContext'
import { useFetch } from '../hooks/useFetch'
import { getDashboardSquadra } from '../api/client'
import {
  PageHeader, LoadingState, ErrorState, EmptyState, RoleBadge, StatCard, TabBar,
} from '../components/ui'
import TeamLogo from '../components/TeamLogo'
import { PitchLineup } from '../components/PitchLineup'

// ── Utilità ──────────────────────────────────────────────────

const fmt = (n, d = 1) => (n === null || n === undefined || Number.isNaN(Number(n)) ? '–' : Number(n).toFixed(d))

function fmtInizio(s) {
  if (!s) return null
  const d = new Date(s.replace(' ', 'T'))
  if (Number.isNaN(d.getTime())) return null
  return d.toLocaleString('it-IT', { weekday: 'short', day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' })
}

const SEGNO = {
  V: { Icon: CheckCircle2, cls: 'text-green-400', bg: 'bg-green-500/10 border-green-500/20', label: 'Vittoria' },
  N: { Icon: MinusCircle,  cls: 'text-yellow-400', bg: 'bg-yellow-500/10 border-yellow-500/20', label: 'Pareggio' },
  P: { Icon: XCircle,      cls: 'text-red-400',   bg: 'bg-red-500/10 border-red-500/20', label: 'Sconfitta' },
}

function EsitoIcon({ segno, className = 'w-5 h-5' }) {
  const s = SEGNO[segno]
  if (!s) return null
  return <s.Icon className={`${className} ${s.cls} flex-shrink-0`} aria-label={s.label} />
}

// Contenitore di sezione con intestazione
function Section({ title, icon: Icon, right, children, className = '' }) {
  return (
    <div className={`card overflow-hidden ${className}`}>
      <div className="px-4 py-3 border-b border-white/5 flex items-center gap-2">
        {Icon && <Icon className="w-4 h-4 text-grass-400 flex-shrink-0" />}
        <h2 className="font-semibold text-slate-200 text-sm">{title}</h2>
        {right && <div className="ml-auto text-xs text-slate-500 font-mono">{right}</div>}
      </div>
      {children}
    </div>
  )
}

function Vuoto({ children }) {
  return <p className="px-4 py-6 text-sm text-slate-600 text-center">{children}</p>
}

// ── Foto allenatore ──────────────────────────────────────────

function CoachPhoto({ logo, nome, className = 'w-16 h-16' }) {
  const [failed, setFailed] = useState(false)
  if (!logo || failed) {
    return (
      <div className={`${className} rounded-full bg-pitch-800 border border-white/10 flex items-center justify-center flex-shrink-0`}>
        <User className="w-1/2 h-1/2 text-slate-500" />
      </div>
    )
  }
  return (
    <img
      src={`${import.meta.env.BASE_URL}allenatori/${logo}`}
      alt={nome ?? 'Allenatore'}
      loading="lazy"
      onError={() => setFailed(true)}
      className={`${className} rounded-full object-cover border border-white/10 flex-shrink-0`}
    />
  )
}

// ── Iconografia albo d'oro ───────────────────────────────────

function AlboItem({ icon: Icon, colore, valore, label, stagioni }) {
  const attivo = valore > 0
  return (
    <div
      title={stagioni?.length ? `${label}: ${stagioni.join(', ')}` : label}
      className={`flex items-center gap-2 px-3 py-2 rounded-xl border ${
        attivo ? 'bg-white/[0.03] border-white/10' : 'border-white/5 opacity-40'
      }`}
    >
      <Icon className={`w-5 h-5 ${attivo ? colore : 'text-slate-600'}`} />
      <div className="leading-none">
        <p className="text-display text-xl font-bold text-white">{valore}</p>
        <p className="text-[10px] uppercase tracking-wider text-slate-500 mt-0.5">{label}</p>
      </div>
    </div>
  )
}

function AlboOro({ albo }) {
  return (
    <div className="flex flex-wrap gap-2">
      <AlboItem icon={Trophy} colore="text-gold-400"  valore={albo.campionato.titoli}      label="Campionati"       stagioni={albo.campionato.stagioni} />
      <AlboItem icon={Crown}  colore="text-sky-400"   valore={albo.champions.titoli}       label="Champions"        stagioni={albo.champions.stagioni} />
      <AlboItem icon={Medal}  colore="text-slate-300" valore={albo.secondi_posti.totale}   label="Secondi posti"    stagioni={albo.secondi_posti.stagioni} />
      <AlboItem icon={ShieldX} colore="text-red-400"  valore={albo.finali_perse.totale}    label="Finali perse"     stagioni={albo.finali_perse.stagioni} />
    </div>
  )
}

// ── Righe risultato ──────────────────────────────────────────

function RigaRisultato({ p, mostraTurno = false }) {
  const s = SEGNO[p.segno]
  return (
    <div className="flex items-center gap-3 px-4 py-2.5 border-b border-white/[0.03] last:border-0">
      <EsitoIcon segno={p.segno} />
      <span className="text-xs font-mono text-slate-600 w-8 flex-shrink-0">G{p.giornata}</span>
      {p.casa !== null && p.casa !== undefined && (
        p.casa
          ? <Home  className="w-3.5 h-3.5 text-slate-600 flex-shrink-0" aria-label="In casa" />
          : <Plane className="w-3.5 h-3.5 text-slate-600 flex-shrink-0" aria-label="In trasferta" />
      )}
      <TeamLogo logo={p.logo_avv} nome={p.avversario} size="sm" />
      <span className="flex-1 min-w-0 text-sm text-slate-300 truncate">
        {p.avversario}
        {mostraTurno && p.turno && <span className="text-xs text-slate-600"> · {p.turno}</span>}
      </span>
      <div className="text-right flex-shrink-0">
        <p className={`font-mono font-bold text-sm ${s?.cls ?? 'text-slate-300'}`}>{p.golf} - {p.gols}</p>
        <p className="text-[10px] font-mono text-slate-600">{fmt(p.ftotale)} / {fmt(p.ftotale_a)}</p>
      </div>
    </div>
  )
}

// ── Serie ────────────────────────────────────────────────────

const SERIE_DEF = [
  { key: 'vittorie',       label: 'Vittorie consecutive',  positiva: true  },
  { key: 'imbattibilita',  label: 'Partite senza sconfitte', positiva: true  },
  { key: 'sconfitte',      label: 'Sconfitte consecutive', positiva: false },
  { key: 'senza_vittorie', label: 'Partite senza vittorie', positiva: false },
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

// ── Andamento posizione ──────────────────────────────────────

function AndamentoChart({ andamento, nSquadre }) {
  if (!andamento?.length) return <Vuoto>Nessuna giornata chiusa.</Vuoto>
  const n = Math.max(nSquadre || 8, ...andamento.map(a => a.posizione))
  const ticks = Array.from({ length: n }, (_, i) => i + 1)
  return (
    <div className="p-4 h-64">
      <ResponsiveContainer width="100%" height="100%">
        <LineChart data={andamento} margin={{ top: 8, right: 12, bottom: 0, left: -16 }}>
          <CartesianGrid stroke="#ffffff10" vertical={false} />
          <XAxis dataKey="giornata" stroke="#64748b" tick={{ fontSize: 11 }} tickFormatter={g => `G${g}`} />
          <YAxis reversed domain={[1, n]} ticks={ticks} allowDecimals={false} stroke="#64748b" tick={{ fontSize: 11 }} tickFormatter={p => `${p}°`} />
          <Tooltip
            contentStyle={{ background: '#0f1f2e', border: '1px solid #ffffff1a', borderRadius: 8, fontSize: 12 }}
            labelFormatter={g => `Giornata ${g}`}
            formatter={(v, name) => [name === 'posizione' ? `${v}°` : v, name === 'posizione' ? 'Posizione' : name === 'punti' ? 'Punti' : 'Fantapunti']}
          />
          <Line type="monotone" dataKey="posizione" stroke="#4ade80" strokeWidth={2.5} dot={{ r: 3, fill: '#4ade80' }} activeDot={{ r: 5 }} />
        </LineChart>
      </ResponsiveContainer>
    </div>
  )
}

// ── Matrice vs le altre squadre ──────────────────────────────

function MatriceScontri({ matrice, vuota }) {
  if (!matrice?.length) return <Vuoto>Nessuna squadra avversaria.</Vuoto>
  return (
    <div className="p-4 grid grid-cols-2 sm:grid-cols-4 gap-3">
      {matrice.map(m => {
        const v = m.incontri.filter(i => i.segno === 'V').length
        const n = m.incontri.filter(i => i.segno === 'N').length
        const p = m.incontri.filter(i => i.segno === 'P').length
        const saldo = v - p
        const bordo = m.incontri.length === 0 ? 'border-white/5'
          : saldo > 0 ? 'border-green-500/30' : saldo < 0 ? 'border-red-500/30' : 'border-yellow-500/30'
        return (
          <div key={m.id} className={`rounded-xl border ${bordo} bg-pitch-900 p-3 flex flex-col gap-2`}>
            <div className="flex items-center gap-2 min-w-0">
              <TeamLogo logo={m.logo} nome={m.nome} size="sm" />
              <span className="text-xs text-slate-300 font-medium truncate">{m.nome}</span>
            </div>
            {m.incontri.length === 0 ? (
              <p className="text-xs text-slate-600">{vuota}</p>
            ) : (
              <>
                <div className="flex items-center gap-2 text-xs font-mono">
                  <span className="text-green-400">{v}V</span>
                  <span className="text-yellow-400">{n}N</span>
                  <span className="text-red-400">{p}P</span>
                </div>
                <div className="flex flex-wrap gap-1">
                  {m.incontri.map((i, k) => (
                    <span
                      key={k}
                      title={`Giornata ${i.giornata}${i.turno ? ` · ${i.turno}` : ''}`}
                      className={`inline-flex items-center gap-1 px-1.5 py-0.5 rounded border text-[11px] font-mono ${SEGNO[i.segno]?.bg ?? ''} ${SEGNO[i.segno]?.cls ?? ''}`}
                    >
                      {i.segno} {i.golf}-{i.gols}
                    </span>
                  ))}
                </div>
              </>
            )}
          </div>
        )
      })}
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

// ── Formazioni ───────────────────────────────────────────────

function Lineup({ giocatori, conVoti = false }) {
  const tit = giocatori.filter(g => g.titolare)
  const pan = giocatori.filter(g => !g.titolare)
  const riga = g => (
    <div key={g.id_giocatore} className="flex items-center gap-3 px-4 py-2 border-b border-white/[0.03] last:border-0">
      <RoleBadge ruolo={g.ruolo} />
      <span className={`flex-1 text-sm truncate ${g.titolare ? 'text-slate-300' : 'text-slate-500'}`}>{g.giocatore}</span>
      {conVoti && (
        <span className="text-right flex-shrink-0">
          {g.giocata === false || g.voto === null || g.voto === undefined
            ? <span className="text-xs text-slate-600 font-mono">s.v.</span>
            : <>
                <span className="text-xs text-slate-600 font-mono mr-2">{fmt(g.voto)}</span>
                <span className="text-sm font-mono font-bold text-slate-200">{fmt(g.totale)}</span>
              </>}
        </span>
      )}
    </div>
  )
  return (
    <div>
      {tit.map(riga)}
      {pan.length > 0 && (
        <>
          <p className="px-4 py-1.5 text-[10px] uppercase tracking-widest text-slate-600 bg-white/[0.02]">Panchina</p>
          {pan.map(riga)}
        </>
      )}
    </div>
  )
}

// ── Storico scontri ──────────────────────────────────────────

function StoricoScontri({ dati, titolo, icona }) {
  const r = dati?.riepilogo
  return (
    <Section title={titolo} icon={icona} right={r ? `${r.giocate} incontri` : undefined}>
      {!r || r.giocate === 0 ? (
        <Vuoto>Nessun precedente.</Vuoto>
      ) : (
        <>
          <div className="grid grid-cols-3 gap-px bg-white/5">
            {[['Vinte', r.vinte, 'text-green-400'], ['Pari', r.nulle, 'text-yellow-400'], ['Perse', r.perse, 'text-red-400']].map(([l, v, c]) => (
              <div key={l} className="bg-pitch-900 py-3 text-center">
                <p className={`text-display text-2xl font-bold ${c}`}>{v}</p>
                <p className="text-[10px] uppercase tracking-wider text-slate-600">{l}</p>
              </div>
            ))}
          </div>
          <p className="px-4 py-2 text-xs text-slate-500 font-mono border-b border-white/5">
            Gol {r.golf}-{r.gols} · Fantapunti medi {fmt(r.media_fp)} vs {fmt(r.media_fp_avv)}
          </p>
          <div className="max-h-72 overflow-y-auto">
            {dati.partite.map((p, i) => (
              <div key={i} className="flex items-center gap-3 px-4 py-2 border-b border-white/[0.03] last:border-0">
                <EsitoIcon segno={p.segno} className="w-4 h-4" />
                <span className="text-xs font-mono text-slate-600 w-20 flex-shrink-0">{p.stagione} · G{p.giornata}</span>
                {p.turno && <span className="text-xs text-slate-600 truncate">{p.turno}</span>}
                <span className="ml-auto font-mono text-sm font-bold text-slate-300">{p.golf} - {p.gols}</span>
                <span className="text-[10px] font-mono text-slate-600 w-20 text-right">{fmt(p.ftotale)} / {fmt(p.ftotale_a)}</span>
              </div>
            ))}
          </div>
        </>
      )}
    </Section>
  )
}

// ── Aree ─────────────────────────────────────────────────────

function AreaCampionato({ d, nSquadre }) {
  const c = d.campionato
  return (
    <div className="space-y-6">
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <Section title="Ultimi 5 risultati" icon={ClipboardList} right="punteggio · fantapunti">
          {c.ultime5.length === 0 ? <Vuoto>Nessuna giornata ancora giocata.</Vuoto>
            : c.ultime5.map(p => <RigaRisultato key={p.giornata} p={p} />)}
        </Section>
        <Section title="Serie" icon={Flame}>
          <SerieTable serie={c.serie} />
        </Section>
      </div>

      <Section title="Andamento in classifica" icon={TrendingUp} right="posizione per giornata">
        <AndamentoChart andamento={c.andamento} nSquadre={nSquadre} />
      </Section>

      <Section title="Scontri con le altre squadre" icon={Swords} right="stagione in corso">
        <MatriceScontri matrice={c.matrice} vuota="Non ancora giocato" />
      </Section>

      <TopFlopPropri top={c.top11} flop={c.flop11} />
    </div>
  )
}

function AreaChampions({ d }) {
  const ch = d.champions
  const u = ch.ultimo
  return (
    <div className="space-y-6">
      <Section title="Ultimo risultato in Champions" icon={Crown} right={u?.turno || undefined}>
        {u ? <RigaRisultato p={u} mostraTurno /> : <Vuoto>Nessuna partita di Champions ancora giocata.</Vuoto>}
      </Section>

      <Section title="Scontri Champions con le altre squadre" icon={Swords} right="stagione in corso">
        <MatriceScontri matrice={ch.matrice} vuota="Mai affrontata" />
      </Section>

      <TopFlopPropri top={ch.top11} flop={ch.flop11} />
    </div>
  )
}

function AreaProssima({ d }) {
  const pr = d.prossima
  if (!pr) {
    return <EmptyState label={d.formazione.stagione_conclusa
      ? 'Campionato concluso: nessun prossimo incontro.'
      : 'Prossimo incontro non disponibile.'} />
  }
  const a = pr.avversario
  const cl = a.classifica
  const fm = pr.formazione_scontro
  const uf = pr.ultima_formazione_avversario

  return (
    <div className="space-y-6">
      {/* Scheda avversario */}
      <div className="card p-5">
        <p className="text-xs font-mono tracking-widest uppercase text-slate-600 mb-3 flex items-center gap-2">
          Giornata {pr.giornata} ·
          {pr.casa ? <><Home className="w-3.5 h-3.5" /> In casa</> : <><Plane className="w-3.5 h-3.5" /> In trasferta</>}
        </p>
        <div className="flex flex-col sm:flex-row sm:items-center gap-4">
          <div className="flex items-center gap-4 min-w-0">
            <TeamLogo logo={a.logo} nome={a.nome} size="lg" />
            <div className="min-w-0">
              <h3 className="text-display text-2xl font-bold text-white truncate">{a.nome}</h3>
              {a.allenatore && (
                <div className="flex items-center gap-2 mt-1">
                  <CoachPhoto logo={a.logo_allenatore} nome={a.allenatore} className="w-7 h-7" />
                  <span className="text-sm text-slate-500 truncate">{a.allenatore}</span>
                </div>
              )}
            </div>
          </div>
          {cl && (
            <div className="sm:ml-auto grid grid-cols-4 gap-4 text-center">
              {[
                ['Pos.', `${cl.posizione}°`],
                ['Punti', cl.punti],
                ['V-N-P', `${cl.vinte}-${cl.nulle}-${cl.perse}`],
                ['Media', fmt(cl.media, 2)],
              ].map(([l, v]) => (
                <div key={l}>
                  <p className="text-display text-xl font-bold text-white">{v}</p>
                  <p className="text-[10px] uppercase tracking-wider text-slate-600">{l}</p>
                </div>
              ))}
            </div>
          )}
        </div>
        {cl && (
          <p className="text-xs text-slate-600 font-mono mt-3">
            Gol {cl.golf}-{cl.gols} · Miglior fantapunteggio {fmt(cl.migliore)} · Peggiore {fmt(cl.peggiore)}
          </p>
        )}
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <Section title={`Ultime 5 di ${a.nome}`} icon={ClipboardList} right="punteggio · fantapunti">
          {a.ultime5.length === 0 ? <Vuoto>Nessuna partita giocata.</Vuoto>
            : a.ultime5.map(p => <RigaRisultato key={p.giornata} p={p} />)}
        </Section>

        <Section
          title="Ultima formazione schierata"
          icon={ClipboardList}
          right={uf ? `G${uf.giornata} vs ${uf.partita.avversario} ${uf.partita.golf}-${uf.partita.gols}` : undefined}
        >
          {uf && uf.giocatori.length > 0
            ? <Lineup giocatori={uf.giocatori} conVoti />
            : <Vuoto>Nessuna formazione disponibile.</Vuoto>}
        </Section>
      </div>

      {/* Formazioni dello scontro diretto */}
      <Section title="Formazioni per lo scontro diretto" icon={Swords} right={`Giornata ${pr.giornata}`}>
        {!fm.mia && !fm.avversario ? (
          <Vuoto>Nessuna formazione ancora inserita per questa partita.</Vuoto>
        ) : (
          <div className="grid grid-cols-1 md:grid-cols-2 gap-6 p-4">
            {[
              { nome: d.squadra.nome, logo: d.squadra.logo, lista: fm.mia },
              { nome: a.nome,         logo: a.logo,         lista: fm.avversario },
            ].map(({ nome, logo, lista }) => (
              <div key={nome}>
                <div className="flex items-center gap-2 mb-3">
                  <TeamLogo logo={logo} nome={nome} size="sm" />
                  <p className="text-sm font-semibold text-slate-300 truncate">{nome}</p>
                </div>
                {lista
                  ? <PitchLineup players={lista} />
                  : <div className="rounded-xl border border-dashed border-white/10 aspect-[3/4] flex items-center justify-center">
                      <p className="text-sm text-slate-600">Formazione non inserita</p>
                    </div>}
              </div>
            ))}
          </div>
        )}
      </Section>

      {/* Storico scontri, tutte le stagioni */}
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <StoricoScontri dati={pr.storico.campionato} titolo="Storico in campionato" icona={Trophy} />
        <StoricoScontri dati={pr.storico.champions}  titolo="Storico in Champions"  icona={Crown} />
      </div>
    </div>
  )
}

// ── Pagina ───────────────────────────────────────────────────

const AREE = [
  { value: 'campionato', label: 'Campionato' },
  { value: 'champions',  label: 'Champions' },
  { value: 'prossima',   label: 'Prossima sfida' },
]

export default function DashboardSquadra() {
  const { utente, ultimaStagione } = useApp()
  const idSquadra = utente?.id ?? null
  const [area, setArea] = useState('campionato')

  const { data: d, loading, error, refetch } = useFetch(
    ultimaStagione && idSquadra ? () => getDashboardSquadra(ultimaStagione, idSquadra) : null,
    [ultimaStagione, idSquadra]
  )

  if (loading || (!d && !error)) return <LoadingState label="Caricamento dashboard..." />
  if (error) return <ErrorState message={error} onRetry={refetch} />

  const f = d.formazione
  const inizio = fmtInizio(f.inizio_giornata)
  const cl = d.classifica

  return (
    <div className="animate-fade-up">
      <PageHeader
        label="Gestione squadra"
        title="Dashboard squadra"
        subtitle={`Stagione ${d.stagione}`}
      />

      {/* ── Intestazione: identità, albo d'oro, formazione ── */}
      <div className="card p-5 mb-6">
        <div className="flex flex-col lg:flex-row gap-6 lg:items-center">
          <div className="flex items-center gap-4 min-w-0">
            <TeamLogo logo={d.squadra.logo} nome={d.squadra.nome} size="lg" />
            <div className="min-w-0">
              <h2 className="text-display text-3xl font-bold text-white truncate">{d.squadra.nome}</h2>
              <div className="flex items-center gap-3 mt-2">
                <CoachPhoto logo={d.squadra.logo_allenatore} nome={d.squadra.allenatore} className="w-14 h-14" />
                <div className="min-w-0">
                  <p className="text-[10px] uppercase tracking-widest text-slate-600">Allenatore</p>
                  <p className="text-sm text-slate-300 truncate">{d.squadra.allenatore || '—'}</p>
                  {cl && (
                    <p className="text-xs text-slate-500 font-mono mt-0.5">
                      {cl.posizione}° su {cl.squadre} · {cl.punti} pt · {cl.vinte}V {cl.nulle}N {cl.perse}P
                    </p>
                  )}
                </div>
              </div>
            </div>
          </div>

          <div className="lg:ml-auto flex flex-col gap-3 lg:items-end">
            <AlboOro albo={d.albo} />
            {!d.albo.disponibile && (
              <p className="text-[10px] text-slate-600">Albo d'oro non ancora disponibile.</p>
            )}
          </div>
        </div>

        {/* Link inserimento formazione */}
        <div className={`mt-5 flex flex-col sm:flex-row sm:items-center gap-3 rounded-xl border px-4 py-3 ${
          f.stagione_conclusa
            ? 'border-white/5 bg-white/[0.02]'
            : f.inserita ? 'border-green-500/20 bg-green-500/5' : 'border-gold-500/30 bg-gold-500/5'
        }`}>
          <CalendarClock className={`w-5 h-5 flex-shrink-0 ${
            f.stagione_conclusa ? 'text-slate-600' : f.inserita ? 'text-green-400' : 'text-gold-400'
          }`} />
          <div className="flex-1 min-w-0">
            {f.stagione_conclusa ? (
              <p className="text-sm text-slate-400">Campionato concluso.</p>
            ) : (
              <>
                <p className="text-sm text-slate-200">
                  Formazione giornata {f.giornata}:{' '}
                  <span className={f.inserita ? 'text-green-400' : 'text-gold-400'}>
                    {f.inserita ? 'inserita' : f.titolari > 0 ? `incompleta (${f.titolari}/11 titolari)` : 'non ancora inserita'}
                  </span>
                </p>
                {inizio && (
                  <p className="text-xs text-slate-500">
                    Prima partita della {f.giornata_serie_a}ª giornata di Serie A: {inizio}
                  </p>
                )}
              </>
            )}
          </div>
          {!f.stagione_conclusa && (
            <Link to="/formazione" className="btn-primary text-sm justify-center">
              <ClipboardList className="w-4 h-4" /> {f.inserita ? 'Modifica formazione' : 'Inserisci formazione'}
            </Link>
          )}
        </div>
      </div>

      {/* ── KPI ── */}
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
        <StatCard label="Miglior fantapunteggio" value={fmt(d.kpi.migliore)} accent />
        <StatCard label="Peggior fantapunteggio" value={fmt(d.kpi.peggiore)} />
        <StatCard label="Media" value={fmt(d.kpi.media, 2)} />
        <StatCard label="Media avversari" value={fmt(d.kpi.media_avversari, 2)} sub="fantapunti subiti" />
      </div>

      {/* ── Aree tematiche ── */}
      <div className="mb-6 overflow-x-auto">
        <TabBar tabs={AREE} active={area} onChange={setArea} />
      </div>

      {area === 'campionato' && <AreaCampionato d={d} nSquadre={cl?.squadre} />}
      {area === 'champions'  && <AreaChampions d={d} />}
      {area === 'prossima'   && <AreaProssima d={d} />}
    </div>
  )
}
