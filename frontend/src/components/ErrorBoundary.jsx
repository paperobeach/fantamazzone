import { Component } from 'react'
import { AlertTriangle, RefreshCw } from 'lucide-react'

/**
 * Error Boundary React.
 *
 * Cattura gli errori che avvengono durante il rendering di un componente
 * (bug, dato inatteso nel JSX, ecc.) — cosa che NON viene intercettata dai
 * try/catch di client.js/useFetch, perché quelli riguardano solo gli
 * errori delle chiamate API, non gli errori di rendering React.
 *
 * Senza un boundary, un errore di rendering fa smontare l'intero albero
 * React a partire dal primo antenato senza boundary: nella pratica,
 * l'intera app diventa una pagina bianca.
 *
 * Uso: avvolgere le <Routes> (così un bug in UNA pagina non porta giù
 * anche la Sidebar/navigazione) ed eventualmente anche l'intera <App />
 * come rete di sicurezza di ultimo livello.
 */
export class ErrorBoundary extends Component {
  constructor(props) {
    super(props)
    this.state = { hasError: false }
  }

  static getDerivedStateFromError() {
    return { hasError: true }
  }

  componentDidCatch(error, info) {
    // In produzione qui si può aggiungere l'invio a un servizio di
    // monitoring (Sentry, LogRocket, ecc.). Per ora solo console, per
    // non perdere lo stack trace durante lo sviluppo.
    console.error('Errore di rendering catturato dall\'ErrorBoundary:', error, info)
  }

  handleReset = () => {
    this.setState({ hasError: false })
    this.props.onReset?.()
  }

  render() {
    if (this.state.hasError) {
      return (
        this.props.fallback ?? (
          <div className="flex flex-col items-center justify-center gap-4 py-20 text-center">
            <AlertTriangle className="w-8 h-8 text-red-400/70" />
            <div>
              <p className="text-slate-300 font-medium">Si è verificato un errore imprevisto</p>
              <p className="text-sm text-slate-600 mt-1">
                Prova a ricaricare la pagina. Se il problema persiste, segnalalo all'amministratore.
              </p>
            </div>
            <button onClick={this.handleReset} className="btn-ghost text-xs">
              <RefreshCw className="w-3.5 h-3.5" /> Riprova
            </button>
          </div>
        )
      )
    }
    return this.props.children
  }
}
