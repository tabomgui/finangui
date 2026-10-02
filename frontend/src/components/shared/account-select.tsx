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
}

export function AccountSelect({ value, onChange, placeholder = 'Escolha a conta', excludeId, ...control }: AccountSelectProps) {
  const { data: accounts = [] } = useAccounts(true)
  const options = accounts.filter(
    (account) => (!account.is_archived || account.id === value) && account.id !== excludeId,
  )

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
            </span>
          </SelectItem>
        ))}
      </SelectContent>
    </Select>
  )
}
