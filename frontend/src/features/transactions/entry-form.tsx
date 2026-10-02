import { zodResolver } from '@hookform/resolvers/zod'
import { LoaderCircle } from 'lucide-react'
import { useEffect } from 'react'
import { Controller, useForm, useWatch } from 'react-hook-form'
import { useCategories } from '@/api/queries/categories'
import { Field } from '@/components/form/field'
import { AccountSelect } from '@/components/shared/account-select'
import { CategoryPicker } from '@/components/shared/category-picker'
import { DateInput } from '@/components/shared/date-input'
import { MoneyInput } from '@/components/shared/money-input'
import { TagPicker } from '@/components/shared/tag-picker'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Switch } from '@/components/ui/switch'
import { Textarea } from '@/components/ui/textarea'
import { applyFieldErrors, notifyError } from '@/lib/form-errors'
import { entrySchema, type EntryValues } from './form-values'

const FIELDS = ['direction', 'account_id', 'amount', 'date', 'description', 'category_id', 'tag_ids', 'notes', 'is_ignored'] as const

type EntryFormProps = {
  defaultValues: EntryValues
  onSubmit: (values: EntryValues) => Promise<void>
  submitLabel: string
  showIgnore?: boolean
}

export function EntryForm({ defaultValues, onSubmit, submitLabel, showIgnore = false }: EntryFormProps) {
  const { data: categories = [] } = useCategories(true)
  const form = useForm<EntryValues>({ resolver: zodResolver(entrySchema), defaultValues })
  const { errors, isSubmitting } = form.formState
  const direction = useWatch({ control: form.control, name: 'direction' })
  const categoryId = useWatch({ control: form.control, name: 'category_id' })

  // Despesa usa categorias de despesa e receita de receita: trocar o sentido limpa uma categoria incompatível.
  useEffect(() => {
    const selected = categories.find((category) => category.id === categoryId)
    const expectedKind = direction === 'out' ? 'expense' : 'income'
    if (selected && selected.kind !== expectedKind) form.setValue('category_id', null)
  }, [direction, categoryId, categories, form])

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
          <Field label="Valor" htmlFor="entry-amount" error={errors.amount?.message}>
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
          <Field label="Descrição" htmlFor="entry-description" error={errors.description?.message}>
            <Input id="entry-description" autoComplete="off" {...form.register('description')} />
          </Field>
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label="Conta" htmlFor="entry-account" error={errors.account_id?.message}>
              {(control) => (
                <Controller
                  control={form.control}
                  name="account_id"
                  render={({ field }) => <AccountSelect {...control} value={field.value} onChange={field.onChange} />}
                />
              )}
            </Field>
            <Field label="Data" htmlFor="entry-date" error={errors.date?.message}>
              <Controller
                control={form.control}
                name="date"
                render={({ field }) => <DateInput id="entry-date" value={field.value} onChange={field.onChange} />}
              />
            </Field>
          </div>
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
