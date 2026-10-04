import { useEffect, useState } from 'react'

/**
 * Devolve `value` com atraso de `delayMs`: só atualiza depois que `value` fica quieto por esse
 * tempo. Usado na prévia de regra para não disparar uma requisição a cada tecla digitada.
 */
export function useDebouncedValue<T>(value: T, delayMs: number): T {
  const [debounced, setDebounced] = useState(value)

  useEffect(() => {
    const timer = setTimeout(() => setDebounced(value), delayMs)
    return () => clearTimeout(timer)
  }, [value, delayMs])

  return debounced
}
