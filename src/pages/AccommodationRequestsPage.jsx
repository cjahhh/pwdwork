import { useEffect, useRef, useState } from 'react';
import { Link, Navigate, useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { useAuth } from '../context/AuthContext.jsx';
import { useResource } from '../hooks/useResource.js';
import { usePaginatedResource } from '../hooks/usePaginatedResource.js';
import { apiErrorMessage } from '../services/api.js';
import { fetchApplicantApplications } from '../services/jobs.js';
import {
  fetchRequestCatalog, fetchAccommodationRequests, fetchAccommodationRequest,
  createAccommodationRequest, actOnAccommodationRequest, fetchRequestMessages, fetchRequestEvents,
} from '../services/accommodationRequests.js';
import RequestState from '../components/RequestState.jsx';
import Pagination from '../components/Pagination.jsx';
import ReviewDialog from '../components/ReviewDialog.jsx';
import StatusBadge from '../components/StatusBadge.jsx';

const label = value => value.replaceAll('_', ' ').replace(/^./, c => c.toUpperCase());
const dateLabel = value => value ? new Date(value).toLocaleString() : 'Not recorded';

// Retain the action key on network failure so retrying cannot duplicate a committed action.
function useRequestMutation() {
  const pending = useRef(null), inFlight = useRef(false);
  const [busy, setBusy] = useState(false), [error, setError] = useState('');
  async function run(payload, operation) {
    if (inFlight.current) return null;
    inFlight.current = true;
    const signature = JSON.stringify(payload);
    if (pending.current?.signature !== signature) pending.current = { signature, key: crypto.randomUUID() };
    const previous = document.activeElement;
    setBusy(true); setError('');
    try {
      const result = await operation({ ...payload, action_key: pending.current.key });
      pending.current = null;
      return result;
    } catch (exception) {
      setError(apiErrorMessage(exception));
      return null;
    } finally {
      inFlight.current = false; setBusy(false);
      requestAnimationFrame(() => { if (previous?.isConnected) previous.focus(); });
    }
  }
  return { run, busy, error };
}

export default function AccommodationRequestsPage() {
  const { user } = useAuth();
  const { id } = useParams();
  const base = `/${user.role}/accommodation-requests`;
  if (id === 'new' && user.role !== 'applicant') return <Navigate to={base} replace />;
  return <main className="page-shell space-y-5">
    <div className="page-heading"><div><p className="eyebrow">Practical support. Private conversations.</p><h1>Accommodation Requests</h1><p className="page-description">Discuss recruitment arrangements with clear sharing choices and a private history.</p></div></div>
    <p>Coordinate practical recruitment and interview arrangements separately from hiring decisions. No medical diagnosis, certificate, or PWD ID is needed.</p>
    <p>Individual requests are shared only when you intentionally submit them. Profile preference sharing is a separate choice on each application.</p>
    {id && <Link to={base}>Back to accommodation requests</Link>}
    {id === 'new' ? <CreateRequest key={user.id} base={base} user={user} /> : id ? <RequestDetails key={`${user.id}:${id}`} id={id} base={base} applicant={user.role === 'applicant'} /> : <RequestList base={base} user={user} />}
  </main>;
}

function RequestList({ base, user }) {
  const [status, setStatus] = useState(''), [query, setQuery] = useState('');
  const [params] = useSearchParams();
  const applicationId = params.get('application_id');
  const catalog = useResource(fetchRequestCatalog, user.id);
  const resource = usePaginatedResource(fetchAccommodationRequests, { ...(status ? { status } : {}), ...(applicationId ? { application_id: applicationId } : {}), query }, user.id);
  return <>
    {user.role === 'applicant' && <Link className="primary-button" to={`${base}/new${applicationId ? `?application_id=${applicationId}` : ''}`}>Create Request</Link>}
    {applicationId && <Link to={base}>Show requests for all applications</Link>}
    <div className="panel grid gap-4 sm:grid-cols-2">
      <label><span className="form-label">Request status</span><select className="form-input" value={status} onChange={event => setStatus(event.target.value)}><option value="">All statuses</option>{catalog.data?.statuses.filter(value => user.role === 'applicant' || value !== 'withdrawn').map(value => <option key={value} value={value}>{label(value)}</option>)}</select></label>
      <label><span className="form-label">Search job or applicant</span><input className="form-input" maxLength={255} value={query} onChange={event => setQuery(event.target.value)} /></label>
    </div>
    <button type="button" onClick={resource.reload} disabled={resource.loading}>Refresh requests</button>
    <RequestState resource={catalog} /><RequestState resource={resource} />
    {resource.data?.meta.total === 0 && <p>No shared requests match this view. Applicants can create an optional request from an existing application.</p>}
    <div className="grid gap-5 md:grid-cols-2">{resource.data?.requests.map(record => <article className="panel request-summary-card space-y-2 break-words" key={record.id}>
      <h2><Link to={`${base}/${record.id}`}>{record.jobTitle} — Request #{record.id}</Link></h2>
      <p>{record.applicant} · {record.company}</p><p>Status: <StatusBadge status={record.status}>{label(record.status)}</StatusBadge></p><p>Stage: {label(record.stage)}</p>
      <p>Submitted: {dateLabel(record.createdAt)}</p>
      <Link to={`${base}/${record.id}`}>Review request and discussion</Link>
    </article>)}</div>
    <Pagination resource={resource} label="Accommodation request pages" />
  </>;
}

function RequestFields({ fields, setFields, categories, busy }) {
  const update = (key, value) => setFields(previous => ({ ...previous, [key]: value }));
  return <fieldset disabled={busy} className="space-y-4">
    <legend>Requested practical arrangements</legend>
    <p>Choose at least one category. Describe only the support you want; do not include diagnoses or medical documents.</p>
    <fieldset><legend>Accommodation categories (choose one or more)</legend>{categories.map(category => <label className="filter-option" key={category}>
      <input type="checkbox" name="categories" value={category} checked={fields.categories.includes(category)} onChange={event => update('categories', event.target.checked ? [...fields.categories, category] : fields.categories.filter(value => value !== category))} />{category}
    </label>)}</fieldset>
    <label className="block"><span className="form-label">Recruitment stage</span><select className="form-input" value={fields.stage} onChange={event => update('stage', event.target.value)}>{['interview', 'assessment', 'recruitment'].map(stage => <option key={stage} value={stage}>{label(stage)}</option>)}</select></label>
    <label className="block"><span className="form-label">Practical arrangement details (optional)</span><textarea className="form-input" maxLength={2000} value={fields.details} onChange={event => update('details', event.target.value)} /></label>
    <label className="filter-option"><input required type="checkbox" checked={fields.share_request} onChange={event => update('share_request', event.target.checked)} />I choose to share this individual request and its discussion with the employer for this application. This does not share my private profile preferences.</label>
    <p>You can withdraw the request to stop future employer access. Information already viewed cannot be made unseen. Request history stays available to you.</p>
  </fieldset>;
}

function CreateRequest({ base, user }) {
  const [params] = useSearchParams();
  const requestedId = params.get('application_id') || '';
  const navigate = useNavigate();
  const applications = usePaginatedResource(fetchApplicantApplications, requestedId ? { application_id: requestedId } : {}, user.id);
  const catalog = useResource(fetchRequestCatalog, user.id);
  const [applicationId, setApplicationId] = useState(requestedId);
  const [fields, setFields] = useState({ categories: [], stage: params.get('stage') === 'interview' ? 'interview' : 'recruitment', details: '', share_request: false });
  const mutation = useRequestMutation();
  const selected = applications.data?.applications.find(application => String(application.applicationId) === applicationId);
  async function submit(event) {
    event.preventDefault();
    const record = await mutation.run({ ...fields, application_id: Number(applicationId) }, createAccommodationRequest);
    if (record) navigate(`${base}/${record.id}`, { state: { created: true } });
  }
  return <section className="space-y-4">
    <h2>Create Request</h2><RequestState resource={applications} /><RequestState resource={catalog} />
    <p>Apply for a job first, then optionally request recruitment arrangements. This will not submit another job application or change an interview schedule.</p>
    {applications.data?.meta.total === 0 && <p>No matching application is available. <Link to="/applicant/applications">View your applications</Link>.</p>}
    <form className="panel space-y-5" onSubmit={submit} aria-busy={mutation.busy}>
      {mutation.error && <p role="alert">{mutation.error}</p>}
      <label className="block"><span className="form-label">Application and employer</span><select className="form-input" required value={selected ? applicationId : ''} disabled={mutation.busy} onChange={event => setApplicationId(event.target.value)}><option value="">Choose your application</option>{applications.data?.applications.map(application => <option key={application.applicationId} value={application.applicationId}>{application.jobTitle} — {application.company}</option>)}</select></label>
      {selected?.interviewDate && <p>Current interview: {selected.interviewDate} at {selected.interviewTime} (Asia/Manila). {selected.interviewNotes}</p>}
      <RequestFields fields={fields} setFields={setFields} categories={catalog.data?.categories || []} busy={mutation.busy} />
      <button className="primary-button" disabled={mutation.busy || !selected || !fields.categories.length || !catalog.data}>{mutation.busy ? 'Submitting...' : 'Share and submit request'}</button>
    </form>
    {!requestedId && <Pagination resource={applications} label="Application choices" disabled={mutation.busy} />}
  </section>;
}

function RequestDetails({ id, base, applicant }) {
  const heading = useRef(null);
  const focusHeading = useRef(false);
  const resource = useResource(() => fetchAccommodationRequest(id), id);
  const catalog = useResource(fetchRequestCatalog, id);
  const mutation = useRequestMutation();
  const [message, setMessage] = useState(''), [text, setText] = useState(''), [responseText, setResponseText] = useState('');
  const [action, setAction] = useState('acknowledge'), [confirm, setConfirm] = useState(null), [editing, setEditing] = useState(false);
  const [fields, setFields] = useState(null);
  const record = resource.data;
  useEffect(() => {
    if (focusHeading.current && !confirm) {
      heading.current?.focus();
      focusHeading.current = false;
    }
  }, [record?.version, confirm]);
  async function perform(nextAction, extra = {}) {
    const updated = await mutation.run({ action: nextAction, expected_version: record.version, ...extra }, payload => actOnAccommodationRequest(id, payload));
    if (updated) {
      focusHeading.current = ['withdraw', 'accept', 'decline'].includes(nextAction);
      resource.setData(updated); setMessage('Request updated. The other participant has been notified.');
      if (nextAction === 'message') setText('');
      if (nextAction === 'edit') setEditing(false);
      setConfirm(null); setResponseText('');
    }
    return updated;
  }
  const withdrawn = record?.status === 'withdrawn';
  const availableActions = record ? [
    ...(record.status === 'submitted' ? [['acknowledge', 'Acknowledge receipt']] : []),
    ...(['submitted', 'under_discussion'].includes(record.status) ? [['agree', 'Confirm the original requested arrangements']] : []),
    ['propose', 'Propose an arrangement or alternative (applicant must accept)'],
    ...(['submitted', 'under_discussion', 'alternative_proposed'].includes(record.status) ? [['unable', 'Unable to provide (explanation required)']] : []),
  ] : [];
  const effectiveAction = availableActions.some(([value]) => value === action) ? action : availableActions[0]?.[0];
  return <section className="space-y-5">
    <button type="button" disabled={mutation.busy || resource.loading} onClick={() => { resource.reload(); setMessage(''); }}>Refresh request and discussion</button>
    <RequestState resource={resource} />
    {mutation.error && <p role="alert">{mutation.error}</p>}<p role="status">{message}</p>
    {record && <>
      <article className="panel request-summary-card space-y-3 break-words">
        <h2 ref={heading} tabIndex={-1}>{record.jobTitle} — Request #{record.id}</h2>
        <p>{record.applicant} · {record.company}</p><p>Status: <StatusBadge status={record.status}>{label(record.status)}</StatusBadge> · Stage: {label(record.stage)}</p>
        <p>Shared: {dateLabel(record.sharedAt)} · Version {record.version}</p>
        <ul>{record.categories.map(category => <li key={category}>{category}</li>)}</ul>
        <p className="whitespace-pre-wrap">{record.details || 'No additional details provided.'}</p>
        <p>{record.interview.date ? `Current interview: ${record.interview.date} at ${record.interview.time} (${record.interview.timezone}).` : 'No interview is currently scheduled. Requests may be discussed before scheduling.'} {record.interview.notes}</p>
        {record.arrangement && <div className="sharing-summary"><p className="whitespace-pre-wrap"><strong>{record.status === 'agreed' ? 'Confirmed arrangement' : 'Proposed arrangement (not agreed until accepted)'}</strong>: {record.arrangement}</p></div>}
        {record.scheduleChanged && <p role="status">The interview schedule differs from the schedule recorded for this request or agreement. Discuss and reconfirm arrangements; no request was deleted. Previously recorded interview: {record.arrangementInterview?.date || record.originalInterview?.date || 'not scheduled'} at {record.arrangementInterview?.time || record.originalInterview?.time || 'not scheduled'}.</p>}
        <p><Link to={applicant ? `/reports?target_type=employer&target_id=${record.employerId}` : '/reports'}>Report an accommodation-related problem</Link></p>
        <p><Link to={applicant ? '/applicant/applications' : '/employer/applicants'}>View applications and interview scheduling</Link></p>
        {withdrawn ? <p>This request is withdrawn. Employer access and further actions are disabled. Your private history remains available.</p> : applicant ? <div className="flex flex-wrap gap-3">
          <button type="button" disabled={mutation.busy} onClick={() => { setFields({ categories: record.categories, stage: record.stage, details: record.details || '', share_request: false }); setEditing(true); }}>Edit request</button>
          <button type="button" disabled={mutation.busy} onClick={() => setConfirm('withdraw')}>Withdraw request and sharing</button>
          {record.status === 'alternative_proposed' && <>
            <button type="button" disabled={mutation.busy} onClick={() => setConfirm('accept')}>Accept proposed arrangement</button>
            <button type="button" disabled={mutation.busy} onClick={() => setConfirm('decline')}>Decline proposed arrangement</button>
          </>}
        </div> : <form className="space-y-4" onSubmit={event => { event.preventDefault(); perform(effectiveAction, { text: responseText }); }} aria-busy={mutation.busy}>
          <label className="block"><span className="form-label">Employer response</span><select className="form-input" value={effectiveAction} disabled={mutation.busy} onChange={event => setAction(event.target.value)}>{availableActions.map(([value, title]) => <option value={value} key={value}>{title}</option>)}</select></label>
          <label className="block"><span className="form-label">Practical arrangement or explanation{effectiveAction === 'acknowledge' ? ' (optional)' : ' (required)'}</span><textarea className="form-input" value={responseText} disabled={mutation.busy} required={effectiveAction !== 'acknowledge'} minLength={5} maxLength={2000} onChange={event => setResponseText(event.target.value)} /></label>
          <p>Confirm original arrangements only when you can provide what was requested. Use a proposal for any alternative; the applicant must accept it. Responses never change hiring outcomes or interview dates.</p>
          <button className="primary-button" disabled={mutation.busy}>Send response</button>
        </form>}
      </article>
      {!withdrawn && <form className="panel space-y-4" onSubmit={event => { event.preventDefault(); perform('message', { text }); }} aria-busy={mutation.busy}>
        <h2>Private discussion</h2><p>Only this applicant and the employer can read this discussion. Send practical details without medical information. Messages cannot be edited or deleted; send a clarification if needed.</p>
        <label className="block"><span className="form-label">Message</span><textarea className="form-input" required minLength={5} maxLength={2000} value={text} disabled={mutation.busy} onChange={event => setText(event.target.value)} /></label>
        <button className="primary-button" disabled={mutation.busy}>Send message</button>
      </form>}
      <Discussion id={id} version={record.version} />
      {confirm && <ReviewDialog title={confirm === 'withdraw' ? 'Withdraw request and revoke sharing?' : `${label(confirm)} proposed arrangement?`} busy={mutation.busy} onClose={() => setConfirm(null)}>
        {confirm === 'withdraw' ? <p>The employer will lose future access to this request, its messages, and its history. Information already viewed cannot be made unseen. Your application and separate profile-sharing choice will remain as they are.</p> : <p>{record.arrangement} {confirm === 'accept' ? 'Accepting confirms this proposed arrangement.' : 'Declining returns this request to discussion.'}</p>}
        {mutation.error && <p role="alert">{mutation.error}</p>}
        <button type="button" disabled={mutation.busy} onClick={() => perform(confirm)}>Confirm {confirm}</button>
      </ReviewDialog>}
      {editing && fields && <ReviewDialog title="Edit shared request" busy={mutation.busy} onClose={() => setEditing(false)}>
        <p>Changes are shared with this employer and recorded in private history. Editing resets the request to Submitted and clears previous agreement, so arrangements must be confirmed again.</p>
        <RequestState resource={catalog} />
        <form className="space-y-4" onSubmit={event => { event.preventDefault(); perform('edit', fields); }}>
          {mutation.error && <p role="alert">{mutation.error}</p>}
          <RequestFields fields={fields} setFields={setFields} categories={catalog.data?.categories || []} busy={mutation.busy} />
          <button disabled={mutation.busy || !fields.categories.length || !catalog.data}>Share updated request</button>
        </form>
      </ReviewDialog>}
      <Link to={base}>Back to request list</Link>
    </>}
  </section>;
}

function Discussion({ id, version }) {
  const messages = usePaginatedResource(params => fetchRequestMessages(id, params), {}, `${id}:${version}`);
  const events = usePaginatedResource(params => fetchRequestEvents(id, params), {}, `${id}:${version}`);
  return <>
    <section className="panel space-y-3" aria-label="Request messages"><h2>Messages (newest first)</h2><RequestState resource={messages} />
      {messages.data?.meta.total === 0 && <p>No messages yet.</p>}
      {messages.data?.messages.map(item => <article className="discussion-message break-words" data-sender={item.sender} key={item.id}><p>{label(item.sender)} · <time dateTime={item.createdAt}>{dateLabel(item.createdAt)}</time></p><p className="whitespace-pre-wrap">{item.body}</p></article>)}
      <Pagination resource={messages} label="Request message pages" />
    </section>
    <section className="panel request-history space-y-3" aria-label="Private request history"><h2>Request history (newest first)</h2><RequestState resource={events} />
      {events.data?.events.map(item => <article className="border-b pb-3 break-words" key={item.id}>
        <p>{label(item.actor)} · {label(item.action)} · Version {item.version} · <time dateTime={item.createdAt}>{dateLabel(item.createdAt)}</time></p>
        <p>{item.beforeStatus && `${label(item.beforeStatus)} → `}{label(item.status)}</p>
        {item.explanation && <p className="whitespace-pre-wrap">{item.explanation}</p>}
        <details><summary>Request details at this change</summary><p>Stage: {label(item.snapshot.stage)}</p><p>{item.snapshot.categories.join(', ')}</p><p className="whitespace-pre-wrap">{item.snapshot.details}</p><p className="whitespace-pre-wrap">{item.snapshot.arrangement}</p></details>
      </article>)}
      <Pagination resource={events} label="Request history pages" />
    </section>
  </>;
}
