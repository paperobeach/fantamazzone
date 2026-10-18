import {
  Trophy, Crown, ClipboardList, Swords, Home, Plane,
} from 'lucide-react'
import { EmptyState, RoleBadge } from '../ui'
import TeamLogo from '../TeamLogo'
import { PitchLineup } from '../PitchLineup'
import { Section, Vuoto, RigaRisultato, EsitoIcon, CoachPhoto, fmt } from './shared'

// ── Formazioni (elenco) ──────────────────────────────────────

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

// ── Area "Prepara la prossima partita" ───────────────────────

function PrepaChampions({ d }) {
  const app = d.appuntamenti.champions
  if (!app) return <EmptyState label="Nessun match di Champions in programma." />
  const a = app.avversario
  return (
    <div className="space-y-6">
      <div className="card p-5">
        <p className="text-xs font-mono tracking-widest uppercase text-slate-600 mb-3 flex items-center gap-2">
          <Crown className="w-3.5 h-3.5 text-sky-400" /> Giornata {app.giornata}{app.turno ? ` · ${app.turno}` : ''} ·
          {app.casa ? <><Home className="w-3.5 h-3.5" /> In casa</> : <><Plane className="w-3.5 h-3.5" /> In trasferta</>}
        </p>
        <div className="flex items-center gap-4">
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
      </div>
      <EmptyState label="Analisi dell'avversario di Champions e formazioni a confronto: in arrivo nella prossima fase." />
    </div>
  )
}

function PrepaCampionato({ d }) {
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

export default function AreaPrepara({ d, competizione }) {
  return competizione === 'CHAMP' ? <PrepaChampions d={d} /> : <PrepaCampionato d={d} />
}
