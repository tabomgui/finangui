import { useNavigate } from 'react-router-dom'
import { ApiError } from '@/api/errors'
import { useLogout } from '@/api/queries/auth'
import { notifyError } from '@/lib/form-errors'

export function useSignOut() {
  const logout = useLogout()
  const navigate = useNavigate()

  return {
    pending: logout.isPending,
    signOut: async () => {
      try {
        await logout.mutateAsync()
      } catch (error) {
        // 401 aqui significa que a sessão já tinha expirado no servidor: o objetivo do usuário
        // (sair) já está cumprido, não é um erro para notificar.
        if (!(error instanceof ApiError) || error.status !== 401) notifyError(error)
      } finally {
        navigate('/login', { replace: true })
      }
    },
  }
}
