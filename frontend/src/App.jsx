import { Routes, Route, Navigate, useLocation } from 'react-router-dom'
import { AppProvider, useApp } from './context/AppContext'
import Sidebar from './components/layout/Sidebar'
import { ErrorBoundary } from './components/ErrorBoundary'
import { Spinner } from './components/ui'
import { Menu, Trophy } from 'lucide-react'

// Pages (lazy-loaded)
import { lazy, Suspense, useState } from 'react'

const Classifica  = lazy(() => import('./pages/Classifica'))
const Calendario  = lazy(() => import('./pages/Calendario'))
const LiveGiornata = lazy(() => import('./pages/LiveGiornata'))
const Squadre     = lazy(() => import('./pages/Squadre'))
const SquadraDetail = lazy(() => import('./pages/SquadraDetail'))
const Statistiche = lazy(() => import('./pages/Statistiche'))
const TopFlop     = lazy(() => import('./pages/TopFlop'))
const Champions   = lazy(() => import('./pages/Champions'))
const AlboOro     = lazy(() => import('./pages/AlboOro'))
const MercatoRiparazione = lazy(() => import('./pages/MercatoRiparazione'))
const Formazione  = lazy(() => import('./pages/Formazione'))
const DashboardSquadra = lazy(() => import('./pages/DashboardSquadra'))
const Login       = lazy(() => import('./pages/Login'))
const Admin       = lazy(() => import('./pages/Admin'))
const InserimentoRose = lazy(() => import('./pages/InserimentoRose'))
const InizializzazioneStagione = lazy(() => import('./pages/InizializzazioneStagione'))
const GestioneVoti = lazy(() => import('./pages/GestioneVoti'))
const GestisciRegoleCalcolo = lazy(() => import('./pages/GestisciRegoleCalcolo'))
const RiaperturaGiornata = lazy(() => import('./pages/RiaperturaGiornata'))
const ImportaCalendarioSerieA = lazy(() => import('./pages/ImportaCalendarioSerieA'))
const ResetStagione = lazy(() => import('./pages/ResetStagione'))
const RipristinoStagione = lazy(() => import('./pages/RipristinoStagione'))
const AiTest = lazy(() => import('./pages/AiTest'))
const ApiFootballTest = lazy(() => import('./pages/ApiFootballTest'))
const ApiFootballMappatura = lazy(() => import('./pages/ApiFootballMappatura'))
const ApiFootballSync = lazy(() => import('./pages/ApiFootballSync'))
const AiArticolo = lazy(() => import('./pages/AiArticolo'))
const AiArticoli = lazy(() => import('./pages/AiArticoli'))
const AiContesto = lazy(() => import('./pages/AiContesto'))
const AiImmagini = lazy(() => import('./pages/AiImmagini'))
const Gazzetta = lazy(() => import('./pages/Gazzetta'))
const GazzettaArticolo = lazy(() => import('./pages/GazzettaArticolo'))

// Home: la dashboard della propria squadra per gli utenti loggati;
// per i visitatori non autenticati resta la classifica.
function Home() {
  const { utente } = useApp()
  return utente ? <DashboardSquadra /> : <Classifica />
}

function PageLoader() {
  return (
    <div className="flex items-center justify-center h-64">
      <Spinner size="lg" />
    </div>
  )
}

function ProtectedRoute({ children }) {
  const { utente } = useApp()
  return utente ? children : <Navigate to="/login" replace />
}

function AdminRoute({ children }) {
  const { utente, isAdmin } = useApp()
  if (!utente) return <Navigate to="/login" replace />
  if (!isAdmin) return <Navigate to="/" replace />
  return children
}

// Sezione "AI": solo utenze con NEW_UTENZE.abilita_ai = 'Y'
function AiRoute({ children }) {
  const { utente, canAi } = useApp()
  if (!utente) return <Navigate to="/login" replace />
  if (!canAi) return <Navigate to="/" replace />
  return children
}

function AppShell() {
  const { loading } = useApp()
  const location = useLocation()
  const [sidebarOpen, setSidebarOpen] = useState(false)

  return (
    <div className="flex min-h-screen bg-pitch-950">
      <Sidebar open={sidebarOpen} onClose={() => setSidebarOpen(false)} />

      {/* Topbar mobile */}
      <header className="lg:hidden fixed top-0 left-0 right-0 z-30 h-14 flex items-center gap-3 px-4
                          bg-pitch-900/95 backdrop-blur border-b border-white/5">
        <button
          onClick={() => setSidebarOpen(true)}
          aria-label="Apri menu"
          className="p-2 -ml-2 rounded-lg text-slate-300 hover:text-white hover:bg-white/[0.06] transition-colors"
        >
          <Menu className="w-5 h-5" />
        </button>
        <div className="flex items-center gap-2">
          <div className="w-6 h-6 rounded-md bg-grass-500 flex items-center justify-center flex-shrink-0">
            <Trophy className="w-3.5 h-3.5 text-pitch-950" />
          </div>
          <span className="text-display text-base font-bold tracking-wide text-white leading-none">
            LFM
          </span>
        </div>
      </header>

      <main className="flex-1 min-w-0 lg:ml-56 min-h-screen pt-14 lg:pt-0">
        <div className="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-6 lg:py-8">
          {loading ? (
            <PageLoader />
          ) : (
            <ErrorBoundary key={location.pathname}>
              <Suspense fallback={<PageLoader />}>
                <Routes>
                  <Route path="/"              element={<Home />} />
                  <Route path="/classifica"    element={<Classifica />} />
                  <Route path="/calendario"   element={<Calendario />} />
                  <Route path="/live"         element={<LiveGiornata />} />
                  <Route path="/squadre"      element={<Squadre />} />
                  <Route path="/squadre/:id"  element={<SquadraDetail />} />
                  {/* Pagina "Incontri" rimossa: i dettagli partita sono ora
                      consultabili direttamente da "Calendario". Redirect
                      mantenuto per eventuali link/segnalibri esistenti. */}
                  <Route path="/incontri"     element={<Navigate to="/calendario" replace />} />
                  <Route path="/statistiche"  element={<Statistiche />} />
                  <Route path="/top-flop"     element={<TopFlop />} />
                  <Route path="/champions"    element={<Champions />} />
                  <Route path="/champions/top-flop" element={<TopFlop key="champ" competizione="CHAMP" />} />
                  <Route path="/albo-oro"     element={<AlboOro />} />
                  <Route path="/mercato-riparazione" element={<Navigate to="/mercato-riparazione/prima-finestra" replace />} />
                  <Route path="/mercato-riparazione/prima-finestra" element={<MercatoRiparazione />} />
                  <Route path="/gazzetta"     element={<Gazzetta />} />
                  <Route path="/gazzetta/:id" element={<GazzettaArticolo />} />
                  <Route path="/formazione"   element={
                    <ProtectedRoute><Formazione /></ProtectedRoute>
                  } />
                  {/* La dashboard squadra è la home: il vecchio indirizzo resta come redirect */}
                  <Route path="/dashboard-squadra" element={<Navigate to="/" replace />} />
                  <Route path="/login"        element={<Login />} />
                  <Route path="/admin"        element={
                    <AdminRoute><Admin /></AdminRoute>
                  } />
                  <Route path="/ai/articolo" element={
                    <AiRoute><AiArticolo /></AiRoute>
                  } />
                  <Route path="/ai/articoli" element={
                    <AiRoute><AiArticoli /></AiRoute>
                  } />
                  <Route path="/ai/contesto" element={
                    <AiRoute><AiContesto /></AiRoute>
                  } />
                  <Route path="/ai/immagini" element={
                    <AiRoute><AiImmagini /></AiRoute>
                  } />
                  <Route path="/ai/test" element={
                    <AiRoute><AiTest /></AiRoute>
                  } />
                  <Route path="/inserimento-rose" element={
                    <AdminRoute><InserimentoRose /></AdminRoute>
                  } />
                  <Route path="/inizializzazione-stagione" element={
                    <AdminRoute><InizializzazioneStagione /></AdminRoute>
                  } />
                  <Route path="/gestione-voti" element={
                    <AdminRoute><GestioneVoti /></AdminRoute>
                  } />
                  <Route path="/riapertura-giornata" element={
                    <AdminRoute><RiaperturaGiornata /></AdminRoute>
                  } />
                  <Route path="/importa-calendario-serie-a" element={
                    <AdminRoute><ImportaCalendarioSerieA /></AdminRoute>
                  } />
                  <Route path="/api-football/test" element={
                    <AdminRoute><ApiFootballTest /></AdminRoute>
                  } />
                  <Route path="/api-football/mappatura" element={
                    <AdminRoute><ApiFootballMappatura /></AdminRoute>
                  } />
                  <Route path="/api-football/sync" element={
                    <AdminRoute><ApiFootballSync /></AdminRoute>
                  } />
                  <Route path="/regole-calcolo" element={
                    <AdminRoute><GestisciRegoleCalcolo /></AdminRoute>
                  } />
                  <Route path="/reset-stagione" element={
                    <AdminRoute><ResetStagione /></AdminRoute>
                  } />
                  <Route path="/ripristino-stagione" element={
                    <AdminRoute><RipristinoStagione /></AdminRoute>
                  } />
                  <Route path="*"            element={<Navigate to="/" replace />} />
                </Routes>
              </Suspense>
            </ErrorBoundary>
          )}
        </div>
      </main>
    </div>
  )
}

export default function App() {
  return (
    <ErrorBoundary>
      <AppProvider>
        <AppShell />
      </AppProvider>
    </ErrorBoundary>
  )
}
