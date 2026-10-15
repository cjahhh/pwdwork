import HowItWorks from "../components/sections/HowItWorks.jsx";
import ImpactStats from "../components/sections/ImpactStats.jsx";

function AboutPage() {
  return (
    <main>
      <section className="bg-white py-16 border-b border-gray-200">
        <div className="max-w-3xl mx-auto px-6 lg:px-12 space-y-4">
          <span className="bg-blue-50 text-brand-blue font-extrabold text-xs uppercase tracking-widest px-3.5 py-1.5 rounded-full border border-blue-200">
            About PWDWork
          </span>
          <h1 className="text-4xl font-black text-brand-dark tracking-tight">Built for accessible employment.</h1>
          <p className="text-lg text-gray-700 leading-relaxed">
            PWDWork is a social-impact employment platform that connects persons with disabilities to employers who
            publish clear, verified workplace accommodations. We align with UN SDG 8 (Decent Work) and SDG 10 (Reduced
            Inequalities).
          </p>
        </div>
      </section>
      <HowItWorks />
      <ImpactStats />
    </main>
  );
}

export default AboutPage;
