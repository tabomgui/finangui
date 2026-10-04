import type { QueryClient } from '@tanstack/react-query'
import type { Direction, ReportBasis } from '@/api/types'

export type TransactionFilters = {
  account_id?: number
  category_id?: number
  tag_id?: number
  from?: string
  to?: string
  direction?: Direction
  search?: string
  statement_id?: number
}

/** Remove chaves vazias para que filtros equivalentes gerem a mesma chave de cache. */
export function compactFilters(filters: TransactionFilters): TransactionFilters {
  return Object.fromEntries(
    Object.entries(filters).filter(([, value]) => value !== undefined && value !== null && value !== ''),
  ) as TransactionFilters
}

export const queryKeys = {
  accounts: (includeArchived: boolean) => ['accounts', { includeArchived }] as const,
  categories: (includeArchived: boolean) => ['categories', { includeArchived }] as const,
  tags: () => ['tags'] as const,
  transactions: (filters: TransactionFilters) => ['transactions', 'list', compactFilters(filters)] as const,
  recentTransactions: () => ['transactions', 'recent'] as const,
  transaction: (id: number) => ['transactions', 'detail', id] as const,
  transfersRoot: () => ['transfers'] as const,
  transfer: (id: string) => ['transfers', id] as const,
  transferSuggestions: () => ['transfer-suggestions'] as const,
  dashboard: (month: string) => ['dashboard', month] as const,
  cards: (includeArchived: boolean) => ['cards', 'list', { includeArchived }] as const,
  card: (id: number) => ['cards', 'detail', id] as const,
  cardStatements: (cardId: number) => ['card-statements', 'list', cardId] as const,
  statementPreview: (cardId: number, date: string) => ['card-statements', 'preview', cardId, date] as const,
  installmentPlans: (cardId: number) => ['installment-plans', cardId] as const,
  rules: () => ['rules'] as const,
  rule: (id: number) => ['rules', id] as const,
  rulePreview: (body: unknown) => ['rule-preview', body] as const,
  importBatches: (accountId?: number) => ['import-batches', 'list', { accountId }] as const,
  importBatch: (id: number) => ['import-batches', 'detail', id] as const,
  bankConnections: () => ['bank-connections'] as const,
  recurrences: () => ['recurrences'] as const,
  recurrence: (id: number) => ['recurrences', 'detail', id] as const,
  overdueOccurrences: () => ['recurrences', 'overdue'] as const,
  budgets: (month: string) => ['budgets', month] as const,
  goals: () => ['goals'] as const,
  goalContributions: (goalId: number) => ['goals', goalId, 'contributions'] as const,
  reportMonthly: (from: string, to: string, basis: ReportBasis) => ['reports', 'monthly', { from, to, basis }] as const,
  reportCategories: (aFrom: string, aTo: string, bFrom: string, bTo: string, basis: ReportBasis) =>
    ['reports', 'categories', { aFrom, aTo, bFrom, bTo, basis }] as const,
  notifications: () => ['notifications', 'list'] as const,
  notificationsUnreadCount: () => ['notifications', 'unread-count'] as const,
}

function invalidate(queryClient: QueryClient, roots: string[]) {
  return Promise.all(roots.map((root) => queryClient.invalidateQueries({ queryKey: [root] })))
}

/**
 * Transações, transferências, contas e limites/faturas de cartão mexem em saldos, listas e no resumo
 * do mês; lançamentos novos também mudam o que uma prévia de regra ainda não salva mostraria.
 * `bank-connections` entra porque `pending_accounts`/`unlinked_accounts` do recurso da conexão
 * (e a sugestão de vínculo de cada um) dependem do estado das contas manuais: editar, arquivar
 * ou excluir uma conta pelo `AccountRow` (usado tanto na lista de contas manuais quanto dentro
 * do `ConnectionCard`, ver `features/banking/connection-card.tsx`) precisa refletir ali também.
 * `transfer-suggestions` entra porque importar, sincronizar, ligar/desligar/aceitar/descartar
 * uma transferência podem criar ou resolver sugestões: toda mutação que já chama isto cobre a
 * lista e a contagem de pendências sem precisar invalidar as duas chaves à parte.
 * `recurrences` entra porque criar/editar/excluir uma recorrência e confirmar/pular uma ocorrência
 * mudam as previstas, que aparecem nas transações, no saldo previsto e nas pendências do Início.
 * `budgets`, `goals` e `reports` entram porque gasto, progresso de meta e os relatórios dependem
 * de lançamentos: qualquer mutação que já chame isto mantém essas telas em dia também.
 */
export function invalidateLedger(queryClient: QueryClient) {
  return invalidate(queryClient, [
    'transactions',
    'transfers',
    'transfer-suggestions',
    'accounts',
    'dashboard',
    'cards',
    'card-statements',
    'installment-plans',
    'rule-preview',
    'bank-connections',
    'recurrences',
    'budgets',
    'goals',
    'reports',
  ])
}

/**
 * Nome, ícone e flag de transferência aparecem nas transações, nas top categorias do mês e nos
 * parcelamentos; a prévia de uma regra ainda não salva também mostra nome de categoria por
 * `category_id` (ver `rule-preview-card.tsx`), então renomear/arquivar uma categoria a deixaria
 * desatualizada sem essa invalidação.
 */
export function invalidateCategories(queryClient: QueryClient) {
  return invalidate(queryClient, ['categories', 'transactions', 'dashboard', 'installment-plans', 'rule-preview'])
}

/** Mesma razão de `invalidateCategories` para `rule-preview`: a prévia também mostra nome de tag. */
export function invalidateTags(queryClient: QueryClient) {
  return invalidate(queryClient, ['tags', 'transactions', 'rule-preview'])
}

export function invalidateRules(queryClient: QueryClient) {
  return invalidate(queryClient, ['rules'])
}

export function invalidateImports(queryClient: QueryClient) {
  return invalidate(queryClient, ['import-batches'])
}

/**
 * Só a listagem de lotes (`import-batches/list/...`), nunca o detalhe de um lote específico.
 * Confirmar e cancelar navegam para fora da prévia na sequência; invalidar o detalhe ali
 * reativaria a query da página que está de saída (ela ainda está montada por um instante) — ao
 * confirmar, isso gastaria uma requisição que o usuário nunca vê; ao cancelar, o lote já não
 * existe mais e essa requisição voltaria 404. Essas duas mutações tratam o detalhe no próprio
 * hook (`removeQueries`), não aqui.
 */
export function invalidateImportBatchesList(queryClient: QueryClient) {
  return queryClient.invalidateQueries({ queryKey: ['import-batches', 'list'] })
}

export function invalidateBankConnections(queryClient: QueryClient) {
  return invalidate(queryClient, ['bank-connections'])
}

/**
 * Notificações não entram em `invalidateLedger`: ler ou marcar como lida não muda saldo, lista
 * ou resumo nenhum — só o sino (lista e `unread_count`), que esta chave cobre sozinha, prefixo
 * comum de `notifications()` e `notificationsUnreadCount()`.
 */
export function invalidateNotifications(queryClient: QueryClient) {
  return invalidate(queryClient, ['notifications'])
}
