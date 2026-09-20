import { RoleBadge } from './ui'

// ── Voto colorato (verde/bianco/giallo/rosso in base al valore) ──
export function VotoBox({ voto, totale, giocata }) {
  if (!giocata || !voto) return <span className="text-slate-700 font-mono text-xs">sv</span>
  const v = Number(totale)
  return (
    <span className={`font-mono text-xs font-semibold ${
      v >= 7.5 ? 'text-green-400' :
      v >= 6   ? 'text-slate-300' :
      v >= 5   ? 'text-yellow-400' :
                 'text-red-400'
    }`}>{v.toFixed(2)}</span>
  )
}

// ── Tabella voti giocatori di una squadra ──
export function GiocatoriTable({ giocatori }) {
  return (
    <div className="overflow-x-auto">
    <table className="w-full text-xs min-w-[560px]">
      <thead>
        <tr className="border-b border-white/5">
          <th className="px-3 py-2 text-left font-mono tracking-widest uppercase text-slate-700 font-normal">R</th>
          <th className="px-3 py-2 text-left font-mono tracking-widest uppercase text-slate-700 font-normal">Giocatore</th>
          <th className="px-3 py-2 text-center font-mono tracking-widest uppercase text-slate-700 font-normal">Voto</th>
          <th className="px-3 py-2 text-center font-mono tracking-widest uppercase text-slate-700 font-normal">Tot</th>
          <th className="px-3 py-2 text-center font-mono tracking-widest uppercase text-slate-700 font-normal">⚽</th>
          <th className="px-3 py-2 text-center font-mono tracking-widest uppercase text-slate-700 font-normal" title="Assist">🅰️</th>
          <th className="px-3 py-2 text-center font-mono tracking-widest uppercase text-slate-700 font-normal" title="Gol subiti (portiere)">GS</th>
          <th className="px-3 py-2 text-center font-mono tracking-widest uppercase text-slate-700 font-normal" title="Autogol">AG</th>
          <th className="px-3 py-2 text-center font-mono tracking-widest uppercase text-slate-700 font-normal">🟨</th>
          <th className="px-3 py-2 text-center font-mono tracking-widest uppercase text-slate-700 font-normal">🟥</th>
        </tr>
      </thead>
      <tbody>
        {(giocatori ?? []).map((g, i) => (
          <tr key={i} className="border-b border-white/[0.03] hover:bg-white/[0.02]">
            <td className="px-3 py-2"><RoleBadge ruolo={g.ruolo} /></td>
            <td className="px-3 py-2 text-slate-300 font-medium">{g.giocatore}</td>
            <td className="px-3 py-2 text-center">
              <VotoBox voto={g.voto} totale={g.voto} giocata={g.giocata} />
            </td>
            <td className="px-3 py-2 text-center">
              <VotoBox voto={g.totale} totale={g.totale} giocata={g.giocata} />
            </td>
            <td className="px-3 py-2 text-center text-slate-500">{g.reti > 0 ? g.reti : '—'}</td>
            <td className="px-3 py-2 text-center text-slate-500">{g.assist > 0 ? g.assist : '—'}</td>
            <td className="px-3 py-2 text-center text-slate-500">{g.retis > 0 ? g.retis : '—'}</td>
            <td className="px-3 py-2 text-center text-slate-500">{g.autogol > 0 ? g.autogol : '—'}</td>
            <td className="px-3 py-2 text-center text-slate-500">{g.ammonizioni > 0 ? g.ammonizioni : '—'}</td>
            <td className="px-3 py-2 text-center text-slate-500">{g.espulsioni > 0 ? g.espulsioni : '—'}</td>
          </tr>
        ))}
      </tbody>
    </table>
    </div>
  )
}

// ── Riepilogo modificatori (difesa / centrocampo / attacco) di una squadra ──
function ModBox({ label, value }) {
  const num = value === null || value === undefined ? null : Number(value)
  return (
    <div className="flex flex-col items-center px-2 py-1.5 rounded-lg bg-pitch-800/60 border border-white/5 min-w-[72px]">
      <span className="text-[9px] font-mono tracking-widest uppercase text-slate-600">{label}</span>
      <span className={`font-mono text-xs font-semibold ${
        num === null ? 'text-slate-700' :
        num > 0      ? 'text-green-400' :
        num < 0      ? 'text-red-400'   :
                       'text-slate-400'
      }`}>{num === null ? '—' : (num > 0 ? `+${num}` : num)}</span>
    </div>
  )
}

export function ModificatoriRow({ squadra }) {
  if (!squadra) return null
  return (
    <div className="flex items-center gap-2 px-3 py-2 border-b border-white/5">
      <ModBox label="Difesa"      value={squadra.mod_dif} />
      <ModBox label="Centrocampo" value={squadra.mod_cc} />
      <ModBox label="Attacco"     value={squadra.mod_att} />
    </div>
  )
}

// ── Dettaglio completo di una partita (usato nell'espansione) ──
export function MatchDetailPanel({ casa, ospite, loading, error }) {
  if (loading) {
    return (
      <div className="border-t border-white/5 px-4 py-6 text-center text-xs font-mono text-slate-600 uppercase tracking-widest">
        Caricamento dettagli...
      </div>
    )
  }
  if (error) {
    return (
      <div className="border-t border-white/5 px-4 py-6 text-center text-xs text-red-400">
        {error}
      </div>
    )
  }
  if (!casa || !ospite) {
    return (
      <div className="border-t border-white/5 px-4 py-6 text-center text-xs font-mono text-slate-600 uppercase tracking-widest">
        Dettagli non disponibili
      </div>
    )
  }
  return (
    <div className="border-t border-white/5 grid grid-cols-1 sm:grid-cols-2 divide-y sm:divide-y-0 sm:divide-x divide-white/5">
      <div>
        <div className="px-3 py-2 border-b border-white/5">
          <p className="text-xs font-semibold text-slate-400">{casa.nome}</p>
        </div>
        <ModificatoriRow squadra={casa} />
        <GiocatoriTable giocatori={casa.giocatori} />
      </div>
      <div>
        <div className="px-3 py-2 border-b border-white/5">
          <p className="text-xs font-semibold text-slate-400">{ospite.nome}</p>
        </div>
        <ModificatoriRow squadra={ospite} />
        <GiocatoriTable giocatori={ospite.giocatori} />
      </div>
    </div>
  )
}
