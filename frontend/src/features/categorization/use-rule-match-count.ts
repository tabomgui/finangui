import { useMemo } from 'react'
import { useRulePreview, type PreviewRuleBody } from '@/api/queries/rules'
import type { Direction } from '@/api/types'
import { useDebouncedValue } from '@/hooks/use-debounced-value'
import { toConditionInput } from '@/features/rules/rule-form-values'
import { countRuleMatches, type MatchableTransaction, type RuleAlternative } from './default-rule'

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
}

function isComplete(alternatives: RuleAlternative[]): boolean {
  return (
    alternatives.length > 0 &&
    alternatives.every(
      (alternative) => alternative.conditions.length > 0 && alternative.conditions.every((condition) => condition.value.trim() !== ''),
    )
  )
}

/**
 * Corpo da prévia: cada alternativa ganha `direction é igual a <direção>` quando ainda não tem,
 * porque o apply só aplica a regra aos pendentes dessa direção — a prévia sozinha contaria as
 * duas. `overwrite: false` faz `changed` contar só quem ainda não tem categoria.
 */
function previewBody(alternatives: RuleAlternative[], direction: Direction, categoryId: number): PreviewRuleBody {
  return {
    match: 'any',
    conditions: alternatives.map((alternative) => {
      const conditions = alternative.conditions.map(toConditionInput)
      const hasDirection = conditions.some((condition) => condition.field === 'direction' && condition.op === 'equals' && condition.value === direction)
      return { match: 'all', conditions: hasDirection ? conditions : [...conditions, { field: 'direction', op: 'equals', value: direction }] }
    }),
    actions: [{ type: 'set_category', category_id: categoryId }],
    overwrite: false,
  }
}

/**
 * "Casa N pendentes": local quando todas as condições são avaliáveis aqui (`countRuleMatches`),
 * senão pela `POST /rules/preview`, com debounce, só depois de haver categoria escolhida.
 */
export function useRuleMatchCount({ alternatives, transactions, selectedIds, direction, categoryId, enabled }: UseRuleMatchCountArgs): RuleMatchState {
  const local = useMemo(
    () => countRuleMatches(alternatives, transactions, selectedIds, direction),
    [alternatives, transactions, selectedIds, direction],
  )
  const complete = isComplete(alternatives)
  const wantsServer = enabled && local.kind === 'server' && complete && categoryId !== null

  // Debounce sobre o JSON (estável entre renders), não sobre o objeto, que muda a cada render.
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
