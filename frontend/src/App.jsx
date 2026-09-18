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
const Squadre     = lazy(() => import('./pages/Squadre'))
const SquadraDetail = lazy(() => import('./pages/SquadraDetail'))
const Incontri    = lazy(() => import('./pages/Incontri'))
const Statistiche = lazy(() => import('./pages/Statistiche'))
const Marcatori   = lazy(() => import('./pages/Marcatori'))
const TopFlop     = lazy(() => import('./pages/TopFlop'))
const Kulovic     = lazy(() => import('./pages/Kulovic'))
const Champions   = lazy(() => import('./pages/Champions'))
const Schedina    = lazy(() => import('./pages/Schedina'))
const Messaggi    = lazy(() => import('./pages/Messaggi'))
const Login       = lazy(() => import('./pages/Login'))
const Admin       = lazy(() => import('./pages/Admin'))
const InserimentoRose = lazy(() => import('./pages/InserimentoRose'))

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

      <main className="flex-1 lg:ml-56 min-h-screen pt-14 lg:pt-0">
        <div className="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-6 lg:py-8">
          {loading ? (
            <PageLoader />
          ) : (
            <ErrorBoundary key={location.pathname}>
              <Suspense fallback={<PageLoader />}>
                <Routes>
                  <Route path="/"              element={<Classifica />} />
                  <Route path="/calendario"   element={<Calendario />} />
                  <Route path="/squadre"      element={<Squadre />} />
                  <Route path="/squadre/:id"  element={<SquadraDetail />} />
                  <Route path="/incontri"     element={<Incontri />} />
                  <Route path="/statistiche"  element={<Statistiche />} />
                  <Route path="/marcatori"    element={<Marcatori />} />
                  <Route path="/top-flop"     element={<TopFlop />} />
                  <Route path="/kulovic"      element={<Kulovic />} />
                  <Route path="/champions"    element={<Champions />} />
                  <Route path="/schedina"     element={<Schedina />} />
                  <Route path="/messaggi"     element={
                    <ProtectedRoute><Messaggi /></ProtectedRoute>
                  } />
                  <Route path="/login"        element={<Login />} />
                  <Route path="/admin"        element={
                    <AdminRoute><Admin /></AdminRoute>
                  } />
                  <Route path="/inserimento-rose" element={
                    <AdminRoute><InserimentoRose /></AdminRoute>
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
