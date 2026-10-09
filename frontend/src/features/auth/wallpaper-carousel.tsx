import { useEffect, useState } from 'react'
import wallpaper1 from '@/assets/wallpapers/wallpaper-1.webp'
import wallpaper2 from '@/assets/wallpapers/wallpaper-2.webp'
import wallpaper3 from '@/assets/wallpapers/wallpaper-3.webp'
import { cn } from '@/lib/utils'

const WALLPAPERS = [wallpaper1, wallpaper2, wallpaper3]

export const WALLPAPER_INTERVAL_MS = 6000

function prefersReducedMotion() {
  // `matchMedia` não existe em todo ambiente (ex.: jsdom sem stub).
  return typeof window.matchMedia === 'function' && window.matchMedia('(prefers-reduced-motion: reduce)').matches
}

/** Fundo das telas de autenticação: imagens em tela cheia que se alternam com fade, mais os indicadores clicáveis. */
export function WallpaperCarousel() {
  const [current, setCurrent] = useState(0)

  // Um timeout por troca (em vez de um intervalo fixo) faz o clique num indicador reiniciar a contagem.
  useEffect(() => {
    if (prefersReducedMotion()) return
    const timer = window.setTimeout(() => setCurrent((index) => (index + 1) % WALLPAPERS.length), WALLPAPER_INTERVAL_MS)
    return () => window.clearTimeout(timer)
  }, [current])

  return (
    <>
      <div className="absolute inset-0 -z-10" aria-hidden="true">
        {WALLPAPERS.map((src, index) => (
          <img
            key={src}
            src={src}
            alt=""
            decoding="async"
            className={cn(
              'absolute inset-0 h-full w-full object-cover transition-opacity duration-1000 motion-reduce:transition-none',
              index === current ? 'opacity-100' : 'opacity-0',
            )}
          />
        ))}
        {/* Escurece as fotos (que são claras) para o texto branco do painel e o logo continuarem legíveis. */}
        <div className="absolute inset-0 bg-emerald-950/55 lg:bg-linear-to-r lg:from-emerald-950/85 lg:via-emerald-950/50 lg:to-black/30" />
      </div>

      <div role="group" aria-label="Imagens de fundo" className="absolute bottom-6 left-1/2 z-10 flex -translate-x-1/2 gap-2">
        {WALLPAPERS.map((src, index) => (
          <button
            key={src}
            type="button"
            aria-label={`Mostrar imagem ${index + 1} de ${WALLPAPERS.length}`}
            aria-current={index === current}
            onClick={() => setCurrent(index)}
            className={cn(
              'h-2 rounded-full outline-none transition-all focus-visible:ring-2 focus-visible:ring-white focus-visible:ring-offset-2 focus-visible:ring-offset-black/50 motion-reduce:transition-none',
              index === current ? 'w-6 bg-white' : 'w-2 bg-white/50 hover:bg-white/80',
            )}
          />
        ))}
      </div>
    </>
  )
}
