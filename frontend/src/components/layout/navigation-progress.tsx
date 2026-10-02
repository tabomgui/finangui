import { useNavigation } from 'react-router-dom'

/** Barra fina no topo enquanto uma rota lazy carrega. */
export function NavigationProgress() {
  const navigation = useNavigation()
  if (navigation.state === 'idle') return null

  return (
    <div role="progressbar" aria-label="Carregando página" className="fixed inset-x-0 top-0 z-[10000] h-0.5 overflow-hidden">
      <div className="h-full w-1/3 animate-[navigation-progress_1s_ease-in-out_infinite] bg-primary" />
    </div>
  )
}
