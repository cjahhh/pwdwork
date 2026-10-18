import { useState } from "react";
import { Link } from "react-router-dom";
import { useAuth } from "../context/AuthContext.jsx";
import { getApplications, getSavedJobs } from "../services/applicant.js";
import { fetchApplicantApplications } from "../services/jobs.js";
import { useAutoRefresh } from "../hooks/useAutoRefresh.js";

function ApplicantDashboardPage() {
  const { user } = useAuth();
  const [applicationCount, setApplicationCount] = useState(() => getApplications(user).length);
  useAutoRefresh(fetchApplicantApplications, (applications) => setApplicationCount(applications.length));
  const cards = [
    { label: "Profile", description: "Update your skills and accommodation preferences.", path: "/applicant/profile", action: "Edit profile" },
    { label: "Applications", description: `${applicationCount} application${applicationCount === 1 ? "" : "s"} in your tracker.`, path: "/applicant/applications", action: "View applications" },
    { label: "Saved jobs", description: `${getSavedJobs(user).length} role${getSavedJobs(user).length === 1 ? "" : "s"} saved for later.`, path: "/applicant/saved-jobs", action: "View saved jobs" },
  ];

  return (
    <main className="bg-brand-gray-bg py-16">
      <div className="max-w-4xl mx-auto px-6 space-y-4">
        <h1 className="text-3xl font-black text-brand-dark">Applicant Dashboard</h1>
        <p className="text-gray-600">
          Welcome, <strong>{user?.name}</strong>. Manage your profile, keep applications organized, and save accessible roles for later.
        </p>
        <div className="grid md:grid-cols-3 gap-4">
          {cards.map((item) => (
            <Link to={item.path} key={item.label} className="bg-white p-6 rounded-2xl border border-gray-200 shadow-soft-lg hover:border-brand-blue hover:-translate-y-0.5 transition">
              <h2 className="font-black text-brand-blue">{item.label}</h2>
              <p className="text-sm text-gray-600 mt-2">{item.description}</p>
              <span className="inline-block text-sm font-extrabold text-brand-teal mt-5">{item.action} →</span>
            </Link>
          ))}
        </div>
      </div>
    </main>
  );
}

export default ApplicantDashboardPage;
