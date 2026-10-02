import { SearchX } from 'lucide-react'
import { Link } from 'react-router-dom'
import { PageBody } from '@/components/layout/page-body'
import { PageHeader } from '@/components/layout/page-header'
import { EmptyState } from '@/components/shared/empty-state'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'

export function NotFoundPage() {
  return (
    <>
      <PageHeader title="Página não encontrada" back="/" />
      <PageBody>
        <Card className="rounded-2xl shadow-card">
          <EmptyState
            icon={SearchX}
            title="Este endereço não existe"
            description="Ele pode ter sido movido ou digitado errado."
            action={
              <Button asChild>
                <Link to="/">Voltar ao início</Link>
              </Button>
            }
          />
        </Card>
      </PageBody>
    </>
  )
}
