import type { FieldValues, Path, UseFormSetError } from 'react-hook-form'
import { toast } from 'sonner'
import { ApiError } from '@/api/errors'

/**
 * Copia erros 422 da API para os campos do formulário. Retorna true se aplicou algum.
 *
 * `fields` é a lista fixa de caminhos a considerar; em formulários com listas dinâmicas e
 * caminhos aninhados (ex.: `conditions.0.value`, `conditions.1.conditions.0.value`), onde os
 * nomes do formulário já espelham os caminhos da API, passe `'*'` para aplicar todo caminho
 * que vier no erro, em vez de enumerar os caminhos possíveis de antemão.
 */
export function applyFieldErrors<T extends FieldValues>(
  error: unknown,
  setError: UseFormSetError<T>,
  fields: readonly Path<T>[] | '*',
): boolean {
  if (!(error instanceof ApiError) || error.status !== 422) return false

  const paths = fields === '*' ? (Object.keys(error.fieldErrors) as Path<T>[]) : fields
  let applied = false
  for (const field of paths) {
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
