import { Controller, useWatch, type UseFormReturn } from 'react-hook-form'
import type { Frequency } from '@/api/types'
import { Field } from '@/components/form/field'
import { DateInput } from '@/components/shared/date-input'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import type { RecurrenceFormValues } from './recurrence-form-dialog'
import { FREQUENCY_LABELS, intervalHint as describeInterval } from './recurrence-labels'

const FREQUENCIES: Frequency[] = ['weekly', 'monthly', 'yearly']

/** Frequência, intervalo, dia do mês (só mensal) e início/fim — ver `recurrence-form-dialog.tsx`. */
export function RecurrenceScheduleFields({ form }: { form: UseFormReturn<RecurrenceFormValues> }) {
  const { errors } = form.formState
  const frequency = useWatch({ control: form.control, name: 'frequency' })
  const interval = useWatch({ control: form.control, name: 'interval' })

  const intervalHint = describeInterval(frequency, Number(interval) || 1)

  return (
    <>
      <div className="grid gap-4 sm:grid-cols-2">
        <Field label="Frequência" htmlFor="recurrence-frequency" error={errors.frequency?.message}>
          {(control) => (
            <Controller
              control={form.control}
              name="frequency"
              render={({ field }) => (
                <Select value={field.value} onValueChange={field.onChange}>
                  <SelectTrigger {...control} className="w-full">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {FREQUENCIES.map((value) => (
                      <SelectItem key={value} value={value}>
                        {FREQUENCY_LABELS[value]}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              )}
            />
          )}
        </Field>
        <Field label="Intervalo" htmlFor="recurrence-interval" error={errors.interval?.message} hint={intervalHint}>
          <Input id="recurrence-interval" inputMode="numeric" maxLength={2} autoComplete="off" {...form.register('interval')} />
        </Field>
      </div>
      {frequency === 'monthly' && (
        <Field
          label="Dia do mês"
          htmlFor="recurrence-day-of-month"
          error={errors.day_of_month?.message}
          hint="Padrão: dia do início."
        >
          <Input
            id="recurrence-day-of-month"
            inputMode="numeric"
            maxLength={2}
            autoComplete="off"
            {...form.register('day_of_month')}
          />
        </Field>
      )}
      <div className="grid gap-4 sm:grid-cols-2">
        <Field label="Início" htmlFor="recurrence-starts-on" error={errors.starts_on?.message}>
          <Controller
            control={form.control}
            name="starts_on"
            render={({ field }) => <DateInput id="recurrence-starts-on" value={field.value} onChange={field.onChange} />}
          />
        </Field>
        <Field label="Fim" htmlFor="recurrence-ends-on" error={errors.ends_on?.message} hint="Opcional.">
          <Controller
            control={form.control}
            name="ends_on"
            render={({ field }) => <DateInput id="recurrence-ends-on" value={field.value} onChange={field.onChange} />}
          />
        </Field>
      </div>
    </>
  )
}
