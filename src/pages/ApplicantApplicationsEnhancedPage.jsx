import { fetchApplicantApplications } from "../services/jobs.js";
import { useResource } from "../hooks/useResource.js";
import RequestState from "../components/RequestState.jsx";
export default function ApplicantApplicationsPage() {
  const resource = useResource(fetchApplicantApplications);
  return (
    <main className="page-shell">
      <h1>Your applications</h1>
      <button type="button" onClick={resource.reload}>
        Refresh applications
      </button>
      <RequestState resource={resource} />
      {resource.data?.length === 0 && <p>You have not applied for any jobs.</p>}
      {resource.data?.map((item) => (
        <article className="panel" key={item.applicationId}>
          <h2>{item.jobTitle}</h2>
          <p>
            {item.company} | Applied {item.appliedAt}
          </p>
          <p>Status: {item.status}</p>
          {item.interviewDate && (
            <p>
              Interview: {item.interviewDate} at {item.interviewTime}{" "}
              (Asia/Manila). {item.interviewNotes}
            </p>
          )}
        </article>
      ))}
    </main>
  );
}
