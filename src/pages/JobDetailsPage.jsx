import { Link, useParams } from "react-router-dom";
import { useEffect, useState } from "react";
import { fetchJob, getJobById } from "../services/jobs.js";
import { useUi } from "../context/UiContext.jsx";
import { useAuth } from "../context/AuthContext.jsx";
import { addApplication, isJobSaved, toggleSavedJob } from "../services/applicant.js";
import { applyToJob } from "../services/jobs.js";

function JobDetailsPage() {
  const { id } = useParams();
  const staticJob = getJobById(id);
  const [loadedJob, setLoadedJob] = useState(null);
  const { showToast } = useUi();
  const { user } = useAuth();
  const job = staticJob || loadedJob;
  const [saved, setSaved] = useState(false);

  useEffect(() => {
    if (!staticJob) fetchJob(id).then(setLoadedJob).catch(() => {});
  }, [id, staticJob]);

  useEffect(() => {
    setSaved(Boolean(user && job && isJobSaved(user, job.id)));
  }, [user, job]);

  if (!job) {
    return (
      <main className="bg-brand-gray-bg py-16">
        <div className="max-w-2xl mx-auto px-6 text-center space-y-4">
          <h1 className="text-3xl font-black text-brand-dark">Job not found</h1>
          <p className="text-gray-600">That listing is not in the current featured set.</p>
          <Link to="/jobs" className="inline-flex bg-brand-blue text-white font-extrabold px-6 py-3 rounded-xl">
            Back to jobs
          </Link>
        </div>
      </main>
    );
  }

  async function applyNow() {
    if (user) {
      try {
        await applyToJob(job.id);
        addApplication(user, job);
        showToast("Application Sent", `Applied to ${job.title}.`);
      } catch {
        showToast("Application not sent", "We could not save this application to the shared account. Please try again.");
        return;
      }
      return;
    }
    showToast("Application Sent", `Applied to ${job.title}.`);
  }

  function saveJob() {
    if (!user) {
      showToast("Sign in required", "Log in to save jobs to your applicant account.");
      return;
    }
    toggleSavedJob(user, job.id);
    setSaved((current) => !current);
    showToast(saved ? "Job unsaved" : "Job saved", saved ? "Removed from your saved jobs." : "Added to your saved jobs.");
  }

  return (
    <main className="bg-brand-gray-bg py-12">
      <div className="max-w-3xl mx-auto px-6">
        <Link to="/jobs" className="text-brand-teal font-bold hover:underline">
          ← Back to listings
        </Link>

        <article className="mt-6 bg-white rounded-3xl p-6 sm:p-8 shadow-soft-xl border-2 border-brand-blue space-y-6">
          <div className="bg-blue-50 p-4 rounded-2xl border border-blue-200 flex items-center justify-between">
            <div>
              <p className="text-lg font-black text-brand-blue">{job.company}</p>
              <p className="text-sm text-gray-600 font-semibold">
                📍 {job.location} • {job.type}
              </p>
            </div>
            <div
              className={`w-12 h-12 rounded-full ${job.companyColor} text-white font-bold flex items-center justify-center text-lg`}
            >
              {job.companyLogo}
            </div>
          </div>

          <div>
            <h1 className="text-3xl font-black text-brand-dark">{job.title}</h1>
          </div>

          <div className="space-y-2">
            <h2 className="font-extrabold text-base text-brand-dark">Role Overview</h2>
            <p className="text-gray-700 leading-relaxed">{job.description}</p>
          </div>

          <div className="bg-brand-teal-light p-5 rounded-2xl border border-brand-teal/20 space-y-3">
            <h2 className="font-extrabold text-base text-brand-teal">♿ Guaranteed Workplace Accommodations:</h2>
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-2">
              {job.accommodations.map((acc) => (
                <div
                  key={acc.label}
                  className="bg-white p-2.5 rounded-xl border border-brand-teal/20 flex items-center gap-2 text-sm font-bold text-brand-dark"
                >
                  <span className="text-lg" aria-hidden="true">
                    {acc.icon}
                  </span>
                  <span>{acc.label}</span>
                </div>
              ))}
            </div>
          </div>

          <div className="flex flex-col sm:flex-row items-stretch sm:items-center justify-end gap-3">
            <Link to="/jobs" className="bg-gray-200 hover:bg-gray-300 text-brand-dark font-bold px-5 py-2.5 rounded-xl text-center">
              Back to Listings
            </Link>
            <button type="button" onClick={saveJob} className="border-2 border-brand-blue text-brand-blue hover:bg-blue-50 font-extrabold px-5 py-2.5 rounded-xl">
              {saved ? "Saved" : "Save job"}
            </button>
            <button
              type="button"
              onClick={applyNow}
              className="bg-brand-blue hover:bg-brand-blue-dark text-white font-black px-8 py-2.5 rounded-xl shadow-soft-lg flex items-center justify-center gap-2"
            >
              <span>Apply Now</span>
              <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M5 13l4 4L19 7" />
              </svg>
            </button>
          </div>
        </article>
      </div>
    </main>
  );
}

export default JobDetailsPage;
