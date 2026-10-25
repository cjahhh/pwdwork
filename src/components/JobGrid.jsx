import JobCard from "./JobCard.jsx";
import Icon from "./Icon.jsx";

function JobGrid({ jobs, totalCount, emptyAction }) {
  if (jobs.length === 0) {
    return (
      <div className="col-span-full bg-white p-12 rounded-3xl border-2 border-gray-200 text-center space-y-4">
        <div className="w-16 h-16 bg-blue-50 text-brand-blue rounded-full flex items-center justify-center mx-auto font-bold text-2xl">
          <Icon name="search" size={28} />
        </div>
        <h3 className="text-2xl font-black text-brand-dark">
          No job openings found matching your filter criteria
        </h3>
        <p className="text-gray-600 max-w-md mx-auto">
          Try clearing your accessibility filter chips or search inputs to explore all available positions.
        </p>
        {emptyAction}
      </div>
    );
  }

  return (
    <>
      <p className="sr-only">
        Showing {jobs.length} of {totalCount} featured roles
      </p>
      <div className="job-grid">
        {jobs.map((job) => (
          <JobCard key={job.id} job={job} />
        ))}
      </div>
    </>
  );
}

export default JobGrid;
