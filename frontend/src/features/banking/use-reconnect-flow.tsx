import { useState } from 'react'
import { toast } from 'sonner'
import { useConnectToken, useMarkReconnected } from '@/api/queries/bank-connections'
import type { BankConnection } from '@/api/types'
import { notifyError } from '@/lib/form-errors'
import { preloadPluggyWidget } from './pluggy-connect-sdk'
import { PluggyWidget } from './pluggy-widget'

type ReconnectState = { connection: BankConnection; token: string; itemId?: string }

/**
 * Fluxo de reconexão (connect token com `connection_id` → widget em modo de atualização →
 * `useMarkReconnected`), compartilhado entre o `ConnectionCard` (Contas) e o `ReauthBanner`
 * (Início e Contas): quem usa só chama `reconnect(connection)` e renderiza `widget` uma vez na
 * página — ver `features/accounts/accounts-page.tsx` e `features/dashboard/dashboard-page.tsx`.
 */
export function useReconnectFlow() {
  const [state, setState] = useState<ReconnectState | null>(null)
  const connectToken = useConnectToken()
  const markReconnected = useMarkReconnected()

  async function reconnect(connection: BankConnection) {
    preloadPluggyWidget()
    try {
      const { token, itemId } = await connectToken.mutateAsync({ connection_id: connection.id })
      setState({ connection, token, itemId })
    } catch (error) {
      notifyError(error)
    }
  }

  async function handleSuccess(returnedItemId: string) {
    if (!state) return
    const { connection, itemId } = state
    setState(null)

    // O widget em modo de atualização devolve o mesmo item pedido; um item diferente (ex.: o
    // connect token expirou e o usuário reaproveitou a aba para outra conexão) não é seguro de
    // aceitar — o backend confere de novo (409 connection_item_mismatch) antes de marcar
    // reconectada, mas evitamos a chamada e já avisamos aqui.
    if (itemId !== undefined && returnedItemId !== itemId) {
      toast.error('O banco devolveu uma conexão diferente da esperada. Tente reconectar de novo.')
      return
    }

    try {
      await markReconnected.mutateAsync({ id: connection.id, itemId: returnedItemId })
      toast.success('Banco reconectado.')
    } catch (error) {
      notifyError(error)
    }
  }

  const widget = state && (
    <PluggyWidget
      key={state.token}
      connectToken={state.token}
      updateItem={state.itemId}
      onSuccess={handleSuccess}
      onClose={() => setState(null)}
      onError={(message) => toast.error(message)}
    />
  )

  return {
    reconnect,
    widget,
    /** Desabilita os gatilhos (menu do ConnectionCard, botão do ReauthBanner) durante o pedido do token ou com o widget já aberto. */
    isPending: connectToken.isPending || state !== null,
  }
}
