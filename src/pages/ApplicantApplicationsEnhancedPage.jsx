import { useState } from "react";
import { Link } from "react-router-dom";
import { fetchApplicantApplications, updateApplicationSharing } from "../services/jobs.js";
import { apiErrorMessage } from "../services/api.js";
import { useAuth } from "../context/AuthContext.jsx";
import { usePaginatedResource } from "../hooks/usePaginatedResource.js";
import RequestState from "../components/RequestState.jsx";
import Pagination from "../components/Pagination.jsx";
export default function ApplicantApplicationsPage() {
  const { user } = useAuth();
  const [status, setStatus] = useState("");
  const [busy, setBusy] = useState(null);
  const [error, setError] = useState("");
  const resource = usePaginatedResource(fetchApplicantApplications, status ? { status } : {}, user.id);
  async function share(id, enabled) {
    setBusy(id);
    setError("");
    try {
      await updateApplicationSharing(id, enabled);
      resource.reload();
    } catch (e) {
      setError(apiErrorMessage(e));
    } finally {
      setBusy(null);
    }
  }
  return (
    <main className="page-shell">
      <h1>Your applications</h1>
      <label>
        <span className="form-label">Application status</span>
        <select value={status} disabled={busy !== null} onChange={(event) => setStatus(event.target.value)}>
          <option value="">All statuses</option>
          {["submitted", "reviewing", "shortlisted", "interview", "hired", "rejected"].map((value) => (
            <option key={value} value={value}>{value[0].toUpperCase() + value.slice(1)}</option>
          ))}
        </select>
      </label>
      <button type="button" onClick={resource.reload} disabled={busy !== null || resource.loading}>
        Refresh applications
      </button>
      <RequestState resource={resource} />
      {error && <p role="alert">{error}</p>}
      {resource.data?.meta.total === 0 && <p>No applications match this view.</p>}
      {resource.data?.applications.map((item) => (
        <article className="panel" key={item.applicationId}>
          <h2>{item.jobTitle}</h2>
          <p>
            {item.company} | Applied {item.appliedAt}
          </p>
          <p>Status: {item.status}</p>
          <p><Link to={`/reports?target_type=employer&target_id=${item.employerId}`}>Report a problem with this employer</Link></p>
          <label className="filter-option">
            <input type="checkbox" checked={item.shareAccommodations} disabled={busy !== null}
              onChange={(event) => share(item.applicationId, event.target.checked)} />
            Share my standardized accommodation requests with {item.company} for this application.
          </label>
          {item.interviewDate && (
            <p>
              Interview: {item.interviewDate} at {item.interviewTime}{" "}
              (Asia/Manila). {item.interviewNotes}
            </p>
          )}
        </article>
      ))}
      <Pagination resource={resource} label="Application pages" disabled={busy !== null} />
    </main>
  );
}
