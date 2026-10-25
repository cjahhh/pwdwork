import { Link } from 'react-router-dom';
import { useAuth } from '../context/AuthContext.jsx';
import { useResource } from '../hooks/useResource.js';
import api, { apiErrorMessage } from '../services/api.js';
import PageHeader from './PageHeader.jsx';
import Icon from './Icon.jsx';
import StatusBadge from './StatusBadge.jsx';
import RequestState from './RequestState.jsx';

export default function WorkspaceOverview({ employer = false }) {
  const { user } = useAuth();
  const base = employer ? '/employer' : '/applicant';
  const endpoints = employer
    ? ['/employer/jobs?per_page=3', '/employer/applications?per_page=3', '/employer/jobs?status=open&per_page=1', '/employer/applications?status=interview&per_page=1']
    : ['/applicant/applications?per_page=3', '/applicant/saved-jobs?per_page=1', '/applicant/resumes?per_page=1', '/applicant/applications?status=interview&per_page=1'];
  const resource = useResource(async () => {
    const responses = await Promise.allSettled(endpoints.map(path => api.get(path)));
    return responses.map(result => result.status === 'fulfilled' ? { data: result.value.data } : { error: apiErrorMessage(result.reason) });
  }, user.id);
  const cards = employer ? [['Job listings', 'briefcase', '/employer/listings'], ['Applications received', 'user', '/employer/applicants'], ['Open listings', 'file', '/employer/listings'], ['Interview-stage applications', 'clock', '/employer/applicants']]
    : [['Applications submitted', 'briefcase', '/applicant/applications'], ['Saved opportunities', 'heart', '/applicant/saved-jobs'], ['Saved resumes', 'file', '/applicant/resumes'], ['Interview-stage applications', 'clock', '/applicant/applications']];
  const applications = employer ? resource.data?.[1]?.data?.applicants : resource.data?.[0]?.data?.applications;
  return <main className="page-shell overview-page">
    <PageHeader eyebrow={employer ? 'Employer workspace' : 'Your career workspace'} title={employer ? 'Your hiring overview' : 'Your next chapter starts here'} description="A clear view of your activity and your next steps." />
    <section className="welcome-banner">
      <div><p className="eyebrow">{employer ? user.company_name || 'Your company' : 'Built around your strengths'}</p><h2>Welcome back, {user.name.split(' ')[0]}.</h2><p>{employer ? 'Create clear opportunities. Find the people who will make a difference.' : 'Explore opportunities, prepare your resume and take your next step with confidence.'}</p><div className="action-row"><Link className="primary-button" to={employer ? '/employer/listings' : '/jobs'}>{employer ? 'Manage job listings' : 'Find opportunities'} <Icon name="arrow" /></Link><Link className="banner-link" to={`${base}/profile`}>{employer ? 'Review company profile' : 'Edit your profile'}</Link></div></div>
      <div className="welcome-emblem"><Icon name={employer ? 'briefcase' : 'user'} size={76} /><span>{employer ? 'Thoughtful hiring' : 'Possibility, with purpose'}</span></div>
    </section>
    <RequestState resource={resource} />
    <div className="metric-grid">{cards.map(([label, icon, link], index) => <Link className="metric-card" to={link} key={label}><span className="metric-icon"><Icon name={icon} /></span><p>{label}</p><strong>{resource.loading ? '—' : resource.data?.[index]?.data?.meta.total ?? 'Unavailable'}</strong>{resource.data?.[index]?.error && <p className="metric-error" role="status">{resource.data[index].error}</p>}<span className="metric-link">View details <Icon name="arrow" size={16} /></span></Link>)}</div>
    <div className="overview-grid"><section className="panel"><div className="section-heading"><div><p className="eyebrow">Keep moving forward</p><h2>{employer ? 'Recent applicants' : 'Recent applications'}</h2></div><Link to={employer ? '/employer/applicants' : '/applicant/applications'}>View all <Icon name="arrow" size={16} /></Link></div>
      {!resource.loading && applications?.length === 0 && <p className="empty-state">{employer ? 'Applications to your published jobs will appear here.' : 'Your first application is a step toward something new. Browse jobs to get started.'}</p>}
      {applications?.map(item => <div className="activity-row" key={item.applicationId || item.id}><span className="activity-icon"><Icon name="briefcase" /></span><div><h3>{item.jobTitle}</h3><p>{employer ? item.name : item.company}</p></div><StatusBadge status={item.statusCode}>{item.status}</StatusBadge></div>)}
      {!resource.loading && !applications && <p>Recent applications could not be loaded. Use the application page to retry.</p>}
    </section><section className="panel mint-panel"><span className="metric-icon"><Icon name={employer ? 'shield' : 'file'} /></span><h2>{employer ? 'Build trust from the start' : 'Let your experience speak'}</h2><p>{employer ? 'Keep company details up to date and describe workplace arrangements clearly. Account review and job moderation are separate.' : 'Create a resume that reflects your strengths. Experience can include education, projects and volunteering.'}</p><Link className="button-secondary" to={employer ? '/employer/profile' : '/applicant/resumes'}>{employer ? 'Company & verification' : 'Open Resume Builder'} <Icon name="arrow" size={16} /></Link></section></div>
    <section className="workspace-guidance"><Icon name="message" /><div><h2>Practical arrangements. Clear choices.</h2><p>Discuss recruitment accommodations separately from hiring decisions. Sharing is intentional and stays within the application.</p></div><Link to={`${base}/accommodation-requests`}>Manage requests <Icon name="arrow" size={16} /></Link></section>
    <button className="button-secondary" disabled={resource.loading} onClick={resource.reload}>Refresh overview</button>
  </main>;
}
