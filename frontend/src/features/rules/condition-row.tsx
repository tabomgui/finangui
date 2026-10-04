import { Trash2 } from 'lucide-react'
import type { ReactNode } from 'react'
import type { RuleFieldName, RuleOperatorName } from '@/api/types'
import { Field } from '@/components/form/field'
import { AccountSelect } from '@/components/shared/account-select'
import { DateInput } from '@/components/shared/date-input'
import { MoneyInput } from '@/components/shared/money-input'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import type { ConditionValues } from './rule-form-values'
import { FIELD_LABELS, OPERATOR_LABELS, OPERATORS_BY_FIELD, TEXT_FIELDS } from './rule-labels'

/** Texto visível igual em toda linha ("Campo"/"Operador"/"Valor"); o sufixo só-leitor-de-tela
 *  desambigua qual linha é essa quando há várias (ex.: "Campo (condição 2 do grupo 1)"). */
function withRowSuffix(base: string, rowLabel: string): ReactNode {
  return (
    <>
      {base}
      <span className="sr-only"> ({rowLabel})</span>
    </>
  )
}

const DIRECTION_OPTIONS: { value: 'in' | 'out'; label: string }[] = [
  { value: 'out', label: 'Saída' },
  { value: 'in', label: 'Entrada' },
]

type FieldError = { message?: string } | undefined

export type ConditionErrors = {
  field?: FieldError
  op?: FieldError
  value?: FieldError
}

type ConditionRowProps = {
  value: ConditionValues
  onChange: (value: ConditionValues) => void
  onRemove: () => void
  errors?: ConditionErrors
  idPrefix: string
  /** Descreve a posição desta linha ("condição 2", "condição 1 do grupo 2") para rótulos únicos. */
  rowLabel: string
}

type ValueControlProps = {
  value: ConditionValues
  onChange: (next: string) => void
  control: { id: string; 'aria-describedby'?: string; 'aria-invalid'?: true }
}

/** Controle de valor varia por campo: texto, dinheiro, direção, conta ou data — mas o formulário sempre guarda string. */
function ValueControl({ value, onChange, control }: ValueControlProps) {
  if (value.field === 'amount') {
    const cents = value.value === '' ? null : Number(value.value)
    return <MoneyInput {...control} value={cents} onChange={(next) => onChange(next === null ? '' : String(next))} />
  }

  if (value.field === 'direction') {
    return (
      <Select value={value.value === '' ? undefined : value.value} onValueChange={onChange}>
        <SelectTrigger {...control} className="w-full">
          <SelectValue placeholder="Escolha" />
        </SelectTrigger>
        <SelectContent>
          {DIRECTION_OPTIONS.map((option) => (
            <SelectItem key={option.value} value={option.value}>
              {option.label}
            </SelectItem>
          ))}
        </SelectContent>
      </Select>
    )
  }

  if (value.field === 'account_id') {
    return (
      <AccountSelect
        {...control}
        includeArchived
        value={value.value === '' ? null : Number(value.value)}
        onChange={(accountId) => onChange(String(accountId))}
      />
    )
  }

  if (value.field === 'date') {
    return <DateInput {...control} value={value.value} onChange={onChange} />
  }

  return <Input {...control} value={value.value} onChange={(event) => onChange(event.target.value)} />
}

export function ConditionRow({ value, onChange, onRemove, errors, idPrefix, rowLabel }: ConditionRowProps) {
  const operators = OPERATORS_BY_FIELD[value.field] ?? []

  // Trocar de campo muda o domínio do valor na maioria dos casos (texto, centavos, id, data...):
  // o valor anterior só sobrevive quando o campo antigo e o novo são ambos de texto livre (o
  // valor digitado continua fazendo sentido). O operador só muda se o atual não valer mais para
  // o campo novo — senão fica como estava.
  const changeField = (field: RuleFieldName) => {
    const validOperators = OPERATORS_BY_FIELD[field] ?? []
    const op = (validOperators.includes(value.op) ? value.op : validOperators[0]) as RuleOperatorName
    const keepValue = TEXT_FIELDS.has(value.field) && TEXT_FIELDS.has(field)
    onChange({ ...value, field, op, value: keepValue ? value.value : '' })
  }

  // Trocar o operador nunca muda o domínio do valor (o campo é o mesmo) — o valor fica como estava.
  const changeOperator = (op: RuleOperatorName) => onChange({ ...value, op })

  return (
    <div className="grid grid-cols-1 gap-2 sm:grid-cols-[1fr_1fr_1fr_auto] sm:items-start">
      <Field label={withRowSuffix('Campo', rowLabel)} htmlFor={`${idPrefix}-field`} error={errors?.field?.message}>
        {(control) => (
          <Select value={value.field} onValueChange={(next) => changeField(next as RuleFieldName)}>
            <SelectTrigger {...control} className="w-full">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {Object.entries(FIELD_LABELS).map(([field, label]) => (
                <SelectItem key={field} value={field}>
                  {label}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        )}
      </Field>
      <Field label={withRowSuffix('Operador', rowLabel)} htmlFor={`${idPrefix}-op`} error={errors?.op?.message}>
        {(control) => (
          <Select value={value.op} onValueChange={(next) => changeOperator(next as RuleOperatorName)}>
            <SelectTrigger {...control} className="w-full">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {operators.map((op) => (
                <SelectItem key={op} value={op}>
                  {OPERATOR_LABELS[op] ?? op}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        )}
      </Field>
      <Field label={withRowSuffix('Valor', rowLabel)} htmlFor={`${idPrefix}-value`} error={errors?.value?.message}>
        {(control) => <ValueControl value={value} onChange={(next) => onChange({ ...value, value: next })} control={control} />}
      </Field>
      <Button type="button" variant="ghost" size="icon" className="self-end" aria-label={`Remover ${rowLabel}`} onClick={onRemove}>
        <Trash2 className="h-4 w-4" />
      </Button>
    </div>
  )
}
