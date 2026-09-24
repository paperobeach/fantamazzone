import { useApp } from '../context/AppContext'
import { PageHeader } from '../components/ui'
import { InizializzazioneStagioneTable } from '../components/InizializzazioneStagioneTable'

export default function InizializzazioneStagione() {
  const { stagione } = useApp()

  return (
    <div className="animate-fade-up">
      <PageHeader
        label="Gestione"
        title="Inizializzazione stagione"
        subtitle="Gestisci in un'unica tabella squadre, allenatori e utenze di accesso per una stagione."
      />
      <InizializzazioneStagioneTable stagione={stagione} />
    </div>
  )
}
