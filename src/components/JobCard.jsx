import { Link } from 'react-router-dom';
import Icon from './Icon.jsx';

export default function JobCard({ job, children }) {
  return (
    <article className="job-card">
      <div className="job-card-header">
        <div className="job-card-identity">
          <span className="company-monogram" aria-hidden="true">
            {job.companyLogo || job.company?.slice(0, 2).toUpperCase()}
          </span>
          <div className="job-chips">
            {job.type && <span>{job.type}</span>}
            {job.mode && <span>{job.mode}</span>}
          </div>
        </div>
        <h3>
          {job.publicAvailable === false ? job.title : (
            <Link className="job-title-link" to={`/jobs/${job.id}`}>{job.title}</Link>
          )}
        </h3>
      </div>

      <div className="job-card-body">
        <div className="job-employer">
          <p className="job-company">{job.company}</p>
          {job.verified && (
            <p className="reviewed-employer" title="Employer account reviewed by PWDWork administrators; workplace accessibility is not independently certified">
              <Icon name="check" size={14} /> Reviewed employer
            </p>
          )}
          <p className="job-location"><Icon name="pin" size={15} />{job.location}</p>
        </div>
        <p className="job-description">{job.description}</p>
      </div>

      <div className="job-support">
        <p className="job-support-heading"><Icon name="shield" size={16} />Employer-reported support</p>
        {job.accommodations.length ? (
          <>
            <ul>{job.accommodations.map(acc => <li key={acc.label}>{acc.label}</li>)}</ul>
            <p className="support-note">Confirm specific arrangements with the employer.</p>
          </>
        ) : (
          <p className="support-missing">No support information reported. Ask the employer about the arrangements you need.</p>
        )}
      </div>

      {children}

      <div className="job-card-footer">
        {job.publicAvailable === false ? (
          <p>This saved listing is currently unavailable.</p>
        ) : (
          <Link className="job-details-action" to={`/jobs/${job.id}`}>
            View Job Details <Icon name="arrow" size={18} />
          </Link>
        )}
      </div>
    </article>
  );
}
