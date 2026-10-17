import { useMemo } from 'react'
import { useRulePreview, type PreviewRuleBody } from '@/api/queries/rules'
import type { Direction } from '@/api/types'
import { useDebouncedValue } from '@/hooks/use-debounced-value'
import { condition, countRuleMatches, toRuleBody, type MatchableTransaction, type RuleAlternative } from './default-rule'

const DEBOUNCE_MS = 800
const EMPTY_BODY: PreviewRuleBody = { match: 'any', conditions: [], actions: [] }

export type RuleMatchState =
  /** Contado aqui, sobre os pendentes carregados. */
  | { status: 'local'; matched: number; extra: number }
  /** Contado pela prévia do servidor: lançamentos sem categoria que a regra categorizaria. */
  | { status: 'server'; matched: number }
  | { status: 'loading' }
  /** A prévia do servidor precisa de uma ação, e a ação é a categoria escolhida. */
  | { status: 'needs-category' }
  /** Alguma condição sem valor ou alternativa vazia: nada a contar ainda. */
  | { status: 'incomplete' }
  | { status: 'error' }

type UseRuleMatchCountArgs = {
  alternatives: RuleAlternative[]
  transactions: MatchableTransaction[]
  selectedIds: ReadonlySet<number>
  direction: Direction
  categoryId: number | null
  enabled: boolean
  /**
   * A lista carregada é só um recorte dos pendentes (busca ativa, ou resposta `truncated`): a
   * contagem local mentiria para baixo, então vai direto para a prévia do servidor.
   */
  partialList: boolean
}

function isComplete(alternatives: RuleAlternative[]): boolean {
  return (
    alternatives.length > 0 &&
    alternatives.every(
      (alternative) => alternative.conditions.length > 0 && alternative.conditions.every((each) => each.value.trim() !== ''),
    )
  )
}

/**
 * Corpo da prévia: cada alternativa ganha `direction é igual a <direção>` quando ainda não tem,
 * porque o apply só aplica a regra aos pendentes dessa direção — a prévia sozinha contaria as
 * duas. Sai compactado por `toRuleBody` (a prévia tem o mesmo limite de condições do apply).
 *
 * A prévia não tem escopo de "pendente": percorre todo lançamento do usuário que não é perna de
 * transferência nem previsto de recorrência. Com `overwrite: false`, `changed` conta os que ainda
 * não têm categoria — inclui ignorados, pagamento de fatura e lançamentos de conta arquivada, que
 * o `apply_to_pending` não toca. Por isso a tela diz "sem categoria", não "pendentes".
 */
function previewBody(alternatives: RuleAlternative[], direction: Direction, categoryId: number): PreviewRuleBody {
  const withDirection = alternatives.map((alternative) => {
    const hasDirection = alternative.conditions.some((each) => each.field === 'direction' && each.op === 'equals' && each.value === direction)
    return hasDirection ? alternative : { ...alternative, conditions: [...alternative.conditions, condition('direction', 'equals', direction)] }
  })
  return { ...toRuleBody(withDirection), actions: [{ type: 'set_category', category_id: categoryId }], overwrite: false }
}

/**
 * "Casa N pendentes": local quando todas as condições são avaliáveis aqui e a lista carregada é
 * completa (`countRuleMatches`), senão pela `POST /rules/preview`, com debounce, só depois de
 * haver categoria escolhida.
 */
export function useRuleMatchCount({
  alternatives,
  transactions,
  selectedIds,
  direction,
  categoryId,
  enabled,
  partialList,
}: UseRuleMatchCountArgs): RuleMatchState {
  const local = useMemo(
    () => (partialList ? ({ kind: 'server' } as const) : countRuleMatches(alternatives, transactions, selectedIds, direction)),
    [partialList, alternatives, transactions, selectedIds, direction],
  )
  const complete = isComplete(alternatives)
  const wantsServer = enabled && local.kind === 'server' && complete && categoryId !== null

  // Debounce sobre o JSON (estável entre renders), não sobre o objeto, que muda a cada render. Os
  // `uid` das condições acrescentadas não entram: `toRuleBody` só leva campo, operador e valor.
  const serialized = wantsServer && categoryId !== null ? JSON.stringify(previewBody(alternatives, direction, categoryId)) : ''
  const debounced = useDebouncedValue(serialized, DEBOUNCE_MS)
  const settled = debounced === serialized
  const body = useMemo<PreviewRuleBody>(() => (debounced === '' ? EMPTY_BODY : (JSON.parse(debounced) as PreviewRuleBody)), [debounced])
  const preview = useRulePreview(body, debounced !== '')

  if (local.kind === 'local') return { status: 'local', matched: local.matched, extra: local.extra }
  if (!complete) return { status: 'incomplete' }
  if (categoryId === null) return { status: 'needs-category' }
  if (!settled || preview.isPending || preview.isPlaceholderData) return { status: 'loading' }
  if (preview.isError || !preview.data) return { status: 'error' }
  return { status: 'server', matched: preview.data.changed }
}
