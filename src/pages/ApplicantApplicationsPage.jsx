import { useMemo, useState } from "react";
import { Link } from "react-router-dom";
import { useAuth } from "../context/AuthContext.jsx";
import { getApplications, getApplicationJob } from "../services/applicant.js";
import { fetchApplicantApplications } from "../services/jobs.js";
import { useAutoRefresh } from "../hooks/useAutoRefresh.js";

const filters = ["All", "Under review", "Interview", "Accepted", "Closed"];

function ApplicantApplicationsPage() {
  const { user } = useAuth();
  const [filter, setFilter] = useState("All");
  const applications = getApplications(user);
  const [remoteApplications, setRemoteApplications] = useState(null);
  useAutoRefresh(fetchApplicantApplications, setRemoteApplications);
  const allApplications = remoteApplications || applications;
  const visibleApplications = useMemo(() => allApplications.filter((application) => filter === "All" || application.status === filter), [allApplications, filter]);
  return (
    <main className="bg-brand-gray-bg py-12 min-h-[60vh]"><div className="max-w-4xl mx-auto px-6">
      <Link to="/applicant" className="text-brand-teal font-bold hover:underline">← Back to dashboard</Link>
      <div className="mt-5 mb-7"><p className="text-xs font-black uppercase tracking-widest text-brand-teal">Keep track of your progress</p><h1 className="text-3xl font-black text-brand-dark">Applications</h1><p className="text-gray-600 mt-1">Review every role you have applied for and see what happens next.</p></div>
      <div className="flex flex-wrap gap-2 mb-6" role="tablist" aria-label="Application status filters">{filters.map((item) => <button key={item} type="button" onClick={() => setFilter(item)} className={`px-4 py-2 rounded-full text-sm font-extrabold border transition ${filter === item ? "bg-brand-blue text-white border-brand-blue" : "bg-white text-brand-dark border-gray-200 hover:border-brand-blue"}`}>{item}</button>)}</div>
      {visibleApplications.length ? <div className="space-y-4">{visibleApplications.map((application) => { const job = getApplicationJob(application); const jobTitle = job?.title || application.jobTitle; const company = job?.company || application.company; const location = job?.location || "Accessible workplace"; if (!jobTitle) return null; return <article key={application.jobId} className="bg-white rounded-2xl border border-gray-200 shadow-soft-lg p-5 sm:p-6 flex flex-col sm:flex-row sm:items-center justify-between gap-4"><div><p className="text-xs font-extrabold uppercase tracking-wider text-gray-500">Applied {new Date(application.appliedAt).toLocaleDateString()}</p><h2 className="text-lg font-black text-brand-dark mt-1">{job ? <Link to={`/jobs/${job.id}`} className="hover:text-brand-blue">{jobTitle}</Link> : jobTitle}</h2><p className="text-sm font-semibold text-gray-600">{company} · {location}</p></div><span className="self-start sm:self-center bg-blue-50 text-brand-blue border border-blue-200 rounded-full px-3 py-1.5 text-sm font-extrabold">{application.status}</span></article>; })}</div> : <div className="bg-white rounded-2xl border border-dashed border-gray-300 p-10 text-center"><h2 className="text-lg font-black text-brand-dark">No applications here yet</h2><p className="text-gray-600 mt-2">Find a role that fits your goals and start your application.</p><Link to="/jobs" className="inline-flex mt-5 bg-brand-blue text-white font-extrabold px-5 py-3 rounded-xl">Find accessible jobs</Link></div>}
    </div></main>
  );
}

export default ApplicantApplicationsPage;
