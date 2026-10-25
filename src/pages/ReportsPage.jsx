import { useState } from "react";
import { useSearchParams } from "react-router-dom";
import { useAuth } from "../context/AuthContext.jsx";
import { usePaginatedResource } from "../hooks/usePaginatedResource.js";
import { fetchOwnReports, submitReport, reportCategories, displayStatus } from "../services/moderation.js";
import { apiErrorMessage } from "../services/api.js";
import RequestState from "../components/RequestState.jsx";
import Pagination from "../components/Pagination.jsx";
import PageHeader from "../components/PageHeader.jsx";

export default function ReportsPage() {
  const { user } = useAuth();
  const [params] = useSearchParams();
  const initial = Object.hasOwn(reportCategories, params.get("target_type")) ? params.get("target_type") : "platform";
  const [target, setTarget] = useState(initial);
  const [targetId, setTargetId] = useState(params.get("target_id") || "");
  const [category, setCategory] = useState(reportCategories[initial][0]);
  const [description, setDescription] = useState("");
  const [status, setStatus] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [message, setMessage] = useState("");
  const resource = usePaginatedResource(fetchOwnReports, status ? { status } : {}, user.id);
  async function submit(event) {
    event.preventDefault(); setBusy(true); setError(""); setMessage("");
    try {
      const data = await submitReport({ target_type: target, ...(target !== "platform" ? { target_id: Number(targetId) } : {}), category, description });
      setMessage(`${data.message} Reference #${data.report.id}.`); setDescription(""); resource.reload();
    } catch (exception) { setError(apiErrorMessage(exception)); }
    finally { setBusy(false); }
  }
  return <main className="page-shell">
    <PageHeader title="Reports and support" eyebrow="Help keep the platform trustworthy" icon="shield" description="Report suspicious jobs, misleading accessibility claims, discrimination, or account/platform problems. Your report is private to you and authorized administrators." />
    <form className="panel space-y-4" onSubmit={submit} aria-busy={busy}>
      <h2>Submit a report</h2>
      {error && <p role="alert">{error}</p>}<p role="status">{message}</p>
      <label className="block"><span className="form-label">What does this concern?</span>
        <select className="form-input" value={target} disabled={busy} onChange={(event) => { setTarget(event.target.value); setCategory(reportCategories[event.target.value][0]); setTargetId(""); }}>
          <option value="platform">Platform or my account</option><option value="job">Job listing</option><option value="employer">Employer</option><option value="account">Account involved in a hiring interaction</option>
        </select>
      </label>
      {target !== "platform" && <label className="block"><span className="form-label">{displayStatus(target)} ID</span>
        <input className="form-input" type="number" min="1" required value={targetId} disabled={busy} onChange={(event) => setTargetId(event.target.value)} />
        <span>Use the Report link on a job or an ID from your hiring interaction. Private unrelated accounts cannot be looked up.</span>
      </label>}
      <label className="block"><span className="form-label">Report category</span>
        <select className="form-input" value={category} disabled={busy} onChange={(event) => setCategory(event.target.value)}>
          {reportCategories[target].map((value) => <option key={value} value={value}>{displayStatus(value)}</option>)}
        </select>
      </label>
      <label className="block"><span className="form-label">Describe the problem</span>
        <textarea className="form-input" required minLength={10} maxLength={5000} value={description} disabled={busy} onChange={(event) => setDescription(event.target.value)} />
      </label>
      <p>Include relevant facts and dates. Keep passwords, medical diagnoses, PWD ID numbers, and unrelated personal details out of reports. Repeated open reports for the same target/category are blocked.</p>
      <button className="primary-button" disabled={busy}>{busy ? "Submitting report..." : "Submit report"}</button>
    </form>
    <section><h2>Your reports</h2>
      <label><span className="form-label">Report status</span><select value={status} className="form-input" onChange={(event) => setStatus(event.target.value)}>
        <option value="">All statuses</option>{["pending", "under_review", "resolved", "dismissed"].map((value) => <option key={value} value={value}>{displayStatus(value)}</option>)}
      </select></label>
      <button type="button" onClick={resource.reload} disabled={resource.loading}>Refresh reports</button>
      <RequestState resource={resource} />
      {resource.data?.meta.total === 0 && <p>No reports match this view.</p>}
      {resource.data?.reports.map((report) => <article key={report.id} className="panel">
        <h3>Report #{report.id} · {displayStatus(report.category)}</h3>
        <p>Status: {displayStatus(report.status)} · {report.targetType}{report.targetId ? ` #${report.targetId}` : ""}</p>
        <p className="whitespace-pre-wrap">{report.description}</p><p>Submitted {report.createdAt} · Last reviewed {report.reviewedAt || "Not yet"}</p>
      </article>)}
      <Pagination resource={resource} label="Your report pages" />
    </section>
  </main>;
}
