function HeroIllustration() {
  return (
    <div className="relative w-full max-w-md lg:max-w-none animate-float">
      <div className="absolute -top-6 -left-6 w-32 h-32 bg-brand-yellow/30 rounded-full blur-2xl pointer-events-none" />
      <div className="absolute -bottom-6 -right-6 w-40 h-40 bg-brand-teal/20 rounded-full blur-2xl pointer-events-none" />

      <div className="bg-white p-6 rounded-3xl shadow-soft-xl border-2 border-brand-blue/10 relative overflow-hidden">
        <svg
          className="w-full h-auto rounded-2xl"
          viewBox="0 0 500 400"
          fill="none"
          xmlns="http://www.w3.org/2000/svg"
          aria-label="Illustration of diverse professionals in an accessible workplace"
        >
          <rect width="500" height="400" rx="16" fill="#F0FDF4" />
          <rect x="30" y="280" width="440" height="12" rx="6" fill="#1E3A8A" opacity="0.15" />
          <rect x="50" y="292" width="16" height="80" rx="4" fill="#0F766E" />
          <rect x="434" y="292" width="16" height="80" rx="4" fill="#0F766E" />

          <g id="wheelchair-user">
            <circle cx="130" cy="280" r="32" stroke="#1E3A8A" strokeWidth="6" fill="none" />
            <circle cx="130" cy="280" r="8" fill="#1E3A8A" />
            <path d="M120 230 L130 280 L160 280" stroke="#1E3A8A" strokeWidth="6" strokeLinecap="round" />
            <circle cx="115" cy="165" r="22" fill="#0F766E" />
            <path d="M95 195 C95 180 135 180 135 195 L135 240 L95 240 Z" fill="#1E3A8A" />
            <rect x="115" y="210" width="40" height="12" rx="4" fill="#FACC15" />
            <circle cx="120" cy="160" r="3" fill="#FFFFFF" />
            <circle cx="128" cy="160" r="3" fill="#FFFFFF" />
          </g>

          <g id="sign-language-user">
            <circle cx="260" cy="150" r="24" fill="#FACC15" />
            <path d="M230 185 C230 170 290 170 290 185 L285 270 L235 270 Z" fill="#0F766E" />
            <path d="M220 200 L200 180 L210 165" stroke="#111827" strokeWidth="5" strokeLinecap="round" />
            <path d="M300 200 L320 180 L310 165" stroke="#111827" strokeWidth="5" strokeLinecap="round" />
            <path d="M190 150 Q180 160 190 170" stroke="#0F766E" strokeWidth="3" fill="none" />
            <path d="M330 150 Q340 160 330 170" stroke="#0F766E" strokeWidth="3" fill="none" />
          </g>

          <g id="screen-reader-user">
            <circle cx="390" cy="160" r="22" fill="#1E3A8A" />
            <path d="M365 160 C365 140 415 140 415 160" stroke="#FACC15" strokeWidth="6" fill="none" />
            <rect x="362" y="152" width="10" height="16" rx="3" fill="#FACC15" />
            <rect x="408" y="152" width="10" height="16" rx="3" fill="#FACC15" />
            <path d="M360 195 C360 180 420 180 420 195 L415 270 L365 270 Z" fill="#F3F4F6" />
            <rect x="350" y="230" width="60" height="35" rx="6" fill="#1E3A8A" />
            <path d="M360 245 L400 245" stroke="#FACC15" strokeWidth="3" />
          </g>

          <g id="speech-bubbles">
            <rect x="190" y="40" width="120" height="40" rx="12" fill="#1E3A8A" />
            <text
              x="250"
              y="65"
              fill="#FFFFFF"
              fontFamily="Inter, sans-serif"
              fontWeight="800"
              fontSize="14"
              textAnchor="middle"
            >
              Equal Opportunity
            </text>
            <circle cx="330" cy="80" r="18" fill="#FACC15" />
            <path d="M324 80 L336 80 M330 74 L330 86" stroke="#111827" strokeWidth="3" />
          </g>
        </svg>

        <div className="mt-4 p-3 bg-brand-blue-light rounded-xl border border-brand-blue/10 flex items-center justify-between text-xs font-bold text-brand-blue">
          <span className="flex items-center gap-1.5">
            <span className="w-2.5 h-2.5 rounded-full bg-brand-teal" />
            Employer-reported Workplace Accommodations
          </span>
          <span className="font-mono text-gray-500">PWDWork Guarantee</span>
        </div>
      </div>
    </div>
  );
}

export default HeroIllustration;
