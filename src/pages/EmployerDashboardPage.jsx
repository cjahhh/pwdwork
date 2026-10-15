import { useAuth } from "../context/AuthContext.jsx";

function EmployerDashboardPage() {
  const { user } = useAuth();

  return (
    <main className="bg-brand-gray-bg py-16">
      <div className="max-w-4xl mx-auto px-6 space-y-4">
        <h1 className="text-3xl font-black text-brand-dark">Employer Dashboard</h1>
        <p className="text-gray-600">
          Welcome, <strong>{user?.name}</strong>
          {user?.company_name ? <> from <strong>{user.company_name}</strong></> : null}. Company profile, job posting, and applicant review will expand next.
        </p>
        <div className="grid md:grid-cols-3 gap-4">
          {["Company profile", "Job listings", "Applicants"].map((item) => (
            <div key={item} className="bg-white p-6 rounded-2xl border border-gray-200 shadow-soft-lg">
              <h2 className="font-black text-brand-teal">{item}</h2>
              <p className="text-sm text-gray-600 mt-2">Coming soon.</p>
            </div>
          ))}
        </div>
      </div>
    </main>
  );
}

export default EmployerDashboardPage;
