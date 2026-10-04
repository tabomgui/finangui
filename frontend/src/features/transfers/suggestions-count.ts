export type SuggestionsCount = { count: number; hasMore: boolean }

/**
 * Sem endpoint de contagem: deriva de uma só página de `/transfer-suggestions` (ver
 * `useTransferSuggestions`) — `hasMore` vem de `next_cursor` dessa mesma página, nunca de buscar
 * mais; usado pelo `PendingCard` do Início e pelo link no cabeçalho de Transações.
 */
export function firstPageSuggestionsCount(firstPage?: { data: unknown[]; meta: { next_cursor: string | null } }): SuggestionsCount {
  return { count: firstPage?.data.length ?? 0, hasMore: firstPage?.meta.next_cursor != null }
}

/** "1 sugestão de transferência" / "3 sugestões de transferência" / "5+ sugestões de transferência". */
export function suggestionsCountLabel({ count, hasMore }: SuggestionsCount): string {
  const suffix = hasMore ? '+' : ''
  const noun = count === 1 && !hasMore ? 'sugestão de transferência' : 'sugestões de transferência'
  return `${count}${suffix} ${noun}`
}
