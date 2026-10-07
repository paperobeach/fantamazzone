import { useEffect, useState } from 'react'
import { useApp } from '../context/AppContext'
import { useFetch } from '../hooks/useFetch'
import { getSquadra, getGiornataCorrente, getFormazione, getFormazioneContesto, saveFormazione } from '../api/client'
import { PageHeader, LoadingState, ErrorState, TabBar } from '../components/ui'
import { FormationBuilder } from '../components/FormationBuilder'
import { ROLE_LABEL, MODULI_VALIDI, conteggioAtteso, getSlotsForModulo, ordinaPerRuoloDefault } from '../lib/pitchLayout'
import { CalendarDays, CheckCircle2, AlertTriangle, Save, Loader2, Lock, Info } from 'lucide-react'

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

const COMP_LABEL = { CAMP: 'Campionato', CHAMP: 'Champions' }

/**
 * Costruisce lo stato locale (modulo, titolari, panchina, tribuna) a
 * partire dalle righe restituite da formazioni.php (codifica MAGLIA
 * descritta sotto). Con `fonte` nulla restituisce la situazione di
 * default: campo vuoto e tutta la rosa in panchina, ordinata per ruolo.
 */
function costruisciStato(fonte, rosa) {
  const byId = Object.fromEntries(rosa.map(p => [p.id, p]))

  if (!fonte) {
    return {
      modulo: MODULO_DEFAULT,
      titolari: [],
      panchina: ordinaPerRuoloDefault(rosa.map(p => p.id), byId),
      tribuna: [],
    }
  }

  // f.MAGLIA codifica la posizione: 1..11 = titolare (nello slot
  // corrispondente, da portiere a attaccanti, sinistra→destra),
  // 12+ = panchina nell'ordine salvato. Chi non compare affatto tra le
  // righe restituite è considerato in tribuna.
  const ordinati = [...fonte].sort((a, b) => a.maglia - b.maglia)
  const idsTitolari = ordinati.filter(r => r.maglia <= 11).map(r => r.id_giocatore).filter(id => byId[id])
  const idsPanchina = ordinati.filter(r => r.maglia >= 12).map(r => r.id_giocatore).filter(id => byId[id])

  const conteggioSalvato = { '1': 0, '2': 0, '3': 0, '4': 0 }
  idsTitolari.forEach(id => conteggioSalvato[String(byId[id].ruolo)]++)
  const moduloSalvato = MODULI_VALIDI.find(
    m => JSON.stringify(conteggioAtteso(m)) === JSON.stringify(conteggioSalvato)
  )

  const inFormazione = new Set([...idsTitolari, ...idsPanchina])
  return {
    modulo: moduloSalvato ?? MODULO_DEFAULT,
    titolari: idsTitolari,
    panchina: idsPanchina,
    // Chi era in rosa ma non compare né tra i titolari né in panchina va in tribuna.
    tribuna: rosa.filter(p => !inFormazione.has(p.id)).map(p => p.id),
  }
}

/**
 * Pagina "Formazione".
 *
 * FORMAZIONE CHAMPIONS DISTINTA: se il parametro di stagione
 * FORMAZIONE_CHAMPIONS_DIVERSA è attivo e nella giornata la squadra gioca
 * un turno di Champions (formazioni.php?modo=contesto → separata_ammessa),
 * compaiono due schede, Campionato e Champions, ciascuna con la propria
 * formazione (la Champions parte da una copia di quella di campionato se
 * non ne è stata salvata una distinta). Al salvataggio si chiede "vuoi
 * salvare la formazione per entrambe le competizioni?" (default NO): se
 * sì, la formazione della scheda attiva viene salvata in entrambe.
 * Altrimenti la pagina funziona come sempre e la formazione vale per
 * entrambe le competizioni.
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
 *   salvata. Se NON esiste, si tenta di caricare quella della giornata
 *   precedente (stesso criterio di codifica). Se non esiste nemmeno
 *   quella (es. giornata 1, o nessuna formazione mai salvata), si parte
 *   dalla "situazione di default": campo vuoto e TUTTA la rosa in
 *   panchina, ordinata per ruolo — Portieri, Attaccanti, Centrocampisti,
 *   Difensori (vedi ordinaPerRuoloDefault in lib/pitchLayout.js). Lo
 *   stesso ordinamento si applica quando l'utente clicca "Svuota il
 *   campo" (logica nel componente FormationBuilder).
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
  const giornataCorrenteVuota = Array.isArray(formazioneSalvata) && formazioneSalvata.length === 0

  // ── Se la giornata corrente non ha ancora una formazione, si prova
  //    con quella della giornata precedente (stesso squadra/stagione) ──
  const { data: formazionePrecedente, error: errorFormazionePrecedente } = useFetch(
    idSquadra !== null && ultimaStagione !== null && giornata !== null &&
      giornata > 1 && giornataCorrenteVuota
      ? () => getFormazione(ultimaStagione, giornata - 1, idSquadra)
      : null,
    [ultimaStagione, idSquadra, giornata, giornataCorrenteVuota]
  )

  // Pronti a inizializzare lo stato locale solo quando sappiamo già se
  // "ripiegare" sulla giornata precedente serve davvero o no: se la
  // giornata corrente ha già una formazione, basta quella; altrimenti
  // serve anche la risposta della giornata precedente — a meno che non
  // si sia già alla giornata 1 (non c'è una "precedente" su cui
  // provare). Un eventuale errore su questo tentativo di ripiego non
  // deve bloccare la pagina all'infinito: si procede semplicemente con
  // la situazione di default, come se non ci fosse nulla da recuperare.
  const formazioniPronte = Array.isArray(formazioneSalvata) &&
    (!giornataCorrenteVuota || giornata <= 1 ||
      Array.isArray(formazionePrecedente) || !!errorFormazionePrecedente)

  // ── Formazione Champions distinta: ammessa per questa squadra/giornata? ──
  const { data: contesto, error: errorContesto } = useFetch(
    idSquadra !== null && ultimaStagione !== null && giornata !== null
      ? () => getFormazioneContesto(ultimaStagione, giornata, idSquadra)
      : null,
    [ultimaStagione, idSquadra, giornata]
  )
  // Un errore sul contesto non blocca la pagina: si procede come se la
  // formazione distinta non fosse ammessa (comportamento tradizionale).
  const contestoPronto = contesto !== null || !!errorContesto
  const separata = !!contesto?.separata_ammessa
  const champsInGiornataSenzaSeparata = !!contesto?.champions_in_giornata && !contesto?.separata_attiva

  const { data: champSalvata, error: errorChamp } = useFetch(
    separata ? () => getFormazione(ultimaStagione, giornata, idSquadra, 'CHAMP') : null,
    [ultimaStagione, idSquadra, giornata, separata]
  )
  const champPronta = !separata || Array.isArray(champSalvata) || !!errorChamp

  const tuttoPronto = formazioniPronte && contestoPronto && champPronta

  const statoIniziale = { modulo: MODULO_DEFAULT, titolari: [], panchina: [], tribuna: [] }
  const [comp, setComp]   = useState('CAMP')
  const [forms, setForms] = useState({ CAMP: statoIniziale, CHAMP: statoIniziale })
  const { modulo, titolari, panchina, tribuna } = forms[comp]

  const [saving, setSaving]             = useState(false)
  const [saveError, setSaveError]       = useState(null)
  const [justSaved, setJustSaved]       = useState(false)
  const [savedPer, setSavedPer]         = useState(['CAMP'])
  const [emailInviata, setEmailInviata] = useState(false)
  const [motivoEmail, setMotivoEmail]   = useState(null)
  const [confermaAperta, setConfermaAperta] = useState(false)
  const [perEntrambe, setPerEntrambe]   = useState(false)

  function resetEsito() {
    setJustSaved(false); setEmailInviata(false); setMotivoEmail(null); setSaveError(null)
  }

  // ── Inizializza/ricarica lo stato locale quando arrivano rosa e
  //    formazione salvata (per la giornata di riferimento corrente,
  //    con eventuale ripiego sulla giornata precedente) ──
  useEffect(() => {
    if (rosa.length === 0) return
    if (!tuttoPronto) return

    setSaving(false)
    resetEsito()
    setConfermaAperta(false)
    setPerEntrambe(false)
    setComp('CAMP')

    // Fonte dei dati, in ordine di priorità: giornata corrente, poi la
    // precedente, altrimenti nessuna (situazione di default).
    const fonte = formazioneSalvata.length > 0
      ? formazioneSalvata
      : (Array.isArray(formazionePrecedente) && formazionePrecedente.length > 0)
        ? formazionePrecedente
        : null

    const statoCamp = costruisciStato(fonte, rosa)
    // Champions: formazione distinta già salvata, altrimenti parte da
    // una copia di quella di campionato.
    const statoChamp = separata && Array.isArray(champSalvata) && champSalvata.length > 0
      ? costruisciStato(champSalvata, rosa)
      : statoCamp
    setForms({ CAMP: statoCamp, CHAMP: statoChamp })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [idSquadra, giornata, rosa.length, formazioneSalvata, formazionePrecedente, champSalvata, separata, tuttoPronto])

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
  // "salvata" e l'eventuale errore precedente. Le modifiche riguardano
  // sempre la scheda (competizione) attiva.
  function aggiornaAttiva(patch) {
    setForms(f => ({ ...f, [comp]: { ...f[comp], ...patch } }))
    setConfermaAperta(false)
    resetEsito()
  }
  function handleFormationChange(t, p, tr) {
    aggiornaAttiva({ titolari: t, panchina: p, tribuna: tr })
  }
  function handleModuloChange(m) {
    aggiornaAttiva({ modulo: m })
  }
  function handleCompChange(c) {
    setComp(c)
    setConfermaAperta(false)
    setPerEntrambe(false)
    resetEsito()
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

  // Con la formazione Champions distinta ammessa, prima di salvare si
  // chiede se la formazione va salvata per entrambe le competizioni
  // (default NO); altrimenti si salva direttamente, come sempre.
  function handleSalva() {
    if (!formazioneCompleta || saving || readOnly || giornata === null) return
    if (separata) {
      setPerEntrambe(false)
      setConfermaAperta(true)
    } else {
      eseguiSalvataggio(false)
    }
  }

  async function eseguiSalvataggio(entrambe) {
    if (!formazioneCompleta || saving || readOnly || giornata === null) return
    setSaving(true)
    setSaveError(null)
    setConfermaAperta(false)
    try {
      const res = await saveFormazione(
        ultimaStagione, giornata, idSquadra, buildGiocatoriPayload(),
        separata ? comp : 'CAMP', separata && entrambe
      )
      // Salvata in entrambe: le due schede sono ora identiche.
      if (separata && entrambe) setForms(f => ({ CAMP: f[comp], CHAMP: f[comp] }))
      setSavedPer(res?.salvata_per ?? [separata ? comp : 'CAMP'])
      setEmailInviata(!!res?.email_inviata)
      setMotivoEmail(res?.motivo_email ?? null)
      setJustSaved(true)
    } catch (e) {
      setSaveError(e.message ?? 'Errore durante il salvataggio')
    } finally {
      setSaving(false)
      setPerEntrambe(false)
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
      ) : loadingGiornata || !tuttoPronto ? (
        <LoadingState label="Caricamento formazione..." />
      ) : (
        <>
          {separata && (
            <div className="mb-4 flex items-center gap-3 flex-wrap">
              <TabBar
                tabs={[
                  { value: 'CAMP', label: COMP_LABEL.CAMP },
                  { value: 'CHAMP', label: COMP_LABEL.CHAMP },
                ]}
                active={comp}
                onChange={handleCompChange}
              />
              <p className="text-xs text-slate-500">
                Questa giornata prevede un turno di Champions: puoi schierare una formazione diversa per ciascuna competizione.
              </p>
            </div>
          )}
          {champsInGiornataSenzaSeparata && (
            <div className="mb-4 flex items-start gap-2 rounded-lg border border-white/10 bg-white/[0.03] px-3 py-2.5">
              <Info className="w-4 h-4 text-slate-500 flex-shrink-0 mt-0.5" />
              <p className="text-xs text-slate-400">
                Questa giornata prevede un turno di Champions: la formazione inserita vale per entrambe le competizioni.
              </p>
            </div>
          )}

          <FormationBuilder
            key={comp}
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
                    disabled={!formazioneCompleta || saving || confermaAperta}
                    className="btn-primary text-xs px-4 py-2 disabled:opacity-40 disabled:cursor-not-allowed disabled:active:scale-100"
                  >
                    {saving ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : <Save className="w-3.5 h-3.5" />}
                    {saving ? 'Salvataggio...' : 'Salva formazione'}
                  </button>
                )}
              </div>
            </div>

            {confermaAperta && (
              <div className="rounded-lg border border-white/10 bg-white/[0.03] p-3 mb-3">
                <p className="text-sm text-slate-200">
                  Vuoi salvare la formazione per entrambe le competizioni?
                </p>
                <p className="text-xs text-slate-500 mt-1 mb-3">
                  Stai salvando la formazione {COMP_LABEL[comp]}. Con "No" la formazione dell'altra competizione resta invariata.
                </p>
                <div className="flex items-center gap-5 mb-3 text-sm text-slate-300">
                  <label className="flex items-center gap-1.5 cursor-pointer">
                    <input type="radio" name="salva-entrambe" checked={!perEntrambe} onChange={() => setPerEntrambe(false)} />
                    No
                  </label>
                  <label className="flex items-center gap-1.5 cursor-pointer">
                    <input type="radio" name="salva-entrambe" checked={perEntrambe} onChange={() => setPerEntrambe(true)} />
                    Sì
                  </label>
                </div>
                <div className="flex items-center gap-2">
                  <button type="button" onClick={() => eseguiSalvataggio(perEntrambe)} className="btn-primary text-xs px-4 py-2">
                    <Save className="w-3.5 h-3.5" /> Conferma e salva
                  </button>
                  <button type="button" onClick={() => { setConfermaAperta(false); setPerEntrambe(false) }} className="btn-ghost text-xs">
                    Annulla
                  </button>
                </div>
              </div>
            )}

            {justSaved && (
              <p className="flex items-center gap-1.5 text-xs text-grass-400 mb-3">
                <CheckCircle2 className="w-3.5 h-3.5" /> Formazione salvata per la giornata {giornata}
                {separata ? ` (${savedPer.length > 1 ? 'Campionato e Champions' : COMP_LABEL[savedPer[0]] ?? savedPer[0]})` : ''}.
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
