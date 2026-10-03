import { CreditCard, Plus, TriangleAlert } from 'lucide-react'
import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useCards } from '@/api/queries/cards'
import type { Card as CardData } from '@/api/types'
import { PageBody } from '@/components/layout/page-body'
import { headerButton, PageHeader } from '@/components/layout/page-header'
import { CategoryIcon } from '@/components/shared/category-icon'
import { EmptyState } from '@/components/shared/empty-state'
import { MoneyText } from '@/components/shared/money-text'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import { Label } from '@/components/ui/label'
import { Skeleton } from '@/components/ui/skeleton'
import { Switch } from '@/components/ui/switch'
import { cn } from '@/lib/utils'
import { AccountFormDialog } from '../accounts/account-form-dialog'
import { CardLimitBar } from './card-limit-bar'
import { dueLabel } from './statement-labels'
import { StatementStatusBadge } from './statement-status-badge'

export function CardsPage() {
  const [showArchived, setShowArchived] = useState(false)
  const { data: cards, isPending, isError, isPlaceholderData, refetch } = useCards(showArchived)
  const [formOpen, setFormOpen] = useState(false)

  return (
    <>
      <PageHeader
        title="Cartões"
        subtitle="Faturas e limites"
        actions={
          <Button className={headerButton} onClick={() => setFormOpen(true)}>
            <Plus className="h-4 w-4" />
            Novo cartão
          </Button>
        }
      />
      <PageBody>
        {isError ? (
          <EmptyState
            icon={TriangleAlert}
            title="Não foi possível carregar os cartões."
            action={
              <Button variant="outline" onClick={() => refetch()}>
                Tentar de novo
              </Button>
            }
          />
        ) : isPending ? (
          <div className="grid gap-4 sm:grid-cols-2">
            {[0, 1].map((i) => (
              <Skeleton key={i} className="h-40 w-full rounded-2xl" />
            ))}
          </div>
        ) : cards && cards.length > 0 ? (
          <div
            aria-busy={isPlaceholderData}
            className={cn('grid gap-4 transition-opacity sm:grid-cols-2', isPlaceholderData && 'opacity-60')}
          >
            {cards.map((card) => (
              <CardTile key={card.id} card={card} />
            ))}
          </div>
        ) : (
          <EmptyState
            icon={CreditCard}
            title="Nenhum cartão ainda"
            description="Cadastre seus cartões de crédito para acompanhar faturas e limite."
            action={<Button onClick={() => setFormOpen(true)}>Cadastrar cartão</Button>}
          />
        )}

        <div className="flex items-center justify-end gap-2 px-1">
          <Switch id="show-archived-cards" checked={showArchived} onCheckedChange={setShowArchived} />
          <Label htmlFor="show-archived-cards" className="text-sm text-muted-foreground">
            Mostrar arquivados
          </Label>
        </div>
      </PageBody>

      <AccountFormDialog open={formOpen} onOpenChange={setFormOpen} defaultType="credit_card" />
    </>
  )
}

function CardTile({ card }: { card: CardData }) {
  const statement = card.current_statement
  return (
    <Link
      to={`/cartoes/${card.id}`}
      className="block rounded-2xl outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
    >
      <Card className="space-y-4 rounded-2xl p-4 shadow-card transition-colors hover:bg-muted/40">
        <div className="flex items-center gap-3">
          <CategoryIcon icon={card.icon} color={card.color} />
          <div className="min-w-0 flex-1">
            <p className="flex min-w-0 items-center gap-2 font-medium">
              <span className="truncate">{card.name}</span>
              {card.is_archived && <Badge variant="secondary">Arquivado</Badge>}
            </p>
            {card.last_four && <p className="text-xs text-muted-foreground">•••• {card.last_four}</p>}
          </div>
          {statement && <StatementStatusBadge statement={statement} />}
        </div>
        {statement ? (
          <div className="flex items-end justify-between gap-2">
            <div>
              <p className="text-xs text-muted-foreground">Fatura atual</p>
              <MoneyText cents={statement.total} currency={card.currency} className="text-xl font-bold" />
            </div>
            <p className="text-xs text-muted-foreground">{dueLabel(statement)}</p>
          </div>
        ) : (
          <p className="text-sm text-muted-foreground">Sem fatura em aberto</p>
        )}
        <CardLimitBar credit_limit={card.credit_limit} limit={card.limit} currency={card.currency} />
      </Card>
    </Link>
  )
}
