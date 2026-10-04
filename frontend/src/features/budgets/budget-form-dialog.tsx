import { zodResolver } from '@hookform/resolvers/zod'
import { LoaderCircle } from 'lucide-react'
import { useEffect } from 'react'
import { Controller, useForm, useWatch } from 'react-hook-form'
import { toast } from 'sonner'
import { z } from 'zod'
import { useSaveBudget } from '@/api/queries/budgets'
import type { BudgetItem } from '@/api/types'
import { Field } from '@/components/form/field'
import { CategoryIcon } from '@/components/shared/category-icon'
import { CategoryPicker } from '@/components/shared/category-picker'
import { MoneyInput } from '@/components/shared/money-input'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import { applyFieldErrors, notifyError } from '@/lib/form-errors'
import { BudgetScopeToggle, type BudgetScope } from './budget-scope-toggle'

const schema = z
  .object({
    category_id: z.number().nullable(),
    scope: z.enum(['default', 'month']),
    noBudgetThisMonth: z.boolean(),
    amount: z.number().nullable(),
  })
  .superRefine((values, ctx) => {
    if (values.category_id === null) {
      ctx.addIssue({ code: 'custom', message: 'Escolha a categoria.', path: ['category_id'] })
    }
    // "Sem orçamento só neste mês" é a única situação em que amount pode ficar vazio: o envio
    // fixa 0 nesse caso (ver onSubmit), que é o valor que o backend entende como "sem orçamento".
    const skipAmount = values.scope === 'month' && values.noBudgetThisMonth
    if (!skipAmount && (values.amount === null || values.amount <= 0)) {
      ctx.addIssue({ code: 'custom', message: 'Informe um valor maior que zero.', path: ['amount'] })
    }
  })

export type BudgetFormValues = z.input<typeof schema>

type BudgetFormDialogProps = {
  open: boolean
  onOpenChange: (open: boolean) => void
  month: string
  /** Sem item: orçar uma categoria nova. Com item: editar a categoria já orçada (categoria fixa). */
  item?: BudgetItem
}

function defaultsFor(item: BudgetItem | undefined): BudgetFormValues {
  return {
    category_id: item?.category.id ?? null,
    scope: item?.source === 'override' ? 'month' : 'default',
    noBudgetThisMonth: false,
    amount: item?.amount ?? null,
  }
}

export function BudgetFormDialog({ open, onOpenChange, month, item }: BudgetFormDialogProps) {
  const save = useSaveBudget()

  const form = useForm<BudgetFormValues>({
    resolver: zodResolver(schema),
    defaultValues: defaultsFor(item),
  })

  // Reseta ao abrir, inclusive trocando de item: `values` do RHF não reabriria o form quando o
  // objeto computado for igual ao anterior (ver recurrence-form-dialog.tsx).
  useEffect(() => {
    if (open) form.reset(defaultsFor(item))
  }, [open, item, form])

  const scope = useWatch({ control: form.control, name: 'scope' })
  const noBudgetThisMonth = useWatch({ control: form.control, name: 'noBudgetThisMonth' })
  const skipAmount = scope === 'month' && noBudgetThisMonth

  const onSubmit = form.handleSubmit(async (values) => {
    try {
      await save.mutateAsync({
        category_id: values.category_id as number,
        amount: skipAmount ? 0 : (values.amount as number),
        ...(values.scope === 'month' ? { month } : {}),
      })
      toast.success(item ? 'Orçamento atualizado.' : 'Categoria orçada.')
      onOpenChange(false)
    } catch (error) {
      if (!applyFieldErrors(error, form.setError, ['category_id', 'amount'])) notifyError(error)
    }
  })

  const { errors } = form.formState

  return (
    <Dialog open={open} onOpenChange={(next) => !save.isPending && onOpenChange(next)}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{item ? `Editar orçamento de ${item.category.name}` : 'Orçar categoria'}</DialogTitle>
          <DialogDescription>
            {item ? 'Ajuste o valor ou o período em que ele vale.' : 'Defina um limite mensal para a categoria.'}
          </DialogDescription>
        </DialogHeader>
        <form id="budget-form" className="space-y-4" onSubmit={onSubmit} noValidate>
          {item ? (
            <div className="flex items-center gap-3 rounded-xl border border-border p-3">
              <CategoryIcon icon={item.category.icon} color={item.category.color} />
              <span className="font-medium">{item.category.name}</span>
            </div>
          ) : (
            <Field label="Categoria" htmlFor="budget-category" error={errors.category_id?.message}>
              {(control) => (
                <Controller
                  control={form.control}
                  name="category_id"
                  render={({ field }) => (
                    <CategoryPicker {...control} kind="expense" allowNone={false} value={field.value} onChange={field.onChange} />
                  )}
                />
              )}
            </Field>
          )}

          <div className="space-y-2">
            <Label>Vale para</Label>
            <Controller
              control={form.control}
              name="scope"
              render={({ field }) => (
                <BudgetScopeToggle value={field.value as BudgetScope} onChange={field.onChange} month={month} />
              )}
            />
          </div>

          {scope === 'month' && (
            <div className="flex items-start gap-3 rounded-xl border border-border p-3">
              <Controller
                control={form.control}
                name="noBudgetThisMonth"
                render={({ field }) => (
                  <Switch id="budget-no-this-month" checked={field.value} onCheckedChange={field.onChange} />
                )}
              />
              <div className="space-y-1">
                <label htmlFor="budget-no-this-month" className="text-sm font-medium">
                  Sem orçamento só neste mês
                </label>
                <p className="text-xs text-muted-foreground">A categoria some da lista neste mês; o padrão continua nos outros.</p>
              </div>
            </div>
          )}

          {!skipAmount && (
            <Field label="Valor" htmlFor="budget-amount" error={errors.amount?.message}>
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
          )}
        </form>
        <DialogFooter>
          <Button type="button" variant="outline" disabled={save.isPending} onClick={() => onOpenChange(false)}>
            Cancelar
          </Button>
          <Button type="submit" form="budget-form" disabled={save.isPending}>
            {save.isPending && <LoaderCircle className="h-4 w-4 animate-spin" />}
            Salvar
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
