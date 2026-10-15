import { useAuth } from "../context/AuthContext.jsx";

function ApplicantDashboardPage() {
  const { user } = useAuth();

  return (
    <main className="bg-brand-gray-bg py-16">
      <div className="max-w-4xl mx-auto px-6 space-y-4">
        <h1 className="text-3xl font-black text-brand-dark">Applicant Dashboard</h1>
        <p className="text-gray-600">
          Welcome, <strong>{user?.name}</strong>. Your account is saved in MySQL. Profile, application tracker, saved jobs, and notifications will expand next.
        </p>
        <div className="grid md:grid-cols-3 gap-4">
          {["Profile", "Applications", "Saved jobs"].map((item) => (
            <div key={item} className="bg-white p-6 rounded-2xl border border-gray-200 shadow-soft-lg">
              <h2 className="font-black text-brand-blue">{item}</h2>
              <p className="text-sm text-gray-600 mt-2">Coming soon.</p>
            </div>
          ))}
        </div>
      </div>
    </main>
  );
}

export default ApplicantDashboardPage;
