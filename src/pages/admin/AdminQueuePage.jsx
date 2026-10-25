import { useState } from "react";
import { useSearchParams } from "react-router-dom";
import { useAuth } from "../../context/AuthContext.jsx";
import { usePaginatedResource } from "../../hooks/usePaginatedResource.js";
import RequestState from "../../components/RequestState.jsx";
import Pagination from "../../components/Pagination.jsx";
import AdminRecordReview from "../../components/admin/AdminRecordReview.jsx";
import { fetchAdminEmployers, fetchAdminJobs, fetchAdminUsers, fetchAdminReports } from "../../services/admin.js";
import { displayStatus, reportCategories } from "../../services/moderation.js";
import StatusBadge from '../../components/StatusBadge.jsx';
import EmptyState from '../../components/EmptyState.jsx';
import PageHeader from '../../components/PageHeader.jsx';

const sections = {
  employer: { title: "Employer verification", key: "employers", fetcher: fetchAdminEmployers, statuses: ["pending", "verified", "rejected"] },
  job: { title: "Job moderation", key: "jobs", fetcher: fetchAdminJobs, statuses: ["pending", "approved", "rejected", "hidden"] },
  user: { title: "User management", key: "users", fetcher: fetchAdminUsers, statuses: ["active", "suspended", "inactive", "pending"] },
  report: { title: "Reports and complaints", key: "reports", fetcher: fetchAdminReports, statuses: ["pending", "under_review", "resolved", "dismissed"] },
};
export default function AdminQueuePage({ kind }) {
  const config = sections[kind];
  const { user } = useAuth();
  const [params, setParams] = useSearchParams();
  const [selected, setSelected] = useState(null);
  const filters = Object.fromEntries(params);
  const resource = usePaginatedResource(config.fetcher, filters, user.id + ":" + kind);
  function filter(name, value) {
    const next = new URLSearchParams(params);
    if (value) next.set(name, value); else next.delete(name);
    setParams(next);
  }
  return <section>
    <PageHeader title={config.title} eyebrow="Platform administration" icon={kind === 'job' ? 'briefcase' : kind === 'report' ? 'message' : 'shield'} description="Review records, apply filters and record decisions with a clear audit history." />
    <div className="admin-filters panel">
      <label><span className="form-label">Search {config.title.toLowerCase()}</span>
        <input className="form-input" maxLength={255} value={params.get("query") || ""} onChange={(event) => filter("query", event.target.value)} />
      </label>
      <label><span className="form-label">{kind === "job" ? "Moderation status" : "Status"}</span>
        <select className="form-input" value={params.get("status") || ""} onChange={(event) => filter("status", event.target.value)}>
          <option value="">All statuses</option>{config.statuses.map((value) => <option key={value} value={value}>{displayStatus(value)}</option>)}
        </select>
      </label>
      {kind === "job" && <label><span className="form-label">Employer ID (from verification records)</span>
        <input className="form-input" type="number" min="1" value={params.get("employer_id") || ""} onChange={(event) => filter("employer_id", event.target.value)} />
      </label>}
      {kind === "user" && <label><span className="form-label">Account role</span>
        <select className="form-input" value={params.get("role") || ""} onChange={(event) => filter("role", event.target.value)}>
          <option value="">All roles</option><option value="applicant">Jobseeker</option><option value="employer">Employer</option><option value="admin">Admin</option>
        </select>
      </label>}
      {kind === "report" && <label><span className="form-label">Report category</span>
        <select className="form-input" value={params.get("category") || ""} onChange={(event) => filter("category", event.target.value)}>
          <option value="">All categories</option>{[...new Set(Object.values(reportCategories).flat())].map((value) => <option key={value} value={value}>{displayStatus(value)}</option>)}
        </select>
      </label>}
      <button type="button" onClick={() => setParams({})}>Clear filters</button>
      <button type="button" onClick={resource.reload} disabled={resource.loading}>Refresh records</button>
    </div>
    <RequestState resource={resource} />
    {resource.data?.meta.total === 0 && <EmptyState title="No records match these filters.">Clear a filter or search for another record.</EmptyState>}
    <div className="space-y-4">
      {resource.data?.[config.key].map((record) => <article key={record.id} className="panel">
        <h2>{record.companyName || record.title || record.name || `Report #${record.id}`} <span className="text-sm">#{record.id}</span></h2>
        <StatusBadge status={record.verificationStatus || record.moderationStatus || record.status}>{displayStatus(record.verificationStatus || record.moderationStatus || record.status)}</StatusBadge>
        {kind === "employer" && <p>{record.contactName} · {record.contactEmail} · Account {displayStatus(record.accountStatus)}</p>}
        {kind === "job" && <p>{record.employer.companyName} (Employer #{record.employer.id}) · {record.location} · Availability {displayStatus(record.availability)}</p>}
        {kind === "user" && <p>{record.email} · {record.role}</p>}
        {kind === "report" && <p>{displayStatus(record.category)} · {record.reporter.name} · {record.targetType} {record.targetId ? `#${record.targetId}` : ""}</p>}
        <p>Created <time>{record.createdAt}</time></p>
        <button type="button" className="primary-button mt-3" onClick={() => setSelected(record.id)}>Review {kind} #{record.id}</button>
      </article>)}
    </div>
    <Pagination resource={resource} label={`${config.title} pages`} />
    {selected && <AdminRecordReview key={`${kind}:${selected}`} kind={kind} id={selected} onClose={() => setSelected(null)} onChanged={resource.reload} />}
  </section>;
}
