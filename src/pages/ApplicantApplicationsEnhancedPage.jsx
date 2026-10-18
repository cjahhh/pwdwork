import { useEffect, useMemo, useState } from "react";
import { Link } from "react-router-dom";
import { getApplicationJob, getApplications } from "../services/applicant.js";
import { applyToJob, fetchApplicantApplications } from "../services/jobs.js";
import { useAutoRefresh } from "../hooks/useAutoRefresh.js";
import { useAuth } from "../context/AuthContext.jsx";

const filters = ["All", "Under review", "Interview", "Accepted", "Closed"];

export default function ApplicantApplicationsEnhancedPage() {
  const { user } = useAuth();
  const [filter, setFilter] = useState("All"); const [remoteApplications, setRemoteApplications] = useState([]);
  useEffect(() => {
    if (!user) return;
    getApplications(user).forEach((application) => {
      applyToJob(application.jobId).catch(() => {});
    });
  }, [user]);
  useAutoRefresh(fetchApplicantApplications, setRemoteApplications);
  const applications = remoteApplications;
  const visible = useMemo(() => applications.filter((item) => filter === "All" || item.status === filter), [applications, filter]);
  return <main className="bg-brand-gray-bg py-12 min-h-[60vh]"><div className="max-w-4xl mx-auto px-6"><Link to="/jobs" className="text-brand-teal font-bold hover:underline">← Find jobs</Link><div className="mt-5 mb-7"><p className="text-xs font-black uppercase tracking-widest text-brand-teal">Keep track of your progress</p><h1 className="text-3xl font-black text-brand-dark">Applications</h1><p className="text-gray-600 mt-1">Review every role you have applied for and see what happens next.</p></div><div className="flex flex-wrap gap-2 mb-6">{filters.map((item) => <button key={item} type="button" onClick={() => setFilter(item)} className={`px-4 py-2 rounded-full text-sm font-extrabold border ${filter === item ? "bg-brand-blue text-white border-brand-blue" : "bg-white text-brand-dark border-gray-200"}`}>{item}</button>)}</div>{visible.length ? <div className="space-y-4">{visible.map((application) => { const job = getApplicationJob(application); const title = job?.title || application.jobTitle; if (!title) return null; return <article key={`${application.jobId}-${application.appliedAt}`} className="bg-white rounded-2xl border border-gray-200 shadow-soft-lg p-5 sm:p-6"><div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4"><div><p className="text-xs font-extrabold uppercase tracking-wider text-gray-500">Applied {new Date(application.appliedAt).toLocaleDateString()}</p><h2 className="text-lg font-black text-brand-dark mt-1">{job ? <Link to={`/jobs/${job.id}`} className="hover:text-brand-blue">{title}</Link> : title}</h2><p className="text-sm font-semibold text-gray-600">{job?.company || application.company} · {job?.location || "Accessible workplace"}</p>{application.interviewDate && <p className="text-sm font-black text-brand-blue mt-3">Interview scheduled: {application.interviewDate} at {application.interviewTime}</p>}{application.interviewNotes && <p className="text-sm text-gray-600 mt-1">Note: {application.interviewNotes}</p>}</div><span className="self-start sm:self-center bg-blue-50 text-brand-blue border border-blue-200 rounded-full px-3 py-1.5 text-sm font-extrabold">{application.status}</span></div></article>; })}</div> : <div className="bg-white rounded-2xl border border-dashed border-gray-300 p-10 text-center"><h2 className="text-lg font-black text-brand-dark">No applications here yet</h2><p className="text-gray-600 mt-2">Find a role that fits your goals and start your application.</p></div>}</div></main>;
}
