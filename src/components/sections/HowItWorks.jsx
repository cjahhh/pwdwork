function HowItWorks() {
  return (
    <section id="how-it-works" className="bg-white py-16 lg:py-24 border-b border-gray-200">
      <div className="max-w-[1440px] mx-auto px-6 lg:px-12">
        <div className="text-center max-w-3xl mx-auto mb-16 space-y-3">
          <span className="bg-blue-50 text-brand-blue font-extrabold text-xs uppercase tracking-widest px-3.5 py-1.5 rounded-full border border-blue-200">
            Seamless 3-Step Process
          </span>
          <h2 className="text-3xl sm:text-4xl lg:text-5xl font-black text-brand-dark tracking-tight">
            How PWDWork Works
          </h2>
          <p className="text-gray-600 text-lg leading-relaxed">
            Designed to eliminate workplace barriers. We connect talented individuals with empathetic hiring teams
            through accessible digital pathways.
          </p>
        </div>

        <div className="grid grid-cols-1 md:grid-cols-3 gap-8 lg:gap-10">
          <article className="bg-white p-8 rounded-2xl border-2 border-gray-200 shadow-soft-lg relative hover:border-brand-blue transition group">
            <div className="absolute -top-5 left-8 bg-brand-blue text-brand-yellow font-black text-lg px-4 py-1 rounded-xl shadow-md border-2 border-white">
              Step 01
            </div>
            <div className="w-16 h-16 rounded-2xl bg-blue-50 text-brand-blue flex items-center justify-center my-4 group-hover:scale-110 transition-transform">
              <svg className="w-9 h-9" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path
                  strokeLinecap="round"
                  strokeLinejoin="round"
                  strokeWidth="2"
                  d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"
                />
              </svg>
            </div>
            <h3 className="text-2xl font-black text-brand-dark mb-3">Create Your Profile</h3>
            <p className="text-gray-600 text-base leading-relaxed">
              Add your skills and optional experience. Choose whether to share standardized profile accommodation
              preferences for each application, and discuss practical arrangements separately.
            </p>
          </article>

          <article className="bg-white p-8 rounded-2xl border-2 border-gray-200 shadow-soft-lg relative hover:border-brand-teal transition group">
            <div className="absolute -top-5 left-8 bg-brand-teal text-white font-black text-lg px-4 py-1 rounded-xl shadow-md border-2 border-white">
              Step 02
            </div>
            <div className="w-16 h-16 rounded-2xl bg-teal-50 text-brand-teal flex items-center justify-center my-4 group-hover:scale-110 transition-transform">
              <svg className="w-9 h-9" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path
                  strokeLinecap="round"
                  strokeLinejoin="round"
                  strokeWidth="2"
                  d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"
                />
              </svg>
            </div>
            <h3 className="text-2xl font-black text-brand-dark mb-3">Search and Apply</h3>
            <p className="text-gray-600 text-base leading-relaxed">
              Browse published jobs with employer-reported accessibility information. Apply to employers
              whose accounts have been reviewed by PWDWork administrators.
            </p>
          </article>

          <article className="bg-white p-8 rounded-2xl border-2 border-gray-200 shadow-soft-lg relative hover:border-brand-blue transition group">
            <div className="absolute -top-5 left-8 bg-brand-blue text-brand-yellow font-black text-lg px-4 py-1 rounded-xl shadow-md border-2 border-white">
              Step 03
            </div>
            <div className="w-16 h-16 rounded-2xl bg-blue-50 text-brand-blue flex items-center justify-center my-4 group-hover:scale-110 transition-transform">
              <svg className="w-9 h-9" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path
                  strokeLinecap="round"
                  strokeLinejoin="round"
                  strokeWidth="2"
                  d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"
                />
              </svg>
            </div>
            <h3 className="text-2xl font-black text-brand-dark mb-3">Track Your Application</h3>
            <p className="text-gray-600 text-base leading-relaxed">
              Check application status and in-app notifications. Employers can schedule interviews, and you can
              discuss practical arrangements with the hiring team.
            </p>
          </article>
        </div>
      </div>
    </section>
  );
}

export default HowItWorks;
