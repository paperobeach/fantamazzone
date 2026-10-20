import { useState } from 'react'
import { ArrowRightLeft, Briefcase, ChevronDown, Clock, Pencil, Radio, MinusCircle, UserMinus } from 'lucide-react'
import { RoleBadge } from './ui'
import { Bandiera } from './Bandiera'

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

// Nome del giocatore preceduto dalla bandiera (assente per le riserve d'ufficio fittizie)
function NomeBandiera({ g }) {
  return (
    <span className="inline-flex items-center gap-1.5">
      <Bandiera id={g.id_giocatore} />
      <span>{g.giocatore}</span>
    </span>
  )
}

// ── Nome giocatore, con evidenza della riserva d'ufficio ──
// Due forme (vedi backend/dettaglio_partita.php):
//  - ID fittizio (ufficio_fittizio): nessun calciatore reale → solo la dicitura "Riserva d'ufficio";
//  - ID reale (dati storici o ammonito senza voto): nome del calciatore + dicitura.
export function NomeGiocatore({ g }) {
  // Pagina LIVE: g.origine indica da dove arriva il voto
  //   reale = voto Serie A, senza_voto = nessun voto (sostituito / a zero)
  // (provvisorio, manuale e ufficio sono gestiti sotto)
  if (g.origine === 'reale') {
    return (
      <span className="inline-flex items-center gap-1.5" title="Voto reale (Serie A)">
        <Radio className="w-3.5 h-3.5 text-grass-400 shrink-0" aria-hidden="true" />
        <NomeBandiera g={g} />
      </span>
    )
  }
  if (g.origine === 'senza_voto') {
    return (
      <span className="inline-flex items-center gap-1.5 text-slate-500" title="Senza voto: sostituito o a zero">
        <MinusCircle className="w-3.5 h-3.5 shrink-0" aria-hidden="true" />
        <NomeBandiera g={g} />
      </span>
    )
  }
  // Simulazione live: 6 provvisorio (partita di Serie A non ancora giocata)
  // o voto inserito a mano
  if (g.provvisorio) {
    return (
      <span className="inline-flex items-center gap-1.5 flex-wrap" title="Voto 6 provvisorio: la partita di Serie A non è ancora stata giocata">
        <Clock className="w-3.5 h-3.5 text-sky-400 shrink-0" aria-hidden="true" />
        <NomeBandiera g={g} />
        <span className="text-[10px] font-mono uppercase tracking-wide text-sky-400">6 provvisorio</span>
      </span>
    )
  }
  if (g.manuale) {
    return (
      <span className="inline-flex items-center gap-1.5 flex-wrap" title="Voto inserito manualmente">
        <Pencil className="w-3.5 h-3.5 text-violet-400 shrink-0" aria-hidden="true" />
        <NomeBandiera g={g} />
        <span className="text-[10px] font-mono uppercase tracking-wide text-violet-400">manuale</span>
      </span>
    )
  }
  if (!g.riserva_ufficio) return <NomeBandiera g={g} />
  return (
    <span className="inline-flex items-center gap-1.5 flex-wrap" title="Voto assegnato d'ufficio">
      <Briefcase className="w-3.5 h-3.5 text-amber-400 shrink-0" aria-hidden="true" />
      {g.ufficio_fittizio
        ? <span className="text-amber-300">Riserva d'ufficio</span>
        : <>
            <NomeBandiera g={g} />
            <span className="text-[10px] font-mono uppercase tracking-wide text-amber-400">Riserva d'ufficio</span>
          </>}
    </span>
  )
}

// ── Etichetta "Subentrato": il giocatore è entrato dalla panchina ──
// (flag g.subentrato calcolato da backend/dettaglio_partita.php e
//  backend/live_dettaglio.php)
export function BadgeSubentrato({ g }) {
  if (!g.subentrato) return null
  return (
    <span
      className="inline-flex items-center gap-1 px-1.5 py-0.5 rounded-md text-[10px] font-mono uppercase tracking-wide leading-none text-emerald-300 bg-emerald-500/15 ring-1 ring-emerald-500/40"
      title={g.sostituisce
        ? `Subentrato dalla panchina al posto di ${g.sostituisce}`
        : 'Subentrato dalla panchina al posto di un titolare senza voto'}
    >
      <ArrowRightLeft className="w-3 h-3 shrink-0" aria-hidden="true" />
      Subentrato
    </span>
  )
}

// ── Etichetta "Sostituito": titolare senza voto rimpiazzato (si vede in panchina) ──
export function BadgeSostituito() {
  return (
    <span
      className="inline-flex items-center gap-1 px-1.5 py-0.5 rounded-md text-[10px] font-mono uppercase tracking-wide leading-none text-rose-300 bg-rose-500/15 ring-1 ring-rose-500/40"
      title="Titolare senza voto, sostituito"
    >
      <UserMinus className="w-3 h-3 shrink-0" aria-hidden="true" />
      Sostituito
    </span>
  )
}

// ── Eventi (bonus / malus) del singolo giocatore, mostrati come icone ──
// Ogni evento compare solo se il giocatore lo ha avuto; con più di uno
// viene aggiunto il moltiplicatore (×2). Al posto di una colonna per ogni
// bonus/malus, le icone stanno accanto al nome.
// Mapping rigori (vedi backend/admin/calcolo_giornata.php):
//   rigores = Rp + Rf  → rigori parati (portiere) o realizzati (altri ruoli)
//   rigorep = Rs       → rigori sbagliati
// Per questo 'rigores' ha due voci distinte, scelte in base al ruolo.
export const EVENTI = [
  { k: 'reti',        icona: '⚽',  titolo: 'Gol',               tono: 'bonus' },
  { k: 'assist',      icona: '🅰️', titolo: 'Assist',            tono: 'bonus' },
  { k: 'rigores',     id: 'rigore_segnato', icona: '🎯', titolo: 'Rigore segnato', tono: 'bonus', ruolo: 'campo' },
  { k: 'rigores',     id: 'rigore_parato',  icona: '🧤', titolo: 'Rigore parato',  tono: 'bonus', ruolo: 'portiere' },
  { k: 'retis',       icona: '🥅',  titolo: 'Gol subiti',        tono: 'malus' },
  { k: 'autogol',     icona: '🔴',  titolo: 'Autogol',           tono: 'malus' },
  { k: 'rigorep',     icona: '❌',  titolo: 'Rigore sbagliato',  tono: 'malus' },
  { k: 'ammonizioni', icona: '🟨',  titolo: 'Ammonizione',       tono: 'malus' },
  { k: 'espulsioni',  icona: '🟥',  titolo: 'Espulsione',        tono: 'malus' },
]

export function EventiGiocatore({ g }) {
  const portiere = Number(g.ruolo) === 1
  const presenti = EVENTI
    .filter(e => !e.ruolo || (e.ruolo === 'portiere') === portiere)
    .map(e => ({ ...e, n: Number(g[e.k]) || 0 }))
    .filter(e => e.n > 0)
  if (!presenti.length) return null
  return (
    <span className="inline-flex items-center gap-1 flex-wrap">
      {presenti.map(e => (
        <span
          key={e.id ?? e.k}
          title={`${e.titolo}${e.n > 1 ? ` ×${e.n}` : ''}`}
          className={`inline-flex items-center gap-0.5 px-1.5 py-1 rounded-md text-[11px] leading-none ring-1 ${
            e.tono === 'bonus'
              ? 'bg-green-500/25 ring-green-500/40'
              : 'bg-red-500/25 ring-red-500/40'
          }`}
        >
          <span aria-hidden="true">{e.icona}</span>
          {e.n > 1 && <span className="font-mono text-[10px] text-slate-300">×{e.n}</span>}
          <span className="sr-only">{e.titolo}{e.n > 1 ? ` ×${e.n}` : ''}</span>
        </span>
      ))}
    </span>
  )
}

// Legenda delle icone (compare in fondo al dettaglio partita)
export function LegendaEventi() {
  return (
    <div className="border-t border-white/5 px-3 py-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-[10px] text-slate-600">
      {EVENTI.map(e => (
        <span key={e.id ?? e.k} className="inline-flex items-center gap-1">
          <span aria-hidden="true">{e.icona}</span>{e.titolo}
        </span>
      ))}
      <span className="inline-flex items-center gap-1">
        <ArrowRightLeft className="w-3 h-3 text-emerald-400" aria-hidden="true" />Subentrato dalla panchina
      </span>
      <span className="inline-flex items-center gap-1">
        <UserMinus className="w-3 h-3 text-rose-400" aria-hidden="true" />Titolare sostituito
      </span>
    </div>
  )
}

// ── Tabella voti giocatori di una squadra ──
// Colonne: ruolo, giocatore (con icone degli eventi), voto, totale.
// Con soloFormazione (partita senza risultati) mostra la sola formazione
// inserita: titolari e panchina, senza voti.
export function GiocatoriTable({ giocatori, soloFormazione = false }) {
  // I titolari sostituiti (g.in_panchina) compaiono solo nella sezione panchina
  const lista = (giocatori ?? []).filter(g => soloFormazione || !g.in_panchina)

  if (soloFormazione) {
    if (!lista.length) {
      return <p className="px-3 py-6 text-center text-xs text-slate-600">Formazione non ancora inserita</p>
    }
    const titolari = lista.filter(g => g.titolare)
    const panchina = lista.filter(g => !g.titolare)
    const gruppo = (titolo, righe) => righe.length > 0 && (
      <>
        <tr><td colSpan={2} className="px-3 py-1.5 bg-white/[0.02] text-[10px] font-mono uppercase tracking-widest text-slate-600">{titolo}</td></tr>
        {righe.map((g, i) => (
          <tr key={`${titolo}-${i}`} className="border-b border-white/[0.03] hover:bg-white/[0.02]">
            <td className="px-3 py-2 w-8"><RoleBadge ruolo={g.ruolo} /></td>
            <td className="px-3 py-2 text-slate-300 font-medium"><NomeBandiera g={g} /></td>
          </tr>
        ))}
      </>
    )
    return (
      <table className="w-full text-xs">
        <tbody>
          {gruppo('Titolari', titolari)}
          {gruppo('Panchina', panchina)}
        </tbody>
      </table>
    )
  }

  return (
    <table className="w-full text-xs">
      <thead>
        <tr className="border-b border-white/5">
          <th className="px-3 py-2 text-left font-mono tracking-widest uppercase text-slate-700 font-normal w-8">R</th>
          <th className="px-3 py-2 text-left font-mono tracking-widest uppercase text-slate-700 font-normal">Giocatore</th>
          <th className="px-2 py-2 text-center font-mono tracking-widest uppercase text-slate-700 font-normal">Voto</th>
          <th className="px-2 py-2 text-center font-mono tracking-widest uppercase text-slate-700 font-normal">Tot</th>
        </tr>
      </thead>
      <tbody>
        {lista.map((g, i) => (
          <tr key={i} className={`border-b border-white/[0.03] hover:bg-white/[0.02] ${g.riserva_ufficio ? 'bg-amber-400/[0.04]' : g.provvisorio ? 'bg-sky-400/[0.05]' : g.manuale ? 'bg-violet-400/[0.05]' : g.subentrato ? 'bg-emerald-400/[0.06]' : ''}`}>
            <td className="px-3 py-2"><RoleBadge ruolo={g.ruolo} /></td>
            <td className="px-3 py-2 text-slate-300 font-medium">
              <div className="flex items-center gap-x-2 gap-y-1 flex-wrap">
                <NomeGiocatore g={g} />
                <BadgeSubentrato g={g} />
                <EventiGiocatore g={g} />
              </div>
            </td>
            <td className="px-2 py-2 text-center">
              <VotoBox voto={g.voto} totale={g.voto} giocata={g.giocata} />
            </td>
            <td className="px-2 py-2 text-center">
              <VotoBox voto={g.totale} totale={g.totale} giocata={g.giocata} />
            </td>
          </tr>
        ))}
      </tbody>
    </table>
  )
}

// ── Panchina di una squadra (visibile a richiesta) ──
// Elenco "panchina" restituito da dettaglio_partita.php / live_dettaglio.php:
// panchinari (subentrati / non entrati) e titolari sostituiti, questi ultimi
// con il badge "Sostituito" e il nome di chi li ha rimpiazzati.
export function PanchinaTable({ panchina }) {
  const lista = panchina ?? []
  if (!lista.length) {
    return <p className="px-3 py-3 text-center text-xs text-slate-600">Panchina non disponibile</p>
  }
  return (
    <table className="w-full text-xs">
      <tbody>
        <tr>
          <td colSpan={2} className="px-3 py-1.5 bg-white/[0.02] text-[10px] font-mono uppercase tracking-widest text-slate-600">Panchina</td>
        </tr>
        {lista.map((p, i) => (
          <tr
            key={`${p.id_giocatore}-${i}`}
            className={`border-b border-white/[0.03] hover:bg-white/[0.02] ${
              p.stato === 'subentrato' ? 'bg-emerald-400/[0.06]' :
              p.stato === 'sostituito' ? 'bg-rose-400/[0.05]' : ''
            }`}
          >
            <td className="px-3 py-2 w-8"><RoleBadge ruolo={p.ruolo} /></td>
            <td className="px-3 py-2 font-medium">
              <div className="flex items-center gap-x-2 gap-y-1 flex-wrap">
                <Bandiera id={p.id_giocatore} />
                <span className={p.stato === 'non_entrato' ? 'text-slate-500' : 'text-slate-300'}>{p.giocatore}</span>
                {p.stato === 'subentrato' && <BadgeSubentrato g={{ subentrato: true, sostituisce: p.sostituisce }} />}
                {p.stato === 'sostituito' && <BadgeSostituito />}
                {p.stato === 'non_entrato' && (
                  <span className="text-[10px] font-mono uppercase tracking-wide text-slate-600">Non entrato</span>
                )}
              </div>
              {p.stato === 'subentrato' && p.sostituisce && (
                <p className="mt-0.5 text-[10px] text-slate-600">al posto di {p.sostituisce}</p>
              )}
              {p.stato === 'sostituito' && p.sostituito_da && (
                <p className="mt-0.5 text-[10px] text-slate-600">sostituito da {p.sostituito_da}</p>
              )}
            </td>
          </tr>
        ))}
      </tbody>
    </table>
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
  if (!squadra || squadra.solo_formazione) return null
  return (
    <div className="flex items-center gap-2 px-3 py-2 border-b border-white/5">
      <ModBox label="Difesa"      value={squadra.mod_dif} />
      <ModBox label="Centrocampo" value={squadra.mod_cc} />
      <ModBox label="Attacco"     value={squadra.mod_att} />
    </div>
  )
}

// ── Supplementari e calci di rigore (Champions) ──
// extra = campo "extra" di dettaglio_partita.php (null se la partita non li ha avuti)
function ExtraPanel({ casa, ospite, extra }) {
  if (!extra || (!extra.supplementari && !extra.rigori)) return null
  const det = extra.dettaglio ?? {}
  const ruoli = { 2: 'D', 3: 'C', 4: 'A' }
  const Squadra = ({ s, nomeSq }) => (
    <div>
      <p className="text-xs font-semibold text-slate-400 mb-1">
        {nomeSq} · {Number(s?.punti ?? 0).toFixed(2)} punti = {s?.gol ?? 0} gol
      </p>
      <ul className="space-y-0.5">
        {(s?.giocatori ?? []).map((g, i) => (
          <li key={i} className="flex items-center gap-2 text-xs text-slate-400">
            <span className="w-4 font-mono text-slate-600">{ruoli[g.ruolo]}</span>
            <Bandiera id={g.id} />
            <span className="flex-1 truncate">{g.nome ?? '—'}</span>
            <span className="font-mono text-slate-500">{g.nota || (g.voto != null ? `voto ${g.voto}` : '')}</span>
            <span className="font-mono text-slate-300 w-12 text-right">{Number(g.punti ?? 0).toFixed(2)}</span>
          </li>
        ))}
      </ul>
    </div>
  )
  return (
    <div className="border-t border-white/5 px-3 py-3 space-y-4">
      {extra.andata && (
        <p className="text-xs text-slate-500">
          Gol dell'andata: {casa.nome} {extra.andata.casa} · {ospite.nome} {extra.andata.ospite}
        </p>
      )}
      {extra.supplementari && (
        <section>
          <h4 className="text-[11px] uppercase tracking-wider text-amber-400 mb-2">
            Supplementari · {extra.supp_golf.casa} - {extra.supp_golf.ospite}
          </h4>
          <div className="grid gap-4 sm:grid-cols-2">
            <Squadra s={det.supp?.casa} nomeSq={casa.nome} />
            <Squadra s={det.supp?.ospite} nomeSq={ospite.nome} />
          </div>
        </section>
      )}
      {extra.rigori && (
        <section>
          <h4 className="text-[11px] uppercase tracking-wider text-amber-400 mb-2">
            Calci di rigore · {extra.rig_golf.casa} - {extra.rig_golf.ospite}
            {det.rig?.oltranza ? ' (ad oltranza)' : ''}
          </h4>
          <div className="overflow-x-auto">
            <table className="w-full text-xs">
              <tbody>
                {(det.rig?.tiri ?? []).map(t => (
                  <tr key={t.n} className="border-t border-white/[0.03]">
                    <td className="py-1 pr-2 font-mono text-slate-600 w-8">{t.n}</td>
                    <td className={`py-1 pr-2 ${t.casa.segna ? 'text-green-400' : 'text-red-400'}`}>
                      {t.casa.nome} <span className="font-mono text-slate-500">{t.casa.voto ?? 's.v.'}</span> {t.casa.segna ? '✓' : '✗'}
                    </td>
                    <td className={`py-1 ${t.ospite.segna ? 'text-green-400' : 'text-red-400'}`}>
                      {t.ospite.nome} <span className="font-mono text-slate-500">{t.ospite.voto ?? 's.v.'}</span> {t.ospite.segna ? '✓' : '✗'}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </section>
      )}
      {extra.passa && (
        <p className="text-xs text-grass-400">Passa il turno: {extra.passa === 'casa' ? casa.nome : ospite.nome}</p>
      )}
    </div>
  )
}

// ── Dettaglio completo di una partita (usato nell'espansione) ──
export function MatchDetailPanel({ casa, ospite, loading, error, extra = null }) {
  // Panchina nascosta in prima battuta: si apre a richiesta dell'utente
  const [mostraPanchina, setMostraPanchina] = useState(false)

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
  // Il pulsante compare solo se almeno una squadra ha dati di panchina
  // (non nella modalità "solo formazione", che mostra già la panchina)
  const haPanchina = !casa.solo_formazione && !ospite.solo_formazione
    && ((casa.panchina?.length ?? 0) > 0 || (ospite.panchina?.length ?? 0) > 0)

  return (
    <>
    {haPanchina && (
      <div className="border-t border-white/5 px-3 py-2 flex justify-end">
        <button
          type="button"
          onClick={() => setMostraPanchina(v => !v)}
          aria-expanded={mostraPanchina}
          className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md text-[11px] font-mono uppercase tracking-wide text-slate-400 bg-white/[0.03] ring-1 ring-white/10 hover:bg-white/[0.06] hover:text-slate-200 transition-colors"
        >
          {mostraPanchina ? 'Nascondi panchina' : 'Mostra panchina'}
          <ChevronDown className={`w-3.5 h-3.5 transition-transform ${mostraPanchina ? 'rotate-180' : ''}`} aria-hidden="true" />
        </button>
      </div>
    )}
    <div className="border-t border-white/5 grid grid-cols-1 sm:grid-cols-2 divide-y sm:divide-y-0 sm:divide-x divide-white/5">
      <div>
        <div className="px-3 py-2 border-b border-white/5">
          <p className="text-xs font-semibold text-slate-400">{casa.nome}</p>
        </div>
        <ModificatoriRow squadra={casa} />
        <GiocatoriTable giocatori={casa.giocatori} soloFormazione={!!casa.solo_formazione} />
        {haPanchina && mostraPanchina && <PanchinaTable panchina={casa.panchina} />}
      </div>
      <div>
        <div className="px-3 py-2 border-b border-white/5">
          <p className="text-xs font-semibold text-slate-400">{ospite.nome}</p>
        </div>
        <ModificatoriRow squadra={ospite} />
        <GiocatoriTable giocatori={ospite.giocatori} soloFormazione={!!ospite.solo_formazione} />
        {haPanchina && mostraPanchina && <PanchinaTable panchina={ospite.panchina} />}
      </div>
    </div>
    <ExtraPanel casa={casa} ospite={ospite} extra={extra} />
    {!casa.solo_formazione && <LegendaEventi />}
    </>
  )
}
