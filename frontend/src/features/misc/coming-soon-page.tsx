import { Hammer } from 'lucide-react'
import { EmptyState } from '@/components/shared/empty-state'

export function ComingSoonPage({ title }: { title: string }) {
  return (
    <div className="p-6">
      <h1 className="mb-4 text-2xl font-bold">{title}</h1>
      <EmptyState icon={Hammer} title="Em breve" description="Esta tela ainda está sendo construída." />
    </div>
  )
}
