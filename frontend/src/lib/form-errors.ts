import type { FieldValues, Path, UseFormSetError } from 'react-hook-form'
import { toast } from 'sonner'
import { ApiError } from '@/api/errors'

/**
 * Copia erros 422 da API para os campos do formulário. Retorna true se aplicou algum erro que
 * de fato aparece em algum campo visível (ver `isCoverable`).
 *
 * `fields` é a lista fixa de caminhos a considerar; em formulários com listas dinâmicas e
 * caminhos aninhados (ex.: `conditions.0.value`, `conditions.1.conditions.0.value`), onde os
 * nomes do formulário já espelham os caminhos da API, passe `'*'` para aplicar todo caminho
 * que vier no erro, em vez de enumerar os caminhos possíveis de antemão.
 *
 * `isCoverable` (só importa com `'*'`) decide se um caminho tem, de fato, um campo que mostra
 * mensagem de erro na tela — por padrão, todo caminho conta (mantém o comportamento de quem já
 * passa uma lista fixa). Formulários com caminhos que não renderizam nada (ex.: o índice de um
 * item de array sozinho, sem sub-campo) passam um predicado próprio; se nenhum caminho aplicado
 * for "coberto", a função devolve `false` e quem chama deve avisar por outro meio (toast).
 */
export function applyFieldErrors<T extends FieldValues>(
  error: unknown,
  setError: UseFormSetError<T>,
  fields: readonly Path<T>[] | '*',
  isCoverable: (path: string) => boolean = () => true,
): boolean {
  if (!(error instanceof ApiError) || error.status !== 422) return false

  const paths = fields === '*' ? (Object.keys(error.fieldErrors) as Path<T>[]) : fields
  let covered = false
  for (const field of paths) {
    const message = error.fieldErrors[field]?.[0]
    if (message) {
      setError(field, { type: 'server', message })
      if (isCoverable(field)) covered = true
    }
  }
  return covered
}

export function notifyError(error: unknown, fallback = 'Algo deu errado. Tente novamente.'): void {
  toast.error(error instanceof ApiError ? error.message : fallback)
}

/**
 * Erro de nível de lista (zod `.min()` num array, ou um erro de servidor setado na própria
 * lista) aparece às vezes em `.root.message` (resolver), às vezes direto em `.message` (quando
 * `setError` grava no caminho da lista inteira) — depende de quem produziu o erro.
 */
export function rootErrorMessage(error: unknown): string | undefined {
  const node = error as { root?: { message?: unknown }; message?: unknown } | undefined
  const message = node?.root?.message ?? node?.message
  return typeof message === 'string' ? message : undefined
}
