import type { Transaction } from '@/api/types'

export type EditKind = 'entry' | 'transfer' | 'installment'

export function editKind(transaction: Pick<Transaction, 'transfer_id' | 'installment'>): EditKind {
  if (transaction.transfer_id !== null) return 'transfer'
  if (transaction.installment) return 'installment'
  return 'entry'
}
