import { Navigate, Outlet, useLocation } from 'react-router-dom'
import { useMe } from '@/api/queries/auth'
import { FullPageSpinner } from '@/components/shared/full-page-spinner'
import { Button } from '@/components/ui/button'

export function ProtectedRoute() {
  const { data: user, isPending, isError, refetch } = useMe()
  const location = useLocation()

  if (isPending) return <FullPageSpinner />

  if (isError) {
    return (
      <div className="flex min-h-dvh flex-col items-center justify-center gap-4 p-6 text-center">
        <p className="text-muted-foreground">Não foi possível falar com o servidor.</p>
        <Button onClick={() => refetch()}>Tentar de novo</Button>
      </div>
    )
  }

  if (!user) return <Navigate to="/login" replace state={{ from: location.pathname + location.search }} />

  return <Outlet />
}
