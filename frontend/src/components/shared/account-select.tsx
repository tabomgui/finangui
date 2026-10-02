import { useAccounts } from '@/api/queries/accounts'
import type { FieldControlProps } from '@/components/form/field'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { CategoryIcon } from './category-icon'

type AccountSelectProps = Partial<FieldControlProps> & {
  id: string
  value: number | null
  onChange: (accountId: number) => void
  placeholder?: string
  /** Conta a esconder (ex.: a origem, ao escolher o destino de uma transferência). */
  excludeId?: number | null
  /** Lista também contas arquivadas, depois das ativas (ex.: filtros, que precisam achar lançamentos antigos). */
  includeArchived?: boolean
}

export function AccountSelect({
  value,
  onChange,
  placeholder = 'Escolha a conta',
  excludeId,
  includeArchived = false,
  ...control
}: AccountSelectProps) {
  const { data: accounts = [] } = useAccounts(true)
  const visible = accounts.filter((account) => account.id !== excludeId)
  const options = includeArchived
    ? [...visible.filter((account) => !account.is_archived), ...visible.filter((account) => account.is_archived)]
    : visible.filter((account) => !account.is_archived || account.id === value)

  return (
    <Select value={value === null ? '' : String(value)} onValueChange={(next) => onChange(Number(next))}>
      <SelectTrigger {...control} className="w-full">
        <SelectValue placeholder={placeholder} />
      </SelectTrigger>
      <SelectContent>
        {options.map((account) => (
          <SelectItem key={account.id} value={String(account.id)}>
            <span className="flex items-center gap-2">
              <CategoryIcon icon={account.icon} color={account.color} size="sm" className="h-6 w-6" />
              {account.name}
              {includeArchived && account.is_archived ? ' (arquivada)' : ''}
            </span>
          </SelectItem>
        ))}
      </SelectContent>
    </Select>
  )
}
