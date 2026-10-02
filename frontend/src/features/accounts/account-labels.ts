import type { AccountType } from '@/api/types'

export const ACCOUNT_TYPE_LABELS: Record<AccountType, string> = {
  checking: 'Conta corrente',
  savings: 'Poupança',
  cash: 'Dinheiro',
}

export const ACCOUNT_TYPES = Object.keys(ACCOUNT_TYPE_LABELS) as AccountType[]
