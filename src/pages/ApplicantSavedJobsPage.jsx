import { useState } from "react";
import { getSavedJobs, unsaveJob } from "../services/applicant.js";
import { apiErrorMessage } from "../services/api.js";
import { usePaginatedResource } from "../hooks/usePaginatedResource.js";
import Pagination from "../components/Pagination.jsx";
import RequestState from "../components/RequestState.jsx";
import JobCard from "../components/JobCard.jsx";
import { useAuth } from "../context/AuthContext.jsx";
import { Link } from 'react-router-dom';
import EmptyState from '../components/EmptyState.jsx';
import PageHeader from '../components/PageHeader.jsx';
export default function ApplicantSavedJobsPage() {
  const { user } = useAuth();
  const resource = usePaginatedResource(getSavedJobs, {}, user.id);
  const [error, setError] = useState(""),
    [busy, setBusy] = useState(null);
  async function remove(id) {
    setBusy(id);
    setError("");
    try {
      await unsaveJob(id);
      resource.reload();
    } catch (e) {
      setError(apiErrorMessage(e));
    } finally {
      setBusy(null);
    }
  }
  return (
    <main className="page-shell">
      <PageHeader title="Saved jobs" eyebrow="Keep the possibilities close" icon="heart" description="Return to opportunities that caught your attention. Saving a job does not apply for it." />
      <RequestState resource={resource} />
      {error && <p role="alert">{error}</p>}
      {resource.data?.meta.total === 0 && <EmptyState title="No saved jobs yet." action={<Link className="primary-button" to="/jobs">Browse jobs</Link>}>Save a job to keep it here for later. Saving does not submit an application.</EmptyState>}
      <div className="saved-jobs-grid grid gap-6 md:grid-cols-2">
        {resource.data?.jobs.map((job) => (
          <div key={job.id} className="saved-job-item surface-card min-w-0">
            <JobCard job={job}>
            <div className="saved-job-actions">
            <p>Status: {job.status}</p>
            <button className="button-secondary" onClick={() => remove(job.id)} disabled={busy !== null}>
              {busy === job.id ? "Removing..." : "Remove saved job"}
            </button>
            </div>
            </JobCard>
          </div>
        ))}
      </div>
      <Pagination resource={resource} label="Saved job pages" disabled={busy !== null} />
    </main>
  );
}
