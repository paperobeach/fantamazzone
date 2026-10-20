import { useMemo } from 'react'
import { Calendar, Users, Home, Plane, ListChecks } from 'lucide-react'
import { RoleBadge } from '../ui'
import { Bandiera } from '../Bandiera'
import { Section, Vuoto, Indicatore, BarraPerc, parseDataOra } from './shared'

const RUOLI = { 1: 'Portieri', 2: 'Difensori', 3: 'Centrocampisti', 4: 'Attaccanti' }

function fmtPartita(data, ora) {
  const d = parseDataOra(`${data} ${ora ?? '00:00:00'}`)
  if (!d) return ''
  const g = d.toLocaleDateString('it-IT', { weekday: 'short', day: '2-digit', month: 'short' })
  return ora ? `${g} · ${d.toLocaleTimeString('it-IT', { hour: '2-digit', minute: '2-digit' })}` : `${g} · orario da definire`
}

function Chips({ giocatori, allinea }) {
  if (!giocatori.length) return <p className={`text-xs text-slate-700 ${allinea}`}>—</p>
  return (
    <div className={`flex flex-wrap gap-1 ${allinea === 'text-right' ? 'justify-end' : ''}`}>
      {giocatori.map(g => (
        <span key={g.id_giocatore} className="inline-flex items-center gap-1 px-1.5 py-0.5 rounded-md bg-grass-500/10 border border-grass-500/20 text-[11px] text-grass-300">
          <RoleBadge ruolo={g.ruolo} /><Bandiera id={g.id_giocatore} /> {g.nome}
        </span>
      ))}
    </div>
  )
}

export default function PreparaSerieA({ rosa, incontri, giornataSerieA }) {
  const conPartita = rosa.filter(g => g.avversaria)
  const inCasa = conPartita.filter(g => g.casa).length
  const inTrasf = conPartita.length - inCasa
  const senza = rosa.length - conPartita.length

  // raggruppamento per squadra avversaria
  const perAvversaria = useMemo(() => {
    const m = new Map()
    for (const g of conPartita) {
      if (!m.has(g.avversaria)) m.set(g.avversaria, { avversaria: g.avversaria, casa: g.casa, giocatori: [] })
      m.get(g.avversaria).giocatori.push(g)
    }
    return [...m.values()].sort((a, b) => b.giocatori.length - a.giocatori.length || a.avversaria.localeCompare(b.avversaria))
  }, [conPartita])

  // per ruolo: giocatori ordinati
  const perRuolo = useMemo(() => [1, 2, 3, 4].map(r => ({ ruolo: r, lista: rosa.filter(g => g.ruolo === r) })), [rosa])

  if (!rosa.length) return <Vuoto>Nessun giocatore in rosa.</Vuoto>

  return (
    <div className="space-y-6">
      {/* Sintesi casa / trasferta */}
      <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
        <Indicatore label="Rosa" value={rosa.length} />
        <Indicatore label="Squadra di Serie A in casa" value={inCasa} tono="text-grass-400" />
        <Indicatore label="Squadra di Serie A in trasferta" value={inTrasf} tono="text-sky-400" />
        <Indicatore label="Senza partita" value={senza} sub="riposo o squadra non determinata" />
      </div>

      {/* Incontri */}
      <Section title="Incontri di Serie A" icon={Calendar} right={giornataSerieA ? `${giornataSerieA}ª giornata` : undefined}>
        {incontri.length === 0 ? <Vuoto>Calendario Serie A non disponibile per questa giornata.</Vuoto> : (
          incontri.map(p => (
            <div key={p.n_partita} className="px-4 py-3 border-b border-white/[0.03] last:border-0">
              <p className="text-[10px] font-mono text-slate-600 mb-1.5">{fmtPartita(p.data, p.ora)}</p>
              <div className="grid grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)] gap-3 items-start">
                <div className="min-w-0">
                  <p className="text-sm font-semibold text-slate-200 truncate">{p.casa}</p>
                  <Chips giocatori={p.giocatori_casa} allinea="text-left" />
                </div>
                <span className="text-xs text-slate-600 font-mono pt-0.5">vs</span>
                <div className="min-w-0 text-right">
                  <p className="text-sm font-semibold text-slate-200 truncate">{p.ospite}</p>
                  <Chips giocatori={p.giocatori_ospite} allinea="text-right" />
                </div>
              </div>
            </div>
          ))
        )}
      </Section>

      {/* Distribuzione per avversaria */}
      <Section title="Giocatori per squadra avversaria" icon={Users} right="casa / trasferta della squadra di Serie A">
        {perAvversaria.length === 0 ? <Vuoto>Nessun tuo giocatore ha una partita in questa giornata.</Vuoto> : (
          <div className="p-4 space-y-4">
            {perAvversaria.map(a => (
              <div key={a.avversaria}>
                <BarraPerc
                  label={a.avversaria.length > 10 ? `${a.avversaria.slice(0, 9)}…` : a.avversaria}
                  valore={a.giocatori.length} totale={conPartita.length}
                  colore={a.casa ? 'bg-grass-500' : 'bg-sky-500'}
                  dx={`${a.giocatori.length}`}
                />
                <p className="text-[11px] text-slate-600 mt-1 pl-[5.75rem] flex items-center gap-1.5 flex-wrap">
                  {a.casa
                    ? <><Home className="w-3 h-3" /> loro in casa</>
                    : <><Plane className="w-3 h-3" /> loro in trasferta</>}
                  <span className="inline-flex items-center gap-x-2 gap-y-0.5 flex-wrap">
                    <span>·</span>
                    {a.giocatori.map((g, i) => (
                      <span key={g.id_giocatore ?? i} className="inline-flex items-center gap-1">
                        <Bandiera id={g.id_giocatore} size="xs" />{g.nome}{i < a.giocatori.length - 1 ? ',' : ''}
                      </span>
                    ))}
                  </span>
                </p>
              </div>
            ))}
          </div>
        )}
      </Section>

      {/* Avversaria di ciascun giocatore */}
      <Section title="Avversaria di ogni giocatore" icon={ListChecks}>
        {perRuolo.map(({ ruolo, lista }) => lista.length > 0 && (
          <div key={ruolo}>
            <p className="px-4 py-1.5 text-[10px] uppercase tracking-widest text-slate-600 bg-white/[0.02]">{RUOLI[ruolo]}</p>
            {lista.map(g => (
              <div key={g.id_giocatore} className="flex items-center gap-3 px-4 py-2 border-b border-white/[0.03] last:border-0">
                <RoleBadge ruolo={g.ruolo} />
                <div className="flex-1 min-w-0">
                  <p className="text-sm text-slate-300 flex items-center gap-1.5 min-w-0">
                    <Bandiera id={g.id_giocatore} />
                    <span className="truncate">{g.nome}</span>
                  </p>
                  <p className="text-[11px] text-slate-600 truncate">{g.serie_a ?? 'Squadra non determinata'}</p>
                </div>
                {g.avversaria ? (
                  <div className="text-right flex-shrink-0">
                    <p className="text-sm text-slate-300 flex items-center gap-1.5 justify-end">
                      {g.casa ? <Home className="w-3 h-3 text-grass-400" aria-label="In casa" />
                              : <Plane className="w-3 h-3 text-sky-400" aria-label="In trasferta" />}
                      {g.avversaria}
                    </p>
                    <p className="text-[10px] font-mono text-slate-600">{fmtPartita(g.data, g.ora)}</p>
                  </div>
                ) : (
                  <span className="text-xs text-slate-600 flex-shrink-0">nessuna partita</span>
                )}
              </div>
            ))}
          </div>
        ))}
      </Section>
    </div>
  )
}
