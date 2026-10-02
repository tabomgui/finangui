import { cleanup } from '@testing-library/react'
import { afterEach } from 'vitest'
import '@testing-library/jest-dom/vitest'

// `globals` do vitest está desligado (testes importam describe/it/expect explicitamente),
// então a limpeza automática do Testing Library entre testes não é detectada; registramos à mão.
afterEach(cleanup)

// jsdom não implementa ResizeObserver; componentes radix-ui (ex.: Switch) o usam para medir
// o thumb. Stub mínimo o suficiente para montar em teste.
class ResizeObserverStub {
  observe() {}
  unobserve() {}
  disconnect() {}
}

globalThis.ResizeObserver ??= ResizeObserverStub as unknown as typeof ResizeObserver

// jsdom não implementa `scrollIntoView`; o cmdk (usado pelo Command/CategoryPicker/TagPicker)
// chama isso ao destacar o item ativo. Stub vazio, só para não quebrar em teste.
Element.prototype.scrollIntoView ??= () => {}

// jsdom não implementa a Pointer Capture API; o radix-ui (ex.: Select) a usa no trigger
// para capturar o ponteiro durante a interação. Stubs mínimos para montar/interagir em teste.
Element.prototype.hasPointerCapture ??= () => false
Element.prototype.setPointerCapture ??= () => {}
Element.prototype.releasePointerCapture ??= () => {}
