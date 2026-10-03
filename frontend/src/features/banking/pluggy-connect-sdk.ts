/**
 * Formas mínimas usadas daqui, declaradas à mão (em vez de `import type { PluggyConnect } from
 * 'pluggy-connect-sdk'`): uma referência estática ao pacote, mesmo só de tipo, faz o scanner de
 * dependências do Vite pré-empacotar o pacote real — e esse bundle pré-otimizado passa por fora
 * do `vi.mock` nos testes (reproduzido com StrictMode: a segunda montagem simulada acaba
 * chamando o zoid de verdade, que não roda em jsdom).
 */
export type PluggyConnectInitProps = {
  connectToken: string
  updateItem?: string
  includeSandbox: boolean
  theme: 'light' | 'dark'
  onSuccess: (data: { item: { id: string } }) => void
  onClose: () => void
  onError: (error: { message: string }) => void
}
export type PluggyConnectInstance = { init: (container?: HTMLElement) => Promise<void>; destroy: () => Promise<void> }
type PluggyConnectClass = new (props: PluggyConnectInitProps) => PluggyConnectInstance

/**
 * Memoizado (não um `import()` novo a cada chamada): o efeito do `PluggyWidget` pode rodar mais
 * de uma vez para o mesmo módulo (StrictMode remonta em dev; o usuário pode abrir o widget de
 * novo depois de fechar) — um só `import()` real por carregamento da página evita baixar o
 * chunk de novo, e evita depender de quantas vezes `vi.mock` honra chamadas repetidas de
 * `import()` do mesmo specifier em teste (visto na prática: a segunda chamada chegou a resolver
 * para o pacote real, não o mock, quebrando o teste de StrictMode do widget).
 */
let pluggyConnectSdk: Promise<{ PluggyConnect: PluggyConnectClass }> | null = null

/** Se o import falhar, o cache se limpa sozinho (a próxima chamada tenta de novo); quem chama ainda recebe a rejeição para decidir o que fazer. */
export function loadPluggyConnectSdk(): Promise<{ PluggyConnect: PluggyConnectClass }> {
  if (!pluggyConnectSdk) {
    pluggyConnectSdk = (import('pluggy-connect-sdk') as unknown as Promise<{ PluggyConnect: PluggyConnectClass }>).catch((error) => {
      pluggyConnectSdk = null
      throw error
    })
  }
  return pluggyConnectSdk
}

/**
 * Baixa o pacote sob demanda, sem esperar o `PluggyWidget` montar: quem abre o widget (botão
 * "Conectar banco"/"Reconectar") chama isto junto do pedido do connect token, para o chunk já
 * estar baixado (ou a caminho) quando o token voltar — a falha, se houver, é silenciosa aqui,
 * porque o próprio `PluggyWidget` tenta de novo ao montar (e aí sim avisa o usuário).
 */
export function preloadPluggyWidget(): void {
  loadPluggyConnectSdk().catch(() => {})
}
