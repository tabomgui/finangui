import { zodResolver } from '@hookform/resolvers/zod'
import { Info, LoaderCircle } from 'lucide-react'
import { useEffect } from 'react'
import { Controller, useForm, useWatch } from 'react-hook-form'
import { Field } from '@/components/form/field'
import { AccountSelect } from '@/components/shared/account-select'
import { CategoryPicker } from '@/components/shared/category-picker'
import { DateInput } from '@/components/shared/date-input'
import { MoneyInput } from '@/components/shared/money-input'
import { TagPicker } from '@/components/shared/tag-picker'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Switch } from '@/components/ui/switch'
import { Textarea } from '@/components/ui/textarea'
import { FREQUENCY_LABELS } from '@/features/recurrences/recurrence-labels'
import { applyFieldErrors, notifyError } from '@/lib/form-errors'
import { CardEntryFields } from './card-entry-fields'
import { entrySchema, type EntryValues } from './form-values'

const FIELDS = [
  'direction',
  'account_id',
  'amount',
  'date',
  'description',
  'category_id',
  'tag_ids',
  'notes',
  'is_ignored',
  'installments',
  'statement_id',
] as const

type EntryFormProps = {
  defaultValues: EntryValues
  onSubmit: (values: EntryValues) => Promise<void>
  submitLabel: string
  showIgnore?: boolean
  /** Foco automático no valor: só numa transação nova, nunca ao editar (o campo já tem conteúdo). */
  autoFocusAmount?: boolean
  mode?: 'create' | 'edit'
  /** Quando presente, trava Valor/Conta/Data e mostra o motivo num aviso acima do formulário (ex.: parcela). */
  lockedReason?: string
}

export function EntryForm({
  defaultValues,
  onSubmit,
  submitLabel,
  showIgnore = false,
  autoFocusAmount = false,
  mode = 'create',
  lockedReason,
}: EntryFormProps) {
  const form = useForm<EntryValues>({ resolver: zodResolver(entrySchema), defaultValues })
  const { errors, isSubmitting } = form.formState
  const direction = useWatch({ control: form.control, name: 'direction' })
  const installments = useWatch({ control: form.control, name: 'installments' })
  const repeat = useWatch({ control: form.control, name: 'repeat' })
  const locked = lockedReason !== undefined
  // "Repetir" só faz sentido numa criação sem parcelas: parcelamento já tem seu próprio
  // calendário de ocorrências futuras.
  const canRepeat = mode === 'create' && installments === 1

  // Virar parcelado (ou deixar de ser criação, embora isso não aconteça em runtime) desliga
  // "Repetir" sozinho, para um valor antigo não sobreviver escondido até o envio.
  useEffect(() => {
    if (!canRepeat) form.setValue('repeat', false)
  }, [canRepeat, form])

  const submit = form.handleSubmit(async (values) => {
    try {
      await onSubmit(values)
    } catch (error) {
      if (!applyFieldErrors(error, form.setError, FIELDS)) notifyError(error)
    }
  })

  return (
    <form className="space-y-4" onSubmit={submit} noValidate>
      {lockedReason && (
        <div role="note" className="flex items-start gap-2 rounded-xl bg-muted p-3 text-sm text-muted-foreground">
          <Info className="h-4 w-4 shrink-0" />
          <p>{lockedReason}</p>
        </div>
      )}
      <Card className="rounded-2xl shadow-card">
        <CardContent className="space-y-4 pt-6">
          <Field label={installments > 1 ? 'Valor total' : 'Valor'} htmlFor="entry-amount" error={errors.amount?.message}>
            {(control) => (
              <Controller
                control={form.control}
                name="amount"
                render={({ field }) => (
                  <MoneyInput
                    {...control}
                    value={field.value}
                    onChange={field.onChange}
                    onBlur={field.onBlur}
                    autoFocus={autoFocusAmount}
                    disabled={locked}
                  />
                )}
              />
            )}
          </Field>
          <Field label="Descrição" htmlFor="entry-description" error={errors.description?.message}>
            <Input id="entry-description" autoComplete="off" {...form.register('description')} />
          </Field>
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label="Conta" htmlFor="entry-account" error={errors.account_id?.message}>
              {(control) => (
                <Controller
                  control={form.control}
                  name="account_id"
                  render={({ field }) => (
                    <AccountSelect
                      {...control}
                      value={field.value}
                      onChange={(accountId) => {
                        field.onChange(accountId)
                        // Trocar de conta invalida parcelas e fatura escolhidas: o backend recalcula
                        // a fatura automaticamente, e "parcelas" só faz sentido na conta de cartão
                        // original (ver `card-entry-fields.tsx`).
                        form.setValue('installments', 1)
                        form.setValue('statement_id', defaultValues.statement_id ?? null)
                      }}
                      disabled={locked}
                    />
                  )}
                />
              )}
            </Field>
            <Field label="Data" htmlFor="entry-date" error={errors.date?.message}>
              <Controller
                control={form.control}
                name="date"
                render={({ field }) => (
                  <DateInput id="entry-date" value={field.value} onChange={field.onChange} disabled={locked} />
                )}
              />
            </Field>
          </div>
          <CardEntryFields
            form={form}
            mode={mode}
            initialAccountId={defaultValues.account_id}
            initialDate={defaultValues.date}
            initialStatementId={defaultValues.statement_id ?? null}
          />
          <Field label="Categoria" htmlFor="entry-category" error={errors.category_id?.message}>
            {(control) => (
              <Controller
                control={form.control}
                name="category_id"
                render={({ field }) => (
                  <CategoryPicker
                    {...control}
                    kind={direction === 'out' ? 'expense' : 'income'}
                    value={field.value}
                    onChange={field.onChange}
                  />
                )}
              />
            )}
          </Field>
          <Field label="Tags" htmlFor="entry-tags" error={errors.tag_ids?.message}>
            {(control) => (
              <Controller
                control={form.control}
                name="tag_ids"
                render={({ field }) => <TagPicker {...control} value={field.value} onChange={field.onChange} />}
              />
            )}
          </Field>
          <Field label="Notas" htmlFor="entry-notes" error={errors.notes?.message}>
            <Textarea id="entry-notes" rows={3} {...form.register('notes')} />
          </Field>
          {canRepeat && (
            <div className="space-y-3 rounded-xl border border-border p-3">
              <Controller
                control={form.control}
                name="repeat"
                render={({ field }) => (
                  <div className="flex items-start gap-3">
                    <Switch id="entry-repeat" checked={field.value} onCheckedChange={field.onChange} />
                    <div className="space-y-1">
                      <label htmlFor="entry-repeat" className="text-sm font-medium">
                        Repetir
                      </label>
                      <p className="text-xs text-muted-foreground">Cria uma recorrência a partir deste lançamento.</p>
                    </div>
                  </div>
                )}
              />
              {repeat && (
                <Field label="Frequência" htmlFor="entry-repeat-frequency">
                  {(control) => (
                    <Controller
                      control={form.control}
                      name="repeat_frequency"
                      render={({ field }) => (
                        <Select value={field.value} onValueChange={field.onChange}>
                          <SelectTrigger {...control} className="w-full">
                            <SelectValue />
                          </SelectTrigger>
                          <SelectContent>
                            {Object.entries(FREQUENCY_LABELS).map(([value, label]) => (
                              <SelectItem key={value} value={value}>
                                {label}
                              </SelectItem>
                            ))}
                          </SelectContent>
                        </Select>
                      )}
                    />
                  )}
                </Field>
              )}
            </div>
          )}
          {showIgnore && (
            <Controller
              control={form.control}
              name="is_ignored"
              render={({ field }) => (
                <div className="flex items-start gap-3 rounded-xl border border-border p-3">
                  <Switch id="entry-ignored" checked={field.value} onCheckedChange={field.onChange} />
                  <div className="space-y-1">
                    <label htmlFor="entry-ignored" className="text-sm font-medium">
                      Ignorar este lançamento
                    </label>
                    <p className="text-xs text-muted-foreground">Fica fora do saldo e dos relatórios (ex.: duplicado).</p>
                  </div>
                </div>
              )}
            />
          )}
        </CardContent>
      </Card>
      <Button type="submit" className="w-full rounded-2xl py-6 text-base" disabled={isSubmitting}>
        {isSubmitting && <LoaderCircle className="h-4 w-4 animate-spin" />}
        {submitLabel}
      </Button>
    </form>
  )
}
