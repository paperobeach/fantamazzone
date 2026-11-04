import { useState, useRef } from 'react'
import { adminAggiornaListaGiocatori } from '../api/client'
import { PageHeader, RoleBadge } from '../components/ui'
import { useCredenzialiAdmin, CardCredenziali, Avviso, Pulsante } from '../components/ApiFootballUi'
import { UploadCloud, FileSpreadsheet, CheckCircle2, AlertTriangle, X, Search, UserPlus, UserMinus, RotateCcw } from 'lucide-react'

// ============================================================
// "Aggiorna lista giocatori" — carica il listone ufficiale (xlsx, foglio "Tutti")
// e lo confronta con i giocatori della stagione in corso:
//   - NUOVI        id mai visti: inseriti come svincolati;
//   - FUORI LISTA  giocatori non più nel file: restano nella lista giocatori (le
//                  statistiche restano consultabili) ma non sono più acquistabili;
//                  se sono in una rosa diventano "giocatore ceduto" e non sono
//                  utilizzabili in formazione (fissi in tribuna);
//   - RIENTRATI    giocatori già fuori lista che ricompaiono nel file.
// Prima "Analizza file" (anteprima, non scrive nulla), poi "Applica aggiornamento".
// ============================================================

function Conteggio({ valore, etichetta, colore = 'text-white' }) {
  return (
    <div className="rounded-xl bg-white/[0.03] border border-white/5 px-3 py-3 text-center">
      <p className={`text-display text-2xl font-bold ${colore}`}>{valore}</p>
      <p className="text-[10px] text-mono uppercase tracking-widest text-slate-600 mt-1">{etichetta}</p>
    </div>
  )
}

/** Elenco ripiegabile di giocatori; `extra` mostra una colonna aggiuntiva. */
function ElencoGiocatori({ titolo, icona: Icona, colore, righe, extra, vuoto }) {
  return (
    <details className="card mb-4" open={righe.length > 0 && righe.length <= 25}>
      <summary className="px-4 py-3 cursor-pointer flex items-center gap-2 text-sm font-semibold text-slate-200">
        <Icona className={`w-4 h-4 ${colore}`} />
        {titolo}
        <span className="ml-auto text-xs font-mono text-slate-500">{righe.length}</span>
      </summary>
      {righe.length === 0 ? (
        <p className="px-4 pb-4 text-xs text-slate-600">{vuoto}</p>
      ) : (
        <div className="overflow-x-auto max-h-96 overflow-y-auto border-t border-white/5">
          <table className="w-full text-sm">
            <thead>
              <tr className="text-left text-xs text-slate-600 uppercase tracking-widest">
                <th className="py-2 px-4">Ruolo</th>
                <th className="py-2 pr-4">Giocatore</th>
                <th className="py-2 pr-4">Serie A</th>
                <th className="py-2 pr-4">Cod.</th>
                {extra && <th className="py-2 pr-4">{extra.titolo}</th>}
              </tr>
            </thead>
            <tbody>
              {righe.map(g => (
                <tr key={g.id} className="border-t border-white/[0.03]">
                  <td className="py-1.5 px-4"><RoleBadge ruolo={g.ruolo} /></td>
                  <td className="py-1.5 pr-4 text-slate-300">{g.nome}</td>
                  <td className="py-1.5 pr-4 text-xs text-slate-500">{g.squadra_serie_a || '—'}</td>
                  <td className="py-1.5 pr-4 text-xs font-mono text-slate-600">{g.id}</td>
                  {extra && <td className="py-1.5 pr-4 text-xs text-slate-400">{extra.valore(g)}</td>}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </details>
  )
}

export default function AggiornaListaGiocatori() {
  const cred = useCredenzialiAdmin()
  const [file, setFile] = useState(null)
  const [fase, setFase] = useState(null)          // null | 'analisi' | 'applicazione'
  const [errori, setErrori] = useState([])        // errori di validazione del file { riga, campo, messaggio }
  const [errore, setErrore] = useState(null)      // errore generico (credenziali, server...)
  const [anteprima, setAnteprima] = useState(null)
  const [esito, setEsito] = useState(null)
  const [conferma, setConferma] = useState(false)
  const inputRef = useRef(null)

  const pulisciRisultati = () => { setErrori([]); setErrore(null); setAnteprima(null); setEsito(null); setConferma(false) }

  const resetFile = () => {
    setFile(null)
    pulisciRisultati()
    if (inputRef.current) inputRef.current.value = ''
  }

  const scegliFile = (e) => {
    pulisciRisultati()
    setFile(e.target.files?.[0] ?? null)
  }

  async function esegui(azione) {
    if (!file) { setErrore('Seleziona un file Excel (.xlsx)'); return }
    setErrori([]); setErrore(null); setFase(azione === 'applica' ? 'applicazione' : 'analisi')
    try {
      const res = await adminAggiornaListaGiocatori(cred.stagioneRif, file, cred.auth, azione)
      if (azione === 'applica') {
        setEsito(res)
        setAnteprima(null)
        setConferma(false)
        setFile(null)
        if (inputRef.current) inputRef.current.value = ''
      } else {
        setAnteprima(res)
        setEsito(null)
      }
    } catch (err) {
      if (err.details?.length) setErrori(err.details)
      else setErrore(err.message)
      setAnteprima(null)
      setConferma(false)
    } finally {
      setFase(null)
    }
  }

  const nessunaModifica = anteprima
    && anteprima.nuovi.length === 0 && anteprima.fuori_lista.length === 0 && anteprima.rientrati.length === 0
  const inRosa = anteprima ? anteprima.fuori_lista.filter(g => g.dove === 'ROSA').length : 0

  return (
    <div className="animate-fade-up">
      <PageHeader
        label="Gestione"
        title="Aggiorna lista giocatori"
        subtitle={`Carica il listone aggiornato (foglio "Tutti") per allineare i giocatori della stagione ${cred.stagioneRif ?? ''}/${cred.stagioneRif ? String(cred.stagioneRif + 1).slice(-2) : ''}: nuovi arrivi e giocatori non più in lista.`}
      />

      <CardCredenziali cred={cred} />

      <div className="card p-6 max-w-2xl">
        <label className="text-xs text-slate-600 mb-1 block">File Excel (foglio "Tutti": Id, R, RM, Nome, Squadra…)</label>

        {!file ? (
          <label
            htmlFor="file-lista"
            className="flex flex-col items-center justify-center gap-2 px-4 py-8 rounded-lg
                       border border-dashed border-white/15 text-slate-500
                       hover:border-grass-500/40 hover:text-slate-300 cursor-pointer transition-colors"
          >
            <UploadCloud className="w-6 h-6" />
            <span className="text-sm">Trascina qui il file o clicca per selezionarlo</span>
            <span className="text-[10px] text-mono uppercase tracking-widest text-slate-700">Solo .xlsx</span>
            <input id="file-lista" ref={inputRef} type="file" accept=".xlsx" onChange={scegliFile} className="hidden" />
          </label>
        ) : (
          <div className="flex items-center gap-3 px-4 py-3 rounded-lg bg-pitch-900 border border-white/10">
            <FileSpreadsheet className="w-4 h-4 text-grass-400 flex-shrink-0" />
            <span className="text-sm text-slate-300 truncate flex-1">{file.name}</span>
            <button type="button" onClick={resetFile} className="p-1 rounded text-slate-600 hover:text-red-400 transition-colors" aria-label="Rimuovi file">
              <X className="w-3.5 h-3.5" />
            </button>
          </div>
        )}

        <div className="mt-5">
          <Pulsante primario icona={Search} onClick={() => esegui('anteprima')}
                    disabled={!cred.pronto || !file || fase !== null} caricando={fase === 'analisi'}>
            Analizza file
          </Pulsante>
          <p className="text-[11px] text-slate-600 mt-2">L'analisi non modifica nulla: mostra cosa cambierebbe prima di applicare.</p>
        </div>
      </div>

      {errore && (
        <div className="max-w-2xl mt-6"><Avviso tono="red">{errore}</Avviso></div>
      )}

      {errori.length > 0 && (
        <div className="card p-6 max-w-2xl mt-6">
          <div className="mb-4">
            <Avviso tono="red">Il file contiene {errori.length} errore/i. Nessun dato è stato modificato: correggi il file e ricaricalo.</Avviso>
          </div>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="text-left text-xs text-slate-600 uppercase tracking-widest">
                  <th className="py-2 pr-4">Riga</th><th className="py-2 pr-4">Campo</th><th className="py-2">Messaggio</th>
                </tr>
              </thead>
              <tbody>
                {errori.map((er, i) => (
                  <tr key={i} className="border-t border-white/[0.03]">
                    <td className="py-2 pr-4 text-slate-500">{er.riga || '-'}</td>
                    <td className="py-2 pr-4 text-slate-400">{er.campo || '-'}</td>
                    <td className="py-2 text-slate-300">{er.messaggio}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {anteprima && (
        <div className="mt-6 max-w-4xl">
          <div className="card p-6 mb-4">
            <h3 className="font-semibold text-slate-200 mb-1">Anteprima — non ancora applicata</h3>
            <p className="text-xs text-slate-500 mb-4">
              {anteprima.titolo_file ? `${anteprima.titolo_file} · ` : ''}{anteprima.totale_file} giocatori nel file, {anteprima.totale_db} nel database.
            </p>
            <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
              <Conteggio valore={anteprima.nuovi.length} etichetta="Nuovi (svincolati)" colore="text-grass-400" />
              <Conteggio valore={anteprima.fuori_lista.length} etichetta="Fuori lista" colore="text-red-400" />
              <Conteggio valore={anteprima.rientrati.length} etichetta="Rientrati" colore="text-gold-400" />
              <Conteggio valore={anteprima.invariati} etichetta="Invariati" />
            </div>
          </div>

          {anteprima.avvisi.map((a, i) => (
            <div key={i} className="mb-4"><Avviso>{a}</Avviso></div>
          ))}

          <ElencoGiocatori
            titolo="Nuovi giocatori — saranno inseriti come svincolati"
            icona={UserPlus} colore="text-grass-400" righe={anteprima.nuovi}
            vuoto="Nessun nuovo giocatore."
          />
          <ElencoGiocatori
            titolo="Non più in lista — restano nella lista giocatori ma non acquistabili"
            icona={UserMinus} colore="text-red-400" righe={anteprima.fuori_lista}
            extra={{ titolo: 'Situazione', valore: g => g.dove === 'ROSA' ? `In rosa: ${g.squadra_lega} → "giocatore ceduto"` : 'Svincolato → non acquistabile' }}
            vuoto="Nessun giocatore uscito dalla lista."
          />
          <ElencoGiocatori
            titolo="Rientrati in lista — tornano acquistabili / utilizzabili"
            icona={RotateCcw} colore="text-gold-400" righe={anteprima.rientrati}
            extra={{ titolo: 'Situazione', valore: g => g.dove === 'ROSA' ? 'In rosa' : 'Svincolato' }}
            vuoto="Nessun giocatore rientrato."
          />

          {nessunaModifica ? (
            <Avviso tono="green" icona={CheckCircle2}>La lista giocatori è già allineata al file: non c'è nulla da applicare.</Avviso>
          ) : (
            <div className="card p-6">
              {inRosa > 0 && (
                <div className="mb-4">
                  <Avviso>
                    {inRosa} giocator{inRosa === 1 ? 'e' : 'i'} {inRosa === 1 ? 'è' : 'sono'} in una rosa: {inRosa === 1 ? 'verrà' : 'verranno'} mostrat{inRosa === 1 ? 'o' : 'i'} come
                    "giocatore ceduto", non {inRosa === 1 ? 'sarà utilizzabile' : 'saranno utilizzabili'} in formazione e
                    {inRosa === 1 ? ' verrà tolto' : ' verranno tolti'} dalle formazioni già inserite per le giornate non ancora chiuse.
                  </Avviso>
                </div>
              )}
              {!conferma ? (
                <Pulsante primario icona={AlertTriangle} onClick={() => setConferma(true)} disabled={fase !== null}>
                  Applica aggiornamento…
                </Pulsante>
              ) : (
                <div className="flex flex-wrap items-center gap-3">
                  <span className="text-sm text-slate-300">Confermi l'aggiornamento della lista giocatori?</span>
                  <Pulsante primario icona={CheckCircle2} onClick={() => esegui('applica')} caricando={fase === 'applicazione'} disabled={fase !== null}>
                    Sì, applica
                  </Pulsante>
                  <Pulsante onClick={() => setConferma(false)} disabled={fase !== null}>Annulla</Pulsante>
                </div>
              )}
            </div>
          )}
        </div>
      )}

      {esito && (
        <div className="card p-6 max-w-2xl mt-6">
          <div className="flex items-center gap-2 mb-4 text-green-300">
            <CheckCircle2 className="w-4 h-4" />
            <span className="font-medium">Lista giocatori aggiornata</span>
          </div>
          <div className="grid grid-cols-3 gap-3">
            <Conteggio valore={esito.esito.nuovi} etichetta="Nuovi svincolati" colore="text-grass-400" />
            <Conteggio valore={esito.esito.fuori_lista} etichetta="Usciti di lista" colore="text-red-400" />
            <Conteggio valore={esito.esito.rientrati} etichetta="Rientrati" colore="text-gold-400" />
          </div>
          {esito.esito.fuori_lista > 0 && (
            <p className="text-xs text-slate-500 mt-4">
              Usciti di lista: {esito.esito.fuori_lista_in_rosa} in rosa ("giocatore ceduto") e {esito.esito.fuori_lista_svincolati} svincolati (non acquistabili).
              {esito.esito.formazioni_ripulite > 0 && ` Rimossi da ${esito.esito.formazioni_ripulite} righe di formazioni delle giornate aperte.`}
            </p>
          )}
          {esito.esito.raccordo && (
            <p className="text-xs text-slate-500 mt-2">Raccordo storico dei giocatori allineato per i nuovi arrivi (verifica eventuali omonimi da "Raccordo giocatori").</p>
          )}
        </div>
      )}
    </div>
  )
}
