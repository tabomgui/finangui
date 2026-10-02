import { act, fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { ConfirmDialog } from './confirm-dialog'

vi.mock('@/lib/form-errors', () => ({ notifyError: vi.fn() }))

describe('ConfirmDialog', () => {
  it('mantém o diálogo aberto e notifica o erro quando onConfirm rejeita', async () => {
    const { notifyError } = await import('@/lib/form-errors')
    const error = new Error('falhou')
    const onConfirm = vi.fn().mockRejectedValue(error)
    const onOpenChange = vi.fn()

    render(<ConfirmDialog open onOpenChange={onOpenChange} title="Excluir?" onConfirm={onConfirm} />)

    await act(async () => {
      fireEvent.click(screen.getByRole('button', { name: 'Confirmar' }))
    })

    expect(onConfirm).toHaveBeenCalled()
    expect(notifyError).toHaveBeenCalledWith(error)
    expect(onOpenChange).not.toHaveBeenCalledWith(false)
    expect(screen.getByText('Excluir?')).toBeInTheDocument()
  })
})
