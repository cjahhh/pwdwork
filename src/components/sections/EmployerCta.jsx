import { Link } from "react-router-dom";

function EmployerCta() {
  return (
    <section id="employer-cta" className="bg-white py-12">
      <div className="max-w-[1440px] mx-auto px-6 lg:px-12">
        <div className="bg-brand-blue rounded-3xl p-8 lg:p-16 text-white shadow-soft-xl relative overflow-hidden">
          <div className="absolute -right-12 -bottom-12 w-96 h-96 bg-brand-teal/20 rounded-full blur-3xl pointer-events-none" />
          <div className="absolute -left-12 -top-12 w-80 h-80 bg-brand-yellow/10 rounded-full blur-3xl pointer-events-none" />

          <div className="relative z-10 max-w-3xl space-y-6">
            <span className="inline-block bg-brand-teal text-white font-extrabold text-xs uppercase tracking-widest px-4 py-1.5 rounded-full border border-teal-400">
              FOR RECRUITERS & ORGANIZATIONS
            </span>
            <h2 className="text-3xl sm:text-4xl lg:text-5xl font-black tracking-tight leading-tight">
              Are you an inclusive employer?
            </h2>
            <p className="text-blue-100 text-lg sm:text-xl font-medium leading-relaxed">
              Help build inclusive workplaces. Post your open
              positions with clearly described, employer-reported accessibility features and connect with applicants.
            </p>
            <div className="pt-4 flex flex-col sm:flex-row items-stretch sm:items-center gap-4">
              <Link
                to="/register?role=employer"
                className="bg-brand-yellow hover:bg-brand-yellow-hover text-brand-dark font-black text-lg px-8 py-4 rounded-2xl shadow-glow-yellow transition flex items-center justify-center gap-3"
              >
                <span>Post a Job Opening</span>
                <svg className="w-5 h-5 text-brand-dark" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2.5" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                </svg>
              </Link>
              <Link
                to="/about"
                className="bg-transparent hover:bg-white/10 text-white border-2 border-white/30 font-bold text-lg px-6 py-4 rounded-2xl transition text-center"
              >
                Learn About Accessibility Audits
              </Link>
            </div>
          </div>
        </div>
      </div>
    </section>
  );
}

export default EmployerCta;
