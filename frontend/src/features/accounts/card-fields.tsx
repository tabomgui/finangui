import { Controller, type UseFormReturn } from 'react-hook-form'
import { Field } from '@/components/form/field'
import { MoneyInput } from '@/components/shared/money-input'
import { Input } from '@/components/ui/input'
import type { AccountFormValues } from './account-form-dialog'

export function CardFields({ form }: { form: UseFormReturn<AccountFormValues> }) {
  const { errors } = form.formState
  return (
    <>
      <Field label="Limite" htmlFor="account-credit-limit" error={errors.credit_limit?.message}>
        {(control) => (
          <Controller
            control={form.control}
            name="credit_limit"
            render={({ field }) => <MoneyInput {...control} value={field.value} onChange={field.onChange} onBlur={field.onBlur} />}
          />
        )}
      </Field>
      <div className="grid grid-cols-2 gap-4">
        <Field label="Dia de fechamento" htmlFor="account-closing-day" error={errors.closing_day?.message}>
          <Input id="account-closing-day" inputMode="numeric" maxLength={2} autoComplete="off" {...form.register('closing_day')} />
        </Field>
        <Field label="Dia de vencimento" htmlFor="account-due-day" error={errors.due_day?.message}>
          <Input id="account-due-day" inputMode="numeric" maxLength={2} autoComplete="off" {...form.register('due_day')} />
        </Field>
      </div>
      <p className="text-xs text-muted-foreground">Mudar os dias vale para as faturas futuras.</p>
      <Field label="Final do cartão" htmlFor="account-last-four" error={errors.last_four?.message} hint="Opcional: os 4 últimos dígitos.">
        <Input id="account-last-four" inputMode="numeric" maxLength={4} autoComplete="off" {...form.register('last_four')} />
      </Field>
    </>
  )
}
