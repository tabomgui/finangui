import { CreditCard, TriangleAlert } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { Link, Navigate, useLocation, useParams, useSearchParams } from 'react-router-dom'
import { toast } from 'sonner'
import { ApiError } from '@/api/errors'
import { useCard, useCardStatements } from '@/api/queries/cards'
import type { Card } from '@/api/types'
import { PageBody } from '@/components/layout/page-body'
import { PageHeader } from '@/components/layout/page-header'
import { EmptyState } from '@/components/shared/empty-state'
import { FullPageSpinner } from '@/components/shared/full-page-spinner'
import { Button } from '@/components/ui/button'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { InstallmentPlansTab } from './installment-plans-tab'
import { PayStatementDialog } from './pay-statement-dialog'
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

/** Casca fina: só resolve o id da rota. A chave em `cardId` remonta o conteúdo inteiro ao trocar
 * de cartão, para que abas, diálogos e a fatura selecionada não sobrevivam de um cartão para o outro. */
export function CardDetailPage() {
  const cardId = Number(useParams().id)
  return <CardDetailContent key={cardId} cardId={cardId} />
}

function CardDetailContent({ cardId }: { cardId: number }) {
  const location = useLocation()
  const [params, setParams] = useSearchParams()
  const { data: card, error: cardError, isError: cardIsError, refetch: refetchCard } = useCard(cardId)
  const {
    data: statements = [],
    isPending: statementsPending,
    isError: statementsIsError,
    refetch: refetchStatements,
  } = useCardStatements(cardId)
  const [tab, setTab] = useState<DetailTab>('fatura')
  const [paying, setPaying] = useState(false)
  const [editingDates, setEditingDates] = useState(false)

  const notFound = cardIsError && cardError instanceof ApiError && cardError.status === 404

  // Toast uma vez (ref) + <Navigate replace />, mesmo padrão de transaction-form-page.tsx:
  // evita duplicar sob StrictMode e a cada nova renderização. Só para 404 real: outros erros
  // (500, rede) não significam que o cartão não existe, não devem mandar o usuário de volta para a lista.
  const toastShown = useRef(false)
  useEffect(() => {
    if (notFound && !toastShown.current) {
      toastShown.current = true
      toast.error('Cartão não encontrado.')
    }
  }, [notFound])

  const selectedId = pickStatementId(statements, params.get('fatura'), card?.current_statement?.id ?? null)

  // Fixa a fatura escolhida na URL assim que resolvida: sem isso, um refetch depois de editar
  // datas ou pagar poderia recalcular `current_statement` e pular a fatura por baixo do usuário.
  useEffect(() => {
    if (card && selectedId !== null && params.get('fatura') !== String(selectedId)) {
      setParams({ fatura: String(selectedId) }, { replace: true })
    }
  }, [card, selectedId, params, setParams])

  if (notFound) return <Navigate to="/cartoes" replace />

  // Erro sem nada em cache: tela de erro com retry. Erro com dado em cache (ex.: um refetch em
  // segundo plano falhou, mas já tínhamos o cartão carregado) ignora o erro e segue mostrando.
  if (cardIsError && !card) {
    return (
      <>
        <PageHeader title="Cartão" back="/cartoes" />
        <PageBody>
          <EmptyState
            icon={TriangleAlert}
            title="Não foi possível carregar o cartão."
            action={
              <Button variant="outline" onClick={() => refetchCard()}>
                Tentar de novo
              </Button>
            }
          />
        </PageBody>
      </>
    )
  }

  if (!card || statementsPending) return <FullPageSpinner />

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
            {statementsIsError ? (
              <EmptyState
                icon={TriangleAlert}
                title="Não foi possível carregar as faturas."
                action={
                  <Button variant="outline" onClick={() => refetchStatements()}>
                    Tentar de novo
                  </Button>
                }
              />
            ) : selected ? (
              <>
                <StatementSummary
                  statement={selected}
                  currency={card.currency}
                  limit={card}
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
                    <Link
                      to={`/transacoes/nova?conta=${card.id}`}
                      state={{ from: location.pathname + location.search }}
                    >
                      Registrar compra
                    </Link>
                  </Button>
                }
              />
            )}
          </TabsContent>
          <TabsContent value="parcelamentos">
            <InstallmentPlansTab cardId={card.id} currency={card.currency} />
          </TabsContent>
        </Tabs>
      </PageBody>

      {selected && <StatementDatesDialog open={editingDates} onOpenChange={setEditingDates} statement={selected} />}
      {selected && <PayStatementDialog open={paying} onOpenChange={setPaying} statement={selected} currency={card.currency} />}
    </>
  )
}
