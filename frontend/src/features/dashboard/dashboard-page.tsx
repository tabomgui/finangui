import { Landmark, TriangleAlert } from 'lucide-react'
import { useEffect, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { useBankConnections } from '@/api/queries/bank-connections'
import { useMe } from '@/api/queries/auth'
import { useDashboard } from '@/api/queries/dashboard'
import { PageBody } from '@/components/layout/page-body'
import { PageHeader } from '@/components/layout/page-header'
import { EmptyState } from '@/components/shared/empty-state'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { appToday } from '@/lib/date'
import { cn } from '@/lib/utils'
import { ReauthBanner } from '../banking/reauth-banner'
import { useReconnectFlow } from '../banking/use-reconnect-flow'
import { AccountsCard } from './accounts-card'
import { BalanceHero } from './balance-hero'
import { MonthNav } from './month-nav'
import { OverdueOccurrencesDialog } from './overdue-occurrences-dialog'
import { PendingCard } from './pending-card'
import { RecentTransactionsCard } from './recent-transactions-card'
import { dayFromParam, monthFromParam } from './shares'
import { SpendingCard } from './spending-card'
import { StatementsCard } from './statements-card'
import { SummaryCards } from './summary-cards'

export function DashboardPage() {
  const [params, setParams] = useSearchParams()
  // `appToday()` só na montagem: evita recalcular a cada render. Fuso do app (America/Sao_Paulo),
  // não do navegador — ver `dayFromParam`/`appToday` em `shares.ts`/`lib/date.ts`.
  const [currentMonth] = useState(() => appToday().slice(0, 7))
  const month = monthFromParam(params.get('mes'), currentMonth)
  // Mês e dia são independentes (a seta de mês nunca muda o dia): `date` só vai pro servidor
  // quando `?dia` é explícito e válido — sem ele, o backend já usa hoje como padrão, para
  // qualquer mês (ver DashboardRequest::balanceDate()). `appToday()` aqui é só o fallback de
  // validação antes da primeira carga (rejeitar um `?dia` futuro pelo relógio do navegador);
  // depois de carregado, "hoje" de verdade vem de `data.today` (ver `today` abaixo).
  const rawDay = params.get('dia')
  const day = dayFromParam(rawDay)
  const { data, isPending, isError, isPlaceholderData, refetch } = useDashboard(month, day)
  const { data: connections } = useBankConnections()
  const { data: me } = useMe()
  const reconnectFlow = useReconnectFlow()
  // Dono aqui, não dentro de `PendingCard`: resolver a última pendência zera a contagem e o card
  // pode desmontar (ex.: sem sugestão também) — o diálogo aberto não pode ir junto nesse momento.
  const [overdueOpen, setOverdueOpen] = useState(false)
  // Hoje "de verdade" (fuso do app, servidor): só cai no fallback do navegador antes da
  // primeira resposta, quando ainda não há `data.today` nenhum pra usar.
  const today = data?.today ?? appToday()

  // `?dia` inválido ou futuro (relógio do navegador na pior hipótese, ou link velho
  // compartilhado) nunca fica preso na URL: sai por `replace`, sem entrar no histórico.
  useEffect(() => {
    if (!rawDay || day !== undefined) return
    setParams(
      (prev) => {
        const next = new URLSearchParams(prev)
        next.delete('dia')
        return next
      },
      { replace: true },
    )
  }, [rawDay, day, setParams])

  // A seta de mês não muda o dia escolhido: só atualiza `mes`, preservando `dia` se já estiver na URL.
  const setMonth = (next: string) => {
    const nextParams = new URLSearchParams(params)
    nextParams.set('mes', next)
    setParams(nextParams, { replace: true })
  }

  // Escolher hoje tira `?dia` da URL (mantém ela limpa); qualquer outro dia grava `?dia` explicitamente.
  const setDay = (next: string) => {
    const nextParams = new URLSearchParams(params)
    if (next === today) {
      nextParams.delete('dia')
    } else {
      nextParams.set('dia', next)
    }
    setParams(nextParams, { replace: true })
  }

  // "Voltar para hoje" é só escolher hoje: sempre tira `?dia`, mesmo vindo de um mês passado.
  const backToToday = () => setDay(today)

  return (
    <>
      <PageHeader title="Início">
        <div className="space-y-4">
          <MonthNav month={month} onChange={setMonth} />
          {data ? (
            <div className={cn('transition-opacity', isPlaceholderData && 'opacity-60')}>
              <BalanceHero
                totalBalance={data.total_balance}
                currency={data.currency}
                balanceDate={data.balance_date}
                today={today}
                onSelectDay={setDay}
                onBackToToday={backToToday}
                isPlaceholderData={isPlaceholderData}
              />
            </div>
          ) : isError ? (
            // Sem saldo para mostrar e o card de erro já aparece no corpo da página: só reserva a altura
            // para o cabeçalho não "pular" quando o usuário tentar de novo.
            <div className="h-28" aria-hidden="true" />
          ) : (
            <Skeleton className="h-28 w-full rounded-2xl bg-white/20" />
          )}
        </div>
      </PageHeader>
      <PageBody>
        <ReauthBanner
          connections={connections ?? []}
          onReconnect={reconnectFlow.reconnect}
          reconnectDisabled={reconnectFlow.isPending}
          bankingEnabled={me?.banking_enabled}
        />
        {isError ? (
          <Card className="rounded-2xl p-0 shadow-card">
            <EmptyState
              icon={TriangleAlert}
              title="Não foi possível carregar o resumo do mês."
              action={
                <Button variant="outline" onClick={() => refetch()}>
                  Tentar de novo
                </Button>
              }
            />
          </Card>
        ) : isPending || !data ? (
          <div className="grid gap-3 sm:grid-cols-3">
            {[0, 1, 2].map((i) => (
              <Skeleton key={i} className="h-20 rounded-2xl" />
            ))}
          </div>
        ) : data.accounts.length === 0 ? (
          <Card className="rounded-2xl shadow-card">
            <EmptyState
              icon={Landmark}
              title="Comece cadastrando uma conta"
              description="Com uma conta você já pode registrar receitas, despesas e transferências."
              action={
                <Button asChild>
                  <Link to="/contas">Cadastrar conta</Link>
                </Button>
              }
            />
          </Card>
        ) : (
          <div className={cn('space-y-4 transition-opacity', isPlaceholderData && 'opacity-60')} aria-busy={isPlaceholderData}>
            <SummaryCards income={data.income} expense={data.expense} net={data.net} currency={data.currency} />
            {/*
              Pendências e Faturas raramente têm conteúdo suficiente para justificar a largura
              toda num desktop; lado a lado a partir de `lg`, cada card com sua própria altura
              (`items-start`, sem estirar o mais curto). Qualquer um dos dois pode não renderizar
              nada (`PendingCard`/`StatementsCard` retornam `null` sem pendência/fatura aberta) —
              `:only-child` detecta isso no DOM de verdade e devolve a largura toda ao que restou,
              sem os dois precisarem saber um do outro; `empty:hidden` cobre o caso dos dois
              nulos, pra não sobrar um espaçamento (`space-y-4` do pai) em cima de nada.
            */}
            <div className="grid gap-4 empty:hidden lg:grid-cols-2 lg:items-start lg:[&>*:only-child]:col-span-2">
              <PendingCard onOpenOverdue={() => setOverdueOpen(true)} />
              <StatementsCard />
            </div>
            <SpendingCard key={month} month={month} />
            {/*
              Contas e Últimos lançamentos pareados do mesmo jeito: os dois sempre renderizam algo
              (mesmo vazios), então não precisam do fallback de `:only-child` aqui.
            */}
            <div className="grid gap-4 lg:grid-cols-2 lg:items-start">
              <AccountsCard accounts={data.accounts} />
              <RecentTransactionsCard />
            </div>
          </div>
        )}
      </PageBody>

      <OverdueOccurrencesDialog open={overdueOpen} onOpenChange={setOverdueOpen} />
      {reconnectFlow.widget}
    </>
  )
}
