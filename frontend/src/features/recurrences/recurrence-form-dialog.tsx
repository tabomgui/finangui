import { zodResolver } from '@hookform/resolvers/zod'
import { LoaderCircle } from 'lucide-react'
import { useEffect } from 'react'
import { Controller, useForm, useWatch } from 'react-hook-form'
import { toast } from 'sonner'
import { z } from 'zod'
import { useCreateRecurrence, useUpdateRecurrence } from '@/api/queries/recurrences'
import type { Recurrence } from '@/api/types'
import { Field } from '@/components/form/field'
import { AccountSelect } from '@/components/shared/account-select'
import { CategoryPicker } from '@/components/shared/category-picker'
import { MoneyInput } from '@/components/shared/money-input'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { isDateOnly, today } from '@/lib/date'
import { applyFieldErrors, notifyError } from '@/lib/form-errors'
import { RecurrenceScheduleFields } from './recurrence-schedule-fields'

const INTERVAL_PATTERN = /^\d{1,2}$/
const DAY_PATTERN = /^\d{1,2}$/
// \p{L} exige ao menos uma letra — mesma regra do backend (StoreRecurrenceRequest::$match_pattern):
// um padrão só com dígitos ou pontuação normaliza para vazio e casaria qualquer descrição.
const HAS_LETTER = /\p{L}/u

const schema = z
  .object({
    description: z.string().trim().min(1, 'Informe a descrição.').max(255, 'Use no máximo 255 caracteres.'),
    direction: z.enum(['in', 'out']),
    amount: z.number().nullable().refine((value) => value !== null && value > 0, 'Informe um valor maior que zero.'),
    account_id: z.number().nullable().refine((value) => value !== null, 'Escolha a conta.'),
    category_id: z.number().nullable(),
    frequency: z.enum(['weekly', 'monthly', 'yearly']),
    interval: z.string(),
    // Vazio: a recorrência usa o dia de `starts_on` (padrão do backend). Só mensal; ver schedule fields.
    day_of_month: z.string(),
    starts_on: z.string().refine(isDateOnly, 'Informe a data de início.'),
    ends_on: z.string(),
    match_pattern: z.string().max(80, 'Use no máximo 80 caracteres.'),
  })
  .superRefine((values, ctx) => {
    const interval = Number(values.interval)
    if (!INTERVAL_PATTERN.test(values.interval) || interval < 1 || interval > 12) {
      ctx.addIssue({ code: 'custom', message: 'Informe um intervalo entre 1 e 12.', path: ['interval'] })
    }

    if (values.frequency === 'monthly' && values.day_of_month !== '') {
      const day = Number(values.day_of_month)
      if (!DAY_PATTERN.test(values.day_of_month) || day < 1 || day > 31) {
        ctx.addIssue({ code: 'custom', message: 'Informe um dia entre 1 e 31.', path: ['day_of_month'] })
      }
    }

    if (values.ends_on !== '' && isDateOnly(values.ends_on) && values.ends_on < values.starts_on) {
      ctx.addIssue({ code: 'custom', message: 'O fim não pode ser antes do início.', path: ['ends_on'] })
    }

    if (values.match_pattern !== '' && !HAS_LETTER.test(values.match_pattern)) {
      ctx.addIssue({ code: 'custom', message: 'Inclua ao menos uma letra.', path: ['match_pattern'] })
    }
  })

export type RecurrenceFormValues = z.input<typeof schema>

type RecurrenceFormDialogProps = {
  open: boolean
  onOpenChange: (open: boolean) => void
  /** Sem recorrência: criação. */
  recurrence?: Recurrence
}

function defaultsFor(recurrence: Recurrence | undefined): RecurrenceFormValues {
  if (recurrence) {
    return {
      description: recurrence.description,
      direction: recurrence.direction,
      amount: recurrence.amount,
      account_id: recurrence.account_id,
      category_id: recurrence.category_id,
      frequency: recurrence.frequency,
      interval: String(recurrence.interval),
      day_of_month: recurrence.day_of_month !== null ? String(recurrence.day_of_month) : '',
      starts_on: recurrence.starts_on,
      ends_on: recurrence.ends_on ?? '',
      match_pattern: recurrence.match_pattern ?? '',
    }
  }

  return {
    description: '',
    direction: 'out',
    amount: null,
    account_id: null,
    category_id: null,
    frequency: 'monthly',
    interval: '1',
    day_of_month: '',
    starts_on: today(),
    ends_on: '',
    match_pattern: '',
  }
}

export function RecurrenceFormDialog({ open, onOpenChange, recurrence }: RecurrenceFormDialogProps) {
  const create = useCreateRecurrence()
  const update = useUpdateRecurrence()
  const pending = create.isPending || update.isPending

  const form = useForm<RecurrenceFormValues>({
    resolver: zodResolver(schema),
    defaultValues: defaultsFor(recurrence),
  })

  // `values` do RHF não reabre o formulário quando o objeto computado é igual ao anterior: reseta
  // explicitamente toda vez que o diálogo abre (padrão do projeto, ver `account-form-dialog.tsx`).
  useEffect(() => {
    if (open) form.reset(defaultsFor(recurrence))
  }, [open, recurrence, form])

  const onSubmit = form.handleSubmit(async (values) => {
    const base = {
      account_id: values.account_id as number,
      category_id: values.category_id,
      description: values.description.trim(),
      amount: values.amount as number,
      frequency: values.frequency,
      interval: Number(values.interval),
      day_of_month: values.frequency === 'monthly' && values.day_of_month !== '' ? Number(values.day_of_month) : null,
      starts_on: values.starts_on,
      ends_on: values.ends_on === '' ? null : values.ends_on,
      match_pattern: values.match_pattern.trim() === '' ? null : values.match_pattern.trim(),
    }

    try {
      if (recurrence) {
        // direction é imutável depois de criada: nunca entra no corpo de um PATCH (prohibited no backend).
        await update.mutateAsync({ id: recurrence.id, body: base })
      } else {
        await create.mutateAsync({ ...base, direction: values.direction })
      }
      toast.success(recurrence ? 'Recorrência atualizada.' : 'Recorrência criada.')
      onOpenChange(false)
    } catch (error) {
      if (
        !applyFieldErrors(error, form.setError, [
          'description',
          'direction',
          'amount',
          'account_id',
          'category_id',
          'frequency',
          'interval',
          'day_of_month',
          'starts_on',
          'ends_on',
          'match_pattern',
        ])
      )
        notifyError(error)
    }
  })

  const { errors } = form.formState
  const direction = useWatch({ control: form.control, name: 'direction' })

  return (
    <Dialog open={open} onOpenChange={(next) => !pending && onOpenChange(next)}>
      <DialogContent className="max-h-[90dvh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle>{recurrence ? 'Editar recorrência' : 'Nova recorrência'}</DialogTitle>
          <DialogDescription>Lançamentos previstos são gerados até o fim do próximo mês.</DialogDescription>
        </DialogHeader>
        <form id="recurrence-form" className="space-y-4" onSubmit={onSubmit} noValidate>
          <Field label="Descrição" htmlFor="recurrence-description" error={errors.description?.message}>
            <Input id="recurrence-description" autoComplete="off" {...form.register('description')} />
          </Field>
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label="Tipo" htmlFor="recurrence-direction" error={errors.direction?.message}>
              {(control) => (
                <Controller
                  control={form.control}
                  name="direction"
                  render={({ field }) => (
                    <Select
                      value={field.value}
                      onValueChange={(next) => {
                        field.onChange(next)
                        // Categoria é filtrada por tipo: a escolhida para despesa não existe (ou não
                        // é compatível) do lado de receita, e vice-versa.
                        form.setValue('category_id', null)
                      }}
                      disabled={recurrence !== undefined}
                    >
                      <SelectTrigger {...control} className="w-full">
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        <SelectItem value="out">Despesa</SelectItem>
                        <SelectItem value="in">Receita</SelectItem>
                      </SelectContent>
                    </Select>
                  )}
                />
              )}
            </Field>
            <Field label="Valor" htmlFor="recurrence-amount" error={errors.amount?.message}>
              {(control) => (
                <Controller
                  control={form.control}
                  name="amount"
                  render={({ field }) => (
                    <MoneyInput {...control} value={field.value} onChange={field.onChange} onBlur={field.onBlur} />
                  )}
                />
              )}
            </Field>
          </div>
          <Field label="Conta" htmlFor="recurrence-account" error={errors.account_id?.message}>
            {(control) => (
              <Controller
                control={form.control}
                name="account_id"
                render={({ field }) => <AccountSelect {...control} value={field.value} onChange={field.onChange} />}
              />
            )}
          </Field>
          <Field label="Categoria" htmlFor="recurrence-category" error={errors.category_id?.message}>
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
          <RecurrenceScheduleFields form={form} />
          <Field
            label="Texto que identifica no extrato"
            htmlFor="recurrence-match-pattern"
            error={errors.match_pattern?.message}
            hint="Opcional: ajuda a casar com o lançamento real quando a descrição do banco vier diferente."
          >
            <Input id="recurrence-match-pattern" autoComplete="off" {...form.register('match_pattern')} />
          </Field>
        </form>
        <DialogFooter>
          <Button type="button" variant="outline" disabled={pending} onClick={() => onOpenChange(false)}>
            Cancelar
          </Button>
          <Button type="submit" form="recurrence-form" disabled={pending}>
            {pending && <LoaderCircle className="h-4 w-4 animate-spin" />}
            {recurrence ? 'Salvar' : 'Criar recorrência'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
