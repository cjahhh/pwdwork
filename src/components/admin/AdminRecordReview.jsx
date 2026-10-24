import { useState } from "react";
import { Link } from "react-router-dom";
import ReviewDialog from "../ReviewDialog.jsx";
import RequestState from "../RequestState.jsx";
import HistoryList from "../HistoryList.jsx";
import { useResource } from "../../hooks/useResource.js";
import { useAuth } from "../../context/AuthContext.jsx";
import { useUi } from "../../context/UiContext.jsx";
import { fetchAdminRecord, decideAdminRecord, fetchAuditLogs } from "../../services/admin.js";
import { apiErrorMessage } from "../../services/api.js";
import { displayStatus } from "../../services/moderation.js";

const labels = { employer: "Employer verification", job: "Job moderation", user: "Account status", report: "Report review" };

function CompanyDetails({ company }) {
  return <dl className="admin-details">
    <dt>Company</dt><dd>{company.companyName} (#{company.id})</dd>
    <dt>Description</dt><dd className="whitespace-pre-wrap">{company.description || "Not provided"}</dd>
    <dt>Location</dt><dd>{company.location || "Not provided"}</dd>
    <dt>Website</dt><dd>{company.website || "Not provided"}</dd>
    <dt>Contact</dt><dd>{company.contactName} · {company.contactEmail}</dd>
    <dt>Registered</dt><dd>{company.createdAt}</dd>
    <dt>Verification</dt><dd>{displayStatus(company.verificationStatus)} · Account {displayStatus(company.accountStatus)}</dd>
    {company.verificationReason && <><dt>Latest reason</dt><dd>{company.verificationReason}</dd></>}
  </dl>;
}

function RecordDetails({ kind, record }) {
  if (kind === "employer") return <CompanyDetails company={record} />;
  if (kind === "job") return <>
    <h3>{record.title}</h3>
    <p>Employer #{record.employer.id} · Job #{record.id}</p>
    <p>{record.location} · {record.employmentType} · {record.workMode}</p>
    <p className="whitespace-pre-wrap my-3">{record.description}</p>
    <p>Availability: {displayStatus(record.availability)} · Moderation: {displayStatus(record.moderationStatus)}</p>
    {record.moderationReason && <p>Latest reason: {record.moderationReason}</p>}
    <p>Flexible schedule: {record.flexibleSchedule ? "Yes" : "No"}</p>
    <p>Employer-reported accommodations: {record.accommodations.join(", ") || "None provided"}. Approval does not independently verify these claims.</p>
    <CompanyDetails company={record.employer} />
  </>;
  if (kind === "user") return <dl className="admin-details">
    <dt>Name</dt><dd>{record.name}</dd><dt>Email</dt><dd>{record.email}</dd>
    <dt>Role</dt><dd>{record.role}</dd><dt>Status</dt><dd>{displayStatus(record.status)}</dd>
    <dt>Registered</dt><dd>{record.createdAt}</dd>
  </dl>;
  const targetRoute = { job: "jobs?job_id=", employer: "employers?employer_id=", account: "users?user_id=" }[record.targetType];
  return <>
    <p>Report #{record.id} · {displayStatus(record.category)} · {displayStatus(record.status)}</p>
    <p>Reporter: {record.reporter.name} ({record.reporter.role}) · {record.reporter.email}</p>
    <p>Submitted: {record.createdAt} · Reviewed: {record.reviewedAt || "Not yet"} · Reviewer: {record.reviewer || "Unassigned"}</p>
    <p className="whitespace-pre-wrap my-4">{record.description}</p>
    <p>Target: {record.targetType}{record.targetId ? ` #${record.targetId}` : ""}</p>
    {targetRoute && <p><Link to={`/admin/${targetRoute}${record.targetId}`}>Review the reported {record.targetType}</Link></p>}
    <p>Reporter identity and internal notes are private to authorized administrators.</p>
  </>;
}

function DecisionForm({ kind, record, onDone, setBusy, busy, refresh }) {
  const { user } = useAuth();
  const current = kind === "employer" ? record.verificationStatus : kind === "job" ? record.moderationStatus : record.status;
  const choices = kind === "employer" ? ["verified", "rejected"] : kind === "job" ? ["approved", "rejected", "hidden"]
    : kind === "user" ? [record.status === "active" ? "suspended" : "active"]
    : ["resolved", "dismissed"].includes(current) ? [current] : ["pending", "under_review", "resolved", "dismissed"];
  const [status, setStatus] = useState(kind === "report" ? (current === "pending" ? "under_review" : current) : choices.find((value) => value !== current) || current);
  const [reason, setReason] = useState("");
  const [notes, setNotes] = useState(record.internalNotes || "");
  const [error, setError] = useState("");
  const requiredReason = kind === "user" || ["rejected", "hidden", "resolved", "dismissed"].includes(status);
  const self = kind === "user" && record.id === user.id;
  const { showToast } = useUi();
  async function submit(event) {
    event.preventDefault();
    setBusy(true); setError("");
    try {
      await decideAdminRecord(kind, record, { status, reason: reason || null, ...(kind === "report" ? { internal_notes: notes } : {}) });
      showToast("Decision saved", "The record, notification, and audit history have been updated.");
      onDone();
    } catch (exception) { setError(apiErrorMessage(exception)); }
    finally { setBusy(false); }
  }
  return <form onSubmit={submit} className="panel space-y-4">
    <h3>Confirm an administrative decision</h3>
    <p>Review the current details before confirming. Reasons may be visible to the affected account; keep medical details and unrelated personal information out of notes.</p>
    {self && <p>You cannot restrict your own administrator account.</p>}
    {error && <div role="alert"><p>{error}</p><button type="button" onClick={refresh} disabled={busy}>Refresh record</button></div>}
    <label className="block"><span className="form-label">Decision</span>
      <select className="form-input" value={status} onChange={(event) => setStatus(event.target.value)} disabled={busy || self}>
        {choices.map((value) => <option key={value} value={value}>{displayStatus(value)}</option>)}
      </select>
    </label>
    <label className="block"><span className="form-label">Reason {requiredReason ? "(required)" : "(optional)"}</span>
      <textarea className="form-input" value={reason} onChange={(event) => setReason(event.target.value)} required={requiredReason} minLength={5} maxLength={2000} disabled={busy || self} />
    </label>
    {kind === "report" && <label className="block"><span className="form-label">Internal moderation notes (admin only)</span>
      <textarea className="form-input" value={notes} onChange={(event) => setNotes(event.target.value)} maxLength={5000} disabled={busy} />
    </label>}
    <button className="primary-button" disabled={busy || self}>{busy ? "Saving decision..." : "Confirm decision"}</button>
  </form>;
}

export default function AdminRecordReview({ kind, id, onClose, onChanged }) {
  const resource = useResource(() => fetchAdminRecord(kind, id), `${kind}:${id}`);
  const [busy, setBusy] = useState(false);
  return <ReviewDialog title={`${labels[kind]} #${id}`} onClose={onClose} busy={busy}>
    <RequestState resource={resource} />
    {resource.data && <>
      <RecordDetails kind={kind} record={resource.data} />
      <DecisionForm key={resource.data.reviewVersion} kind={kind} record={resource.data} busy={busy} setBusy={setBusy}
        refresh={resource.reload} onDone={() => { onChanged(); onClose(); }} />
      <HistoryList fetcher={fetchAuditLogs} params={{ target_type: kind, target_id: id }} scope={`${kind}:${id}`} />
    </>}
  </ReviewDialog>;
}
