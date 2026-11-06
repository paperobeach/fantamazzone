import { useState } from 'react'
import { raccordoGiocatori } from '../api/client'
import { PageHeader, TabBar, RoleBadge } from '../components/ui'
import { useCredenzialiAdmin, CardCredenziali, Avviso, Pulsante } from '../components/ApiFootballUi'
import { RefreshCw, GitMerge, Search, CheckCircle2, Scissors, ListChecks } from 'lucide-react'

// ============================================================
// "Raccordo giocatori" — l'id di NEW_GIOCATORI vale solo dentro la propria
// stagione: il raccordo assegna ad ogni (stagione, id) una persona stabile,
// usata dalla scheda giocatore per lo storico. Non usa dati API-Football.
//
//   - "Ricostruisci raccordo": carico iniziale di tutte le stagioni (rifà i
//     collegamenti automatici, le correzioni manuali restano);
//   - "Da verificare": omonimi e collegamenti con nazione diversa/mancante;
//     la riga resta nell'elenco finché non si preme "Conferma e chiudi": nel
//     frattempo si collegano, una stagione alla volta, le edizioni delle altre
//     persone omonime (oppure si uniscono tutte le stagioni di una persona).
//     Una persona può avere PIÙ id nella stessa stagione (cessione/acquisto):
//     l'id più basso è il primo in ordine cronologico;
//   - "Persone": ricerca per nome, unisci due persone, collega o separa una singola stagione.
// Per le stagioni future l'allineamento parte al termine di "Inserimento rose".
// ============================================================

const stagioneLabel = s => `${s}/${String(Number(s) + 1).slice(-2)}`

function InfoGiocatore({ g }) {
  if (!g) return <span className="text-xs text-slate-600">(non più presente)</span>
  return (
    <span className="inline-flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-slate-500">
      <RoleBadge ruolo={g.ruolo} />
      <span>{g.nazione || 'nazione n.d.'}</span>
      {g.squadra_serie_a && <span>· {g.squadra_serie_a}</span>}
      {g.squadra && <span>· lega: {g.squadra}</span>}
    </span>
  )
}

function Persona({ p, azioni }) {
  return (
    <div className="flex flex-wrap items-center gap-x-4 gap-y-2 rounded-lg border border-white/10 px-3 py-2">
      <div className="min-w-0 flex-1">
        <p className="text-sm text-slate-200 truncate">
          {p.nome} <span className="text-xs text-slate-600 font-mono">#{p.id_anagrafica}</span>
        </p>
        <p className="text-xs text-slate-500">
          Stagioni: {p.stagioni.length ? p.stagioni.map(stagioneLabel).join(', ') : '—'}
        </p>
        <InfoGiocatore g={p.ultima} />
      </div>
      {azioni}
    </div>
  )
}

export default function RaccordoGiocatori() {
  const cred = useCredenzialiAdmin()
  const [tab, setTab] = useState('verifica')
  const [errore, setErrore] = useState(null)
  const [esito, setEsito] = useState(null)
  const [occupato, setOccupato] = useState(null)
  const [stato, setStato] = useState(null)
  const [verifica, setVerifica] = useState(null)
  const [q, setQ] = useState('')
  const [trovati, setTrovati] = useState(null)
  const [persona, setPersona] = useState(null)
  const [selezione, setSelezione] = useState([])   // max 2 persone da unire

  const off = !cred.pronto || !!occupato

  const chiama = async (azione, extra = {}, etichetta = azione) => {
    setErrore(null)
    setOccupato(etichetta)
    try {
      const r = await raccordoGiocatori(cred.stagioneRif, { ...cred.auth, azione, ...extra })
      if (r?.stato) setStato(r.stato)
      return r
    } catch (e) {
      setErrore(e.message)
      return null
    } finally {
      setOccupato(null)
    }
  }

  const caricaStato = () => chiama('stato').then(r => r && setStato(r))
  const caricaVerifica = async () => {
    const r = await chiama('verifica')
    if (r) setVerifica(r.righe)
  }

  const ricostruisci = async () => {
    if (!window.confirm('Rifare i collegamenti automatici di tutte le stagioni? Le correzioni manuali restano; vengono segnati "da verificare" solo quelli per cui l\'automatico propone un collegamento diverso.')) return
    const r = await chiama('ricostruisci', { azzera_auto: true })
    if (r) {
      const tot = r.stagioni.reduce((a, x) => ({
        giocatori: a.giocatori + x.giocatori, collegati: a.collegati + x.collegati,
        nuove: a.nuove + x.nuove_persone, omonimi: a.omonimi + x.omonimi, verifica: a.verifica + x.da_verificare,
      }), { giocatori: 0, collegati: 0, nuove: 0, omonimi: 0, verifica: 0 })
      const man = r.manuali
        ? ` Manuali esaminati: ${r.manuali.manuali}; ${r.manuali.con_differenze} segnati da verificare (${r.manuali.gruppi} persone), gli altri non sono stati toccati.`
        : ''
      setEsito(`Raccordo ricostruito su ${r.stagioni.length} stagioni: ${tot.giocatori} giocatori, ${tot.collegati} collegati alla stagione precedente, ${tot.nuove} nuove persone (di cui ${tot.omonimi} omonimi), ${tot.verifica} da verificare.${man}`)
      caricaVerifica()
    }
  }

  const conferma = async g => {
    const r = await chiama('conferma', { stagione_giocatore: g.stagione, id_giocatore: g.id_giocatore }, 'conferma')
    if (r) caricaVerifica()
  }

  // Collega una sola stagione (un solo giocatore) a una persona esistente
  const collega = async (g, idAnagrafica, dopo) => {
    const r = await chiama('collega', {
      stagione_giocatore: g.stagione, id_giocatore: g.id_giocatore, id_anagrafica: idAnagrafica,
    }, 'collega')
    if (r) {
      setEsito(`${g.nome} (${stagioneLabel(g.stagione)}) collegato alla persona #${idAnagrafica}; le altre stagioni non sono state toccate.`)
      dopo?.()
    }
  }

  const unisci = async (tieni, assorbi) => {
    const r = await chiama('unisci', { id_tieni: tieni, id_assorbi: assorbi }, 'unisci')
    if (r) {
      setEsito(`Persone unite: #${assorbi} è confluita in #${tieni}.`)
      setSelezione([])
      setPersona(null)
      setTrovati(null)
      caricaVerifica()
    }
  }

  // Unisce tutte le persone omonime candidate (tutte le loro stagioni) in quella della riga
  const uniciTuttiCandidati = async (tieni, candidati) => {
    const r = await chiama('unisci_molti', { id_tieni: tieni, id_assorbi: candidati.map(c => c.id_anagrafica) }, 'unisci_molti')
    if (r) {
      setEsito(`${r.unite} persone unite nella persona #${tieni}.`)
      caricaVerifica()
    }
  }

  const separa = async g => {
    if (!window.confirm(`Staccare ${g.nome} (${stagioneLabel(g.stagione)}) in una persona nuova?`)) return
    const r = await chiama('separa', { stagione_giocatore: g.stagione, id_giocatore: g.id_giocatore }, 'separa')
    if (r) {
      setEsito(`Giocatore separato in una nuova persona (#${r.id_anagrafica}).`)
      apriPersona(g.id_anagrafica_origine)
    }
  }

  const cerca = async () => {
    const r = await chiama('cerca', { q }, 'cerca')
    if (r) setTrovati(r)
  }

  const apriPersona = async id => {
    const r = await chiama('persona', { id_anagrafica: id }, 'persona')
    setPersona(r ?? null)
  }

  const toggleSel = p => setSelezione(s =>
    s.some(x => x.id_anagrafica === p.id_anagrafica)
      ? s.filter(x => x.id_anagrafica !== p.id_anagrafica)
      : [...s, p].slice(-2))

  return (
    <div className="animate-fade-up">
      <PageHeader
        label="Avvio stagione"
        title="Raccordo giocatori"
        subtitle="Collega lo stesso giocatore tra le stagioni (l'id cambia ogni anno), così la scheda giocatore mostra lo storico corretto."
      />

      <CardCredenziali cred={cred} />

      <div className="max-w-5xl mb-6 space-y-4">
        <div className="flex flex-wrap items-center gap-3">
          <Pulsante onClick={caricaStato} disabled={off} caricando={occupato === 'stato'} icona={RefreshCw}>
            {stato ? 'Aggiorna stato' : 'Carica stato'}
          </Pulsante>
          <Pulsante onClick={ricostruisci} disabled={off} caricando={occupato === 'ricostruisci'} icona={GitMerge} primario>
            Ricostruisci raccordo
          </Pulsante>
        </div>

        {errore && <Avviso tono="red">{errore}</Avviso>}
        {esito && <Avviso tono="green" icona={CheckCircle2}>{esito}</Avviso>}

        {stato && (
          <div className="card overflow-x-auto">
            <table className="fanta-table min-w-[480px]">
              <thead>
                <tr>
                  <th>Stagione</th>
                  <th className="text-center">Giocatori</th>
                  <th className="text-center">Raccordati</th>
                  <th className="text-center">Da verificare</th>
                  <th className="text-center">Manuali</th>
                </tr>
              </thead>
              <tbody>
                {stato.stagioni.map(s => (
                  <tr key={s.stagione}>
                    <td className="px-4 py-2.5 font-mono text-xs text-slate-400">{stagioneLabel(s.stagione)}</td>
                    <td className="px-4 py-2.5 text-center font-mono text-xs">{s.giocatori}</td>
                    <td className={`px-4 py-2.5 text-center font-mono text-xs ${s.raccordati < s.giocatori ? 'text-gold-400' : 'text-slate-400'}`}>{s.raccordati}</td>
                    <td className={`px-4 py-2.5 text-center font-mono text-xs ${s.da_verificare ? 'text-gold-400 font-bold' : 'text-slate-500'}`}>{s.da_verificare}</td>
                    <td className="px-4 py-2.5 text-center font-mono text-xs text-slate-500">{s.manuali}</td>
                  </tr>
                ))}
                <tr className="border-t border-white/10">
                  <td className="px-4 py-2.5 text-xs font-bold text-slate-300 uppercase" colSpan={5}>Persone: {stato.persone}</td>
                </tr>
              </tbody>
            </table>
          </div>
        )}

        <TabBar
          tabs={[{ value: 'verifica', label: 'Da verificare' }, { value: 'persone', label: 'Persone' }]}
          active={tab} onChange={setTab}
        />

        {tab === 'verifica' && (
          <div className="space-y-4">
            <Pulsante onClick={caricaVerifica} disabled={off} caricando={occupato === 'verifica'} icona={ListChecks}>
              {verifica ? 'Ricarica elenco' : 'Carica elenco'}
            </Pulsante>
            {verifica && !verifica.length && <Avviso tono="green" icona={CheckCircle2}>Nessun collegamento da verificare.</Avviso>}
            {verifica?.map(r => (
              <div key={`${r.giocatore?.stagione}-${r.giocatore?.id_giocatore}`} className="card p-4 space-y-3">
                <div>
                  <p className="text-sm text-slate-200">
                    {r.giocatore?.nome ?? r.persona?.nome}
                    <span className="ml-2 text-xs text-slate-600 font-mono">
                      {r.giocatore && stagioneLabel(r.giocatore.stagione)} · id {r.giocatore?.id_giocatore} · persona #{r.persona?.id_anagrafica}
                    </span>
                  </p>
                  <InfoGiocatore g={r.giocatore} />
                  {r.nota && <p className="text-xs text-gold-400/90 mt-1">{r.nota}</p>}
                  {r.persona && (
                    <p className="text-xs text-slate-600 mt-1">
                      Stagioni della persona: {r.persona.stagioni.map(stagioneLabel).join(', ')}
                    </p>
                  )}
                </div>

                {r.candidati.length > 0 && (
                  <div className="space-y-2">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                      <p className="text-[10px] text-mono tracking-widest uppercase text-slate-600">
                        Altre persone con lo stesso nome — collega, stagione per stagione, quelle che sono questo giocatore
                      </p>
                      {r.candidati.length > 1 && (
                        <Pulsante piccolo primario icona={GitMerge} disabled={off}
                                  titolo="Sposta tutte le stagioni di tutte le persone omonime su quella della riga"
                                  onClick={() => window.confirm(`Unire TUTTE le ${r.candidati.length} persone omonime nella persona #${r.persona.id_anagrafica} (${stagioneLabel(r.giocatore.stagione)})?\n\n${r.candidati.map(c => `#${c.id_anagrafica}: ${c.edizioni.map(e => stagioneLabel(e.stagione)).join(', ') || '—'}`).join('\n')}\n\nUsare solo se sono tutte lo stesso giocatore.`) && uniciTuttiCandidati(r.persona.id_anagrafica, r.candidati)}>
                          Unisci tutte le persone
                        </Pulsante>
                      )}
                    </div>
                    {r.candidati.map(c => (
                      <div key={c.id_anagrafica} className="rounded-lg border border-white/10 px-3 py-2 space-y-2">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                          <p className="text-sm text-slate-200">
                            {c.nome} <span className="text-xs text-slate-600 font-mono">#{c.id_anagrafica}</span>
                          </p>
                          {c.edizioni.length > 1 && (
                            <Pulsante piccolo icona={GitMerge} disabled={off}
                                      titolo="Sposta tutte le stagioni di questa persona su quella della riga"
                                      onClick={() => window.confirm(`Unire TUTTE le stagioni (${c.edizioni.map(e => stagioneLabel(e.stagione)).join(', ')}) della persona #${c.id_anagrafica} nella persona #${r.persona.id_anagrafica} (${stagioneLabel(r.giocatore.stagione)})?`) && unisci(r.persona.id_anagrafica, c.id_anagrafica)}>
                              Unisci tutte le stagioni
                            </Pulsante>
                          )}
                        </div>
                        {c.edizioni.map(e => {
                          const stessaStagione = r.persona.stagioni.includes(e.stagione)
                          return (
                            <div key={`${e.stagione}-${e.id_giocatore}`}
                                 className="flex flex-wrap items-center gap-x-4 gap-y-2 border-t border-white/[0.04] pt-2">
                              <div className="min-w-0 flex-1">
                                <p className="text-xs font-mono text-slate-400">{stagioneLabel(e.stagione)} · id {e.id_giocatore}</p>
                                <InfoGiocatore g={e} />
                              </div>
                              <Pulsante piccolo primario icona={GitMerge} disabled={off}
                                        titolo={`Collega solo ${stagioneLabel(e.stagione)} alla persona #${r.persona.id_anagrafica}`}
                                        onClick={() => window.confirm(`Collegare solo ${stagioneLabel(e.stagione)} (id ${e.id_giocatore}) alla persona #${r.persona.id_anagrafica}? Le altre stagioni di #${c.id_anagrafica} non cambiano.${stessaStagione ? '\n\nLa persona avrà più id nella stessa stagione (cessione/acquisto): l\'id più basso è il primo in ordine cronologico.' : ''}`) && collega(e, r.persona.id_anagrafica, caricaVerifica)}>
                                Collega solo {stagioneLabel(e.stagione)}
                              </Pulsante>
                            </div>
                          )
                        })}
                      </div>
                    ))}
                  </div>
                )}

                <Pulsante piccolo icona={CheckCircle2} disabled={off} onClick={() => conferma(r.giocatore)}
                          titolo="Chiude la verifica di questo giocatore: esce dall'elenco">
                  Conferma e chiudi
                </Pulsante>
              </div>
            ))}
          </div>
        )}

        {tab === 'persone' && (
          <div className="space-y-4">
            <div className="flex flex-wrap items-center gap-3">
              <input
                type="text" value={q} onChange={e => setQ(e.target.value)}
                onKeyDown={e => e.key === 'Enter' && cred.pronto && cerca()}
                placeholder="Nome giocatore (min. 2 caratteri)" className="fanta-input max-w-xs"
              />
              <Pulsante onClick={cerca} disabled={off || q.trim().length < 2} caricando={occupato === 'cerca'} icona={Search}>
                Cerca
              </Pulsante>
            </div>

            {selezione.length > 0 && (
              <Avviso tono="gold" icona={GitMerge}>
                <p>
                  Selezionate: {selezione.map(p => `${p.nome} #${p.id_anagrafica}`).join(' + ')}
                  {selezione.length === 2 && ' — la prima viene mantenuta, la seconda confluisce nella prima.'}
                </p>
                {selezione.length === 2 && (
                  <div className="mt-2">
                    <Pulsante piccolo primario icona={GitMerge} disabled={off}
                              onClick={() => unisci(selezione[0].id_anagrafica, selezione[1].id_anagrafica)}>
                      Unisci
                    </Pulsante>
                  </div>
                )}
              </Avviso>
            )}

            {trovati && !trovati.length && <p className="text-sm text-slate-500">Nessuna persona trovata.</p>}
            {trovati?.map(p => (
              <Persona key={p.id_anagrafica} p={p} azioni={
                <div className="flex gap-2">
                  <Pulsante piccolo disabled={off} onClick={() => apriPersona(p.id_anagrafica)}>Dettaglio</Pulsante>
                  <Pulsante piccolo disabled={off} onClick={() => toggleSel(p)}
                            primario={selezione.some(x => x.id_anagrafica === p.id_anagrafica)}>
                    {selezione.some(x => x.id_anagrafica === p.id_anagrafica) ? 'Selezionata' : 'Seleziona'}
                  </Pulsante>
                </div>
              } />
            ))}

            {persona && (
              <div className="card p-4 space-y-3">
                <p className="text-sm font-semibold text-slate-200">
                  {persona.nome} <span className="text-xs text-slate-600 font-mono">#{persona.id_anagrafica}</span>
                </p>
                {persona.edizioni.map(g => (
                  <div key={`${g.stagione}-${g.id_giocatore}`}
                       className="flex flex-wrap items-center gap-x-4 gap-y-2 rounded-lg border border-white/10 px-3 py-2">
                    <div className="min-w-0 flex-1">
                      <p className="text-sm text-slate-300">
                        {stagioneLabel(g.stagione)} · {g.nome}
                        <span className="ml-2 text-xs text-slate-600 font-mono">id {g.id_giocatore} · {g.metodo}{g.da_verificare ? ' · da verificare' : ''}</span>
                        {g.totale_stagione > 1 && (
                          <span className="ml-2 text-xs text-gold-400/90">
                            {g.ordine_stagione}/{g.totale_stagione} nella stagione (id più basso = primo cronologicamente)
                          </span>
                        )}
                      </p>
                      <InfoGiocatore g={g} />
                      {g.nota && <p className="text-xs text-gold-400/90">{g.nota}</p>}
                    </div>
                    {selezione.length === 1 && selezione[0].id_anagrafica !== persona.id_anagrafica && (
                      <Pulsante piccolo icona={GitMerge} disabled={off}
                                titolo="Collega solo questa stagione alla persona selezionata"
                                onClick={() => collega(g, selezione[0].id_anagrafica, () => apriPersona(persona.id_anagrafica))}>
                        Collega a #{selezione[0].id_anagrafica}
                      </Pulsante>
                    )}
                    {persona.edizioni.length > 1 && (
                      <Pulsante piccolo icona={Scissors} disabled={off}
                                onClick={() => separa({ ...g, id_anagrafica_origine: persona.id_anagrafica })}>
                        Separa
                      </Pulsante>
                    )}
                  </div>
                ))}
              </div>
            )}
          </div>
        )}
      </div>
    </div>
  )
}
