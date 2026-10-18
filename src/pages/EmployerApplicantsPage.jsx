import { useMemo, useState } from "react";
import { Link } from "react-router-dom";
import { useAuth } from "../context/AuthContext.jsx";
import { useUi } from "../context/UiContext.jsx";
import { getEmployerApplicants, saveEmployerApplicants } from "../services/employer.js";
import { dedupeApplicants, fetchEmployerApplications, updateEmployerApplication } from "../services/jobs.js";
import { useAutoRefresh } from "../hooks/useAutoRefresh.js";

const statuses = ["All", "New", "Reviewing", "Interview", "Shortlisted", "Rejected"];

function EmployerApplicantsPage() {
  const { user } = useAuth();
  const { showToast } = useUi();
  const [applicants, setApplicants] = useState(() => getEmployerApplicants(user));
  const [filter, setFilter] = useState("All");
  useAutoRefresh(fetchEmployerApplications, (nextApplicants) => setApplicants(dedupeApplicants(nextApplicants)));
  const visible = useMemo(() => applicants.filter((applicant) => filter === "All" || applicant.status === filter), [applicants, filter]);
  async function updateStatus(id, status) {
    try {
      await updateEmployerApplication(id, status);
    } catch {
      const next = applicants.map((applicant) => applicant.id === id ? { ...applicant, status } : applicant);
      setApplicants(next);
      saveEmployerApplicants(user, next);
    }
    setApplicants((current) => current.map((applicant) => applicant.id === id ? { ...applicant, status } : applicant));
    showToast("Applicant updated", `Status changed to ${status}.`);
  }
  return (
    <main className="bg-brand-gray-bg py-12 min-h-[60vh]"><div className="max-w-5xl mx-auto px-6">
      <Link to="/employer" className="text-brand-teal font-bold hover:underline">← Back to dashboard</Link>
      <div className="mt-5 mb-7"><p className="text-xs font-black uppercase tracking-widest text-brand-teal">Find the right fit</p><h1 className="text-3xl font-black text-brand-dark">Applicants</h1><p className="text-gray-600 mt-1">Review candidate strengths and keep accessibility needs visible throughout hiring.</p></div>
      <div className="flex flex-wrap gap-2 mb-6">{statuses.map((item) => <button key={item} type="button" onClick={() => setFilter(item)} className={`px-4 py-2 rounded-full text-sm font-extrabold border ${filter === item ? "bg-brand-teal text-white border-brand-teal" : "bg-white text-brand-dark border-gray-200 hover:border-brand-teal"}`}>{item}</button>)}</div>
      {visible.length ? <div className="space-y-4">{visible.map((applicant) => <article key={applicant.id} className="bg-white rounded-2xl border border-gray-200 shadow-soft-lg p-5 sm:p-6"><div className="flex flex-col lg:flex-row lg:items-start justify-between gap-5"><div className="flex gap-4"><div className="w-12 h-12 shrink-0 rounded-full bg-brand-blue text-white flex items-center justify-center font-black">{applicant.name.split(" ").map((name) => name[0]).join("")}</div><div><h2 className="text-lg font-black text-brand-dark">{applicant.name}</h2><p className="text-sm font-semibold text-brand-teal">{applicant.jobTitle}</p><p className="text-sm text-gray-600 mt-2">Experience: {applicant.experience}</p><div className="flex flex-wrap gap-2 mt-3">{applicant.accommodations.map((item) => <span key={item} className="bg-teal-50 text-brand-teal border border-teal-100 rounded-lg px-2.5 py-1 text-xs font-bold">{item}</span>)}</div></div></div><div className="flex flex-col items-start lg:items-end gap-2"><span className="text-xs text-gray-500">Applied {applicant.appliedAt}</span><label className="text-xs font-extrabold uppercase text-gray-500">Status<select value={applicant.status} onChange={(event) => updateStatus(applicant.id, event.target.value)} className="block mt-1 border border-gray-300 rounded-lg px-3 py-2 text-sm font-bold text-brand-dark"><option>New</option><option>Reviewing</option><option>Interview</option><option>Shortlisted</option><option>Rejected</option></select></label></div></div></article>)}</div> : <div className="bg-white rounded-2xl border border-dashed border-gray-300 p-10 text-center"><h2 className="text-lg font-black text-brand-dark">No applicants in this view</h2><p className="text-gray-600 mt-2">Try a different status filter.</p></div>}
    </div></main>
  );
}

export default EmployerApplicantsPage;
