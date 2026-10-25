import EmployerCta from "../components/sections/EmployerCta.jsx";
import ImpactStats from "../components/sections/ImpactStats.jsx";

function ForEmployersPage() {
  return (
    <main className="informational-page">
      <section className="py-16">
        <div className="max-w-4xl mx-auto px-6 lg:px-12">
          <span className="inline-block bg-brand-teal-light border border-brand-teal/20 text-brand-teal px-4 py-2 rounded-full font-bold text-sm">
            Inclusive hiring with reviewed employer accounts
          </span>
          <h1 className="mt-6 text-4xl sm:text-5xl font-black text-brand-dark tracking-tight">
            Hire talent with accessibility built in.
          </h1>
          <p className="mt-4 text-xl text-gray-700 font-medium leading-relaxed">
            Post roles with employer-reported accommodations and review applications. Applicants choose whether to share
            accommodation preferences and can discuss recruitment arrangements separately.
          </p>
        </div>
      </section>
      <EmployerCta />
      <ImpactStats />
    </main>
  );
}

export default ForEmployersPage;
