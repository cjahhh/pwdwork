import { useState } from "react";
import { getSavedJobs, unsaveJob } from "../services/applicant.js";
import { apiErrorMessage } from "../services/api.js";
import { usePaginatedResource } from "../hooks/usePaginatedResource.js";
import Pagination from "../components/Pagination.jsx";
import RequestState from "../components/RequestState.jsx";
import JobCard from "../components/JobCard.jsx";
import { useAuth } from "../context/AuthContext.jsx";
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
      <h1>Saved jobs</h1>
      <RequestState resource={resource} />
      {error && <p role="alert">{error}</p>}
      {resource.data?.meta.total === 0 && <p>No saved jobs yet.</p>}
      <div className="grid gap-6 md:grid-cols-2">
        {resource.data?.jobs.map((job) => (
          <div key={job.id}>
            <JobCard job={job} />
            <p>Status: {job.status}</p>
            <button onClick={() => remove(job.id)} disabled={busy !== null}>
              {busy === job.id ? "Removing..." : "Remove saved job"}
            </button>
          </div>
        ))}
      </div>
      <Pagination resource={resource} label="Saved job pages" disabled={busy !== null} />
    </main>
  );
}
