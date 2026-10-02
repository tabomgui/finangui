import { zodResolver } from '@hookform/resolvers/zod'
import { LoaderCircle } from 'lucide-react'
import { Controller, useForm, useWatch } from 'react-hook-form'
import { Field } from '@/components/form/field'
import { AccountSelect } from '@/components/shared/account-select'
import { DateInput } from '@/components/shared/date-input'
import { MoneyInput } from '@/components/shared/money-input'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Textarea } from '@/components/ui/textarea'
import { applyFieldErrors, notifyError } from '@/lib/form-errors'
import { transferSchema, type TransferValues } from './form-values'

const FIELDS = ['from_account_id', 'to_account_id', 'amount', 'date', 'description', 'notes'] as const

type TransferFormProps = {
  defaultValues: TransferValues
  onSubmit: (values: TransferValues) => Promise<void>
  submitLabel: string
}

export function TransferForm({ defaultValues, onSubmit, submitLabel }: TransferFormProps) {
  const form = useForm<TransferValues>({ resolver: zodResolver(transferSchema), defaultValues })
  const { errors, isSubmitting } = form.formState
  const fromAccountId = useWatch({ control: form.control, name: 'from_account_id' })

  const submit = form.handleSubmit(async (values) => {
    try {
      await onSubmit(values)
    } catch (error) {
      if (!applyFieldErrors(error, form.setError, FIELDS)) notifyError(error)
    }
  })

  return (
    <form className="space-y-4" onSubmit={submit} noValidate>
      <Card className="rounded-2xl shadow-card">
        <CardContent className="space-y-4 pt-6">
          <Field label="Valor" htmlFor="transfer-amount" error={errors.amount?.message}>
            {(control) => (
              <Controller
                control={form.control}
                name="amount"
                render={({ field }) => (
                  <MoneyInput {...control} value={field.value} onChange={field.onChange} onBlur={field.onBlur} autoFocus />
                )}
              />
            )}
          </Field>
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label="De" htmlFor="transfer-from" error={errors.from_account_id?.message}>
              {(control) => (
                <Controller
                  control={form.control}
                  name="from_account_id"
                  render={({ field }) => <AccountSelect {...control} value={field.value} onChange={field.onChange} />}
                />
              )}
            </Field>
            <Field label="Para" htmlFor="transfer-to" error={errors.to_account_id?.message}>
              {(control) => (
                <Controller
                  control={form.control}
                  name="to_account_id"
                  render={({ field }) => (
                    <AccountSelect {...control} value={field.value} onChange={field.onChange} excludeId={fromAccountId} />
                  )}
                />
              )}
            </Field>
          </div>
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label="Descrição" htmlFor="transfer-description" error={errors.description?.message}>
              <Input id="transfer-description" autoComplete="off" {...form.register('description')} />
            </Field>
            <Field label="Data" htmlFor="transfer-date" error={errors.date?.message}>
              <Controller
                control={form.control}
                name="date"
                render={({ field }) => <DateInput id="transfer-date" value={field.value} onChange={field.onChange} />}
              />
            </Field>
          </div>
          <Field label="Notas" htmlFor="transfer-notes" error={errors.notes?.message}>
            <Textarea id="transfer-notes" rows={3} {...form.register('notes')} />
          </Field>
        </CardContent>
      </Card>
      <Button type="submit" className="w-full rounded-2xl py-6 text-base" disabled={isSubmitting}>
        {isSubmitting && <LoaderCircle className="h-4 w-4 animate-spin" />}
        {submitLabel}
      </Button>
    </form>
  )
}
