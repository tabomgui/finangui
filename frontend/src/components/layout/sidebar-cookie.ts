const SIDEBAR_COOKIE_NAME = 'sidebar_state'

/**
 * `SidebarProvider` (shadcn) só grava o cookie `sidebar_state`, não lê de volta no mount: assume
 * SSR, que o finangui (Vite, CSR puro) não tem. Lido aqui e passado como `defaultOpen`.
 */
export function readSidebarState(): boolean {
  const match = document.cookie.match(new RegExp(`(?:^|; )${SIDEBAR_COOKIE_NAME}=([^;]*)`))
  return match ? match[1] === 'true' : true
}
