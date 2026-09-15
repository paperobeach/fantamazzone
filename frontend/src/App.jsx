import { Routes, Route, Navigate, useLocation } from 'react-router-dom'
import { AppProvider, useApp } from './context/AppContext'
import Sidebar from './components/layout/Sidebar'
import { ErrorBoundary } from './components/ErrorBoundary'
import { Spinner } from './components/ui'

// Pages (lazy-loaded) COMMENTO NUOVO
import { lazy, Suspense } from 'react'

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

  if (loading) {
    return (
      <div className="flex items-center justify-center h-screen bg-pitch-950">
        <div className="flex flex-col items-center gap-4">
          <Spinner size="lg" />
          <p className="text-mono text-xs tracking-widest uppercase text-slate-600">
            Caricamento...
          </p>
        </div>
      </div>
    )
  }

  return (
    <div className="flex min-h-screen bg-pitch-950">
      <Sidebar />
      <main className="flex-1 ml-56 min-h-screen">
        <div className="max-w-6xl mx-auto px-8 py-8">
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
                <Route path="*"            element={<Navigate to="/" replace />} />
              </Routes>
            </Suspense>
          </ErrorBoundary>
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
