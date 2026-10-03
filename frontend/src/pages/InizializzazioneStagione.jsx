import { useState } from 'react'
import { useApp } from '../context/AppContext'
import { PageHeader, TabBar } from '../components/ui'
import { InizializzazioneStagioneTable } from '../components/InizializzazioneStagioneTable'
import { CreazioneCalendari } from '../components/CreazioneCalendari'

export default function InizializzazioneStagione() {
  const { stagione } = useApp()
  const [passo, setPasso] = useState('utenze')
  // Stagione su cui si sta lavorando: condivisa tra i due passi
  const [stagioneLavoro, setStagioneLavoro] = useState(stagione)

  return (
    <div className="animate-fade-up">
      <PageHeader
        label="Gestione"
        title="Inizializzazione stagione"
        subtitle="Due passi: prima la creazione di squadre e utenze, poi, dopo aver controllato i parametri della stagione, la creazione dei calendari."
      />

      <div className="mb-5">
        <TabBar
          tabs={[
            { value: 'utenze', label: '1 · Creazione utenze' },
            { value: 'calendari', label: '2 · Creazione calendari' },
          ]}
          active={passo}
          onChange={setPasso}
        />
      </div>

      {/* Il passo 1 resta montato per non perdere le modifiche non salvate */}
      <div className={passo === 'utenze' ? '' : 'hidden'}>
        <InizializzazioneStagioneTable
          stagione={stagione}
          onStagioneChange={s => setStagioneLavoro(Number(s))}
          onProsegui={() => setPasso('calendari')}
        />
      </div>
      {passo === 'calendari' && <CreazioneCalendari stagione={stagioneLavoro} />}
    </div>
  )
}
