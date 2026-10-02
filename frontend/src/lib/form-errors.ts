import type { FieldValues, Path, UseFormSetError } from 'react-hook-form'
import { toast } from 'sonner'
import { ApiError } from '@/api/errors'

/** Copia erros 422 da API para os campos do formulário. Retorna true se aplicou algum. */
export function applyFieldErrors<T extends FieldValues>(
  error: unknown,
  setError: UseFormSetError<T>,
  fields: readonly Path<T>[],
): boolean {
  if (!(error instanceof ApiError) || error.status !== 422) return false

  let applied = false
  for (const field of fields) {
    const message = error.fieldErrors[field]?.[0]
    if (message) {
      setError(field, { type: 'server', message })
      applied = true
    }
  }
  return applied
}

export function notifyError(error: unknown, fallback = 'Algo deu errado. Tente novamente.'): void {
  toast.error(error instanceof ApiError ? error.message : fallback)
}
