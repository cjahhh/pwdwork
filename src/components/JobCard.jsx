import { Link } from "react-router-dom";

function JobCard({ job }) {
  return (
    <article className="bg-white p-7 rounded-3xl border border-gray-200 shadow-soft-lg flex flex-col justify-between space-y-6 hover:border-brand-blue hover:shadow-soft-xl transition duration-200 group">
      <div>
        <div className="flex items-start justify-between gap-3 mb-4">
          <div
            className={`w-14 h-14 rounded-full ${job.companyColor} text-white font-black text-xl flex items-center justify-center shadow-md group-hover:scale-105 transition-transform`}
          >
            {job.companyLogo}
          </div>
          <span className="bg-blue-50 text-brand-blue border border-blue-200 font-extrabold text-xs px-3.5 py-1.5 rounded-full uppercase tracking-wider">
            {job.type}
          </span>
        </div>

        <h3 className="text-xl font-black text-brand-dark leading-snug">
          <Link to={`/jobs/${job.id}`} className="hover:text-brand-blue transition">
            {job.title}
          </Link>
        </h3>
        <p className="text-base font-bold text-gray-700 mt-1 flex items-center gap-2">{job.company}{job.verified ? <span className="inline-flex items-center gap-1 rounded-full bg-teal-50 text-brand-teal border border-teal-200 px-2 py-0.5 text-xs font-extrabold" title="Employer verification completed">✓ Verified employer</span> : null}</p>
        <p className="text-sm font-semibold text-gray-500 flex items-center gap-1.5 mt-1">
          <span aria-hidden="true">📍</span> {job.location}
        </p>

        <p className="text-sm text-gray-600 mt-3 line-clamp-2 leading-relaxed">{job.description}</p>

        <div className="mt-5 pt-4 border-t border-gray-100">
          <p className="text-xs font-extrabold uppercase tracking-wider text-brand-teal mb-2">
            Employer-reported support:
          </p>
          <div className="flex flex-wrap gap-2">
            {job.accommodations.map((acc) => (
              <span
                key={acc.label}
                className="bg-brand-gray-bg text-brand-dark text-xs font-bold px-2.5 py-1 rounded-lg border border-gray-200 flex items-center gap-1"
              >
                <span aria-hidden="true">{acc.icon}</span>
                <span>{acc.label}</span>
              </span>
            ))}
          </div>
        </div>
      </div>

      <Link
        to={`/jobs/${job.id}`}
        className="w-full bg-brand-blue hover:bg-brand-blue-dark text-white font-black text-base py-3.5 rounded-2xl shadow-md transition duration-150 flex items-center justify-center gap-2"
      >
        <span>View Job Details</span>
        <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2.5" d="M14 5l7 7m0 0l-7 7m7-7H3" />
        </svg>
      </Link>
    </article>
  );
}

export default JobCard;
