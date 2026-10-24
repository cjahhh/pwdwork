import { Link } from "react-router-dom";
import { useAuth } from "../context/AuthContext.jsx";
import { fetchEmployerApplications, fetchEmployerJobs } from "../services/jobs.js";
import { useResource } from "../hooks/useResource.js";
import RequestState from "../components/RequestState.jsx";

function EmployerDashboardPage() {
  const { user } = useAuth();
  const resource = useResource(async () => {
    const [jobs, applications] = await Promise.all([
      fetchEmployerJobs({ status: "open", per_page: 1 }),
      fetchEmployerApplications({ per_page: 1 }),
    ]);
    return { jobs: jobs.meta.total, applicants: applications.meta.total };
  }, user.id);
  const cards = [
    { label: "Company profile", description: "Keep your employer story and verification details current.", path: "/employer/profile", action: "Edit company profile" },
    { label: "Job listings", description: resource.data ? `${resource.data.jobs} open listings.` : "Manage your listings.", path: "/employer/listings", action: "Manage listings" },
    { label: "Applicants", description: resource.data ? `${resource.data.applicants} applications in your pipeline.` : "Review your applicants.", path: "/employer/applicants", action: "Review applicants" },
  ];

  return (
    <main className="bg-brand-gray-bg py-16">
      <div className="max-w-4xl mx-auto px-6 space-y-4">
        <h1 className="text-3xl font-black text-brand-dark">Employer Dashboard</h1>
        <RequestState resource={resource} />
        <p className="text-gray-600">
          Welcome, <strong>{user?.name}</strong>
          {user?.company_name ? <> from <strong>{user.company_name}</strong></> : null}. Manage your company presence, roles, and candidate pipeline.
        </p>
        <div className="grid md:grid-cols-3 gap-4">
          {cards.map((item) => (
            <Link to={item.path} key={item.label} className="bg-white p-6 rounded-2xl border border-gray-200 shadow-soft-lg hover:border-brand-teal hover:-translate-y-0.5 transition">
              <h2 className="font-black text-brand-teal">{item.label}</h2>
              <p className="text-sm text-gray-600 mt-2">{item.description}</p>
              <span className="inline-block text-sm font-extrabold text-brand-teal mt-5">{item.action} →</span>
            </Link>
          ))}
        </div>
      </div>
    </main>
  );
}

export default EmployerDashboardPage;
