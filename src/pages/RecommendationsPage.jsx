import { useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { useAuth } from '../context/AuthContext.jsx';
import { getRecommendations, saveJob } from '../services/applicant.js';
import { apiErrorMessage } from '../services/api.js';
import { usePaginatedResource } from '../hooks/usePaginatedResource.js';
import JobCard from '../components/JobCard.jsx';
import Pagination from '../components/Pagination.jsx';
import RequestState from '../components/RequestState.jsx';
import PageHeader from '../components/PageHeader.jsx';

export function RecommendationCard({ job }) {
  const [saved, setSaved] = useState(Boolean(job.saved));
  const saving = useRef(false);
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');
  async function save() {
    if (saved || saving.current) return;
    saving.current = true;
    setBusy(true); setError('');
    try { await saveJob(job.id); setSaved(true); setMessage('Job saved. Find it in Saved Jobs.'); }
    catch (e) { setError(apiErrorMessage(e)); }
    finally { saving.current = false; setBusy(false); }
  }
  return <section className="panel surface-card recommendation-card" aria-label={`${job.title} recommendation`}>
    <JobCard job={job}><div className="recommendation-details space-y-4">
    <p>Work arrangement: {job.mode || 'Not reported'}</p>
    <p className="match-score">Fit &amp; Access Match: {job.match.score === null ? 'More profile information needed' : `${job.match.score}/100`}</p>
    <p>{job.match.confidence}</p>
    <ul className="recommendation-reasons">{job.match.reasons.map(reason => <li key={reason}>{reason}</li>)}</ul>
    <details><summary>How this score is calculated</summary><p>{job.match.formula}</p></details>
    <button type="button" className="primary-button" aria-disabled={busy || saved} aria-busy={busy} onClick={save}>{busy ? 'Saving...' : saved ? 'Saved' : 'Save Job'}</button>
    <Link className="primary-button" to={`/jobs/${job.id}`}>Apply for Job</Link>
    <p role="status">{message}</p>{error && <p role="alert">{error}</p>}
    </div></JobCard>
  </section>;
}

export default function RecommendationsPage({ preview = false }) {
  const { user } = useAuth();
  const resource = usePaginatedResource(getRecommendations, { per_page: preview ? 3 : 12 }, user.id);
  const Container = preview ? 'section' : 'main';
  return <Container className="page-shell">
    {preview ? <div className="section-heading"><div><p className="eyebrow">Explore what fits your goals</p><h2>Recommended for You</h2></div></div> : <PageHeader title="Recommended Jobs" eyebrow="Fit & Access Match" icon="briefcase" description="Understand what makes an opportunity relevant, then make your own choice." />}
    <p>Fit &amp; Access Match compares your stated preferences with job listings. Scores do not establish eligibility, hiring probability, or guaranteed accessibility. All available jobs remain open for you to explore.</p>
    <Link to="/applicant/profile">Update your optional matching preferences</Link>
    <RequestState resource={resource} />
    {resource.data?.meta.total === 0 && <p>No approved opportunities are available right now. Check again later or <Link to="/jobs">browse jobs</Link>.</p>}
    <div className="grid gap-6 md:grid-cols-2">{resource.data?.jobs.map(job => <RecommendationCard key={job.id} job={job} />)}</div>
    {preview ? <Link to="/applicant/recommendations">View all recommendations</Link> : <Pagination resource={resource} label="Recommendation pages" />}
  </Container>;
}
