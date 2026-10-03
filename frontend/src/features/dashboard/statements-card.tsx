import { Link } from 'react-router-dom'
import { useCards } from '@/api/queries/cards'
import type { CardStatement } from '@/api/types'
import { CategoryIcon } from '@/components/shared/category-icon'
import { MoneyText } from '@/components/shared/money-text'
import { Badge } from '@/components/ui/badge'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { dueLabel } from '../cards/statement-labels'

export function StatementsCard() {
  const { data: cards = [] } = useCards(false)
  const open = cards.filter((card) => card.current_statement && card.current_statement.status !== 'paid' && card.current_statement.remaining > 0)
  if (open.length === 0) return null

  return (
    <Card className="rounded-2xl shadow-card">
      <CardHeader className="flex flex-row items-center justify-between">
        <CardTitle className="text-base">
          <h2>Faturas</h2>
        </CardTitle>
        <Link to="/cartoes" className="text-sm font-medium text-primary hover:underline">
          Ver cartões
        </Link>
      </CardHeader>
      <CardContent className="space-y-1 p-2 pt-0">
        {open.map((card) => {
          const statement = card.current_statement as CardStatement
          return (
            <Link
              key={card.id}
              to={`/cartoes/${card.id}`}
              className="flex items-center gap-3 rounded-xl px-2 py-2 hover:bg-muted/50"
            >
              <CategoryIcon icon={card.icon} color={card.color} size="sm" />
              <div className="min-w-0 flex-1">
                <p className="truncate text-sm font-medium">{card.name}</p>
                <p className="text-xs text-muted-foreground">{dueLabel(statement)}</p>
              </div>
              {statement.is_overdue && <Badge variant="destructive">Vencida</Badge>}
              <MoneyText cents={statement.remaining} currency={card.currency} className="font-semibold" />
            </Link>
          )
        })}
      </CardContent>
    </Card>
  )
}
