/** "N lançamentos sem categoria · M grupos", usada no aviso da Início e no subtítulo de `/categorizar`. */
export function pendingCategorizationLabel(totalPending: number, groupCount: number): string {
  const transactionsLabel = totalPending === 1 ? '1 lançamento sem categoria' : `${totalPending} lançamentos sem categoria`
  const groupsLabel = groupCount === 1 ? '1 grupo' : `${groupCount} grupos`
  return `${transactionsLabel} · ${groupsLabel}`
}
