import { Plus, Trash2 } from 'lucide-react'
import { Controller, useFieldArray, useWatch, type UseFormReturn } from 'react-hook-form'
import { Button } from '@/components/ui/button'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { rootErrorMessage } from '@/lib/form-errors'
import { ConditionRow, type ConditionErrors } from './condition-row'
import { emptyCondition, emptyGroup, type ConditionOrGroupValues, type ConditionValues, type GroupValues, type RuleFormValues } from './rule-form-values'

type ConditionListProps = {
  form: UseFormReturn<RuleFormValues>
}

// O array de uma lista pode trazer, além dos erros por item, um erro do nível da lista inteira
// (zod `.min()`, ou um `setError` manual do servidor) — `rootErrorMessage` sabe ler os dois formatos.
type ConditionArrayErrors = ((ConditionErrors & { conditions?: ConditionArrayErrors }) | undefined)[] | undefined

type GroupRowProps = {
  value: GroupValues
  onChange: (next: GroupValues) => void
  onRemove: () => void
  errors?: ConditionArrayErrors
  groupIndex: number
}

function GroupRow({ value, onChange, onRemove, errors, groupIndex }: GroupRowProps) {
  const updateChild = (index: number, next: ConditionValues) =>
    onChange({ ...value, conditions: value.conditions.map((child, i) => (i === index ? next : child)) })
  const removeChild = (index: number) => onChange({ ...value, conditions: value.conditions.filter((_, i) => i !== index) })
  const addChild = () => onChange({ ...value, conditions: [...value.conditions, emptyCondition()] })
  const groupLabel = `grupo ${groupIndex + 1}`
  const rootMessage = rootErrorMessage(errors)

  return (
    <div className="space-y-3 rounded-xl border border-border p-3">
      <div className="flex flex-wrap items-center gap-2 text-sm">
        <span className="text-muted-foreground">Grupo: casar</span>
        <Select value={value.match} onValueChange={(match) => onChange({ ...value, match: match as 'all' | 'any' })}>
          <SelectTrigger className="h-8 w-auto" aria-label={`Como casar as condições do ${groupLabel}`}>
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="all">todas</SelectItem>
            <SelectItem value="any">qualquer uma</SelectItem>
          </SelectContent>
        </Select>
        <span className="text-muted-foreground">as condições</span>
        <Button type="button" variant="ghost" size="icon" className="ml-auto" aria-label={`Remover ${groupLabel}`} onClick={onRemove}>
          <Trash2 className="h-4 w-4" />
        </Button>
      </div>
      {rootMessage && <p className="text-sm text-destructive">{rootMessage}</p>}
      <div className="space-y-2">
        {value.conditions.map((child, index) => (
          <ConditionRow
            key={child.uid ?? index}
            idPrefix={`group-${groupIndex}-condition-${index}`}
            rowLabel={`condição ${index + 1} do ${groupLabel}`}
            value={child}
            onChange={(next) => updateChild(index, next)}
            onRemove={() => removeChild(index)}
            errors={errors?.[index]}
          />
        ))}
      </div>
      <Button type="button" variant="outline" size="sm" onClick={addChild}>
        <Plus className="h-4 w-4" />
        Adicionar condição ao grupo
      </Button>
    </div>
  )
}

export function ConditionList({ form }: ConditionListProps) {
  const { fields, append, remove } = useFieldArray({ control: form.control, name: 'conditions' })
  const match = useWatch({ control: form.control, name: 'match' })
  const conditionsError = form.formState.errors.conditions
  const rootMessage = rootErrorMessage(conditionsError)
  const itemErrors = conditionsError as unknown as ConditionArrayErrors

  return (
    <div className="space-y-3">
      <div className="flex flex-wrap items-center gap-2 text-sm">
        <span>Casar</span>
        <Controller
          control={form.control}
          name="match"
          render={({ field }) => (
            <Select value={field.value} onValueChange={field.onChange}>
              <SelectTrigger className="h-8 w-auto" aria-label="Como casar as condições">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">todas</SelectItem>
                <SelectItem value="any">qualquer uma</SelectItem>
              </SelectContent>
            </Select>
          )}
        />
        <span>as condições</span>
      </div>
      {rootMessage && <p className="text-sm text-destructive">{rootMessage}</p>}

      <div className="space-y-2">
        {fields.map((field, index) => (
          <Controller
            key={field.id}
            control={form.control}
            name={`conditions.${index}`}
            render={({ field: controllerField }) => {
              const current = controllerField.value as ConditionOrGroupValues
              if (current.kind === 'group') {
                return (
                  <GroupRow
                    value={current}
                    onChange={(next) => controllerField.onChange(next)}
                    onRemove={() => remove(index)}
                    errors={itemErrors?.[index]?.conditions}
                    groupIndex={index}
                  />
                )
              }
              return (
                <ConditionRow
                  idPrefix={`condition-${index}`}
                  rowLabel={`condição ${index + 1}`}
                  value={current}
                  onChange={(next) => controllerField.onChange(next)}
                  onRemove={() => remove(index)}
                  errors={itemErrors?.[index]}
                />
              )
            }}
          />
        ))}
      </div>

      <div className="flex flex-wrap gap-2">
        <Button type="button" variant="outline" onClick={() => append(emptyCondition())}>
          <Plus className="h-4 w-4" />
          Adicionar condição
        </Button>
        <Button type="button" variant="outline" onClick={() => append(emptyGroup())}>
          <Plus className="h-4 w-4" />
          Adicionar grupo
        </Button>
      </div>
      <p className="text-xs text-muted-foreground">
        Casar {match === 'any' ? 'qualquer uma' : 'todas'} as condições acima (grupos contam como uma condição, com a regra interna deles).
      </p>
    </div>
  )
}
