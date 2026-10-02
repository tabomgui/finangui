import { useNavigate } from 'react-router-dom'
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
        notifyError(error)
      } finally {
        navigate('/login', { replace: true })
      }
    },
  }
}
