import { LogOut } from 'lucide-react'
import { useNavigate } from 'react-router-dom'
import { useLogout, useMe } from '@/api/queries/auth'
import { Button } from '@/components/ui/button'
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

export function UserSummary() {
  const { data: user } = useMe()
  if (!user) return null

  return (
    <div className="min-w-0">
      <p className="truncate text-sm font-medium">{user.name}</p>
      <p className="truncate text-xs text-muted-foreground">{user.email}</p>
    </div>
  )
}

export function SignOutButton() {
  const { signOut, pending } = useSignOut()

  return (
    <Button type="button" variant="ghost" size="icon" aria-label="Sair" title="Sair" disabled={pending} onClick={signOut}>
      <LogOut className="h-4 w-4" />
    </Button>
  )
}
