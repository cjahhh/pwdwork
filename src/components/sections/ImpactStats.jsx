function ImpactStats() {
  return (
    <section className="bg-brand-gray-bg py-16 border-b border-gray-200">
      <div className="max-w-[1440px] mx-auto px-6 lg:px-12">
        <div className="text-center max-w-2xl mx-auto mb-12">
          <span className="text-xs font-black uppercase tracking-widest text-brand-teal block mb-1">
            Measurable Progress
          </span>
          <h2 className="text-3xl font-black text-brand-dark tracking-tight">Our Impact in Inclusive Employment</h2>
          <p className="text-gray-600 text-base mt-2">Connecting qualified candidates with workplaces that value diversity.</p>
        </div>

        <div className="grid grid-cols-1 md:grid-cols-3 gap-8 max-w-5xl mx-auto">
          <article className="bg-white p-8 rounded-2xl border border-gray-200 shadow-soft-lg text-center space-y-3 hover:border-brand-blue transition">
            <div className="w-12 h-12 rounded-2xl bg-blue-50 text-brand-blue flex items-center justify-center mx-auto">
              <svg className="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path
                  strokeLinecap="round"
                  strokeLinejoin="round"
                  strokeWidth="2"
                  d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"
                />
              </svg>
            </div>
            <p className="text-4xl lg:text-5xl font-black text-brand-blue tracking-tight">1,200+</p>
            <p className="text-lg font-bold text-brand-dark">Jobs Posted</p>
            <p className="text-sm text-gray-500">Verified inclusive listings from registered companies.</p>
          </article>

          <article className="bg-white p-8 rounded-2xl border border-gray-200 shadow-soft-lg text-center space-y-3 hover:border-brand-teal transition">
            <div className="w-12 h-12 rounded-2xl bg-teal-50 text-brand-teal flex items-center justify-center mx-auto">
              <svg className="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path
                  strokeLinecap="round"
                  strokeLinejoin="round"
                  strokeWidth="2"
                  d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"
                />
              </svg>
            </div>
            <p className="text-4xl lg:text-5xl font-black text-brand-teal tracking-tight">5,400+</p>
            <p className="text-lg font-bold text-brand-dark">Applications Sent</p>
            <p className="text-sm text-gray-500">Direct connections facilitated with hiring teams.</p>
          </article>

          <article className="bg-white p-8 rounded-2xl border border-gray-200 shadow-soft-lg text-center space-y-3 hover:border-brand-blue transition">
            <div className="w-12 h-12 rounded-2xl bg-yellow-50 text-brand-dark flex items-center justify-center mx-auto">
              <svg className="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path
                  strokeLinecap="round"
                  strokeLinejoin="round"
                  strokeWidth="2"
                  d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"
                />
              </svg>
            </div>
            <p className="text-4xl lg:text-5xl font-black text-brand-blue tracking-tight">860+</p>
            <p className="text-lg font-bold text-brand-dark">PWDs Hired</p>
            <p className="text-sm text-gray-500">Secured meaningful employment with accommodation support.</p>
          </article>
        </div>
      </div>
    </section>
  );
}

export default ImpactStats;
