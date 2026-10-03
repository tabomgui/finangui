import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { PluggyWidget } from './pluggy-widget'

const receivedProps: Record<string, unknown>[] = []

vi.mock('react-pluggy-connect', () => ({
  // Mock do pacote real (sem iframe): expõe botões para disparar cada callback do widget.
  PluggyConnect: (props: {
    connectToken: string
    updateItem?: string
    includeSandbox?: boolean
    onSuccess: (data: { item: { id: string } }) => void
    onClose: () => void
    onError: (error: { message: string }) => void
  }) => {
    receivedProps.push(props)
    return (
      <div>
        <button onClick={() => props.onSuccess({ item: { id: 'item-123' } })}>mock-success</button>
        <button onClick={() => props.onClose()}>mock-close</button>
        <button onClick={() => props.onError({ message: 'Falha ao conectar.' })}>mock-error</button>
      </div>
    )
  },
}))

describe('PluggyWidget', () => {
  it('repassa connectToken, updateItem e includeSandbox para o pacote', async () => {
    receivedProps.length = 0
    render(<PluggyWidget connectToken="tok-1" updateItem="item-9" onSuccess={vi.fn()} onClose={vi.fn()} onError={vi.fn()} />)

    await screen.findByText('mock-success')

    expect(receivedProps[0]).toMatchObject({ connectToken: 'tok-1', updateItem: 'item-9' })
  })

  it('onSuccess do widget chama onSuccess com o id do item', async () => {
    const onSuccess = vi.fn()
    render(<PluggyWidget connectToken="tok-1" onSuccess={onSuccess} onClose={vi.fn()} onError={vi.fn()} />)

    fireEvent.click(await screen.findByText('mock-success'))

    expect(onSuccess).toHaveBeenCalledWith('item-123')
  })

  it('onClose do widget chama onClose', async () => {
    const onClose = vi.fn()
    render(<PluggyWidget connectToken="tok-1" onSuccess={vi.fn()} onClose={onClose} onError={vi.fn()} />)

    fireEvent.click(await screen.findByText('mock-close'))

    expect(onClose).toHaveBeenCalled()
  })

  it('onError do widget chama onError com a mensagem', async () => {
    const onError = vi.fn()
    render(<PluggyWidget connectToken="tok-1" onSuccess={vi.fn()} onClose={vi.fn()} onError={onError} />)

    fireEvent.click(await screen.findByText('mock-error'))

    expect(onError).toHaveBeenCalledWith('Falha ao conectar.')
  })
})
