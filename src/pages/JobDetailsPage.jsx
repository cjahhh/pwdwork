import { useState } from "react";
import { Link, useParams } from "react-router-dom";
import { fetchJob, applyToJob } from "../services/jobs.js";
import { getSavedJobState, saveJob, unsaveJob } from "../services/applicant.js";
import { apiErrorMessage } from "../services/api.js";
import { useAuth } from "../context/AuthContext.jsx";
import { useResource } from "../hooks/useResource.js";
import RequestState from "../components/RequestState.jsx";
export default function JobDetailsPage() {
  const { id } = useParams(),
    { user } = useAuth();
  return <JobDetails key={id + ":" + user?.id + ":" + Boolean(user?.email_verification_required)} id={id} user={user} />;
}
function JobDetails({ id, user }) {
  const canUseAccount = user?.role === "applicant" && !user.email_verification_required;
  const resource = useResource(() => fetchJob(id), id);
  const savedResource = useResource(
    () => (canUseAccount ? getSavedJobState(id) : Promise.resolve(false)),
    String(user?.id) + ":" + canUseAccount,
  );
  const [busy, setBusy] = useState(false),
    [error, setError] = useState(""),
    [message, setMessage] = useState("");
  const [coverLetter, setCoverLetter] = useState("");
  const [shareAccommodations, setShareAccommodations] = useState(false);
  const saved = Boolean(savedResource.data);
  async function act(action) {
    if (user?.email_verification_required) {
      setError("Verify your email address before applying or saving jobs.");
      return;
    }
    if (user?.role !== "applicant") {
      setError("Sign in with an applicant account to apply or save jobs.");
      return;
    }
    setBusy(true);
    setError("");
    setMessage("");
    try {
      if (action === "apply") {
        await applyToJob(id, coverLetter, shareAccommodations);
        setMessage("Your application is saved to your account.");
      } else {
        await (saved ? unsaveJob(id) : saveJob(id));
        savedResource.setData(!saved);
        setMessage(
          saved ? "Job removed from saved jobs." : "Job saved to your account.",
        );
      }
    } catch (e) {
      setError(apiErrorMessage(e));
    } finally {
      setBusy(false);
    }
  }
  const job = resource.data;
  return (
    <main className="page-shell job-details-page">
      <Link to="/jobs">Back to jobs</Link>
      <RequestState resource={resource} />
      {job && (
        <article className="panel space-y-5">
          <h1>{job.title}</h1>
          <p>
            {job.company} | {job.location} | {job.type} | {job.mode}
          </p>
          <h2>About this role</h2><p className="whitespace-pre-wrap">{job.description}</p>
          {user && !user.email_verification_required && ["applicant", "employer"].includes(user.role) && <p>
            <Link to={`/reports?target_type=job&target_id=${job.id}`}>Report this listing</Link>{" | "}
            <Link to={`/reports?target_type=employer&target_id=${job.employerId}`}>Report this employer</Link>
          </p>}
          {job.flexibleSchedule && <p>Flexible schedule available</p>}
          <h2>Employer-reported accommodations</h2>
          <p>
            These details have not been independently verified. Confirm your
            requirements with the employer.
          </p>
          {job.accommodations.length ? (
            <ul className="support-list">
              {job.accommodations.map((item) => (
                <li key={item.label}>{item.label}</li>
              ))}
            </ul>
          ) : (
            <p>No accommodations have been specified.</p>
          )}
          {error && <p role="alert">{error}</p>}
          <p role="status">{message}</p>
          {user?.email_verification_required && <p><Link to="/verify-email">Verify your email</Link> to apply or save jobs. You can keep browsing this listing.</p>}
          {canUseAccount && (
            <>
              <RequestState resource={savedResource} />
              <label className="block">
                <span className="form-label">Cover letter (optional)</span>
                <textarea className="form-input" maxLength={10000} value={coverLetter}
                  onChange={(event) => setCoverLetter(event.target.value)} />
              </label>
              <label className="filter-option">
                <input type="checkbox" checked={shareAccommodations}
                  onChange={(event) => setShareAccommodations(event.target.checked)} />
                Share my standardized accommodation requests with this employer for this application.
              </label>
              <p>Your professional profile is shared when you apply. Keep medical details out of your bio, experience, cover letter, and resume. Manage accommodation sharing for existing applications in Your applications.</p>
            </>
          )}
          <div className="flex flex-wrap gap-4">
            <button
              disabled={
                busy || Boolean(user?.email_verification_required) || savedResource.loading || Boolean(savedResource.error)
              }
              aria-pressed={saved}
              onClick={() => act("save")}
            >
              {saved ? "Unsave job" : "Save job"}
            </button>
            <button
              className="primary-button"
              disabled={busy || Boolean(user?.email_verification_required)}
              onClick={() => act("apply")}
            >
              {busy ? "Saving..." : "Apply now"}
            </button>
            {!user && <Link to="/login">Sign in</Link>}
          </div>
        </article>
      )}
    </main>
  );
}
