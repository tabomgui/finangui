import { cn } from '@/lib/utils'

/** Moeda cunhada com o rosto do mascote do finangui — mesma arte do favicon (public/favicon.svg), inline para renderizar sem uma requisição extra. */
function Coin({ className }: { className?: string }) {
  return (
    <svg viewBox="0 0 64 64" className={className} role="img" aria-label="finangui">
      <defs>
        <clipPath id="finangui-logo-field">
          <circle cx="32" cy="32" r="24" />
        </clipPath>
        <radialGradient id="finangui-logo-gold" cx="35%" cy="30%" r="80%">
          <stop offset="0" stopColor="#FFE08A" />
          <stop offset="0.6" stopColor="#F0B93A" />
          <stop offset="1" stopColor="#C98A1C" />
        </radialGradient>
        <radialGradient id="finangui-logo-field-gold" cx="40%" cy="35%" r="75%">
          <stop offset="0" stopColor="#F7CE5B" />
          <stop offset="1" stopColor="#D99A25" />
        </radialGradient>
      </defs>

      <circle cx="32" cy="32" r="31" fill="url(#finangui-logo-gold)" />
      <circle
        cx="32"
        cy="32"
        r="29.6"
        fill="none"
        stroke="#A86E12"
        strokeWidth="2.6"
        strokeDasharray="1.2 1.4"
        opacity="0.55"
      />
      <circle cx="32" cy="32" r="31" fill="none" stroke="#6E4A0E" strokeWidth="1.4" />

      <circle cx="32" cy="32" r="25.2" fill="url(#finangui-logo-field-gold)" stroke="#7A5512" strokeWidth="1.6" />

      <g clipPath="url(#finangui-logo-field)" stroke="#5E400C" strokeWidth="1.3" strokeLinejoin="round" strokeLinecap="round">
        <path d="M10 60 C10 49 19 45 25 45 L39 45 C45 45 54 49 54 60 Z" fill="#B47A18" />
        <path d="M25 45 C27 50 37 50 39 45 L39 42 L25 42 Z" fill="#9C6912" />
        <path d="M27.5 40 L27.5 46 C27.5 48.5 36.5 48.5 36.5 46 L36.5 40 Z" fill="#D9A23A" />
        <ellipse cx="19.8" cy="31" rx="2.6" ry="3.4" fill="#E9B54A" />
        <ellipse cx="44.2" cy="31" rx="2.6" ry="3.4" fill="#E9B54A" />
        <path
          d="M20.5 28 C20.5 18 25.5 13.5 32 13.5 C38.5 13.5 43.5 18 43.5 28 C43.5 36 38.8 41.5 32 41.5 C25.2 41.5 20.5 36 20.5 28 Z"
          fill="#F2C55C"
        />
        <path
          d="M19 30 C16.5 22 17 16 22 12.8 C21.5 10.5 23 9.8 24.5 10.5 C24.6 9 27 9 27.6 10.4 C28.6 8.2 31.3 8.8 32 10.3 C33.6 8.8 36.6 9.6 37.2 11.8 C42.8 13.4 45.6 19 44.2 27.6 C43.4 25.4 42.6 23.8 40.4 23.2 C38.6 25.4 35.6 26.2 32 26.2 C28.2 26.2 25.2 25.4 23.6 23.2 C21.4 24.6 19.8 27 19 30 Z"
          fill="#FFE29A"
        />
        <path d="M24.5 11 C26 13.4 27.6 14.2 30 14.2" fill="none" stroke="#C99528" strokeWidth="0.9" />
        <path d="M33 11 C34.6 13.4 37 14.8 40 15.6" fill="none" stroke="#C99528" strokeWidth="0.9" />
        <rect
          x="21.6"
          y="26.4"
          width="9.4"
          height="8"
          rx="2.8"
          fill="#FFF1C2"
          fillOpacity="0.35"
          stroke="#3E2A06"
          strokeWidth="1.8"
        />
        <rect
          x="33"
          y="26.4"
          width="9.4"
          height="8"
          rx="2.8"
          fill="#FFF1C2"
          fillOpacity="0.35"
          stroke="#3E2A06"
          strokeWidth="1.8"
        />
        <path d="M31 29.6 L33 29.6" stroke="#3E2A06" strokeWidth="1.8" />
        <circle cx="26.3" cy="30.6" r="1.5" fill="#3E2A06" stroke="none" />
        <circle cx="37.7" cy="30.6" r="1.5" fill="#3E2A06" stroke="none" />
        <path d="M31.4 33 C30.6 35.2 31.3 36 32.8 36" fill="none" strokeWidth="1.1" />
        <path d="M27 37.8 C29.2 41.6 34.8 41.6 37 37.8 C34.2 39.2 29.8 39.2 27 37.8 Z" fill="#8A5A0E" />
      </g>

      <g fill="#7A5512">
        <path d="M10.5 32 l1.1 -2.2 l1.1 2.2 l-1.1 2.2 Z" />
        <path d="M51.3 32 l1.1 -2.2 l1.1 2.2 l-1.1 2.2 Z" />
      </g>

      <path
        d="M12 22 C14.5 15 20 10 27 8.4"
        fill="none"
        stroke="#FFF6D6"
        strokeWidth="2"
        strokeLinecap="round"
        opacity="0.8"
      />
    </svg>
  )
}

export function Logo({ inverted = false, className }: { inverted?: boolean; className?: string }) {
  return (
    <span className={cn('flex items-center gap-2 font-semibold', className)}>
      <Coin className="h-9 w-9 shrink-0" />
      <span className={cn('text-lg tracking-tight', inverted && 'text-white')}>finangui</span>
    </span>
  )
}
