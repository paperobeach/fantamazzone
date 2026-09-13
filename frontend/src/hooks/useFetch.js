import { useState, useEffect, useCallback, useRef } from 'react'

/**
 * Hook generico per fetch asincroni.
 *
 * @param {Function|null} fetcher  Funzione che restituisce una Promise
 * @param {Array}         deps     Dipendenze (come useEffect)
 *
 * @returns {{ data, loading, error, refetch }}
 */
export function useFetch(fetcher, deps = []) {
  const [data,    setData]    = useState(null)
  const [loading, setLoading] = useState(false)
  const [error,   setError]   = useState(null)
  const abortRef = useRef(null)

  const run = useCallback(async () => {
    if (!fetcher) return

    // Cancella la chiamata precedente (se ancora in corso)
    abortRef.current?.abort()
    abortRef.current = new AbortController()

    setLoading(true)
    setError(null)

    try {
      const result = await fetcher()
      setData(result)
    } catch (e) {
      if (e.name !== 'AbortError') setError(e.message ?? 'Errore sconosciuto')
    } finally {
      setLoading(false)
    }
  }, deps) // eslint-disable-line react-hooks/exhaustive-deps

  useEffect(() => {
    run()
    return () => abortRef.current?.abort()
  }, [run])

  return { data, loading, error, refetch: run }
}
