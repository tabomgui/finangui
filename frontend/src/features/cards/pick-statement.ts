/** Lista em ordem de vencimento (como vem da API). */
export function pickStatementId(statements: { id: number }[], fromUrl: string | null, currentId: number | null): number | null {
  const ids = statements.map((statement) => statement.id)
  const requested = fromUrl !== null && /^\d+$/.test(fromUrl) ? Number(fromUrl) : null
  if (requested !== null && ids.includes(requested)) return requested
  if (currentId !== null && ids.includes(currentId)) return currentId
  return ids.at(-1) ?? null
}
