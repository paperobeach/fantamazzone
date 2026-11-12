// ============================================================
// Area "Analizza match passati" della Dashboard squadra.
//
//   Selettore      partita da analizzare tra quelle giocate (default: l'ultima)
//   Performance    metriche della giornata vs media della stagione (squadra)
//   Performance avversario   idem per la formazione dell'avversario
//   Scelte         formazione schierata vs migliore possibile, bonus persi, gol
//                  possibili, percentile tra tutte le formazioni possibili e sintesi
//                  delle formazioni vincenti/perdenti rispetto al risultato reale
//   Scelte avversario        bonus persi, percentile e sintesi dell'avversario
//
// Le definizioni (formazioni possibili, fantamedia, percentile) sono in
// backend/lib/AnalisiMatch.php.
// ============================================================
import { useState } from 'react'
import {
  ChevronLeft, ChevronRight, Home, Plane, Gauge, Swords, ListChecks, Crosshair,
  ArrowUp, ArrowDown, Minus, ArrowRight, Sparkles, Info,
} from 'lucide-react'
import { useFetch } from '../../hooks/useFetch'
import { getDashboardAnalisiMatch } from '../../api/client'
import { LoadingState, ErrorState, EmptyState, RoleBadge, Select, Spinner } from '../ui'
import { NomeConBandiera } from '../Bandiera'
import { Section, Vuoto, Indicatore, LogoCompatto, EsitoIcon, SEGNO, fmt } from './shared'

// ── Utilità ──────────────────────────────────────────────────

const ESITO_TESTO = { V: 'vittoria', N: 'pareggio', P: 'sconfitta' }

const fmtSegno = (n, d = 1) => {
  if (n === null || n === undefined || Number.isNaN(Number(n))) return '–'
  const v = Number(n)
  return `${v > 0 ? '+' : ''}${v.toFixed(d)}`
}

const fmtInt = n => (n === null || n === undefined ? '–' : Number(n).toLocaleString('it-IT'))

const etichettaPartita = p =>
  `G${p.giornata} · ${p.casa === false ? '@' : 'vs'} ${p.avversario}${p.turno ? ` (${p.turno})` : ''} · ${p.golf}-${p.gols}`

// ── Selettore della partita ──────────────────────────────────

function SelettoreMatch({ partite, giornata, onChange, caricamento }) {
  const ordinate = [...partite].reverse()               // più recente per prima
  const indice = partite.findIndex(p => p.giornata === giornata)
  const corrente = partite[indice] ?? null
  const ultima = partite[partite.length - 1]?.giornata
  const vai = i => { if (partite[i]) onChange(partite[i].giornata) }

  const btn = 'p-2 rounded-lg border border-white/10 text-slate-400 hover:text-slate-200 hover:border-white/25 disabled:opacity-30 disabled:hover:text-slate-400 disabled:hover:border-white/10 transition-colors'

  return (
    <Section title="Partita da analizzare" icon={Crosshair}
             right={caricamento ? <span className="inline-flex items-center gap-1.5"><Spinner size="sm" /> aggiorno…</span> : `${partite.length} giocate`}>
      <div className="p-4 space-y-3">
        <div className="flex items-center gap-2">
          <button className={btn} onClick={() => vai(indice - 1)} disabled={indice <= 0} aria-label="Partita precedente">
            <ChevronLeft className="w-4 h-4" />
          </button>
          <Select
            className="flex-1 min-w-0"
            value={giornata}
            onChange={v => onChange(Number(v))}
            options={ordinate.map(p => ({
              value: p.giornata,
              label: etichettaPartita(p) + (p.giornata === ultima ? ' · ultima' : ''),
            }))}
          />
          <button className={btn} onClick={() => vai(indice + 1)} disabled={indice < 0 || indice >= partite.length - 1} aria-label="Partita successiva">
            <ChevronRight className="w-4 h-4" />
          </button>
        </div>

        {corrente && (
          <div className={`flex items-center gap-3 rounded-xl border px-3 py-2.5 ${SEGNO[corrente.segno]?.bg ?? 'border-white/5'}`}>
            <EsitoIcon segno={corrente.segno} />
            {corrente.casa !== null && (corrente.casa
              ? <Home className="w-3.5 h-3.5 text-slate-500 flex-shrink-0" aria-label="In casa" />
              : <Plane className="w-3.5 h-3.5 text-slate-500 flex-shrink-0" aria-label="In trasferta" />)}
            <LogoCompatto logo={corrente.logo_avv} nome={corrente.avversario} />
            <div className="flex-1 min-w-0">
              <p className="text-sm text-slate-200 truncate">{corrente.avversario}</p>
              <p className="text-[10px] font-mono text-slate-500">
                Giornata {corrente.giornata}{corrente.turno ? ` · ${corrente.turno}` : ''}
              </p>
            </div>
            <div className="text-right flex-shrink-0">
              <p className={`font-mono font-bold text-lg leading-none ${SEGNO[corrente.segno]?.cls ?? 'text-slate-300'}`}>
                {corrente.golf} - {corrente.gols}
              </p>
              <p className="text-[10px] font-mono text-slate-500 mt-1">{fmt(corrente.ftotale)} / {fmt(corrente.ftotale_a)}</p>
            </div>
          </div>
        )}
      </div>
    </Section>
  )
}

// ── Performance ──────────────────────────────────────────────

const VERDETTO = {
  sopra:    { testo: 'Sopra la media',  Icon: ArrowUp },
  in_linea: { testo: 'In linea',        Icon: Minus },
  sotto:    { testo: 'Sotto la media',  Icon: ArrowDown },
}
const GIUDIZIO_CLS = {
  positivo: 'text-green-400 bg-green-500/10 border-green-500/25',
  negativo: 'text-red-400 bg-red-500/10 border-red-500/25',
  neutro:   'text-slate-400 bg-white/[0.04] border-white/10',
}

function BadgeVerdetto({ verdetto, giudizio, breve = false }) {
  if (!verdetto) return <span className="text-[10px] text-slate-600">n.d.</span>
  const { testo, Icon } = VERDETTO[verdetto]
  return (
    <span className={`inline-flex items-center gap-1 px-2 py-0.5 rounded-full border text-[11px] font-semibold whitespace-nowrap ${GIUDIZIO_CLS[giudizio]}`}>
      <Icon className="w-3 h-3" /> {breve ? testo.replace(' la media', '') : testo}
    </span>
  )
}

function titoloSintesi(perf) {
  const s = perf.sintesi
  const base = {
    sopra: 'Giornata sopra la media',
    in_linea: 'Giornata in linea con la media',
    sotto: 'Giornata sotto la media',
  }[s.verdetto] ?? 'Giornata'
  const tono = s.giudizio === 'positivo' ? 'text-green-400' : s.giudizio === 'negativo' ? 'text-red-400' : 'text-slate-200'
  return { base, tono }
}

function SezionePerformance({ id, titolo, icona, perf, nome }) {
  if (!perf) {
    return (
      <div id={id} className="sezione-scroll space-y-3">
        <h2 className="text-xs uppercase tracking-widest text-slate-500">{titolo}</h2>
        <EmptyState label="Dati della giornata non disponibili." />
      </div>
    )
  }
  const { base, tono } = titoloSintesi(perf)
  const gruppi = [...new Set(perf.metriche.map(m => m.gruppo))]
  const s = perf.sintesi

  return (
    <div id={id} className="sezione-scroll space-y-3">
      <h2 className="text-xs uppercase tracking-widest text-slate-500">{titolo}</h2>
      <Section title={nome} icon={icona}
               right={perf.n_rif > 0 ? `media su ${perf.n_rif} ${perf.n_rif === 1 ? 'altra partita' : 'altre partite'}` : 'nessun termine di confronto'}>
        <div className="px-4 py-3 border-b border-white/5 space-y-2">
          <p className={`text-display text-xl font-bold ${tono}`}>{base}</p>
          <div className="flex flex-wrap gap-2 text-xs font-mono">
            <span className="px-2 py-1 rounded-lg bg-green-500/10 text-green-400 border border-green-500/20">{s.positive} migliori della media</span>
            <span className="px-2 py-1 rounded-lg bg-white/[0.04] text-slate-400 border border-white/10">{s.neutre} in linea</span>
            <span className="px-2 py-1 rounded-lg bg-red-500/10 text-red-400 border border-red-500/20">{s.negative} peggiori della media</span>
          </div>
        </div>

        {gruppi.map(gr => (
          <div key={gr}>
            <p className="px-4 py-1.5 text-[10px] uppercase tracking-widest text-slate-600 bg-white/[0.02]">{gr}</p>
            {perf.metriche.filter(m => m.gruppo === gr).map(m => (
              <div key={m.codice} className="flex items-center gap-2 sm:gap-3 px-4 py-2.5 border-b border-white/[0.03] last:border-0">
                <span className="flex-1 min-w-0 text-sm text-slate-300">{m.label}</span>
                <span className="text-right w-14 flex-shrink-0">
                  <span className="block font-mono font-bold text-sm text-slate-100">{m.valore === null ? '–' : fmt(m.valore, m.dec)}</span>
                </span>
                <span className="text-right w-20 flex-shrink-0 hidden sm:block">
                  <span className="block text-[10px] uppercase tracking-wider text-slate-600">media</span>
                  <span className="block font-mono text-xs text-slate-400">{m.media === null ? '–' : fmt(m.media, m.dec)}</span>
                </span>
                <span className="text-right w-12 flex-shrink-0 font-mono text-xs text-slate-500">
                  {m.delta === null ? '' : fmtSegno(m.delta, m.dec)}
                </span>
                <span className="w-[7.5rem] flex-shrink-0 text-right">
                  <BadgeVerdetto verdetto={m.verdetto} giudizio={m.giudizio} />
                </span>
              </div>
            ))}
          </div>
        ))}
        <p className="px-4 py-2 text-[11px] text-slate-600 border-t border-white/5">
          "In linea" = entro mezza deviazione standard dalla media delle altre partite della stagione
          (con una soglia minima). Per gol e fantapunti subiti, "sopra la media" è un dato negativo.
        </p>
      </Section>
    </div>
  )
}

// ── Scelte: componenti ───────────────────────────────────────

function Esito({ segno, gol, subiti }) {
  return (
    <span className={`inline-flex items-center gap-1.5 font-mono font-bold ${SEGNO[segno]?.cls ?? 'text-slate-300'}`}>
      {gol} - {subiti} <span className="text-[10px] uppercase font-semibold opacity-80">{ESITO_TESTO[segno]}</span>
    </span>
  )
}

function SchedaFormazione({ titolo, f, evidenza, simulata = false }) {
  return (
    <div className={`rounded-xl border p-3 space-y-2 ${evidenza ? 'border-grass-500/40 bg-grass-500/[0.05]' : 'border-white/10 bg-pitch-900'}`}>
      <div className="flex items-center justify-between gap-2">
        <p className="text-xs uppercase tracking-widest text-slate-500">{titolo}</p>
        <span className="stat-pill font-mono">{f.modulo}</span>
      </div>
      <div className="grid grid-cols-2 gap-2">
        <Indicatore label="Fantamedia" value={fmt(f.fantamedia, 2)} sub={`somma ${fmt(f.somma)}`} tono={evidenza ? 'text-grass-300' : 'text-white'} />
        <Indicatore label="Punteggio" value={fmt(f.punteggio)} sub={simulata ? 'stimato' : 'reale'} />
      </div>
      <p className="text-center text-sm">
        <Esito segno={f.segno} gol={f.gol} subiti={f.gol_subiti} />
        {simulata && <span className="ml-2 text-[10px] text-slate-600 uppercase">simulato</span>}
      </p>
    </div>
  )
}

function RigaGiocatore({ g, stagione, dx, tono = 'text-slate-300' }) {
  return (
    <div className="flex items-center gap-2 px-3 py-2 border-b border-white/[0.03] last:border-0">
      <RoleBadge ruolo={g.ruolo} />
      <NomeConBandiera id={g.id_giocatore} nome={g.giocatore} stagione={stagione} className={`flex-1 min-w-0 text-sm ${tono}`} />
      {dx}
    </div>
  )
}

function Fantavoto({ g }) {
  return (
    <span className="text-right flex-shrink-0">
      <span className="text-[11px] text-slate-600 font-mono mr-2">{fmt(g.voto)}</span>
      <span className="text-sm font-mono font-bold text-slate-100">{fmt(g.fantavoto)}</span>
      {g.bonus !== 0 && <span className={`ml-1.5 text-[10px] font-mono ${g.bonus > 0 ? 'text-green-400' : 'text-red-400'}`}>({fmtSegno(g.bonus)})</span>}
    </span>
  )
}

function dettaglioEventi(g) {
  const ev = []
  if (g.gol) ev.push(`${g.gol} gol`)
  if (g.assist) ev.push(`${g.assist} assist`)
  if (g.rigori_parati) ev.push(`${g.rigori_parati} rigore parato`)
  if (g.rigori_realizzati) ev.push(`${g.rigori_realizzati} rigore segnato`)
  return ev.join(' · ')
}

function BonusPersi({ bp, stagione, titolo = 'Bonus persi', vuoto }) {
  const chips = [
    [bp.gol, 'gol'], [bp.assist, 'assist'], [bp.rigori_parati, 'rigori parati'], [bp.rigori_realizzati, 'rigori segnati'],
  ].filter(([n]) => n > 0)
  return (
    <Section title={titolo} icon={Sparkles}
             right={bp.totale > 0 ? <span className="text-gold-300">{fmtSegno(bp.totale)} punti lasciati fuori</span> : undefined}>
      {bp.giocatori.length === 0 ? (
        <Vuoto>{vuoto}</Vuoto>
      ) : (
        <>
          <div className="px-4 py-3 flex flex-wrap gap-2 border-b border-white/5">
            {chips.map(([n, l]) => (
              <span key={l} className="px-2.5 py-1 rounded-lg bg-gold-500/10 border border-gold-500/25 text-gold-300 text-xs font-mono">
                {n} {l}
              </span>
            ))}
          </div>
          {bp.giocatori.slice(0, 6).map(g => (
            <RigaGiocatore key={g.id_giocatore} g={g} stagione={stagione}
              dx={
                <span className="text-right flex-shrink-0">
                  <span className="block text-sm font-mono font-bold text-gold-300">{fmtSegno(g.bonus)}</span>
                  <span className="block text-[10px] text-slate-500">{dettaglioEventi(g) || 'bonus'} · {g.posizione}</span>
                </span>
              } />
          ))}
          {bp.giocatori.length > 6 && (
            <p className="px-4 py-2 text-[11px] text-slate-600">… e altri {bp.giocatori.length - 6} giocatori con bonus non utilizzati.</p>
          )}
        </>
      )}
    </Section>
  )
}

function descrizionePercentile(p) {
  if (p === null || p === undefined) return ''
  if (p >= 90) return 'scelta eccellente'
  if (p >= 65) return 'scelta buona'
  if (p >= 40) return 'scelta nella media'
  if (p >= 15) return 'scelta da rivedere'
  return 'scelta molto penalizzante'
}

function BarraPercentile({ valore }) {
  const v = Math.max(0, Math.min(100, valore ?? 0))
  return (
    <div className="pt-6 pb-1">
      <div className="relative h-3 rounded-full bg-gradient-to-r from-red-500/50 via-yellow-400/40 to-green-500/60">
        <div className="absolute -top-6 -translate-x-1/2 flex flex-col items-center" style={{ left: `${v}%` }}>
          <span className="text-[10px] font-mono font-bold text-white bg-pitch-950 border border-white/20 rounded px-1.5 py-0.5">{v}°</span>
          <span className="w-0.5 h-2 bg-white/70" />
        </div>
        <div className="absolute top-0 h-full w-0.5 bg-white/20" style={{ left: '50%' }} />
      </div>
      <div className="flex justify-between text-[10px] font-mono text-slate-600 mt-1">
        <span>peggiore possibile</span><span>mediana</span><span>migliore possibile</span>
      </div>
    </div>
  )
}

function Percentile({ sc, titolo = 'Percentile della formazione scelta', contro = "la formazione reale dell'avversario" }) {
  const p = sc.percentile
  return (
    <Section title={titolo} icon={Gauge} right={`${fmtInt(p.formazioni)} formazioni possibili`}>
      <div className="px-4 py-3 space-y-3">
        <BarraPercentile valore={p.fantamedia} />
        <p className="text-sm text-slate-300">
          Fantamedia <span className="font-mono font-bold text-white">{fmt(sc.schierata.fantamedia, 2)}</span>:
          meglio del <span className="font-bold text-white">{p.fantamedia}%</span> delle formazioni possibili
          (posizione <span className="font-mono">{fmtInt(p.posizione)}ª</span> su {fmtInt(p.formazioni)}) — {descrizionePercentile(p.fantamedia)}.
        </p>
        <p className="text-xs text-slate-500">
          Includendo i modificatori di difesa, centrocampo e attacco contro {contro},
          il punteggio finale si colloca al <span className="font-mono text-slate-300">{p.punteggio}°</span> percentile
          (migliore possibile: fantamedia {fmt(sc.migliore.fantamedia, 2)}, modulo {sc.migliore.modulo}).
        </p>
      </div>
    </Section>
  )
}

function commentoSintesi(sin, p, chi) {
  const { perc_v: v, perc_n: n, perc_p: pp, esito_reale: reale } = sin
  const quota = { V: v, N: n, P: pp }[reale]
  const alta = p.fantamedia >= 65
  const bassa = p.fantamedia < 40
  const vinceMaggioranza = v >= 50
  const perdeMaggioranza = pp >= 50

  if (reale === 'V') {
    if (quota >= 50) return `${chi} ha vinto, come avrebbe fatto la maggior parte delle formazioni possibili (${fmt(v, 0)}%): esito in linea con le scelte.`
    return alta
      ? `${chi} ha vinto con una formazione tra le migliori, in una sfida difficile: solo il ${fmt(v, 0)}% delle formazioni possibili avrebbe vinto.`
      : `${chi} ha vinto, ma solo il ${fmt(v, 0)}% delle formazioni possibili avrebbe fatto altrettanto: risultato favorito anche dalla sorte.`
  }
  if (reale === 'P') {
    if (perdeMaggioranza) return `${chi} ha perso e il ${fmt(pp, 0)}% delle formazioni possibili avrebbe perso: sconfitta difficile da evitare${alta ? ', nonostante una buona scelta' : ''}.`
    if (vinceMaggioranza) return `${chi} ha perso, ma il ${fmt(v, 0)}% delle formazioni possibili avrebbe vinto: sconfitta evitabile${bassa ? ' con scelte migliori' : ', un po\' di sfortuna'}.`
    return `${chi} ha perso in una sfida equilibrata (vittoria per il ${fmt(v, 0)}% delle formazioni possibili).`
  }
  // pareggio: si guarda se, tra le formazioni possibili, pesavano di più le vittorie o le sconfitte
  if (v - pp >= 10) return `${chi} ha pareggiato, ma il ${fmt(v, 0)}% delle formazioni possibili avrebbe vinto (perso solo il ${fmt(pp, 0)}%): pareggio rimediabile.`
  if (pp - v >= 10) return `${chi} ha pareggiato, mentre il ${fmt(pp, 0)}% delle formazioni possibili avrebbe perso (vinto solo il ${fmt(v, 0)}%): pareggio positivo.`
  return `${chi} ha pareggiato in una sfida equilibrata: vittoria per il ${fmt(v, 0)}% delle formazioni possibili, sconfitta per il ${fmt(pp, 0)}%.`
}

function BarraEsiti({ sin }) {
  const parti = [
    ['V', sin.perc_v, 'bg-green-500', 'Vincenti'],
    ['N', sin.perc_n, 'bg-yellow-400', 'Pareggianti'],
    ['P', sin.perc_p, 'bg-red-500', 'Perdenti'],
  ]
  return (
    <div className="space-y-2">
      <div className="flex h-5 rounded-full overflow-hidden bg-pitch-800">
        {parti.map(([k, perc, cls]) => perc > 0 && (
          <div key={k} className={cls} style={{ width: `${perc}%` }} title={`${perc}%`} />
        ))}
      </div>
      <div className="grid grid-cols-3 gap-2 text-center">
        {parti.map(([k, perc, , label]) => (
          <div key={k} className={`rounded-lg border px-2 py-1.5 ${sin.esito_reale === k ? SEGNO[k].bg : 'border-white/5'}`}>
            <p className={`font-mono font-bold ${SEGNO[k].cls}`}>{fmt(perc, 1)}%</p>
            <p className="text-[10px] uppercase tracking-wider text-slate-500">{label}</p>
            <p className="text-[10px] font-mono text-slate-600">{fmtInt({ V: sin.vincenti, N: sin.pareggianti, P: sin.perdenti }[k])}</p>
          </div>
        ))}
      </div>
    </div>
  )
}

function SintesiEsiti({ sc, chi, contro, titolo = 'Sintesi: formazioni vincenti e perdenti' }) {
  const sin = sc.sintesi
  return (
    <Section title={titolo} icon={ListChecks} right={`${fmtInt(sin.formazioni)} formazioni`}>
      <div className="px-4 py-3 space-y-3">
        <p className="text-xs text-slate-500">
          Esito di ogni formazione possibile contro {contro}.
          Esito reale: <span className={`font-semibold ${SEGNO[sin.esito_reale]?.cls}`}>{ESITO_TESTO[sin.esito_reale] ?? '–'}</span>.
        </p>
        <BarraEsiti sin={sin} />
        <p className="text-sm text-slate-200 leading-relaxed">{commentoSintesi(sin, sc.percentile, chi)}</p>
      </div>
    </Section>
  )
}

function ScostamentoModello({ sc }) {
  if (Math.abs(sc.scostamento_modello ?? 0) <= 0.05) return null
  return (
    <p className="flex items-start gap-2 text-[11px] text-slate-500 px-1">
      <Info className="w-3.5 h-3.5 flex-shrink-0 mt-0.5" />
      Il ricalcolo del punteggio dalle formazioni si scosta di {fmtSegno(sc.scostamento_modello, 2)} da quello salvato
      (dati storici o correzioni manuali): le stime di esito sono approssimate.
    </p>
  )
}

// ── Scelte (squadra) ─────────────────────────────────────────

function SezioneScelte({ sc, stagione, avv }) {
  const [elenco, setElenco] = useState(false)
  if (!sc?.disponibile) {
    return <Vuoto>{sc?.motivo ?? 'Analisi delle scelte non disponibile.'}</Vuoto>
  }
  const d = sc.differenze
  const g = sc.gol_possibili
  const gDiff = g.differenza

  return (
    <div className="space-y-4">
      {/* Schierata vs migliore */}
      <Section title="Formazione schierata e migliore possibile" icon={Swords}
               right={`migliore per fantamedia · ${sc.migliore.modulo}`}>
        <div className="p-4 grid grid-cols-1 sm:grid-cols-2 gap-3">
          <SchedaFormazione titolo="Schierata" f={sc.schierata} />
          <SchedaFormazione titolo="Migliore possibile" f={sc.migliore} evidenza simulata />
        </div>

        <div className="px-4 pb-1">
          <p className="text-xs uppercase tracking-widest text-slate-500 mb-2">Differenze</p>
          {d.stessa_formazione ? (
            <p className="text-sm text-green-400 pb-3">Hai schierato esattamente la migliore formazione possibile.</p>
          ) : (
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 pb-3">
              <div className="rounded-xl border border-red-500/20 overflow-hidden">
                <p className="px-3 py-1.5 text-[10px] uppercase tracking-widest text-red-400 bg-red-500/10">Fuori ({d.usciti.length})</p>
                {d.usciti.length === 0
                  ? <p className="px-3 py-3 text-xs text-slate-600">Nessuno (la formazione reale non aveva 11 giocatori)</p>
                  : d.usciti.map(g2 => <RigaGiocatore key={g2.id_giocatore} g={g2} stagione={stagione} tono="text-slate-400" dx={<Fantavoto g={g2} />} />)}
              </div>
              <div className="rounded-xl border border-green-500/20 overflow-hidden">
                <p className="px-3 py-1.5 text-[10px] uppercase tracking-widest text-green-400 bg-green-500/10">Dentro ({d.entrati.length})</p>
                {d.entrati.map(g2 => (
                  <RigaGiocatore key={g2.id_giocatore} g={g2} stagione={stagione}
                    dx={
                      <span className="flex items-center gap-2 flex-shrink-0">
                        <span className="text-[10px] text-slate-600 hidden sm:inline">{g2.posizione}</span>
                        <Fantavoto g={g2} />
                      </span>
                    } />
                ))}
              </div>
            </div>
          )}
          <p className="text-sm text-slate-300 pb-3">
            Fantamedia <span className="font-mono">{fmt(sc.schierata.fantamedia, 2)}</span>
            <ArrowRight className="inline w-3.5 h-3.5 mx-1.5 text-slate-600" />
            <span className="font-mono font-bold text-grass-300">{fmt(sc.migliore.fantamedia, 2)}</span>
            <span className="ml-2 font-mono text-xs text-slate-500">({fmtSegno(d.delta_fantamedia, 2)} · somma {fmtSegno(d.delta_somma)} · punteggio finale {fmtSegno(d.delta_punteggio)})</span>
          </p>
        </div>

        {/* Gol possibili */}
        <div className="px-4 py-3 border-t border-white/5 bg-white/[0.02]">
          <p className="text-xs uppercase tracking-widest text-slate-500 mb-2">Gol possibili con la formazione migliore</p>
          <div className="flex flex-wrap items-center gap-x-6 gap-y-2">
            <div>
              <p className="text-[10px] uppercase tracking-wider text-slate-600">Reale</p>
              <Esito segno={sc.schierata.segno} gol={g.gol_reali} subiti={g.gol_subiti_reali} />
            </div>
            <ArrowRight className="w-4 h-4 text-slate-600" />
            <div>
              <p className="text-[10px] uppercase tracking-wider text-slate-600">Con la migliore</p>
              <Esito segno={sc.migliore.segno} gol={g.gol_migliore} subiti={g.gol_subiti_migliore} />
            </div>
            <p className={`text-sm font-semibold ${gDiff > 0 ? 'text-green-400' : gDiff < 0 ? 'text-red-400' : 'text-slate-400'}`}>
              {gDiff > 0 ? `+${gDiff} gol possibili` : gDiff === 0 ? 'stessi gol segnati' : `${gDiff} gol: la migliore per fantamedia non avrebbe segnato di più`}
            </p>
          </div>
        </div>
      </Section>

      <BonusPersi bp={sc.bonus_persi} stagione={stagione} titolo="Bonus persi"
                  vuoto="Nessun bonus lasciato fuori: nessun giocatore non schierato ha ottenuto gol, assist o rigori." />

      <Percentile sc={sc} />
      <SintesiEsiti sc={sc} chi="La tua squadra" contro="la formazione realmente schierata dall'avversario" />
      <ScostamentoModello sc={sc} />

      <button className="text-xs text-slate-500 hover:text-slate-300 underline underline-offset-2" onClick={() => setElenco(v => !v)}>
        {elenco ? 'Nascondi' : 'Mostra'} la formazione migliore completa
      </button>
      {elenco && (
        <Section title={`Migliore formazione possibile · ${sc.migliore.modulo}`} icon={ListChecks}>
          {sc.migliore.giocatori.map(g2 => (
            <RigaGiocatore key={g2.id_giocatore} g={g2} stagione={stagione} dx={<Fantavoto g={g2} />} />
          ))}
        </Section>
      )}
    </div>
  )
}

// ── Scelte avversario ────────────────────────────────────────

function SezioneScelteAvv({ sc, stagione, nome }) {
  if (!sc?.disponibile) {
    return <Vuoto>{sc?.motivo ?? 'Analisi delle scelte non disponibile.'}</Vuoto>
  }
  return (
    <div className="space-y-4">
      <BonusPersi bp={sc.bonus_persi} stagione={stagione} titolo={`Bonus persi da ${nome}`}
                  vuoto="Nessun bonus lasciato fuori dall'avversario." />
      <Percentile sc={sc} titolo={`Percentile della formazione scelta da ${nome}`} contro="la tua formazione reale" />
      <SintesiEsiti sc={sc} chi={nome} contro="la tua formazione realmente schierata" />
      <ScostamentoModello sc={sc} />
    </div>
  )
}

// ── Area ─────────────────────────────────────────────────────

export default function AreaMatchPassati({ stagione, idSquadra, competizione, nomeSquadra }) {
  const champ = competizione === 'CHAMP'
  // null = ultima partita giocata (scelta dal backend)
  const [scelta, setScelta] = useState(null)

  const { data, loading, error, refetch } = useFetch(
    () => getDashboardAnalisiMatch(stagione, idSquadra, competizione, scelta),
    [stagione, idSquadra, competizione, scelta]
  )

  if (error) return <ErrorState message={error} onRetry={refetch} />
  if (!data) return <LoadingState label="Analisi della partita..." />

  if (data.partite.length === 0) {
    return <EmptyState label={champ ? 'Nessuna partita di Champions ancora giocata.' : 'Nessuna partita di campionato ancora giocata.'} />
  }

  const giornata = scelta ?? data.giornata
  const nomeAvv = data.avversario?.nome ?? data.partita.avversario
  const ultima = data.partite[data.partite.length - 1].giornata
  // Durante il cambio di partita si mostra il risultato precedente, attenuato
  const aggiornamento = loading || data.giornata !== giornata

  return (
    <div className="space-y-6">
      <SelettoreMatch
        partite={data.partite}
        giornata={giornata}
        caricamento={aggiornamento}
        onChange={g => setScelta(g === ultima ? null : g)}
      />

      <div className={`space-y-6 transition-opacity duration-150 ${aggiornamento ? 'opacity-50 pointer-events-none' : ''}`}>
        <SezionePerformance id="match-performance" titolo="Performance" icona={Gauge}
                            perf={data.performance} nome={nomeSquadra ?? 'La tua squadra'} />
        <SezionePerformance id="match-performance-avv" titolo="Performance avversario" icona={Gauge}
                            perf={data.performance_avv} nome={nomeAvv} />

        <div id="match-scelte" className="sezione-scroll space-y-3">
          <h2 className="text-xs uppercase tracking-widest text-slate-500">Scelte</h2>
          <SezioneScelte sc={data.scelte} stagione={stagione} />
        </div>

        <div id="match-scelte-avv" className="sezione-scroll space-y-3">
          <h2 className="text-xs uppercase tracking-widest text-slate-500">Scelte avversario</h2>
          <SezioneScelteAvv sc={data.scelte_avv} stagione={stagione} nome={nomeAvv} />
        </div>
      </div>
    </div>
  )
}
