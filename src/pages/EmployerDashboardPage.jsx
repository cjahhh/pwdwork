import { Link } from "react-router-dom";
import { useAuth } from "../context/AuthContext.jsx";
import { getEmployerApplicants, getEmployerListings } from "../services/employer.js";

function EmployerDashboardPage() {
  const { user } = useAuth();
  const listings = getEmployerListings(user);
  const applicants = getEmployerApplicants(user);
  const cards = [
    { label: "Company profile", description: "Keep your employer story and verification details current.", path: "/employer/profile", action: "Edit company profile" },
    { label: "Job listings", description: `${listings.filter((listing) => listing.status === "Open").length} open listing${listings.filter((listing) => listing.status === "Open").length === 1 ? "" : "s"}.`, path: "/employer/listings", action: "Manage listings" },
    { label: "Applicants", description: `${applicants.length} candidate${applicants.length === 1 ? "" : "s"} to review.`, path: "/employer/applicants", action: "Review applicants" },
  ];

  return (
    <main className="bg-brand-gray-bg py-16">
      <div className="max-w-4xl mx-auto px-6 space-y-4">
        <h1 className="text-3xl font-black text-brand-dark">Employer Dashboard</h1>
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
