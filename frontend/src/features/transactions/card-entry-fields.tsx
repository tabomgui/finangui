import { Controller, useWatch, type UseFormReturn } from 'react-hook-form'
import { useAccounts } from '@/api/queries/accounts'
import { useCardStatements, useStatementPreview } from '@/api/queries/cards'
import { Field } from '@/components/form/field'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { STATUS_LABELS } from '@/features/cards/statement-labels'
import { formatDate, isDateOnly } from '@/lib/date'
import type { EntryValues } from './form-values'

const INSTALLMENT_OPTIONS = Array.from({ length: 48 }, (_, index) => index + 1)

type CardEntryFieldsProps = {
  form: UseFormReturn<EntryValues>
  mode: 'create' | 'edit'
  initialAccountId: number | null
  /** Data original da transação (edição): usada para saber se o usuário mudou a data. */
  initialDate?: string
  /** Fatura original da transação (edição): usada para saber se o usuário já escolheu outra pelo select. */
  initialStatementId?: number | null
}

export function CardEntryFields({
  form,
  mode,
  initialAccountId,
  initialDate,
  initialStatementId = null,
}: CardEntryFieldsProps) {
  const { errors } = form.formState
  const accountId = useWatch({ control: form.control, name: 'account_id' })
  const date = useWatch({ control: form.control, name: 'date' })
  const direction = useWatch({ control: form.control, name: 'direction' })
  const installments = useWatch({ control: form.control, name: 'installments' })
  const statementId = useWatch({ control: form.control, name: 'statement_id' })

  const { data: accounts = [] } = useAccounts(true)
  const account = accounts.find((item) => item.id === accountId)
  const isCreditCard = account?.type === 'credit_card'

  // Select de fatura só com a MESMA conta da transação original (trocar de conta sempre volta pro
  // automático — o reset de `statement_id` já acontece no onChange da conta, em `entry-form.tsx`;
  // a condição aqui é defesa redundante). Dentro da mesma conta, fica disponível quando a data
  // também não mudou, ou quando o usuário já escolheu uma fatura diferente da original por esse
  // mesmo select (nesse caso continua visível mesmo que a data mude depois).
  const sameAccount = accountId === initialAccountId
  const sameDate = initialDate === undefined || date === initialDate
  const statementOverridden = statementId !== initialStatementId
  const showStatementSelect = mode === 'edit' && isCreditCard && sameAccount && (sameDate || statementOverridden)

  const dateValid = typeof date === 'string' && isDateOnly(date)
  const previewEnabled = isCreditCard && !showStatementSelect && accountId !== null && dateValid
  const preview = useStatementPreview(previewEnabled ? accountId : null, previewEnabled ? date : null)
  // `useStatementPreview` usa `placeholderData: keepPreviousData` (único uso do hook) pra não
  // piscar vazio a cada tecla digitada na data; só que isso também mantém o valor da fatura
  // anterior enquanto a prévia da nova conta/data ainda não chegou ou quando a prévia está
  // desabilitada — por isso só mostramos o texto quando a consulta está habilitada e sem erro.
  const previewData = previewEnabled && !preview.isError ? preview.data : undefined

  const statements = useCardStatements(showStatementSelect ? (accountId ?? null) : null)

  if (!isCreditCard) return null

  return (
    <div className="space-y-3 rounded-xl border border-border p-3">
      {mode === 'create' && direction === 'out' && (
        <Field label="Parcelas" htmlFor="entry-installments" error={errors.installments?.message}>
          {(control) => (
            <Controller
              control={form.control}
              name="installments"
              render={({ field }) => (
                <Select value={String(field.value)} onValueChange={(next) => field.onChange(Number(next))}>
                  <SelectTrigger {...control} className="w-full">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {INSTALLMENT_OPTIONS.map((number) => (
                      <SelectItem key={number} value={String(number)}>
                        {number === 1 ? 'À vista' : `${number}x`}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              )}
            />
          )}
        </Field>
      )}

      {showStatementSelect ? (
        <Field label="Fatura" htmlFor="entry-statement" error={errors.statement_id?.message}>
          {(control) => (
            <Controller
              control={form.control}
              name="statement_id"
              render={({ field }) => (
                <Select
                  value={field.value === null ? '' : String(field.value)}
                  onValueChange={(next) => field.onChange(Number(next))}
                >
                  <SelectTrigger {...control} className="w-full">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {(statements.data ?? []).map((statement) => (
                      <SelectItem key={statement.id} value={String(statement.id)}>
                        {`Vence ${formatDate(statement.due_date)} · ${STATUS_LABELS[statement.status]}`}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              )}
            />
          )}
        </Field>
      ) : (
        previewData && (
          <p aria-live="polite" className="text-sm text-muted-foreground">
            {installments > 1
              ? `Primeira parcela na fatura que vence em ${formatDate(previewData.due_date)}. O valor informado é o total da compra.`
              : `Entra na fatura que vence em ${formatDate(previewData.due_date)}.`}
          </p>
        )
      )}
    </div>
  )
}
