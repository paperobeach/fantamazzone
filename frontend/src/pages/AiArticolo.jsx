import { useState, useEffect } from 'react'
import { useApp } from '../context/AppContext'
import { useFetch } from '../hooks/useFetch'
import { getGiornataCorrente, aiArticolo } from '../api/client'
import { PageHeader, Spinner } from '../components/ui'
import {
  Newspaper, Calculator, Sparkles, Lock, CheckCircle2, XCircle, AlertTriangle, ChevronDown,
} from 'lucide-react'

// ============================================================
// "Genera articolo" — Fase 2 della funzione di articoli AI
// ("La Gazzetta del Fantamazzone": commento goliardico alla giornata).
//
// Flusso in due passi, per vedere il costo PRIMA di spendere:
//   1. Anteprima (gratuita): il backend costruisce il prompt reale
//      (dati della giornata, profili, episodi storici), misura i token e
//      restituisce il costo previsto per ogni modello.
//   2. Conferma e genera (a pagamento): invia il modello scelto e
//      l'impronta (prompt_hash) dell'anteprima; se i dati sono cambiati nel
//      frattempo il backend rifiuta e va rifatta l'anteprima.
// L'articolo viene salvato come BOZZA (modifica e pubblicazione: fase 3).
//
// Accesso: solo utenze con NEW_UTENZE.abilita_ai = 'Y'. Ogni chiamata
// richiede utenza e password, verificate lato server.
// ============================================================

// Importi in USD (e in euro, se config_ai.php ha "cambio_eur_per_usd")
function fmtUsd(n) {
  if (n == null) return '—'
  if (n > 0 && n < 0.0001) return '<$0.0001'
  return `$${Number(n).toFixed(n < 1 ? 4 : 2)}`
}
function fmtEur(n, cambio) {
  if (n == null || cambio == null) return null
  const e = Number(n) * cambio
  if (e > 0 && e < 0.0001) return '<€0.0001'
  return `€${e.toFixed(e < 1 ? 4 : 2)}`
}
function Importo({ usd, cambio }) {
  const eur = fmtEur(usd, cambio)
  return (
    <span className="tabular-nums">
      {fmtUsd(usd)}{eur && <span className="text-slate-500"> (≈ {eur})</span>}
    </span>
  )
}

function Avviso({ children, tono = 'gold', icona: Icona = AlertTriangle }) {
  const stili = tono === 'red'
    ? 'bg-red-500/5 border-red-500/20 text-red-200'
    : tono === 'green'
      ? 'bg-green-500/5 border-green-500/20 text-green-200'
      : 'bg-gold-500/5 border-gold-500/20 text-gold-100'
  const col = tono === 'red' ? 'text-red-400' : tono === 'green' ? 'text-green-400' : 'text-gold-400'
  return (
    <div className={`rounded-lg px-4 py-3 flex gap-3 border text-sm ${stili}`}>
      <Icona className={`w-4 h-4 flex-shrink-0 mt-0.5 ${col}`} />
      <div className="min-w-0">{children}</div>
    </div>
  )
}

export default function AiArticolo() {
  const { utente, ultimaStagione, stagione } = useApp()
  // Come per il login, le credenziali si verificano sulla stagione più recente
  const stagioneRif = Number(ultimaStagione ?? stagione)

  const { data: corrente, loading: caricaGiornata } =
    useFetch(() => getGiornataCorrente(stagioneRif), [stagioneRif])
  const ultimaChiusa = Number(corrente?.ultima_chiusa ?? 0)

  const [giornata, setGiornata] = useState('')
  const [utenza,   setUtenza]   = useState(utente?.utenza ?? '')
  const [password, setPassword] = useState('')
  const [fase,     setFase]     = useState(null)   // 'anteprima' | 'genera' (in corso)
  const [errore,   setErrore]   = useState(null)
  const [anteprima, setAnteprima] = useState(null)
  const [modello,  setModello]  = useState(null)
  const [risultato, setRisultato] = useState(null)
  const [cambio,   setCambio]   = useState(null)   // euro per 1 USD (opzionale, da config_ai.php)

  // Giornata predefinita: l'ultima chiusa
  useEffect(() => {
    if (ultimaChiusa > 0 && giornata === '') setGiornata(String(ultimaChiusa))
  }, [ultimaChiusa, giornata])

  const reset = () => { setAnteprima(null); setModello(null); setRisultato(null); setErrore(null) }

  const pronto = utenza.trim() !== '' && password !== '' && giornata !== '' && !fase

  const chiama = (azione, extra = {}) =>
    aiArticolo(stagioneRif, {
      utenza: utenza.trim(), password, azione, giornata: Number(giornata), ...extra,
    })

  const preparaAnteprima = async () => {
    reset()
    setFase('anteprima')
    try {
      const res = await chiama('anteprima')
      setAnteprima(res)
      setCambio(res.cambio_eur ?? null)
      const primo = res.modelli.find(m => m.entro_tetto) ?? res.modelli[0]
      setModello(primo?.modello ?? null)
    } catch (err) {
      setErrore(err.message)
    } finally {
      setFase(null)
    }
  }

  const conferma = async () => {
    setErrore(null)
    setFase('genera')
    try {
      const res = await chiama('genera', { modello, prompt_hash: anteprima.prompt_hash })
      setRisultato(res)
      setAnteprima(null)
    } catch (err) {
      setErrore(err.message)
    } finally {
      setFase(null)
    }
  }

  const scelto = anteprima?.modelli.find(m => m.modello === modello) ?? null

  return (
    <div className="animate-fade-up">
      <PageHeader
        label="AI"
        title="Genera articolo"
        subtitle="Commento goliardico alla giornata, scritto dall'AI con i dati reali, i profili dei fantallenatori e gli episodi storici della lega."
      />

      {/* ── Passo 1: scelta giornata e identità ───────────────── */}
      <div className="card p-6 max-w-2xl mb-6">
        <h3 className="font-semibold text-slate-200 mb-1 flex items-center gap-2">
          <Lock className="w-4 h-4 text-slate-400" /> Giornata e conferma identità
        </h3>
        <p className="text-xs text-slate-500 mb-5">
          Prima vedi il costo previsto (gratuito); l'AI viene interpellata solo dopo la tua conferma.
          Utenza e password sono verificate lato server (serve l'abilitazione AI).
        </p>

        <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-4">
          <div>
            <label htmlFor="art-giornata" className="text-xs text-slate-600 mb-1 block">Giornata</label>
            <select
              id="art-giornata" value={giornata}
              onChange={e => { setGiornata(e.target.value); reset() }}
              disabled={caricaGiornata || ultimaChiusa < 1}
              className="fanta-input cursor-pointer"
            >
              {ultimaChiusa < 1 && <option value="">Nessuna giornata chiusa</option>}
              {Array.from({ length: ultimaChiusa }, (_, i) => ultimaChiusa - i).map(g => (
                <option key={g} value={g}>Giornata {g}</option>
              ))}
            </select>
          </div>
          <div>
            <label htmlFor="art-utenza" className="text-xs text-slate-600 mb-1 block">Utenza</label>
            <input
              id="art-utenza" type="text" autoComplete="username"
              value={utenza} onChange={e => setUtenza(e.target.value)}
              className="fanta-input"
            />
          </div>
          <div>
            <label htmlFor="art-password" className="text-xs text-slate-600 mb-1 block">Password</label>
            <input
              id="art-password" type="password" autoComplete="current-password"
              value={password} onChange={e => setPassword(e.target.value)}
              className="fanta-input"
            />
          </div>
        </div>

        <button
          type="button"
          onClick={preparaAnteprima}
          disabled={!pronto}
          className="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-grass-500 hover:bg-grass-400
                     text-pitch-950 font-semibold text-sm transition-all duration-150 active:scale-95
                     disabled:opacity-40 disabled:cursor-not-allowed disabled:active:scale-100"
        >
          {fase === 'anteprima'
            ? <><Spinner size="sm" /> Calcolo in corso...</>
            : <><Calculator className="w-4 h-4" /> Calcola costo previsto (gratuito)</>}
        </button>

        {errore && (
          <div className="mt-4">
            <Avviso tono="red" icona={XCircle}>{errore}</Avviso>
          </div>
        )}
      </div>

      {/* ── Passo 2: anteprima del costo e conferma ───────────── */}
      {anteprima && (
        <div className="card p-6 max-w-2xl mb-6 space-y-5">
          <h3 className="font-semibold text-slate-200 flex items-center gap-2">
            <Calculator className="w-4 h-4 text-slate-400" /> Costo previsto — giornata {anteprima.giornata}
          </h3>

          <ul className="text-sm text-slate-400 space-y-1">
            <li>
              Dati: {anteprima.riepilogo.partite_campionato} partite di campionato
              {anteprima.riepilogo.partite_champions > 0 && `, ${anteprima.riepilogo.partite_champions} di Champions`}
              {anteprima.riepilogo.classifica_inclusa ? ', classifica aggiornata' : ', senza classifica'}.
            </li>
            <li>
              Profili usati: {anteprima.riepilogo.profili_usati}
              {anteprima.riepilogo.senza_profilo.length > 0 &&
                <span className="text-slate-500"> · senza profilo: {anteprima.riepilogo.senza_profilo.join(', ')}</span>}
            </li>
            <li>
              Episodi richiamati: {anteprima.riepilogo.episodi.length === 0
                ? 'nessuno'
                : anteprima.riepilogo.episodi.map(e => e.titolo).join(' · ')}
            </li>
            <li>
              Lunghezza: circa {anteprima.parole_target} parole (limite {anteprima.max_token} token di output).
            </li>
          </ul>

          <fieldset>
            <legend className="text-xs text-slate-600 mb-2">Modello</legend>
            <div className="space-y-2">
              {anteprima.modelli.map(m => (
                <label
                  key={m.modello}
                  className={`block rounded-lg border px-4 py-3 transition-colors ${
                    !m.entro_tetto
                      ? 'border-white/5 opacity-50 cursor-not-allowed'
                      : modello === m.modello
                        ? 'border-grass-500/50 bg-grass-500/5 cursor-pointer'
                        : 'border-white/10 hover:border-white/20 cursor-pointer'
                  }`}
                >
                  <div className="flex items-start gap-3">
                    <input
                      type="radio" name="modello" className="mt-1"
                      checked={modello === m.modello} disabled={!m.entro_tetto}
                      onChange={() => setModello(m.modello)}
                    />
                    <div className="min-w-0 flex-1">
                      <div className="flex flex-wrap items-baseline justify-between gap-x-4">
                        <span className="text-sm font-medium text-slate-200">{m.etichetta}</span>
                        <span className="text-sm text-slate-200">
                          circa <Importo usd={m.costo_tipico_usd} cambio={cambio} />
                        </span>
                      </div>
                      <div className="flex flex-wrap items-baseline justify-between gap-x-4 text-xs text-slate-500 mt-0.5">
                        <span>
                          {m.approssimato ? '≈ ' : ''}{m.token_input} token di input
                          {m.approssimato && ' (stima approssimativa)'}
                        </span>
                        <span>al massimo <Importo usd={m.costo_massimo_usd} cambio={cambio} /></span>
                      </div>
                      {!m.entro_tetto && (
                        <p className="text-xs text-red-300 mt-1">Supererebbe il tetto mensile di spesa.</p>
                      )}
                    </div>
                  </div>
                </label>
              ))}
            </div>
          </fieldset>

          <p className="text-xs text-slate-500">
            Spesa AI del mese: <Importo usd={anteprima.spesa_mese_usd} cambio={cambio} />
            {anteprima.tetto_mensile_usd != null && <> su un tetto di <Importo usd={anteprima.tetto_mensile_usd} cambio={cambio} /></>}.
            Il costo tipico ipotizza un articolo di lunghezza media; il massimo è garantito dal limite di token.
          </p>

          {anteprima.modelli.some(m => m.approssimato) && (
            <Avviso>Il conteggio ufficiale dei token non ha risposto: l'input è una stima approssimativa.</Avviso>
          )}

          <details className="group">
            <summary className="cursor-pointer text-xs text-slate-500 hover:text-slate-300 flex items-center gap-1 select-none">
              <ChevronDown className="w-3 h-3 transition-transform group-open:rotate-180" />
              Vedi i dati inviati all'AI
            </summary>
            <pre className="mt-2 max-h-72 overflow-auto rounded-lg bg-black/30 p-3 text-[11px] leading-relaxed text-slate-400 whitespace-pre-wrap break-words">
              {anteprima.prompt_utente}
            </pre>
          </details>

          <button
            type="button"
            onClick={conferma}
            disabled={!scelto || !scelto.entro_tetto || !!fase}
            className="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-grass-500 hover:bg-grass-400
                       text-pitch-950 font-semibold text-sm transition-all duration-150 active:scale-95
                       disabled:opacity-40 disabled:cursor-not-allowed disabled:active:scale-100"
          >
            {fase === 'genera'
              ? <><Spinner size="sm" /> Generazione in corso (può richiedere un minuto)...</>
              : <><Sparkles className="w-4 h-4" /> Conferma e genera{scelto ? ` — circa ${fmtUsd(scelto.costo_tipico_usd)}` : ''}</>}
          </button>
        </div>
      )}

      {/* ── Risultato: bozza generata ─────────────────────────── */}
      {risultato && (
        <div className="card p-6 max-w-2xl">
          <div className="flex flex-wrap items-center gap-2 mb-4">
            <span className="stat-pill">Bozza</span>
            <span className="text-xs text-slate-500">
              Giornata {risultato.articolo.giornata} · {risultato.articolo.modello}
            </span>
          </div>

          <h3 className="text-xl font-bold text-slate-100 leading-snug">{risultato.articolo.titolo}</h3>
          {risultato.articolo.sommario && (
            <p className="text-sm italic text-slate-400 mt-2">{risultato.articolo.sommario}</p>
          )}
          <div className="mt-4 text-sm text-slate-300 leading-relaxed whitespace-pre-line">
            {risultato.articolo.testo}
          </div>

          <div className="mt-5 space-y-3">
            {risultato.articolo.troncato && (
              <Avviso>
                Il testo è stato interrotto perché ha raggiunto il limite di token: la parte finale
                (probabilmente le pagelle) potrebbe mancare.
              </Avviso>
            )}
            {!risultato.formato_ok && (
              <Avviso>Il modello non ha rispettato il formato atteso: titolo e sommario potrebbero non essere separati bene.</Avviso>
            )}
            <Avviso tono="green" icona={CheckCircle2}>
              Bozza salvata (n. {risultato.articolo.id}). Costo reale: <Importo usd={risultato.costo_usd} cambio={cambio} />
              {' '}— previsto <Importo usd={risultato.costo_stimato_usd} cambio={cambio} />;
              token usati {risultato.token_input} in / {risultato.token_output} out.
            </Avviso>
            <p className="text-xs text-slate-500">
              La modifica e la pubblicazione delle bozze arriveranno nella prossima fase.
            </p>
          </div>

          <button
            type="button"
            onClick={reset}
            className="mt-5 inline-flex items-center gap-2 px-4 py-2.5 rounded-lg border border-white/10
                       text-slate-300 hover:text-white hover:border-white/20 font-medium text-sm
                       transition-all duration-150 active:scale-95"
          >
            <Newspaper className="w-4 h-4" /> Nuova anteprima
          </button>
        </div>
      )}
    </div>
  )
}
