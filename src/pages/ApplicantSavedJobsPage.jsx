import { useState } from "react";
import { getSavedJobs, unsaveJob } from "../services/applicant.js";
import { apiErrorMessage } from "../services/api.js";
import { useResource } from "../hooks/useResource.js";
import RequestState from "../components/RequestState.jsx";
import JobCard from "../components/JobCard.jsx";
export default function ApplicantSavedJobsPage() {
  const resource = useResource(getSavedJobs);
  const [error, setError] = useState(""),
    [busy, setBusy] = useState(null);
  async function remove(id) {
    setBusy(id);
    setError("");
    try {
      await unsaveJob(id);
      resource.setData((items) => items.filter((job) => job.id !== id));
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
      {resource.data?.length === 0 && <p>No saved jobs yet.</p>}
      <div className="grid gap-6 md:grid-cols-2">
        {resource.data?.map((job) => (
          <div key={job.id}>
            <JobCard job={job} />
            <p>Status: {job.status}</p>
            <button onClick={() => remove(job.id)} disabled={busy !== null}>
              {busy === job.id ? "Removing..." : "Remove saved job"}
            </button>
          </div>
        ))}
      </div>
    </main>
  );
}
