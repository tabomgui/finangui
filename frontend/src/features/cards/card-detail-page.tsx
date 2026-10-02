import { CreditCard } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { Link, Navigate, useParams, useSearchParams } from 'react-router-dom'
import { toast } from 'sonner'
import { useCard, useCardStatements } from '@/api/queries/cards'
import type { Card } from '@/api/types'
import { PageBody } from '@/components/layout/page-body'
import { PageHeader } from '@/components/layout/page-header'
import { EmptyState } from '@/components/shared/empty-state'
import { FullPageSpinner } from '@/components/shared/full-page-spinner'
import { Button } from '@/components/ui/button'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { pickStatementId } from './pick-statement'
import { StatementDatesDialog } from './statement-dates-dialog'
import { StatementNav } from './statement-nav'
import { StatementSummary } from './statement-summary'
import { StatementTransactions } from './statement-transactions'

type DetailTab = 'fatura' | 'parcelamentos'

function cardSubtitle(card: Card): string {
  const digits = card.last_four ? `•••• ${card.last_four} · ` : ''
  return `${digits}fecha dia ${card.closing_day}, vence dia ${card.due_day}`
}

export function CardDetailPage() {
  const cardId = Number(useParams().id)
  const [params, setParams] = useSearchParams()
  const { data: card, isError: cardError } = useCard(cardId)
  const { data: statements = [], isPending: statementsPending } = useCardStatements(cardId)
  const [tab, setTab] = useState<DetailTab>('fatura')
  const [paying, setPaying] = useState(false)
  const [editingDates, setEditingDates] = useState(false)

  // Toast uma vez (ref) + <Navigate replace />, mesmo padrão de transaction-form-page.tsx:
  // evita duplicar sob StrictMode e a cada nova renderização.
  const toastShown = useRef(false)
  useEffect(() => {
    if (cardError && !toastShown.current) {
      toastShown.current = true
      toast.error('Cartão não encontrado.')
    }
  }, [cardError])

  if (cardError) return <Navigate to="/cartoes" replace />
  if (!card || statementsPending) return <FullPageSpinner />

  const selectedId = pickStatementId(statements, params.get('fatura'), card.current_statement?.id ?? null)
  const selected = statements.find((statement) => statement.id === selectedId) ?? null

  return (
    <>
      <PageHeader title={card.name} subtitle={cardSubtitle(card)} back="/cartoes">
        {selected && (
          <StatementNav
            statements={statements}
            selectedId={selected.id}
            onSelect={(id) => setParams({ fatura: String(id) }, { replace: true })}
          />
        )}
      </PageHeader>
      <PageBody>
        <Tabs value={tab} onValueChange={(value) => setTab(value as DetailTab)}>
          <TabsList className="w-full">
            <TabsTrigger value="fatura" className="flex-1">
              Fatura
            </TabsTrigger>
            <TabsTrigger value="parcelamentos" className="flex-1">
              Parcelamentos
            </TabsTrigger>
          </TabsList>
          <TabsContent value="fatura" className="space-y-4">
            {selected ? (
              <>
                <StatementSummary
                  statement={selected}
                  currency={card.currency}
                  onPay={() => setPaying(true)}
                  onEditDates={() => setEditingDates(true)}
                />
                <StatementTransactions statementId={selected.id} />
              </>
            ) : (
              <EmptyState
                icon={CreditCard}
                title="Nenhuma fatura ainda"
                description="Registre a primeira compra do cartão para começar a fatura."
                action={
                  <Button asChild>
                    <Link to={`/transacoes/nova?conta=${card.id}`}>Registrar compra</Link>
                  </Button>
                }
              />
            )}
          </TabsContent>
          <TabsContent value="parcelamentos">
            <EmptyState icon={CreditCard} title="Parcelamentos" description="Em breve." />
          </TabsContent>
        </Tabs>
      </PageBody>

      {selected && <StatementDatesDialog open={editingDates} onOpenChange={setEditingDates} statement={selected} />}
      {/* Pagamento de fatura ainda não tem diálogo: o estado fica pronto para quando ele existir. */}
      {paying && <span className="sr-only">Pagamento de fatura ainda não está disponível.</span>}
    </>
  )
}
