import { zodResolver } from '@hookform/resolvers/zod'
import { LoaderCircle } from 'lucide-react'
import { useEffect } from 'react'
import { Controller, useForm, useWatch } from 'react-hook-form'
import { toast } from 'sonner'
import { z } from 'zod'
import { useCreateGoal, useUpdateGoal } from '@/api/queries/goals'
import type { Goal } from '@/api/types'
import { Field } from '@/components/form/field'
import { AccountSelect } from '@/components/shared/account-select'
import { ColorPicker } from '@/components/shared/color-picker'
import { DateInput } from '@/components/shared/date-input'
import { IconPicker } from '@/components/shared/icon-picker'
import { MoneyInput } from '@/components/shared/money-input'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Switch } from '@/components/ui/switch'
import { applyFieldErrors, notifyError } from '@/lib/form-errors'
import { DEFAULT_COLOR } from '@/lib/palette'

const schema = z
  .object({
    name: z.string().trim().min(1, 'Informe o nome da meta.').max(60, 'Use no máximo 60 caracteres.'),
    target_amount: z.number().nullable(),
    hasDate: z.boolean(),
    target_date: z.string(),
    trackAccount: z.boolean(),
    account_id: z.number().nullable(),
    color: z.string().nullable(),
    icon: z.string().nullable(),
  })
  .superRefine((values, ctx) => {
    if (values.target_amount === null || values.target_amount <= 0) {
      ctx.addIssue({ code: 'custom', message: 'Informe um valor maior que zero.', path: ['target_amount'] })
    }
    if (values.hasDate && values.target_date === '') {
      ctx.addIssue({ code: 'custom', message: 'Informe a data.', path: ['target_date'] })
    }
    if (values.trackAccount && values.account_id === null) {
      ctx.addIssue({ code: 'custom', message: 'Escolha a conta.', path: ['account_id'] })
    }
  })

export type GoalFormValues = z.input<typeof schema>

type GoalFormDialogProps = {
  open: boolean
  onOpenChange: (open: boolean) => void
  /** Sem meta: criação. */
  goal?: Goal
}

function defaultsFor(goal: Goal | undefined): GoalFormValues {
  return {
    name: goal?.name ?? '',
    target_amount: goal?.target_amount ?? null,
    hasDate: Boolean(goal?.target_date),
    target_date: goal?.target_date ?? '',
    trackAccount: (goal?.account_id ?? null) !== null,
    account_id: goal?.account_id ?? null,
    color: goal?.color ?? DEFAULT_COLOR,
    icon: goal?.icon ?? 'piggy-bank',
  }
}

export function GoalFormDialog({ open, onOpenChange, goal }: GoalFormDialogProps) {
  const create = useCreateGoal()
  const update = useUpdateGoal()
  const pending = create.isPending || update.isPending

  const form = useForm<GoalFormValues>({
    resolver: zodResolver(schema),
    defaultValues: defaultsFor(goal),
  })

  // Reseta ao abrir, inclusive trocando de meta: `values` do RHF não reabriria o form quando o
  // objeto computado for igual ao anterior (ver CLAUDE.md).
  useEffect(() => {
    if (open) form.reset(defaultsFor(goal))
  }, [open, goal, form])

  const hasDate = useWatch({ control: form.control, name: 'hasDate' })
  const trackAccount = useWatch({ control: form.control, name: 'trackAccount' })
  const color = useWatch({ control: form.control, name: 'color' })

  const onSubmit = form.handleSubmit(async (values) => {
    const body = {
      name: values.name.trim(),
      target_amount: values.target_amount as number,
      target_date: values.hasDate ? values.target_date : null,
      account_id: values.trackAccount ? (values.account_id as number) : null,
      color: values.color,
      icon: values.icon,
    }
    try {
      if (goal) {
        await update.mutateAsync({ id: goal.id, body })
      } else {
        await create.mutateAsync(body)
      }
      toast.success(goal ? 'Meta atualizada.' : 'Meta criada.')
      onOpenChange(false)
    } catch (error) {
      if (!applyFieldErrors(error, form.setError, ['name', 'target_amount', 'target_date', 'account_id', 'color', 'icon'])) {
        notifyError(error)
      }
    }
  })

  const { errors } = form.formState

  return (
    <Dialog open={open} onOpenChange={(next) => !pending && onOpenChange(next)}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{goal ? 'Editar meta' : 'Nova meta'}</DialogTitle>
          <DialogDescription>Defina quanto quer guardar e, se quiser, até quando.</DialogDescription>
        </DialogHeader>
        <form id="goal-form" className="space-y-4" onSubmit={onSubmit} noValidate>
          <Field label="Nome" htmlFor="goal-name" error={errors.name?.message}>
            <Input id="goal-name" autoComplete="off" {...form.register('name')} />
          </Field>

          <Field label="Valor da meta" htmlFor="goal-target-amount" error={errors.target_amount?.message}>
            {(control) => (
              <Controller
                control={form.control}
                name="target_amount"
                render={({ field }) => (
                  <MoneyInput {...control} value={field.value} onChange={field.onChange} onBlur={field.onBlur} />
                )}
              />
            )}
          </Field>

          <div className="space-y-2">
            <div className="flex items-start gap-3 rounded-xl border border-border p-3">
              <Controller
                control={form.control}
                name="hasDate"
                render={({ field }) => <Switch id="goal-has-date" checked={field.value} onCheckedChange={field.onChange} />}
              />
              <label htmlFor="goal-has-date" className="text-sm font-medium">
                Definir uma data-limite
              </label>
            </div>
            {hasDate && (
              <Field label="Data" htmlFor="goal-target-date" error={errors.target_date?.message}>
                <Controller
                  control={form.control}
                  name="target_date"
                  render={({ field }) => <DateInput id="goal-target-date" value={field.value} onChange={field.onChange} />}
                />
              </Field>
            )}
          </div>

          <div className="space-y-2">
            <div className="flex items-start gap-3 rounded-xl border border-border p-3">
              <Controller
                control={form.control}
                name="trackAccount"
                render={({ field }) => (
                  <Switch id="goal-track-account" checked={field.value} onCheckedChange={field.onChange} />
                )}
              />
              <div className="space-y-1">
                <label htmlFor="goal-track-account" className="text-sm font-medium">
                  Acompanhar saldo de uma conta
                </label>
                <p className="text-xs text-muted-foreground">
                  O progresso passa a ser o saldo da conta, em vez da soma de aportes manuais.
                </p>
              </div>
            </div>
            {trackAccount && (
              <Field label="Conta" htmlFor="goal-account" error={errors.account_id?.message}>
                {(control) => (
                  <Controller
                    control={form.control}
                    name="account_id"
                    render={({ field }) => (
                      <AccountSelect
                        {...control}
                        value={field.value}
                        onChange={field.onChange}
                        excludeTypes={['credit_card']}
                      />
                    )}
                  />
                )}
              </Field>
            )}
          </div>

          <Field label="Cor" htmlFor="goal-color">
            {(control) => (
              <Controller
                control={form.control}
                name="color"
                render={({ field }) => <ColorPicker id={control.id} value={field.value} onChange={field.onChange} />}
              />
            )}
          </Field>
          <Field label="Ícone" htmlFor="goal-icon">
            {(control) => (
              <Controller
                control={form.control}
                name="icon"
                render={({ field }) => <IconPicker id={control.id} value={field.value} color={color} onChange={field.onChange} />}
              />
            )}
          </Field>
        </form>
        <DialogFooter>
          <Button type="button" variant="outline" disabled={pending} onClick={() => onOpenChange(false)}>
            Cancelar
          </Button>
          <Button type="submit" form="goal-form" disabled={pending}>
            {pending && <LoaderCircle className="h-4 w-4 animate-spin" />}
            {goal ? 'Salvar' : 'Criar meta'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
