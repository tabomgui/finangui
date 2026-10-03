import { useTheme } from 'next-themes'
import { useEffect, useRef } from 'react'
import { toast } from 'sonner'
import { loadPluggyConnectSdk, type PluggyConnectInstance } from './pluggy-connect-sdk'

type PluggyWidgetProps = {
  connectToken: string
  /** Presente só ao reconectar: o item do Pluggy já existe e o widget abre direto no modo de atualização dele. */
  updateItem?: string
  onSuccess: (itemId: string) => void
  onClose: () => void
  /** Erro do fluxo do Pluggy (ex.: login falhou no banco): só informativo — o widget continua aberto, quem fecha é o usuário (onClose). */
  onError: (message: string) => void
}

/**
 * Usa o `pluggy-connect-sdk` direto (classe `PluggyConnect`), não o wrapper `react-pluggy-connect`:
 * o wrapper nunca destrói a instância anterior ao desmontar — a ref callback só chama
 * `innerRef(null)` quando o container sai, sem `destroy()` (ver dist/.../react-pluggy-connect.js
 * do pacote). Sob o double-mount do StrictMode (dev, React 19), isso abre duas instâncias
 * empilhadas: monta (cria A), "desmonta" (A fica solta, só o innerRef é avisado), remonta (cria
 * B) — A e B ficam lado a lado. Aqui o ciclo de vida é todo nosso: cria no efeito, destrói na
 * limpeza, então o double-mount faz criar→destruir→criar sem deixar nada preso. O `destroyed`
 * cobre o caso da limpeza rodar antes do import (ou do init) terminar: a instância da primeira
 * montagem simulada nunca chega a existir.
 */
export function PluggyWidget({ connectToken, updateItem, onSuccess, onClose, onError }: PluggyWidgetProps) {
  const { resolvedTheme } = useTheme()
  // As callbacks (e o tema) não devem recriar o widget a cada render — só connectToken/updateItem
  // justificam uma instância nova (ver as dependências do efeito abaixo); por isso ficam numa
  // ref, mantida em dia por este efeito (sem deps: roda depois de todo render) em vez de
  // escrita direto no corpo do componente — mutar ref fora de efeito/handler é o que o React
  // considera "durante a renderização".
  const callbacksRef = useRef({ onSuccess, onClose, onError })
  useEffect(() => {
    callbacksRef.current = { onSuccess, onClose, onError }
  })

  useEffect(() => {
    let destroyed = false
    let connect: PluggyConnectInstance | null = null

    loadPluggyConnectSdk()
      .then(({ PluggyConnect }) => {
        if (destroyed) return undefined
        connect = new PluggyConnect({
          connectToken,
          updateItem,
          includeSandbox: import.meta.env.DEV,
          theme: resolvedTheme === 'dark' ? 'dark' : 'light',
          onSuccess: ({ item }) => callbacksRef.current.onSuccess(item.id),
          onClose: () => callbacksRef.current.onClose(),
          onError: (error) => callbacksRef.current.onError(error.message),
        })
        return connect.init()
      })
      .catch(() => {
        if (!destroyed) {
          toast.error('Não foi possível abrir o Pluggy.')
          callbacksRef.current.onClose()
        }
      })

    return () => {
      destroyed = true
      connect?.destroy().catch(() => {})
    }
  }, [connectToken, updateItem, resolvedTheme])

  // O SDK renderiza seu próprio modal (iframe) direto em document.body; este componente não
  // precisa de nenhum nó próprio na árvore.
  return null
}
