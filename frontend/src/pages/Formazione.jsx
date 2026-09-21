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
 * (scelta modulo, drag&drop dei titolari, riordino panchina) usando la
 * rosa reale della squadra dell'utente (endpoint squadre.php, già
 * esistente e non modificato).
 *
 * Quando backend e DB saranno pronti, basterà collegare il pulsante
 * "Salva" alla API di persistenza: la UI è già predisposta.
 */
export default function Formazione() {
  const { stagione, utente } = useApp()
  const id_squadra = utente?.id

  const { data: squadra, loading, error } = useFetch(
    () => getSquadra(stagione, id_squadra),
    [stagione, id_squadra]
  )

  const rosa = squadra?.rosa ?? []

  const [modulo, setModulo]     = useState(MODULO_DEFAULT)
  const [titolari, setTitolari] = useState([])
  const [panchina, setPanchina] = useState([])

  // Quando arriva la rosa, tutti i giocatori partono in panchina,
  // pronti per essere trascinati in campo.
  useEffect(() => {
    if (rosa.length) {
      setTitolari([])
      setPanchina(rosa.map(p => p.id))
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [rosa.length])

  const attesi = conteggioAtteso(modulo)
  const conteggio = { '1': 0, '2': 0, '3': 0, '4': 0 }
  titolari.forEach(id => {
    const p = rosa.find(r => r.id === id)
    if (p) conteggio[String(p.ruolo)]++
  })
  const formazioneCompleta = titolari.length === 11 &&
    JSON.stringify(conteggio) === JSON.stringify(attesi)

  if (loading) return <LoadingState label="Caricamento rosa..." />
  if (error)   return <ErrorState message={error} />

  return (
    <div className="animate-fade-up">
      <PageHeader
        label="Gestione squadra · anteprima"
        title="Formazione"
        subtitle="Scegli il modulo e trascina i giocatori della rosa in campo per provare l'interazione. Il salvataggio sul server sarà attivato in una fase successiva."
      />

      <div className="mb-4 flex items-start gap-2 rounded-lg border border-gold-500/20 bg-gold-500/5 px-3 py-2.5">
        <FlaskConical className="w-4 h-4 text-gold-400 flex-shrink-0 mt-0.5" />
        <p className="text-xs text-gold-200/80">
          Anteprima funzionale: la formazione composta qui non viene ancora salvata.
          Puoi provare liberamente modulo, posizionamento titolari e ordine della panchina.
        </p>
      </div>

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
    </div>
  )
}
