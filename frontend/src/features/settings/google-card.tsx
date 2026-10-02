import { CircleCheck } from 'lucide-react'
import { useAuthStatus } from '@/api/queries/auth'
import type { User } from '@/api/types'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'

export function GoogleCard({ user }: { user: User }) {
  const { data: status } = useAuthStatus()

  return (
    <Card className="rounded-2xl shadow-card">
      <CardHeader>
        <CardTitle>Conta Google</CardTitle>
        <CardDescription>Entre com o Google além da senha.</CardDescription>
      </CardHeader>
      <CardContent>
        {user.google_linked ? (
          <p className="flex items-center gap-2 text-sm text-income">
            <CircleCheck className="h-4 w-4" />
            Conta Google vinculada.
          </p>
        ) : status?.google_login_enabled ? (
          <Button variant="outline" asChild>
            <a href="/api/auth/google/redirect">Vincular conta Google</a>
          </Button>
        ) : (
          <p className="text-sm text-muted-foreground">O login com Google não está configurado nesta instância.</p>
        )}
      </CardContent>
    </Card>
  )
}
