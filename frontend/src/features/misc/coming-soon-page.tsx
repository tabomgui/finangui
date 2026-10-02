import { Hammer } from 'lucide-react'
import { PageBody } from '@/components/layout/page-body'
import { PageHeader } from '@/components/layout/page-header'
import { EmptyState } from '@/components/shared/empty-state'
import { Card } from '@/components/ui/card'

export function ComingSoonPage({ title }: { title: string }) {
  return (
    <>
      <PageHeader title={title} />
      <PageBody>
        <Card className="rounded-2xl shadow-card">
          <EmptyState icon={Hammer} title="Em breve" description="Esta tela ainda está sendo construída." />
        </Card>
      </PageBody>
    </>
  )
}
