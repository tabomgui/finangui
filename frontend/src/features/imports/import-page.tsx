import { PageBody } from '@/components/layout/page-body'
import { PageHeader } from '@/components/layout/page-header'
import { ImportHistoryCard } from './import-history-card'
import { ImportUploadCard } from './import-upload-card'

export function ImportPage() {
  return (
    <>
      <PageHeader title="Importar extrato" subtitle="CSV ou OFX do seu banco" />
      <PageBody>
        <ImportUploadCard />
        <ImportHistoryCard />
      </PageBody>
    </>
  )
}
