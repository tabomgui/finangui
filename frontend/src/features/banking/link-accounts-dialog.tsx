import { useState } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { useAccounts } from '@/api/queries/accounts'
import { useLinkAccounts } from '@/api/queries/bank-connections'
import { ApiError } from '@/api/errors'
import { invalidateBankConnections, invalidateLedger } from '@/api/query-keys'
import type { BankConnection, ProviderAccount } from '@/api/types'
import { MoneyText } from '@/components/shared/money-text'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Skeleton } from '@/components/ui/skeleton'
import { notifyError } from '@/lib/form-errors'
import { ACCOUNT_TYPE_LABELS } from '../accounts/account-labels'

const NEW_ACCOUNT = 'new'

type LinkAccountsDialogProps = {
  connection: BankConnection
  open: boolean
  onOpenChange: (open: boolean) => void
}

/** Últimos 4 dígitos do número informado pelo banco; null quando não há dígitos suficientes para mascarar. */
function maskedNumber(number: string | null): string | null {
  const digits = (number ?? '').replace(/\D/g, '')
  return digits.length >= 4 ? `•••• ${digits.slice(-4)}` : null
}

function initialLinks(accounts: ProviderAccount[]): Record<string, number | null> {
  return Object.fromEntries(accounts.map((account) => [account.external_id, account.suggested_account_id]))
}

/**
 * Uma linha por conta do banco (`connection.pending_accounts`, sempre preenchida pelo backend
 * enquanto a conexão está `pending_link`), com a escolha entre criar conta nova (padrão) ou
 * vincular a uma conta manual compatível (mesmo tipo e moeda, sem conexão, e ainda não escolhida
 * em outra linha deste mesmo diálogo).
 */
export function LinkAccountsDialog({ connection, open, onOpenChange }: LinkAccountsDialogProps) {
  const { data: manualAccounts = [], isPending: accountsPending } = useAccounts(false)
  const linkAccounts = useLinkAccounts()
  const queryClient = useQueryClient()
  // Quem usa este diálogo só o monta enquanto há uma conexão para vincular (`linking &&`/`resuming &&`
  // no componente pai) — a troca de conexão sempre desmonta e remonta, então o estado inicial aqui já
  // nasce certo, sem precisar de um efeito para resetar ao reabrir.
  const [links, setLinks] = useState<Record<string, number | null>>(() => initialLinks(connection.pending_accounts))
  // Erros 422 de `links.N.account_id` (ex.: outra aba já conectou a conta escolhida entre a
  // prévia e a confirmação), por external_id da conta do banco.
  const [rowErrors, setRowErrors] = useState<Record<string, string>>({})

  function compatibleAccounts(providerAccount: ProviderAccount) {
    return manualAccounts.filter(
      (account) =>
        account.connection_id === null &&
        account.type === providerAccount.kind &&
        account.currency === providerAccount.currency &&
        // Uma conta manual só pode ser escolhida numa linha por vez: tira quem já foi escolhido
        // em outra linha (menos a própria, para o valor atualmente selecionado continuar visível).
        !Object.entries(links).some(([externalId, accountId]) => externalId !== providerAccount.external_id && accountId === account.id),
    )
  }

  function setLink(externalId: string, accountId: number | null) {
    setLinks((prev) => ({ ...prev, [externalId]: accountId }))
  }

  async function handleConfirm() {
    setRowErrors({})
    const body = {
      links: connection.pending_accounts.map((account) => ({
        external_id: account.external_id,
        account_id: links[account.external_id] ?? null,
      })),
    }
    try {
      await linkAccounts.mutateAsync({ id: connection.id, body })
      toast.success('Banco conectado. A primeira sincronização pode levar alguns minutos.')
      onOpenChange(false)
    } catch (error) {
      handleError(error)
    }
  }

  function handleError(error: unknown) {
    if (!(error instanceof ApiError)) {
      notifyError(error)
      return
    }

    if (error.status === 422) {
      const nextRowErrors: Record<string, string> = {}
      connection.pending_accounts.forEach((account, index) => {
        const message = error.fieldErrors[`links.${index}.account_id`]?.[0]
        if (message) nextRowErrors[account.external_id] = message
      })
      if (Object.keys(nextRowErrors).length > 0) {
        setRowErrors(nextRowErrors)
        return
      }
    }

    if (error.code === 'account_no_longer_linkable') {
      // A lista de contas manuais pode estar desatualizada (a conta escolhida foi conectada ou
      // arquivada entre abrir o diálogo e confirmar) — atualiza antes do usuário escolher de novo.
      invalidateLedger(queryClient)
      notifyError(error)
      return
    }

    if (error.code === 'connection_not_pending_link') {
      invalidateBankConnections(queryClient)
      toast.error('Este banco já foi vinculado.')
      onOpenChange(false)
      return
    }

    notifyError(error)
  }

  return (
    <Dialog open={open} onOpenChange={(next) => !linkAccounts.isPending && onOpenChange(next)}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Vincular contas</DialogTitle>
          <DialogDescription>
            Vincular a uma conta que você já usa mantém o histórico; lançamentos manuais iguais aos do banco são
            reconhecidos automaticamente.
          </DialogDescription>
        </DialogHeader>
        <div className="max-h-[60vh] space-y-3 overflow-y-auto">
          {accountsPending
            ? connection.pending_accounts.map((providerAccount) => (
                <Skeleton key={providerAccount.external_id} className="h-20 w-full rounded-xl" />
              ))
            : connection.pending_accounts.map((providerAccount) => {
                const masked = maskedNumber(providerAccount.number)
                const selected = links[providerAccount.external_id] ?? null
                const error = rowErrors[providerAccount.external_id]
                return (
                  <div key={providerAccount.external_id} className="space-y-2 rounded-xl border p-3">
                    <div className="flex items-center justify-between gap-3">
                      <div className="min-w-0">
                        <p className="truncate font-medium">{providerAccount.name}</p>
                        <p className="truncate text-xs text-muted-foreground">
                          {masked ? `${masked} · ` : ''}
                          {ACCOUNT_TYPE_LABELS[providerAccount.kind]}
                        </p>
                      </div>
                      <MoneyText cents={providerAccount.balance} currency={providerAccount.currency} colored={false} />
                    </div>
                    <Select
                      value={selected === null ? NEW_ACCOUNT : String(selected)}
                      onValueChange={(value) => setLink(providerAccount.external_id, value === NEW_ACCOUNT ? null : Number(value))}
                    >
                      <SelectTrigger aria-label={`Vínculo de ${providerAccount.name}`} className="w-full" aria-invalid={!!error}>
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        <SelectItem value={NEW_ACCOUNT}>Criar conta nova</SelectItem>
                        {compatibleAccounts(providerAccount).map((account) => (
                          <SelectItem key={account.id} value={String(account.id)}>
                            {account.name}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                    {error && <p className="text-xs text-destructive">{error}</p>}
                  </div>
                )
              })}
        </div>
        <DialogFooter>
          <Button type="button" variant="outline" disabled={linkAccounts.isPending} onClick={() => onOpenChange(false)}>
            Fechar
          </Button>
          <Button type="button" disabled={linkAccounts.isPending || accountsPending} onClick={handleConfirm}>
            Confirmar
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
