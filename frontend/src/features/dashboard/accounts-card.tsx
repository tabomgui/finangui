import { Link } from 'react-router-dom'
import { CategoryIcon } from '@/components/shared/category-icon'
import { MoneyText } from '@/components/shared/money-text'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'

type DashboardAccount = { id: number; name: string; currency: string; color: string | null; icon: string | null; balance: number }

export function AccountsCard({ accounts }: { accounts: DashboardAccount[] }) {
  return (
    <Card className="rounded-2xl shadow-card">
      <CardHeader className="flex flex-row items-center justify-between">
        <CardTitle className="text-base">
          <h2>Contas</h2>
        </CardTitle>
        <Link to="/contas" className="text-sm font-medium text-primary hover:underline">
          Gerenciar
        </Link>
      </CardHeader>
      <CardContent>
        <ul className="space-y-3">
          {accounts.map((account) => (
            <li key={account.id} className="flex items-center gap-3">
              <CategoryIcon icon={account.icon} color={account.color} size="sm" />
              <span className="min-w-0 flex-1 truncate">{account.name}</span>
              <MoneyText cents={account.balance} currency={account.currency} className="font-semibold" />
            </li>
          ))}
        </ul>
      </CardContent>
    </Card>
  )
}
