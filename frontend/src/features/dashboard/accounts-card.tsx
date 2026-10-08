import { Link } from 'react-router-dom'
import type { DashboardAccount } from '@/api/types'
import { CategoryIcon } from '@/components/shared/category-icon'
import { MoneyText } from '@/components/shared/money-text'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'

// Mesmo teto de `useRecentTransactions(5)` (ver `recent-transactions-card.tsx`), pareado do lado
// dele no Início: sem isso, uma lista grande de contas desequilibra a altura dos dois cards.
const VISIBLE_ACCOUNTS = 5

export function AccountsCard({ accounts }: { accounts: DashboardAccount[] }) {
  const visibleAccounts = accounts.slice(0, VISIBLE_ACCOUNTS)
  const hasMore = accounts.length > VISIBLE_ACCOUNTS

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
          {visibleAccounts.map((account) => (
            <li key={account.id} className="flex items-center gap-3">
              <CategoryIcon icon={account.icon} color={account.color} size="sm" />
              <span className="min-w-0 flex-1 truncate">{account.name}</span>
              <MoneyText cents={account.balance} currency={account.currency} className="font-semibold" />
            </li>
          ))}
        </ul>
        {hasMore && (
          <Link to="/contas" className="mt-3 inline-block text-sm font-medium text-primary hover:underline">
            Ver todas as contas
          </Link>
        )}
      </CardContent>
    </Card>
  )
}
