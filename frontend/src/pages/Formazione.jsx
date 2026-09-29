import { useEffect, useState } from 'react'
import { useApp } from '../context/AppContext'
import { useFetch } from '../hooks/useFetch'
import { getSquadra, getGiornataCorrente, getFormazione, saveFormazione } from '../api/client'
import { PageHeader, LoadingState, ErrorState } from '../components/ui'
import { FormationBuilder } from '../components/FormationBuilder'
import { ROLE_LABEL, MODULI_VALIDI, conteggioAtteso, getSlotsForModulo } from '../lib/pitchLayout'
import { CalendarDays, CheckCircle2, AlertTriangle, Save, Loader2, Lock } from 'lucide-react'

const MODULO_DEFAULT = '4-4-2'

// Spiega perché l'email di conferma non è stata inviata (vedi
// backend/mailer.php per l'elenco completo dei motivi restituiti).
const MOTIVO_EMAIL_LABEL = {
  email_non_configurata: "l'allenatore non ha un indirizzo email configurato",
  email_non_valida: "l'indirizzo email configurato non è valido",
  invio_fallito: 'il server non è riuscito a inviarla (probabile limitazione lato hosting)',
  formazione_incompleta: 'la formazione non risulta completa',
  squadra_non_trovata: 'squadra non trovata',
}

/**
 * Pagina "Formazione".
 *
 * La formazione si riferisce sempre alla giornata di riferimento della
 * stagione corrente: la prima giornata successiva all'ultima già chiusa
 * (calcolata lato server da giornata_corrente.php, in base a
 * NEW_CALENDARIO_CK). Squadra e giornata di riferimento sono esposte in
 * testa alla pagina.
 *
 * Caricamento/salvataggio sono collegati a formazioni.php. La tabella
 * NEW_FORMAZIONI ha un solo campo utile per la posizione (MAGLIA, un
 * intero libero): usiamo quel numero per codificare anche panchina e
 * tribuna, senza bisogno di toccare lo schema del database:
 * - maglia 1..11  → titolare, nell'ordine di slot del modulo (portiere,
 *   poi difensori/centrocampisti/attaccanti da sinistra a destra);
 * - maglia 12, 13, ... → panchina, nell'ordine in cui compare nell'elenco
 *   (così il riordino manuale della panchina viene preservato);
 * - tribuna → NON viene salvata affatto. In lettura, ogni giocatore della
 *   rosa che non compare tra le righe restituite da formazioni.php
 *   (né come titolare né come panchinaro) viene considerato "in tribuna".
 *
 * - GET  al mount (quando si conosce la giornata di riferimento) per
 *   precompilare modulo, titolari, panchina e tribuna secondo la
 *   codifica sopra, se per quella giornata esiste già una formazione
 *   salvata.
 * - POST quando l'utente clicca "Salva formazione" (solo se completa).
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
 * rosa e la giornata di riferimento: la corrispondenza id-squadra ↔
 * id-utente, infatti, è garantita solo all'interno della stessa stagione.
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

  // ── Giornata di riferimento: prima giornata successiva all'ultima chiusa ──
  const { data: giornataInfo, loading: loadingGiornata, error: errorGiornata } = useFetch(
    ultimaStagione !== null ? () => getGiornataCorrente(ultimaStagione) : null,
    [ultimaStagione]
  )
  const giornata = giornataInfo?.giornata ?? null
  // Caso limite: la stagione è terminata (tutte le giornate del calendario
  // sono già chiuse) e non c'è una giornata "aperta" su cui schierarsi.
  const nessunaGiornataAperta = !!giornataInfo &&
    giornataInfo.ultima_calendario > 0 &&
    giornata !== null &&
    giornata <= giornataInfo.ultima_chiusa

  // ── Formazione già salvata per squadra + giornata (se esiste) ──────
  const {
    data: formazioneSalvata, error: errorFormazione,
  } = useFetch(
    idSquadra !== null && ultimaStagione !== null && giornata !== null
      ? () => getFormazione(ultimaStagione, giornata, idSquadra)
      : null,
    [ultimaStagione, idSquadra, giornata]
  )

  const [modulo, setModulo]     = useState(MODULO_DEFAULT)
  const [titolari, setTitolari] = useState([])
  const [panchina, setPanchina] = useState([])
  const [tribuna, setTribuna]   = useState([])

  const [saving, setSaving]             = useState(false)
  const [saveError, setSaveError]       = useState(null)
  const [justSaved, setJustSaved]       = useState(false)
  const [emailInviata, setEmailInviata] = useState(false)
  const [motivoEmail, setMotivoEmail]   = useState(null)

  // ── Inizializza/ricarica lo stato locale quando arrivano rosa e
  //    formazione salvata (per la giornata di riferimento corrente) ──
  useEffect(() => {
    if (rosa.length === 0) return
    if (formazioneSalvata === null) return // risposta non ancora arrivata

    setSaving(false)
    setSaveError(null)
    setJustSaved(false)
    setEmailInviata(false)
    setMotivoEmail(null)

    if (!Array.isArray(formazioneSalvata) || formazioneSalvata.length === 0) {
      // Nessuna formazione salvata per questa giornata: si riparte da zero.
      setModulo(MODULO_DEFAULT)
      setTitolari([])
      setPanchina(rosa.map(p => p.id))
      setTribuna([])
      return
    }

    const byId = Object.fromEntries(rosa.map(p => [p.id, p]))

    // f.MAGLIA codifica la posizione: 1..11 = titolare (nello slot
    // corrispondente, da portiere a attaccanti, sinistra→destra),
    // 12+ = panchina nell'ordine salvato. Chi non compare affatto tra le
    // righe restituite è considerato in tribuna.
    const ordinati = [...formazioneSalvata].sort((a, b) => a.maglia - b.maglia)
    const idsTitolari = ordinati.filter(r => r.maglia <= 11).map(r => r.id_giocatore).filter(id => byId[id])
    const idsPanchina = ordinati.filter(r => r.maglia >= 12).map(r => r.id_giocatore).filter(id => byId[id])

    const conteggioSalvato = { '1': 0, '2': 0, '3': 0, '4': 0 }
    idsTitolari.forEach(id => conteggioSalvato[String(byId[id].ruolo)]++)
    const moduloSalvato = MODULI_VALIDI.find(
      m => JSON.stringify(conteggioAtteso(m)) === JSON.stringify(conteggioSalvato)
    )

    const inFormazione = new Set([...idsTitolari, ...idsPanchina])

    setModulo(moduloSalvato ?? MODULO_DEFAULT)
    setTitolari(idsTitolari)
    setPanchina(idsPanchina)
    // Chi era in rosa ma non compare né tra i titolari né in panchina
    // (nella formazione letta) va in tribuna.
    setTribuna(rosa.filter(p => !inFormazione.has(p.id)).map(p => p.id))
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [idSquadra, giornata, rosa.length, formazioneSalvata])

  const attesi = conteggioAtteso(modulo)
  const conteggio = { '1': 0, '2': 0, '3': 0, '4': 0 }
  titolari.forEach(id => {
    const p = rosa.find(r => r.id === id)
    if (p) conteggio[String(p.ruolo)]++
  })
  const formazioneCompleta = titolari.length === 11 &&
    JSON.stringify(conteggio) === JSON.stringify(attesi)

  const readOnly = nessunaGiornataAperta

  // Qualsiasi modifica successiva a un salvataggio invalida il messaggio
  // "salvata" e l'eventuale errore precedente.
  function handleFormationChange(t, p, tr) {
    setTitolari(t); setPanchina(p); setTribuna(tr)
    setJustSaved(false); setEmailInviata(false); setMotivoEmail(null); setSaveError(null)
  }
  function handleModuloChange(m) {
    setModulo(m)
    setJustSaved(false); setEmailInviata(false); setMotivoEmail(null); setSaveError(null)
  }

  // Costruisce il payload per formazioni.php, un elemento per ciascun
  // giocatore da salvare, codificando la posizione nel campo "maglia":
  // - titolari: maglia 1..11, secondo la posizione dello slot del modulo
  //   corrente (portiere, poi difensori/centrocampisti/attaccanti da
  //   sinistra a destra);
  // - panchina: maglia 12, 13, ... nello stesso ordine dell'elenco, per
  //   preservare l'eventuale riordino manuale al prossimo caricamento;
  // - tribuna: esclusa di proposito. Non avendo una riga salvata, al
  //   prossimo caricamento verrà ricostruita come "tutto ciò che è in
  //   rosa ma non compare nella formazione letta".
  function buildGiocatoriPayload() {
    const slots = getSlotsForModulo(modulo)
    const cursor = { '1': 0, '2': 0, '3': 0, '4': 0 }
    const byRuolo = { '1': [], '2': [], '3': [], '4': [] }
    titolari.forEach(id => {
      const p = rosa.find(r => r.id === id)
      if (p) byRuolo[String(p.ruolo)].push(id)
    })
    const titolariPayload = slots.map((slot, i) => ({
      id: byRuolo[slot.ruolo][cursor[slot.ruolo]++],
      maglia: i + 1,
    }))
    const panchinaPayload = panchina.map((id, i) => ({ id, maglia: 12 + i }))
    return [...titolariPayload, ...panchinaPayload]
  }

  async function handleSalva() {
    if (!formazioneCompleta || saving || readOnly || giornata === null) return
    setSaving(true)
    setSaveError(null)
    try {
      const res = await saveFormazione(ultimaStagione, giornata, idSquadra, buildGiocatoriPayload())
      setEmailInviata(!!res?.email_inviata)
      setMotivoEmail(res?.motivo_email ?? null)
      setJustSaved(true)
    } catch (e) {
      setSaveError(e.message ?? 'Errore durante il salvataggio')
    } finally {
      setSaving(false)
    }
  }

  if (idSquadra === null || ultimaStagione === null) return <LoadingState label="Caricamento..." />

  return (
    <div className="animate-fade-up">
      <PageHeader
        label="Gestione squadra"
        title="Formazione"
        subtitle={
          squadra?.nome
            ? `${squadra.nome} · stagione ${ultimaStagione}/${ultimaStagione + 1}`
            : `Stagione ${ultimaStagione}/${ultimaStagione + 1}`
        }
      >
        <div className="flex items-center gap-2 rounded-lg border border-white/10 bg-pitch-800 px-3 py-1.5">
          <CalendarDays className="w-4 h-4 text-grass-400 flex-shrink-0" />
          <span className="text-sm font-mono text-slate-200 whitespace-nowrap">
            {loadingGiornata
              ? 'Giornata —'
              : errorGiornata || giornata === null
                ? 'Giornata n.d.'
                : `Giornata ${giornata}`}
          </span>
        </div>
      </PageHeader>

      {readOnly && (
        <div className="mb-4 flex items-start gap-2 rounded-lg border border-white/10 bg-white/[0.03] px-3 py-2.5">
          <Lock className="w-4 h-4 text-slate-500 flex-shrink-0 mt-0.5" />
          <p className="text-xs text-slate-400">
            La stagione non ha più giornate aperte: la formazione non può essere modificata.
          </p>
        </div>
      )}

      {loadingRosa ? (
        <LoadingState label="Caricamento rosa..." />
      ) : errorRosa ? (
        <ErrorState message={errorRosa} />
      ) : errorGiornata ? (
        <ErrorState message={errorGiornata} />
      ) : errorFormazione ? (
        <ErrorState message={errorFormazione} />
      ) : loadingGiornata || !Array.isArray(formazioneSalvata) ? (
        <LoadingState label="Caricamento formazione..." />
      ) : (
        <>
          <FormationBuilder
            rosa={rosa}
            modulo={modulo}
            onModuloChange={handleModuloChange}
            titolariIds={titolari}
            panchinaIds={panchina}
            tribunaIds={tribuna}
            onChange={handleFormationChange}
            readOnly={readOnly}
          />

          {/* Riepilogo + salvataggio */}
          <div className="card mt-6 p-4">
            <div className="flex items-center justify-between mb-3 gap-3 flex-wrap">
              <h3 className="text-sm font-semibold text-slate-300">Riepilogo formazione</h3>
              <div className="flex items-center gap-3">
                {formazioneCompleta ? (
                  <span className="flex items-center gap-1.5 text-xs text-grass-400">
                    <CheckCircle2 className="w-3.5 h-3.5" /> Modulo {modulo} completo
                  </span>
                ) : (
                  <span className="flex items-center gap-1.5 text-xs text-gold-400">
                    <AlertTriangle className="w-3.5 h-3.5" /> {titolari.length}/11 titolari inseriti
                  </span>
                )}
                {!readOnly && (
                  <button
                    type="button"
                    onClick={handleSalva}
                    disabled={!formazioneCompleta || saving}
                    className="btn-primary text-xs px-4 py-2 disabled:opacity-40 disabled:cursor-not-allowed disabled:active:scale-100"
                  >
                    {saving ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : <Save className="w-3.5 h-3.5" />}
                    {saving ? 'Salvataggio...' : 'Salva formazione'}
                  </button>
                )}
              </div>
            </div>

            {justSaved && (
              <p className="flex items-center gap-1.5 text-xs text-grass-400 mb-3">
                <CheckCircle2 className="w-3.5 h-3.5" /> Formazione salvata per la giornata {giornata}.
                {emailInviata
                  ? ' Email di conferma inviata.'
                  : ` (email di conferma non inviata: ${MOTIVO_EMAIL_LABEL[motivoEmail] ?? 'motivo sconosciuto'})`}
              </p>
            )}
            {saveError && (
              <p className="flex items-center gap-1.5 text-xs text-red-400 mb-3">
                <AlertTriangle className="w-3.5 h-3.5" /> {saveError}
              </p>
            )}

            <div className="grid grid-cols-2 sm:grid-cols-4 gap-2 text-xs">
              {['1', '2', '3', '4'].map(r => (
                <div key={r} className="rounded-lg bg-white/[0.03] px-3 py-2 flex items-center justify-between">
                  <span className="text-slate-500">{ROLE_LABEL[r]}</span>
                  <span className="font-mono text-slate-300">{conteggio[r]} / {attesi[r]}</span>
                </div>
              ))}
            </div>
            {tribuna.length > 0 && (
              <p className="text-[11px] text-slate-600 mt-3">
                {tribuna.length} giocator{tribuna.length === 1 ? 'e' : 'i'} in tribuna (esclus{tribuna.length === 1 ? 'o' : 'i'} da campo e panchina).
              </p>
            )}
          </div>
        </>
      )}
    </div>
  )
}
