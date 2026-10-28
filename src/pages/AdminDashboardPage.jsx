import { Link } from "react-router-dom";
import { useResource } from "../hooks/useResource.js";
import RequestState from "../components/RequestState.jsx";
import PageHeader from "../components/PageHeader.jsx";
import Icon from "../components/Icon.jsx";
import { fetchAdminDashboard } from "../services/admin.js";

const cards = [
  ["jobseekers", "Registered jobseekers"], ["employers", "Registered employers"],
  ["verifiedEmployers", "Verified employers"], ["pendingEmployers", "Pending verifications"],
  ["rejectedEmployers", "Rejected verifications"], ["jobListings", "Total job listings"],
  ["activeApprovedJobs", "Active approved jobs"], ["pendingJobs", "Pending job approvals"],
  ["applications", "Submitted applications"], ["pendingReports", "Pending user reports"],
  ["suspendedAccounts", "Suspended accounts"],
];
export default function AdminDashboardPage() {
  const resource = useResource(fetchAdminDashboard);
  return <section>
    <PageHeader title="Admin dashboard overview" eyebrow="Platform operations" icon="shield" description="Review registrations, publication requests and complaints. Active job counts require a verified, active employer." actions={<button className="button-secondary" type="button" onClick={resource.reload} disabled={resource.loading}>Refresh overview</button>} />
    <RequestState resource={resource} />
    {resource.data && <>
      <dl className="admin-stats">
        {cards.map(([key, label]) => <div key={key} className="panel"><span className="metric-icon"><Icon name={key.toLowerCase().includes('job') ? 'briefcase' : key.includes('pending') ? 'clock' : 'user'} /></span><dt>{label}</dt><dd className="admin-count">{resource.data.stats[key]}</dd></div>)}
      </dl>
      <div className="grid gap-5 md:grid-cols-2">
        {[
          ["Employer verification queue", "employers", resource.data.employers, (item) => item.companyName],
          ["Pending job approvals", "jobs", resource.data.jobs, (item) => item.title],
          ["Recent user reports", "reports", resource.data.reports, (item) => `Report #${item.id}: ${item.category.replaceAll("_", " ")}`],
          ["Recent administrative activities", "audit", resource.data.audit, (item) => `${item.action}: ${item.targetType} #${item.targetId}`],
        ].map(([title, path, items, label]) => <section key={path} className="panel">
          <h2>{title}</h2>
          {items.length ? <ul className="my-3 space-y-2">{items.map((item) => <li key={item.id}>{label(item)}</li>)}</ul> : <p>No records in this queue.</p>}
          <Link className="font-bold underline" to={`/admin/${path}${path === "audit" ? "" : "?status=pending"}`}>Open {title.toLowerCase()}</Link>
        </section>)}
      </div>
    </>}
  </section>;
}
