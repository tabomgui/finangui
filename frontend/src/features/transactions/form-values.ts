import { z } from 'zod'
import type { components } from '@/api/schema'
import type { Direction, Transaction, Transfer } from '@/api/types'

type StoreTransactionRequest = components['schemas']['StoreTransactionRequest']
type StoreTransferRequest = components['schemas']['StoreTransferRequest']

const DATE_ONLY = /^\d{4}-\d{2}-\d{2}$/

const amount = z
  .number()
  .nullable()
  .refine((value) => value !== null && value > 0, 'Informe um valor maior que zero.')

const description = z.string().trim().min(1, 'Informe a descrição.').max(255, 'Use no máximo 255 caracteres.')
const notes = z.string().max(2000, 'Use no máximo 2000 caracteres.')
const date = z.string().regex(DATE_ONLY, 'Informe a data.')

export const entrySchema = z.object({
  direction: z.enum(['in', 'out']),
  account_id: z.number().nullable().refine((value) => value !== null, 'Escolha a conta.'),
  amount,
  date,
  description,
  category_id: z.number().nullable(),
  tag_ids: z.array(z.number()),
  notes,
  is_ignored: z.boolean(),
  installments: z.number().int().min(1).max(48),
  statement_id: z.number().nullable(),
})

// `.nullable().refine(...)` no zod 4 estreita o tipo de SAÍDA (amount/account_id passam a `number`),
// mas os valores do formulário (e os padrões, antes de validar) ainda aceitam `null`: usar o tipo
// de ENTRADA do schema para `EntryValues`, como em `account-form-dialog.tsx`.
export type EntryValues = z.input<typeof entrySchema>

export function entryDefaults(
  input: { transaction: Transaction } | { direction: Direction; accountId: number | null; today: string },
): EntryValues {
  if ('transaction' in input) {
    const { transaction } = input
    return {
      direction: transaction.direction,
      account_id: transaction.account_id,
      amount: transaction.amount,
      date: transaction.date,
      description: transaction.description,
      category_id: transaction.category_id,
      tag_ids: (transaction.tags ?? []).map((tag) => tag.id),
      notes: transaction.notes ?? '',
      is_ignored: transaction.is_ignored,
      installments: 1,
      statement_id: transaction.statement_id ?? null,
    }
  }

  return {
    direction: input.direction,
    account_id: input.accountId,
    amount: null,
    date: input.today,
    description: '',
    category_id: null,
    tag_ids: [],
    notes: '',
    is_ignored: false,
    installments: 1,
    statement_id: null,
  }
}

export function toTransactionBody(
  values: EntryValues,
  { initialStatementId = null }: { initialStatementId?: number | null } = {},
): StoreTransactionRequest {
  return {
    direction: values.direction,
    account_id: values.account_id as number,
    amount: values.amount as number,
    date: values.date,
    description: values.description.trim(),
    category_id: values.category_id,
    tag_ids: values.tag_ids,
    notes: values.notes.trim() === '' ? null : values.notes.trim(),
    is_ignored: values.is_ignored,
    ...(values.installments > 1 ? { installments: values.installments } : {}),
    ...(values.statement_id !== null && values.statement_id !== initialStatementId
      ? { statement_id: values.statement_id }
      : {}),
  }
}

export const transferSchema = z
  .object({
    from_account_id: z.number().nullable().refine((value) => value !== null, 'Escolha a conta de origem.'),
    to_account_id: z.number().nullable().refine((value) => value !== null, 'Escolha a conta de destino.'),
    amount,
    date,
    description,
    notes,
  })
  .refine((values) => values.from_account_id === null || values.from_account_id !== values.to_account_id, {
    message: 'Escolha contas diferentes.',
    path: ['to_account_id'],
  })

export type TransferValues = z.input<typeof transferSchema>

export function transferDefaults(
  input: { transfer: Transfer } | { today: string; fromAccountId?: number | null },
): TransferValues {
  if ('transfer' in input) {
    const { transfer } = input
    return {
      from_account_id: transfer.from.account_id,
      to_account_id: transfer.to.account_id,
      amount: transfer.amount,
      date: transfer.date,
      description: transfer.description,
      notes: transfer.notes ?? '',
    }
  }

  return {
    from_account_id: input.fromAccountId ?? null,
    to_account_id: null,
    amount: null,
    date: input.today,
    description: 'Transferência',
    notes: '',
  }
}

export function toTransferBody(values: TransferValues): StoreTransferRequest {
  return {
    from_account_id: values.from_account_id as number,
    to_account_id: values.to_account_id as number,
    amount: values.amount as number,
    date: values.date,
    description: values.description.trim(),
    notes: values.notes.trim() === '' ? null : values.notes.trim(),
  }
}
