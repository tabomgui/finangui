import { lazy, Suspense } from 'react'

/**
 * `react-pluggy-connect` renderiza um iframe hospedado em connect.pluggy.ai (a Pluggy injeta um
 * modal cobrindo a tela sozinha, sem precisarmos desenhar nada ao redor) — o `lazy` garante que o
 * pacote só é baixado quando o usuário de fato clica em "Conectar banco" ou "Reconectar", nunca no
 * carregamento inicial da página.
 */
const PluggyConnect = lazy(() => import('react-pluggy-connect').then((module) => ({ default: module.PluggyConnect })))

type PluggyWidgetProps = {
  connectToken: string
  /** Presente só ao reconectar: o item do Pluggy já existe e o widget abre direto no modo de atualização dele. */
  updateItem?: string
  onSuccess: (itemId: string) => void
  onClose: () => void
  onError: (message: string) => void
}

/** Wrapper do `react-pluggy-connect`; quem usa monta este componente só enquanto o widget está aberto. */
export function PluggyWidget({ connectToken, updateItem, onSuccess, onClose, onError }: PluggyWidgetProps) {
  return (
    <Suspense fallback={null}>
      <PluggyConnect
        connectToken={connectToken}
        updateItem={updateItem}
        includeSandbox={import.meta.env.DEV}
        onSuccess={({ item }) => onSuccess(item.id)}
        onClose={() => onClose()}
        onError={(error) => onError(error.message)}
      />
    </Suspense>
  )
}
