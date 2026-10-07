import type { Transaction } from '@/api/types'

/** Partes do subtítulo de uma linha de transação, na ordem em que aparecem. */
export function transactionSubtitle(transaction: Transaction): string {
  const kind = transaction.is_card_payment
    ? 'Pagamento'
    : transaction.transfer_id !== null
      ? 'Transferência'
      : (transaction.category?.name ?? 'Sem categoria')
  const installment = transaction.installment ? `${transaction.installment.number}/${transaction.installment.total}` : null
  const recurrent = transaction.recurrence ? 'Recorrente' : null
  return [kind, transaction.account?.name, installment, recurrent].filter(Boolean).join(' · ')
}
