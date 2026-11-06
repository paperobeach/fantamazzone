import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useApp } from '../context/AppContext'
import { useFetch } from '../hooks/useFetch'
import { getAllStarGame, getAllStarGameDettaglio } from '../api/client'
import { PageHeader, LoadingState, ErrorState, EmptyState, RoleBadge } from '../components/ui'
import { MatchResultRow, EsitoExtra } from '../components/MatchResult'
import { MatchDetailPanel } from '../components/MatchDetail'
import TeamLogo from '../components/TeamLogo'
import { CheckCircle2, Circle, Radio, Lock, Send, Sparkles } from 'lucide-react'

const STATO_LABEL = {
  da_giocare: 'Da giocare',
  in_corso: 'In corso (risultati non definitivi)',
  giocata: 'Giocata',
}

// Scheda di una compagine: squadre componenti, stato della formazione e formazione (se visibile)
function SchedaCompagine({ c }) {
  const f = c.formazione
  const titolari = f.righe.filter(r => r.maglia <= 11)
  const panchina = f.righe.filter(r => r.maglia > 11)
  const nomeRig = new Map(f.righe.map(r => [r.id_giocatore, r.giocatore]))

  return (
    <div className="card overflow-hidden">
      <div className="px-4 py-3 border-b border-white/5 flex items-center justify-between gap-2 flex-wrap">
        <h2 className="text-sm font-semibold text-slate-200 flex items-center gap-2">
          <Sparkles className="w-4 h-4 text-gold-400" /> {c.nome}
        </h2>
        {f.inviata ? (
          <span className="flex items-center gap-1.5 text-xs text-grass-400"><Send className="w-3.5 h-3.5" /> Formazione inviata</span>
        ) : f.presente ? (
          <span className="text-xs text-gold-400">Bozza in corso</span>
        ) : (
          <span className="text-xs text-slate-500">Formazione non ancora inserita</span>
        )}
      </div>

      <div className="px-4 py-3 grid grid-cols-1 sm:grid-cols-2 gap-2">
        {c.squadre.map(s => {
          const ok = f.ok.find(o => o.id === s.id)?.ok
          return (
            <div key={s.id} className="flex items-center gap-2 rounded-lg bg-white/[0.03] px-3 py-2">
              <TeamLogo logo={s.logo} nome={s.nome} size="sm" />
              <div className="min-w-0 flex-1">
                <div className="text-sm text-slate-300 truncate">{s.nome}</div>
                {s.allenatore && <div className="text-[11px] text-slate-600 truncate">{s.allenatore}</div>}
              </div>
              {!f.inviata && f.presente && (
                ok
                  ? <span title="Ok registrato" className="text-grass-400"><CheckCircle2 className="w-4 h-4" /></span>
                  : <span title="Ok non ancora dato" className="text-slate-600"><Circle className="w-4 h-4" /></span>
              )}
            </div>
          )
        })}
      </div>

      <div className="px-4 pb-4">
        {!f.presente ? null : !f.visibile ? (
          <p className="flex items-center gap-2 text-xs text-slate-500 border-t border-white/5 pt-3">
            <Lock className="w-3.5 h-3.5" /> La formazione resta riservata fino all'inizio della prima partita di Serie A della giornata.
          </p>
        ) : (
          <div className="border-t border-white/5 pt-3 grid grid-cols-1 gap-3">
            <div>
              <p className="text-[10px] font-mono uppercase tracking-widest text-slate-500 mb-1">Titolari</p>
              <ul className="space-y-1">
                {titolari.map(r => (
                  <li key={r.id_giocatore} className="flex items-center gap-2 text-sm text-slate-300">
                    <span className="w-5 text-right font-mono text-xs text-slate-600">{r.maglia}</span>
                    <RoleBadge ruolo={r.ruolo} /> <span className="truncate">{r.giocatore}</span>
                  </li>
                ))}
              </ul>
            </div>
            {panchina.length > 0 && (
              <div>
                <p className="text-[10px] font-mono uppercase tracking-widest text-slate-500 mb-1">Panchina</p>
                <ul className="space-y-1">
                  {panchina.map(r => (
                    <li key={r.id_giocatore} className="flex items-center gap-2 text-sm text-slate-400">
                      <span className="w-5 text-right font-mono text-xs text-slate-600">{r.maglia}</span>
                      <RoleBadge ruolo={r.ruolo} /> <span className="truncate">{r.giocatore}</span>
                    </li>
                  ))}
                </ul>
              </div>
            )}
            {f.rigoristi.length > 0 && (
              <div>
                <p className="text-[10px] font-mono uppercase tracking-widest text-slate-500 mb-1">Ordine rigoristi</p>
                <p className="text-xs text-slate-400">
                  {f.rigoristi.map((id, i) => `${i + 1}. ${nomeRig.get(id) ?? id}`).join(' · ')}
                </p>
              </div>
            )}
          </div>
        )}
      </div>
    </div>
  )
}

export default function AllStarGame() {
  const { stagione } = useApp()
  const [open, setOpen] = useState(false)

  const { data, loading, error, refetch } = useFetch(
    stagione ? () => getAllStarGame(Number(stagione)) : null, [stagione])

  const { data: det, loading: loadingDet, error: errorDet } = useFetch(
    open && data?.stato === 'giocata' ? () => getAllStarGameDettaglio(Number(stagione)) : null,
    [open, data?.stato, stagione])

  if (loading && !data) return <LoadingState label="Caricamento All Star Game..." />
  if (error) return <ErrorState message={error} onRetry={refetch} />
  if (!data) return null

  if (data.stato === 'non_configurato') {
    return (
      <div className="animate-fade-up">
        <PageHeader label="Generale" title="All Star Game" />
        <EmptyState label="L'All Star Game non è previsto in questa stagione" />
      </div>
    )
  }

  const casa = { id: 901, nome: 'Est', logo: null }
  const ospite = { id: 902, nome: 'Ovest', logo: null }
  const r = data.risultato

  return (
    <div className="animate-fade-up">
      <PageHeader
        label="Generale"
        title="All Star Game"
        subtitle={`Est contro Ovest · Giornata ${data.giornata} · ${STATO_LABEL[data.stato] ?? ''}`}
      >
        {data.stato === 'in_corso' && (
          <Link to="/live" className="btn-primary">
            <Radio className="w-4 h-4" /> Vai al LIVE
          </Link>
        )}
      </PageHeader>

      <p className="text-xs text-slate-500 mb-4 max-w-3xl">
        Le squadre con id dispari formano l'Est, quelle con id pari l'Ovest. La rosa di ciascuna compagine è l'unione
        delle rose delle sue squadre. In caso di parità si giocano supplementari e calci di rigore, come in Champions.
        Ogni fantallenatore deve dare il proprio ok alla formazione prima che uno qualsiasi della compagine possa inviarla.
      </p>

      <div className="card overflow-hidden mb-6">
        <div className="px-4 py-3 border-b border-white/5">
          <h2 className="text-sm font-semibold text-slate-300">Partita</h2>
        </div>
        <MatchResultRow
          casa={casa}
          ospite={ospite}
          risultato={r}
          expandable={!!r}
          open={open}
          onToggle={() => setOpen(o => !o)}
          centroExtra={r ? <EsitoExtra risultato={r} /> : null}
        />
        {!r && (
          <p className="px-4 pb-3 text-center text-[11px] text-slate-600">
            {data.stato === 'giocata' ? 'Risultato non disponibile.' : 'Il risultato sarà disponibile a giornata chiusa.'}
          </p>
        )}
        {r?.passa && (
          <p className="px-4 pb-3 text-center text-xs text-grass-400">
            Vince {r.passa === 'casa' ? 'l\'Est' : 'l\'Ovest'}
          </p>
        )}
        {open && r && (
          loadingDet && !det ? <LoadingState label="Caricamento dettaglio..." /> :
          errorDet ? <ErrorState message={errorDet} /> :
          <MatchDetailPanel casa={det?.casa} ospite={det?.ospite} extra={det?.extra} loading={loadingDet} error={errorDet} />
        )}
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {data.compagini.map(c => <SchedaCompagine key={c.id} c={c} />)}
      </div>
    </div>
  )
}
