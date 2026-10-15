import { useMemo, useState } from "react";
import { useSearchParams } from "react-router-dom";
import { filterJobs, jobsDataset } from "../services/jobs.js";
import { useUi } from "../context/UiContext.jsx";
import FilterChips from "../components/FilterChips.jsx";
import JobGrid from "../components/JobGrid.jsx";

function FindJobsPage() {
  const { showToast } = useUi();
  const [searchParams, setSearchParams] = useSearchParams();
  const [query, setQuery] = useState(searchParams.get("q") || "");
  const [location, setLocation] = useState(searchParams.get("location") || "");
  const [activeChip, setActiveChip] = useState(searchParams.get("tag") || "Remote");

  const filteredJobs = useMemo(
    () => filterJobs(jobsDataset, { chip: activeChip, query, location }),
    [activeChip, query, location],
  );

  function syncParams(next) {
    const params = new URLSearchParams();
    if (next.query) params.set("q", next.query);
    if (next.location) params.set("location", next.location);
    if (next.chip) params.set("tag", next.chip);
    setSearchParams(params);
  }

  function toggleChip(chipId) {
    const nextChip = activeChip === chipId ? null : chipId;
    setActiveChip(nextChip);
    syncParams({ query, location, chip: nextChip });
    showToast(
      nextChip ? "Filter Applied" : "Filter Cleared",
      nextChip ? `Showing jobs matching "${nextChip}".` : "Showing all job listings.",
    );
  }

  function clearFilters() {
    setActiveChip(null);
    setQuery("");
    setLocation("");
    setSearchParams({});
    showToast("Filters Reset", "Showing all featured roles.");
  }

  return (
    <main>
      <section className="bg-white py-12 border-b border-gray-200">
        <div className="max-w-[1440px] mx-auto px-6 lg:px-12 space-y-6">
          <div>
            <span className="text-xs font-black uppercase tracking-widest text-brand-teal block mb-1">
              Search Accessible Roles
            </span>
            <h1 className="text-3xl sm:text-4xl font-black text-brand-dark tracking-tight">Latest Job Openings</h1>
            <p className="text-gray-600 text-lg mt-1">
              Filter by skill, location, and verified workplace accommodations.
            </p>
          </div>

          <form
            className="bg-white p-4 sm:p-5 rounded-2xl shadow-soft-xl border border-gray-200 space-y-4 sm:space-y-0 sm:flex sm:items-end sm:gap-4"
            onSubmit={(event) => {
              event.preventDefault();
              syncParams({ query, location, chip: activeChip });
            }}
          >
            <div className="flex-1">
              <label htmlFor="jobsSearchInput" className="block text-xs font-extrabold uppercase tracking-wider text-brand-teal mb-1">
                Job Title or Skill
              </label>
              <input
                id="jobsSearchInput"
                value={query}
                onChange={(event) => setQuery(event.target.value)}
                placeholder="e.g. Accessibility QA, Data Analyst..."
                className="w-full px-4 py-3 bg-gray-50 border-2 border-gray-200 rounded-xl font-medium text-base text-brand-dark focus:bg-white focus:border-brand-blue transition"
              />
            </div>
            <div className="flex-1">
              <label htmlFor="jobsLocationInput" className="block text-xs font-extrabold uppercase tracking-wider text-brand-teal mb-1">
                Location Preference
              </label>
              <input
                id="jobsLocationInput"
                value={location}
                onChange={(event) => setLocation(event.target.value)}
                placeholder="e.g. Remote, Manila, Hybrid..."
                className="w-full px-4 py-3 bg-gray-50 border-2 border-gray-200 rounded-xl font-medium text-base text-brand-dark focus:bg-white focus:border-brand-blue transition"
              />
            </div>
            <button
              type="submit"
              className="w-full sm:w-auto bg-brand-teal hover:bg-brand-teal-hover text-white font-extrabold text-lg px-8 py-3.5 rounded-xl shadow-soft-lg transition"
            >
              Search Jobs
            </button>
          </form>
        </div>
      </section>

      <FilterChips activeChip={activeChip} onToggle={toggleChip} onClear={clearFilters} />

      <section className="bg-brand-gray-bg py-16">
        <div className="max-w-[1440px] mx-auto px-6 lg:px-12">
          <div className="bg-white px-4 py-2 rounded-xl border border-gray-200 shadow-sm text-sm font-bold text-brand-dark inline-flex items-center gap-2 mb-8">
            <span className="w-2.5 h-2.5 rounded-full bg-brand-teal" />
            Showing <strong className="text-brand-blue">{filteredJobs.length}</strong> of {jobsDataset.length} Featured
            Roles
          </div>
          <JobGrid
            jobs={filteredJobs}
            totalCount={jobsDataset.length}
            emptyAction={
              <button
                type="button"
                onClick={clearFilters}
                className="bg-brand-blue text-white font-extrabold px-6 py-3 rounded-xl hover:bg-brand-blue-dark transition"
              >
                Reset All Filters
              </button>
            }
          />
        </div>
      </section>
    </main>
  );
}

export default FindJobsPage;
