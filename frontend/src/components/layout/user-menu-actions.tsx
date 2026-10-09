import { LogOut } from 'lucide-react'
import { useMe } from '@/api/queries/auth'
import { Button } from '@/components/ui/button'
import { useSignOut } from './use-sign-out'

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

export function SignOutButton({ className }: { className?: string }) {
  const { signOut, pending } = useSignOut()

  return (
    <Button
      type="button"
      variant="ghost"
      size="icon"
      aria-label="Sair"
      title="Sair"
      disabled={pending}
      onClick={signOut}
      className={className}
    >
      <LogOut className="h-4 w-4" aria-hidden="true" />
    </Button>
  )
}
