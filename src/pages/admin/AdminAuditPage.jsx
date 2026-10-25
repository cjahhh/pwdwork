import { useState } from "react";
import { fetchAuditLogs } from "../../services/admin.js";
import HistoryList from "../../components/HistoryList.jsx";
import PageHeader from "../../components/PageHeader.jsx";

export default function AdminAuditPage() {
  const [query, setQuery] = useState("");
  const [type, setType] = useState("");
  const [actor, setActor] = useState("");
  const [action, setAction] = useState("");
  const params = { query, ...(type ? { target_type: type } : {}), ...(actor ? { actor_id: actor } : {}), ...(action ? { action } : {}) };
  return <section>
    <PageHeader title="Administrative audit logs" eyebrow="Decision history" icon="clock" description="Read-only history of decisions and review requests. Status changes and their audit records are committed together." />
    <div className="admin-filters panel">
      <label><span className="form-label">Search action, reason, or actor</span><input className="form-input" maxLength={255} value={query} onChange={(event) => setQuery(event.target.value)} /></label>
      <label><span className="form-label">Target type</span><select className="form-input" value={type} onChange={(event) => setType(event.target.value)}>
        <option value="">All types</option>{["employer", "job", "user", "report"].map((value) => <option key={value}>{value}</option>)}
      </select></label>
      <label><span className="form-label">Actor account ID</span><input className="form-input" type="number" min="1" value={actor} onChange={(event) => setActor(event.target.value)} /></label>
      <label><span className="form-label">Exact action (optional)</span><input className="form-input" maxLength={80} placeholder="e.g. job.approved" value={action} onChange={(event) => setAction(event.target.value)} /></label>
    </div>
    <HistoryList fetcher={fetchAuditLogs} params={params} title="Administrative activities" />
  </section>;
}
