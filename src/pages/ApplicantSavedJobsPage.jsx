import { useState } from "react";
import { Link } from "react-router-dom";
import { useAuth } from "../context/AuthContext.jsx";
import { getSavedJobs, toggleSavedJob } from "../services/applicant.js";
import { useUi } from "../context/UiContext.jsx";

function ApplicantSavedJobsPage() {
  const { user } = useAuth();
  const { showToast } = useUi();
  const [savedJobs, setSavedJobs] = useState(() => getSavedJobs(user));
  function remove(job) { toggleSavedJob(user, job.id); setSavedJobs((current) => current.filter((item) => item.id !== job.id)); showToast("Job removed", `${job.title} is no longer in your saved jobs.`); }
  return (
    <main className="bg-brand-gray-bg py-12 min-h-[60vh]"><div className="max-w-5xl mx-auto px-6">
      <Link to="/applicant" className="text-brand-teal font-bold hover:underline">← Back to dashboard</Link>
      <div className="mt-5 mb-7"><p className="text-xs font-black uppercase tracking-widest text-brand-teal">Roles you want to revisit</p><h1 className="text-3xl font-black text-brand-dark">Saved jobs</h1><p className="text-gray-600 mt-1">Keep promising opportunities in one place while you decide what to apply for.</p></div>
      {savedJobs.length ? <div className="grid md:grid-cols-2 gap-5">{savedJobs.map((job) => <article key={job.id} className="bg-white rounded-2xl border border-gray-200 shadow-soft-lg p-6 flex flex-col justify-between gap-5"><div><div className="flex items-start justify-between gap-3"><div className={`w-12 h-12 rounded-full ${job.companyColor} text-white font-black flex items-center justify-center`}>{job.companyLogo}</div><span className="bg-blue-50 text-brand-blue border border-blue-200 rounded-full px-3 py-1 text-xs font-extrabold uppercase">{job.type}</span></div><h2 className="text-xl font-black text-brand-dark mt-4"><Link to={`/jobs/${job.id}`} className="hover:text-brand-blue">{job.title}</Link></h2><p className="font-bold text-gray-700 mt-1">{job.company}</p><p className="text-sm text-gray-500 mt-1">{job.location}</p><p className="text-sm text-gray-600 mt-3 leading-relaxed">{job.description}</p></div><div className="flex gap-3"><Link to={`/jobs/${job.id}`} className="flex-1 text-center bg-brand-blue hover:bg-brand-blue-dark text-white font-black py-3 rounded-xl">View job</Link><button type="button" onClick={() => remove(job)} className="px-4 py-3 rounded-xl border border-gray-300 text-gray-700 font-extrabold hover:border-red-300 hover:text-red-700">Remove</button></div></article>)}</div> : <div className="bg-white rounded-2xl border border-dashed border-gray-300 p-10 text-center"><h2 className="text-lg font-black text-brand-dark">No saved jobs yet</h2><p className="text-gray-600 mt-2">Save roles from the job details page to compare them later.</p><Link to="/jobs" className="inline-flex mt-5 bg-brand-blue text-white font-extrabold px-5 py-3 rounded-xl">Explore jobs</Link></div>}
    </div></main>
  );
}

export default ApplicantSavedJobsPage;
