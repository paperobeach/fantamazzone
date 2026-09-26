import { useEffect, useState } from 'react'
import { useApp } from '../context/AppContext'
import { useFetch } from '../hooks/useFetch'
import { getSquadra } from '../api/client'
import { PageHeader, LoadingState, ErrorState } from '../components/ui'
import { FormationBuilder } from '../components/FormationBuilder'
import { ROLE_LABEL, conteggioAtteso } from '../lib/pitchLayout'
import { FlaskConical, CheckCircle2, AlertTriangle } from 'lucide-react'

const MODULO_DEFAULT = '4-4-2'

/**
 * Pagina "Formazione" — FASE 1 (solo frontend).
 *
 * Nessuna chiamata a formazioni.php: la formazione non viene ancora
 * salvata lato server. Questa pagina serve a provare l'interazione
 * (scelta modulo, drag&drop dei titolari, riordino panchina) usando
 * dati reali letti da squadre.php (endpoint già esistente e non
 * modificato).
 *
 * La pagina è protetta (ProtectedRoute in App.jsx): "utente" è quindi
 * sempre presente. La squadra caricata è sempre e solo quella
 * dell'utente loggato — l'id squadra coincide con l'id utente (stesso
 * "ordine" usato in Inizializzazione stagione per NEW_SQUADRE e
 * NEW_UTENZE) — quindi non serve alcun selettore di squadra.
 *
 * IMPORTANTE: la sezione "Gestione squadra" deve sempre riferirsi
 * all'ULTIMA stagione disponibile (quella corrente), indipendentemente
 * dalla stagione "in navigazione" scelta nella sidebar per il resto
 * dell'app (classifica, calendario, ecc.), che l'utente può cambiare
 * in qualsiasi momento per consultare stagioni passate. Si usa quindi
 * sempre `ultimaStagione` dal context (mai `stagione`, né la stagione
 * salvata nell'utente al momento del login) per caricare la propria
 * rosa: la corrispondenza id-squadra ↔ id-utente, infatti, è garantita
 * solo all'interno della stessa stagione.
 *
 * Quando backend e DB saranno pronti, basterà collegare il pulsante
 * "Salva" alla API di persistenza: la UI è già predisposta.
 */
export default function Formazione() {
  const { utente, ultimaStagione } = useApp()

  const idSquadra = utente?.id ?? null

  // ── Rosa della squadra dell'utente loggato (sempre nell'ultima stagione) ──
  const { data: squadra, loading: loadingRosa, error: errorRosa } = useFetch(
    idSquadra !== null && ultimaStagione !== null ? () => getSquadra(ultimaStagione, idSquadra) : null,
    [ultimaStagione, idSquadra]
  )

  const rosa = squadra?.rosa ?? []

  const [modulo, setModulo]     = useState(MODULO_DEFAULT)
  const [titolari, setTitolari] = useState([])
  const [panchina, setPanchina] = useState([])

  // Ogni volta che cambia la squadra selezionata (o la sua rosa arriva),
  // si riparte da capo: tutti i giocatori in panchina, campo vuoto.
  useEffect(() => {
    setModulo(MODULO_DEFAULT)
    setTitolari([])
    setPanchina(rosa.map(p => p.id))
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [idSquadra, rosa.length])

  const attesi = conteggioAtteso(modulo)
  const conteggio = { '1': 0, '2': 0, '3': 0, '4': 0 }
  titolari.forEach(id => {
    const p = rosa.find(r => r.id === id)
    if (p) conteggio[String(p.ruolo)]++
  })
  const formazioneCompleta = titolari.length === 11 &&
    JSON.stringify(conteggio) === JSON.stringify(attesi)

  if (idSquadra === null || ultimaStagione === null) return <LoadingState label="Caricamento..." />

  return (
    <div className="animate-fade-up">
      <PageHeader
        label="Gestione squadra · anteprima"
        title="Formazione"
        subtitle={
          squadra?.nome
            ? `${squadra.nome} · stagione ${ultimaStagione}/${ultimaStagione + 1} — scegli il modulo e trascina i giocatori della rosa in campo per provare l'interazione. Il salvataggio sul server sarà attivato in una fase successiva.`
            : "Scegli il modulo e trascina i giocatori della rosa in campo per provare l'interazione. Il salvataggio sul server sarà attivato in una fase successiva."
        }
      />

      <div className="mb-4 flex items-start gap-2 rounded-lg border border-gold-500/20 bg-gold-500/5 px-3 py-2.5">
        <FlaskConical className="w-4 h-4 text-gold-400 flex-shrink-0 mt-0.5" />
        <p className="text-xs text-gold-200/80">
          Anteprima funzionale: la formazione composta qui non viene ancora salvata.
          Puoi provare liberamente modulo, posizionamento titolari e ordine della panchina.
        </p>
      </div>

      {loadingRosa ? (
        <LoadingState label="Caricamento rosa..." />
      ) : errorRosa ? (
        <ErrorState message={errorRosa} />
      ) : (
        <>
          <FormationBuilder
            rosa={rosa}
            modulo={modulo}
            onModuloChange={setModulo}
            titolariIds={titolari}
            panchinaIds={panchina}
            onChange={(t, p) => { setTitolari(t); setPanchina(p) }}
          />

          {/* Riepilogo locale, utile per verificare l'interazione senza backend */}
          <div className="card mt-6 p-4">
            <div className="flex items-center justify-between mb-3">
              <h3 className="text-sm font-semibold text-slate-300">Riepilogo formazione</h3>
              {formazioneCompleta ? (
                <span className="flex items-center gap-1.5 text-xs text-grass-400">
                  <CheckCircle2 className="w-3.5 h-3.5" /> Modulo {modulo} completo
                </span>
              ) : (
                <span className="flex items-center gap-1.5 text-xs text-gold-400">
                  <AlertTriangle className="w-3.5 h-3.5" /> {titolari.length}/11 titolari inseriti
                </span>
              )}
            </div>
            <div className="grid grid-cols-2 sm:grid-cols-4 gap-2 text-xs">
              {['1', '2', '3', '4'].map(r => (
                <div key={r} className="rounded-lg bg-white/[0.03] px-3 py-2 flex items-center justify-between">
                  <span className="text-slate-500">{ROLE_LABEL[r]}</span>
                  <span className="font-mono text-slate-300">{conteggio[r]} / {attesi[r]}</span>
                </div>
              ))}
            </div>
          </div>
        </>
      )}
    </div>
  )
}
