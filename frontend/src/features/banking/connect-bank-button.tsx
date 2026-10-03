import { Landmark } from 'lucide-react'
import { useState } from 'react'
import { toast } from 'sonner'
import { useConnectToken, useCreateConnection } from '@/api/queries/bank-connections'
import type { BankConnection } from '@/api/types'
import { Button } from '@/components/ui/button'
import { notifyError } from '@/lib/form-errors'
import { LinkAccountsDialog } from './link-accounts-dialog'
import { preloadPluggyWidget } from './pluggy-connect-sdk'
import { PluggyWidget } from './pluggy-widget'

type ConnectBankButtonProps = {
  className?: string
}

/**
 * Botão "Conectar banco": busca o connect token, abre o widget do Pluggy e, ao conectar, cria a
 * conexão (`pending_link`) e abre o diálogo de vínculo com as contas que o banco devolveu.
 */
export function ConnectBankButton({ className }: ConnectBankButtonProps) {
  const [widgetToken, setWidgetToken] = useState<string | null>(null)
  const [linking, setLinking] = useState<BankConnection | null>(null)
  const connectToken = useConnectToken()
  const createConnection = useCreateConnection()
  const widgetOpen = widgetToken !== null

  async function handleClick() {
    // Começa a baixar o chunk do widget junto do pedido do token, não depois: os dois terminam
    // perto um do outro, em vez de empilhar o download atrás da espera da rede.
    preloadPluggyWidget()
    try {
      const { token } = await connectToken.mutateAsync({})
      setWidgetToken(token)
    } catch (error) {
      notifyError(error)
    }
  }

  async function handleSuccess(itemId: string) {
    setWidgetToken(null)
    try {
      const { connection } = await createConnection.mutateAsync(itemId)
      setLinking(connection)
    } catch (error) {
      notifyError(error)
    }
  }

  return (
    <>
      <Button className={className} onClick={handleClick} disabled={connectToken.isPending || widgetOpen}>
        <Landmark className="h-4 w-4" />
        <span className="sr-only sm:not-sr-only">Conectar banco</span>
      </Button>
      {widgetToken && (
        <PluggyWidget
          key={widgetToken}
          connectToken={widgetToken}
          onSuccess={handleSuccess}
          onClose={() => setWidgetToken(null)}
          // Só avisa: o widget continua aberto (o usuário pode tentar outro banco/credencial);
          // quem fecha é o próprio usuário (onClose) ou uma falha de carregamento do pacote.
          onError={(message) => toast.error(message)}
        />
      )}
      {linking && <LinkAccountsDialog connection={linking} open onOpenChange={(open) => !open && setLinking(null)} />}
    </>
  )
}
