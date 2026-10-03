import { render, waitFor } from '@testing-library/react'
import { StrictMode } from 'react'
import { describe, expect, it, vi } from 'vitest'
import { PluggyWidget } from './pluggy-widget'

type MockProps = {
  connectToken: string
  updateItem?: string
  includeSandbox?: boolean
  theme?: 'light' | 'dark'
  onSuccess: (data: { item: { id: string } }) => void
  onClose: () => void
  onError: (error: { message: string }) => void
}

const instances: { props: MockProps; init: ReturnType<typeof vi.fn>; destroy: ReturnType<typeof vi.fn> }[] = []
let nextInitResult: 'resolve' | 'reject' = 'resolve'

vi.mock('pluggy-connect-sdk', () => ({
  // Mock da classe real (sem iframe nem zoid): grava cada instância criada, para os testes
  // conferirem quantas foram de fato construídas (ver o teste de StrictMode) e chamarem as
  // callbacks do construtor manualmente.
  PluggyConnect: class {
    props: MockProps
    init = vi.fn(() => (nextInitResult === 'resolve' ? Promise.resolve() : Promise.reject(new Error('load failed'))))
    destroy = vi.fn(() => Promise.resolve())

    constructor(props: MockProps) {
      this.props = props
      instances.push(this)
    }
  },
}))

vi.mock('sonner', () => ({ toast: { error: vi.fn(), success: vi.fn() } }))

const { toast } = await import('sonner')

function lastInstance() {
  return instances[instances.length - 1]
}

describe('PluggyWidget', () => {
  it('cria a instância com connectToken, updateItem e tema claro por padrão', async () => {
    instances.length = 0
    nextInitResult = 'resolve'
    render(<PluggyWidget connectToken="tok-1" updateItem="item-9" onSuccess={vi.fn()} onClose={vi.fn()} onError={vi.fn()} />)

    await waitFor(() => expect(instances).toHaveLength(1))
    expect(lastInstance().props).toMatchObject({ connectToken: 'tok-1', updateItem: 'item-9', theme: 'light' })
    expect(lastInstance().init).toHaveBeenCalled()
  })

  it('onSuccess da instância chama onSuccess com o id do item', async () => {
    instances.length = 0
    nextInitResult = 'resolve'
    const onSuccess = vi.fn()
    render(<PluggyWidget connectToken="tok-1" onSuccess={onSuccess} onClose={vi.fn()} onError={vi.fn()} />)

    await waitFor(() => expect(instances).toHaveLength(1))
    lastInstance().props.onSuccess({ item: { id: 'item-123' } })

    expect(onSuccess).toHaveBeenCalledWith('item-123')
  })

  it('onClose da instância chama onClose', async () => {
    instances.length = 0
    nextInitResult = 'resolve'
    const onClose = vi.fn()
    render(<PluggyWidget connectToken="tok-1" onSuccess={vi.fn()} onClose={onClose} onError={vi.fn()} />)

    await waitFor(() => expect(instances).toHaveLength(1))
    lastInstance().props.onClose()

    expect(onClose).toHaveBeenCalled()
  })

  it('onError da instância chama onError com a mensagem e não fecha por conta própria', async () => {
    instances.length = 0
    nextInitResult = 'resolve'
    const onError = vi.fn()
    const onClose = vi.fn()
    render(<PluggyWidget connectToken="tok-1" onSuccess={vi.fn()} onClose={onClose} onError={onError} />)

    await waitFor(() => expect(instances).toHaveLength(1))
    lastInstance().props.onError({ message: 'Falha ao conectar.' })

    expect(onError).toHaveBeenCalledWith('Falha ao conectar.')
    expect(onClose).not.toHaveBeenCalled()
  })

  it('init rejeitada mostra toast "Não foi possível abrir o Pluggy." e chama onClose', async () => {
    instances.length = 0
    nextInitResult = 'reject'
    const onClose = vi.fn()
    render(<PluggyWidget connectToken="tok-1" onSuccess={vi.fn()} onClose={onClose} onError={vi.fn()} />)

    await waitFor(() => expect(onClose).toHaveBeenCalled())

    expect(toast.error).toHaveBeenCalledWith('Não foi possível abrir o Pluggy.')
  })

  it('desmonta chamando destroy na instância', async () => {
    instances.length = 0
    nextInitResult = 'resolve'
    const { unmount } = render(<PluggyWidget connectToken="tok-1" onSuccess={vi.fn()} onClose={vi.fn()} onError={vi.fn()} />)

    await waitFor(() => expect(instances).toHaveLength(1))
    const instance = lastInstance()
    unmount()

    expect(instance.destroy).toHaveBeenCalled()
  })

  it('sob StrictMode (double-mount do dev), cria só uma instância de verdade', async () => {
    instances.length = 0
    nextInitResult = 'resolve'
    render(
      <StrictMode>
        <PluggyWidget connectToken="tok-1" onSuccess={vi.fn()} onClose={vi.fn()} onError={vi.fn()} />
      </StrictMode>,
    )

    // Dá tempo para qualquer import()/then() pendente de uma montagem simulada e já desfeita
    // terminar — se o bug (duas instâncias empilhadas) existisse, apareceria aqui.
    await waitFor(() => expect(lastInstance()?.init).toHaveBeenCalled())
    await new Promise((resolve) => setTimeout(resolve, 10))

    expect(instances).toHaveLength(1)
  })
})
