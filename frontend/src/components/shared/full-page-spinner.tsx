import { LoaderCircle } from 'lucide-react'

export function FullPageSpinner() {
  return (
    <div className="flex min-h-dvh items-center justify-center" role="status" aria-label="Carregando">
      <LoaderCircle className="h-10 w-10 animate-spin text-primary" />
    </div>
  )
}
