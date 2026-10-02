import { act, renderHook } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { ApiError } from '@/api/errors'

const { navigate, mutateAsync } = vi.hoisted(() => ({ navigate: vi.fn(), mutateAsync: vi.fn() }))

vi.mock('react-router-dom', () => ({ useNavigate: () => navigate }))
vi.mock('@/api/queries/auth', () => ({ useLogout: () => ({ mutateAsync, isPending: false }) }))
vi.mock('@/lib/form-errors', () => ({ notifyError: vi.fn() }))

const { useSignOut } = await import('./use-sign-out')

describe('useSignOut', () => {
  it('ignora 401 (sessão já expirada no servidor) e ainda assim navega para /login', async () => {
    const { notifyError } = await import('@/lib/form-errors')
    mutateAsync.mockRejectedValueOnce(new ApiError(401, 'Sua sessão terminou. Entre novamente.'))

    const { result } = renderHook(() => useSignOut())
    await act(async () => {
      await result.current.signOut()
    })

    expect(notifyError).not.toHaveBeenCalled()
    expect(navigate).toHaveBeenCalledWith('/login', { replace: true })
  })

  it('notifica outros erros de logout e ainda assim navega para /login', async () => {
    const { notifyError } = await import('@/lib/form-errors')
    const error = new ApiError(500, 'Erro inesperado no servidor. Tente novamente.')
    mutateAsync.mockRejectedValueOnce(error)

    const { result } = renderHook(() => useSignOut())
    await act(async () => {
      await result.current.signOut()
    })

    expect(notifyError).toHaveBeenCalledWith(error)
    expect(navigate).toHaveBeenCalledWith('/login', { replace: true })
  })

  it('navega para /login quando o logout é bem-sucedido', async () => {
    const { notifyError } = await import('@/lib/form-errors')
    mutateAsync.mockResolvedValueOnce(undefined)

    const { result } = renderHook(() => useSignOut())
    await act(async () => {
      await result.current.signOut()
    })

    expect(notifyError).not.toHaveBeenCalled()
    expect(navigate).toHaveBeenCalledWith('/login', { replace: true })
  })
})
