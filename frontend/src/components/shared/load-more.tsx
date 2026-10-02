import { LoaderCircle } from 'lucide-react'
import { useEffect, useRef } from 'react'
import { Button } from '@/components/ui/button'

type LoadMoreProps = {
  hasMore: boolean
  loading: boolean
  onLoadMore: () => void
}

/** Carrega a próxima página ao chegar perto do fim da lista; o botão fica como alternativa acessível. */
export function LoadMore({ hasMore, loading, onLoadMore }: LoadMoreProps) {
  const ref = useRef<HTMLDivElement>(null)

  useEffect(() => {
    const node = ref.current
    if (!node || !hasMore || loading || typeof IntersectionObserver === 'undefined') return

    const observer = new IntersectionObserver(
      (entries) => {
        if (entries.some((entry) => entry.isIntersecting)) onLoadMore()
      },
      { rootMargin: '300px' },
    )
    observer.observe(node)
    return () => observer.disconnect()
  }, [hasMore, loading, onLoadMore])

  if (!hasMore) return null

  return (
    <div ref={ref} className="flex justify-center py-4">
      <Button variant="outline" onClick={onLoadMore} disabled={loading}>
        {loading && <LoaderCircle className="h-4 w-4 animate-spin" />}
        Carregar mais
      </Button>
    </div>
  )
}
