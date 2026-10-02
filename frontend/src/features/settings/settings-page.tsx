import { useEffect } from 'react'
import { useSearchParams } from 'react-router-dom'
import { toast } from 'sonner'
import { useMe } from '@/api/queries/auth'
import { PageBody } from '@/components/layout/page-body'
import { PageHeader } from '@/components/layout/page-header'
import { AppearanceCard } from './appearance-card'
import { GoogleCard } from './google-card'
import { PasswordCard } from './password-card'
import { ProfileCard } from './profile-card'

const GOOGLE_RESULTS: Record<string, { type: 'success' | 'error'; message: string }> = {
  linked: { type: 'success', message: 'Conta Google vinculada.' },
  taken: { type: 'error', message: 'Esta conta Google já está vinculada a outro usuário.' },
  already_linked: { type: 'error', message: 'Sua conta já está vinculada a outra conta Google.' },
  failed: { type: 'error', message: 'Não foi possível vincular a conta Google. Tente novamente.' },
}

export function SettingsPage() {
  const { data: user } = useMe()
  const [params, setParams] = useSearchParams()

  useEffect(() => {
    const result = GOOGLE_RESULTS[params.get('google') ?? '']
    if (!result) return
    toast[result.type](result.message)
    params.delete('google')
    setParams(params, { replace: true })
  }, [params, setParams])

  if (!user) return null

  return (
    <>
      <PageHeader title="Configurações" subtitle="Perfil, segurança e aparência" />
      <PageBody className="max-w-2xl">
        <ProfileCard user={user} />
        <PasswordCard user={user} />
        <GoogleCard user={user} />
        <AppearanceCard />
      </PageBody>
    </>
  )
}
