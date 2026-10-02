import { PieChart } from 'lucide-react'
import { Link } from 'react-router-dom'
import { CategoryIcon } from '@/components/shared/category-icon'
import { EmptyState } from '@/components/shared/empty-state'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { monthRange } from '@/lib/date'
import { formatMoney } from '@/lib/money'
import { categoryShares, type TopCategory } from './shares'

type TopCategoriesCardProps = { categories: TopCategory[]; expense: number; currency: string; month: string }

export function TopCategoriesCard({ categories, expense, currency, month }: TopCategoriesCardProps) {
  const shares = categoryShares(categories, expense)
  const { from, to } = monthRange(month)

  return (
    <Card className="rounded-2xl shadow-card">
      <CardHeader>
        <CardTitle className="text-base">Maiores despesas</CardTitle>
      </CardHeader>
      <CardContent>
        {shares.length === 0 ? (
          <EmptyState icon={PieChart} title="Sem despesas no mês" />
        ) : (
          <ul className="space-y-4">
            {shares.map((share) => {
              const body = (
                <>
                  <div className="flex items-center gap-3">
                    <CategoryIcon icon={share.icon} color={share.color} size="sm" />
                    <span className="min-w-0 flex-1 truncate font-medium">{share.name}</span>
                    <span className="text-right">
                      <span className="block font-semibold tabular-nums">{formatMoney(share.amount, currency)}</span>
                      <span className="block text-xs text-muted-foreground">{share.expensePercent}%</span>
                    </span>
                  </div>
                  <div className="h-2 overflow-hidden rounded-full bg-muted">
                    <div
                      className="h-full rounded-full bg-primary"
                      style={{ width: `${share.barPercent}%`, ...(share.color ? { backgroundColor: share.color } : {}) }}
                    />
                  </div>
                </>
              )

              return (
                <li key={share.category_id ?? 'none'}>
                  {share.category_id === null ? (
                    // A API não filtra transações sem categoria: um link para /transacoes aqui mostraria todas as despesas,
                    // não só as sem categoria. Mostra o item sem torná-lo clicável.
                    <div className="space-y-2 rounded-xl p-1">{body}</div>
                  ) : (
                    <Link
                      to={`/transacoes?categoria=${share.category_id}&de=${from}&ate=${to}&tipo=out`}
                      className="block space-y-2 rounded-xl p-1 hover:bg-muted/50"
                    >
                      {body}
                    </Link>
                  )}
                </li>
              )
            })}
          </ul>
        )}
      </CardContent>
    </Card>
  )
}
