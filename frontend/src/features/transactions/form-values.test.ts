import { describe, expect, it } from 'vitest'
import type { Transaction, Transfer } from '@/api/types'
import { entryDefaults, entrySchema, toTransactionBody, toTransferBody, transferDefaults, transferSchema } from './form-values'

const transaction = {
  id: 7,
  account_id: 3,
  date: '2026-10-01',
  amount: 4590,
  direction: 'out',
  currency: 'BRL',
  description: 'Padaria',
  original_description: 'PADARIA X',
  description_locked: false,
  notes: null,
  payee: null,
  category_id: 9,
  tags: [{ id: 2, name: 'viagem', color: null }],
  status: 'posted',
  source: 'manual',
  categorized_by: 'manual',
  is_ignored: false,
  transfer_id: null,
  statement_id: null,
  installment: null,
} as Transaction

describe('lançamento', () => {
  it('preenche a partir de uma transação existente', () => {
    expect(entryDefaults({ transaction })).toEqual({
      direction: 'out',
      account_id: 3,
      amount: 4590,
      date: '2026-10-01',
      description: 'Padaria',
      category_id: 9,
      tag_ids: [2],
      notes: '',
      is_ignored: false,
      installments: 1,
      statement_id: null,
      repeat: false,
      repeat_frequency: 'monthly',
    })
  })

  it('usa padrões para um lançamento novo', () => {
    expect(entryDefaults({ direction: 'in', accountId: 5, today: '2026-10-03' })).toMatchObject({
      direction: 'in',
      account_id: 5,
      amount: null,
      date: '2026-10-03',
      tag_ids: [],
    })
  })

  it('exige conta, valor positivo e descrição', () => {
    const result = entrySchema.safeParse(entryDefaults({ direction: 'out', accountId: null, today: '2026-10-03' }))

    expect(result.success).toBe(false)
    const fields = result.error?.issues.map((issue) => issue.path[0])
    expect(fields).toEqual(expect.arrayContaining(['account_id', 'amount', 'description']))
  })

  it('monta o corpo da API', () => {
    const body = toTransactionBody({ ...entryDefaults({ transaction }), description: '  Padaria  ', notes: '  ' })

    expect(body).toEqual({
      direction: 'out',
      account_id: 3,
      amount: 4590,
      date: '2026-10-01',
      description: 'Padaria',
      category_id: 9,
      tag_ids: [2],
      notes: null,
      is_ignored: false,
    })
  })
})

describe('parcelas e fatura', () => {
  it('não inclui installments no corpo quando é 1', () => {
    const body = toTransactionBody({ ...entryDefaults({ transaction }), installments: 1 })

    expect(body).not.toHaveProperty('installments')
  })

  it('inclui installments no corpo quando maior que 1', () => {
    const body = toTransactionBody({ ...entryDefaults({ transaction }), installments: 3 })

    expect(body.installments).toBe(3)
  })

  it('não inclui statement_id quando não mudou', () => {
    const values = { ...entryDefaults({ transaction }), statement_id: 10 }

    expect(toTransactionBody(values, { initialStatementId: 10 })).not.toHaveProperty('statement_id')
  })

  it('inclui statement_id quando mudou', () => {
    const values = { ...entryDefaults({ transaction }), statement_id: 11 }

    expect(toTransactionBody(values, { initialStatementId: 10 }).statement_id).toBe(11)
  })
})

describe('transferência', () => {
  it('não aceita a mesma conta na origem e no destino', () => {
    const values = { ...transferDefaults({ today: '2026-10-03' }), from_account_id: 1, to_account_id: 1, amount: 100 }

    const result = transferSchema.safeParse(values)

    expect(result.success).toBe(false)
    expect(result.error?.issues.some((issue) => issue.path[0] === 'to_account_id')).toBe(true)
  })

  it('preenche a partir de uma transferência e monta o corpo', () => {
    const transfer = {
      transfer_id: 'uuid',
      date: '2026-10-02',
      amount: 50000,
      description: 'Reserva',
      notes: null,
      from: { ...transaction, account_id: 1 },
      to: { ...transaction, account_id: 2, direction: 'in' },
    } as Transfer

    const values = transferDefaults({ transfer })

    expect(toTransferBody(values)).toEqual({
      from_account_id: 1,
      to_account_id: 2,
      amount: 50000,
      date: '2026-10-02',
      description: 'Reserva',
      notes: null,
    })
  })
})
