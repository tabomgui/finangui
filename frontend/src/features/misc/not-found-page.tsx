import { SearchX } from 'lucide-react'
import { Link } from 'react-router-dom'
import { EmptyState } from '@/components/shared/empty-state'
import { Button } from '@/components/ui/button'

export function NotFoundPage() {
  return (
    <EmptyState
      icon={SearchX}
      title="Página não encontrada"
      description="O endereço não existe ou foi movido."
      action={
        <Button asChild>
          <Link to="/">Voltar ao início</Link>
        </Button>
      }
    />
  )
}
