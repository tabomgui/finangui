import { useEffect } from 'react'

/** Mesmo breakpoint `md` do Tailwind (ver `@theme` em `src/index.css`, que não o sobrescreve). */
export const DESKTOP_MEDIA_QUERY = '(min-width: 768px)'

/**
 * Fecha algo que só devia abrir no mobile (ex.: um `Sheet` cujo gatilho vira `md:hidden`) quando a
 * tela cruza para o layout de desktop com ele já aberto. Nada mais ouve essa mudança de breakpoint:
 * o conteúdo do `Sheet` é renderizado num portal, fora do fluxo normal, então continua visível por
 * cima do layout de desktop depois do redimensionamento se nada o fechar.
 */
export function useCloseOnDesktop(open: boolean, setOpen: (open: boolean) => void) {
  useEffect(() => {
    // `matchMedia` não existe em todo ambiente (ex.: jsdom sem stub, em teste que nem abre o
    // `Sheet`/`Popover`); sem ele não dá para ouvir a mudança de breakpoint, então não faz nada.
    if (!open || typeof window.matchMedia !== 'function') return

    const media = window.matchMedia(DESKTOP_MEDIA_QUERY)
    // Cobre abrir já em desktop (não devia acontecer, já que o gatilho está escondido ali, mas é
    // barato garantir) e, principalmente, a mudança de breakpoint ocorrer entre o render e a
    // inscrição no `change` logo abaixo — sem checar aqui, essa mudança passaria despercebida.
    if (media.matches) {
      setOpen(false)
      return
    }

    const handleChange = (event: MediaQueryListEvent) => {
      if (event.matches) setOpen(false)
    }

    media.addEventListener('change', handleChange)
    return () => media.removeEventListener('change', handleChange)
  }, [open, setOpen])
}
