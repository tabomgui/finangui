import { Plus, Trash2 } from 'lucide-react'
import { Controller, useFieldArray, useWatch, type UseFormReturn } from 'react-hook-form'
import { useTags } from '@/api/queries/tags'
import type { RuleActionTypeName } from '@/api/types'
import { Field, type FieldControlProps } from '@/components/form/field'
import { CategoryPicker } from '@/components/shared/category-picker'
import { Button } from '@/components/ui/button'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { rootErrorMessage } from '@/lib/form-errors'
import { ACTION_LABELS } from './rule-labels'
import { emptyAction, type ActionValues, type RuleFormValues } from './rule-form-values'

type ActionListProps = {
  form: UseFormReturn<RuleFormValues>
}

type ActionFieldError = { message?: string } | undefined

type ActionErrors = {
  category_id?: ActionFieldError
  tag_id?: ActionFieldError
  value?: ActionFieldError
}

const ACTION_TYPES: RuleActionTypeName[] = ['set_category', 'set_description', 'set_payee', 'add_tag', 'ignore']

// Só `add_tag` pode se repetir numa regra (até 5 tags); os outros tipos ficam desabilitados
// no menu depois de usados uma vez. O limite exato é validado de novo pelo backend.
const UNIQUE_TYPES: RuleActionTypeName[] = ['set_category', 'set_description', 'set_payee', 'ignore']

function TagSelect({ value, onChange, ...control }: { value: number | null; onChange: (tagId: number) => void } & Partial<FieldControlProps>) {
  const { data: tags = [] } = useTags()
  return (
    <Select value={value === null ? undefined : String(value)} onValueChange={(next) => onChange(Number(next))}>
      <SelectTrigger {...control} className="w-full">
        <SelectValue placeholder="Escolha a tag" />
      </SelectTrigger>
      <SelectContent>
        {tags.map((tag) => (
          <SelectItem key={tag.id} value={String(tag.id)}>
            #{tag.name}
          </SelectItem>
        ))}
      </SelectContent>
    </Select>
  )
}

type ActionRowProps = {
  value: ActionValues
  onChange: (next: ActionValues) => void
  onRemove: () => void
  errors?: ActionErrors
  idPrefix: string
  rowLabel: string
}

function ActionRow({ value, onChange, onRemove, errors, idPrefix, rowLabel }: ActionRowProps) {
  return (
    <div className="flex flex-col gap-2 rounded-xl border border-border p-3 sm:flex-row sm:items-start sm:gap-3">
      <div className="flex-1 space-y-2">
        <p className="text-sm font-medium">{ACTION_LABELS[value.type]}</p>

        {value.type === 'set_category' && (
          <Field label="Categoria" htmlFor={`${idPrefix}-category`} error={errors?.category_id?.message}>
            {(control) => (
              <CategoryPicker
                {...control}
                allowNone={false}
                value={value.category_id}
                onChange={(categoryId) => onChange({ ...value, category_id: categoryId })}
              />
            )}
          </Field>
        )}

        {(value.type === 'set_description' || value.type === 'set_payee') && (
          <Field
            label={value.type === 'set_description' ? 'Nova descrição' : 'Novo favorecido'}
            htmlFor={`${idPrefix}-value`}
            error={errors?.value?.message}
          >
            {/* `Field` só injeta aria-describedby/aria-invalid por clone — sem `id` explícito aqui,
                o `htmlFor` do label acima não casava com nada. */}
            <Input id={`${idPrefix}-value`} value={value.value} onChange={(event) => onChange({ ...value, value: event.target.value })} />
          </Field>
        )}

        {value.type === 'add_tag' && (
          <Field label="Tag" htmlFor={`${idPrefix}-tag`} error={errors?.tag_id?.message}>
            {(control) => <TagSelect {...control} value={value.tag_id} onChange={(tagId) => onChange({ ...value, tag_id: tagId })} />}
          </Field>
        )}

        {value.type === 'ignore' && <p className="text-sm text-muted-foreground">O lançamento fica marcado como ignorado.</p>}
      </div>
      <Button type="button" variant="ghost" size="icon" aria-label={`Remover ${rowLabel}`} onClick={onRemove}>
        <Trash2 className="h-4 w-4" />
      </Button>
    </div>
  )
}

export function ActionList({ form }: ActionListProps) {
  const { fields, append, remove } = useFieldArray({ control: form.control, name: 'actions' })
  const actions = useWatch({ control: form.control, name: 'actions' })
  const actionsError = form.formState.errors.actions
  const rootMessage = rootErrorMessage(actionsError)
  const itemErrors = actionsError as unknown as ActionErrors[] | undefined

  const usedTypes = new Set(actions.map((action) => action.type))

  return (
    <div className="space-y-3">
      {rootMessage && <p className="text-sm text-destructive">{rootMessage}</p>}

      <div className="space-y-2">
        {fields.map((field, index) => (
          <Controller
            key={field.id}
            control={form.control}
            name={`actions.${index}`}
            render={({ field: controllerField }) => (
              <ActionRow
                idPrefix={`action-${index}`}
                rowLabel={`ação ${index + 1}`}
                value={controllerField.value}
                onChange={controllerField.onChange}
                onRemove={() => remove(index)}
                errors={itemErrors?.[index]}
              />
            )}
          />
        ))}
      </div>

      <DropdownMenu>
        <DropdownMenuTrigger asChild>
          <Button type="button" variant="outline">
            <Plus className="h-4 w-4" />
            Adicionar ação
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="start">
          {ACTION_TYPES.map((type) => (
            <DropdownMenuItem
              key={type}
              disabled={UNIQUE_TYPES.includes(type) && usedTypes.has(type)}
              onSelect={() => append(emptyAction(type))}
            >
              {ACTION_LABELS[type]}
            </DropdownMenuItem>
          ))}
        </DropdownMenuContent>
      </DropdownMenu>
    </div>
  )
}
