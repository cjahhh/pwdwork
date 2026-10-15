import { useMemo, useState } from "react";
import { Link, useNavigate } from "react-router-dom";
import { filterJobs, jobsDataset } from "../services/jobs.js";
import { useUi } from "../context/UiContext.jsx";
import FilterChips from "../components/FilterChips.jsx";
import JobGrid from "../components/JobGrid.jsx";
import HeroIllustration from "../components/HeroIllustration.jsx";
import HowItWorks from "../components/sections/HowItWorks.jsx";
import EmployerCta from "../components/sections/EmployerCta.jsx";
import ImpactStats from "../components/sections/ImpactStats.jsx";

function HomePage() {
  const navigate = useNavigate();
  const { showToast } = useUi();
  const [query, setQuery] = useState("");
  const [location, setLocation] = useState("");
  const [activeChip, setActiveChip] = useState("Remote");

  const filteredJobs = useMemo(
    () => filterJobs(jobsDataset, { chip: activeChip, query, location }),
    [activeChip, query, location],
  );

  function toggleChip(chipId) {
    if (activeChip === chipId) {
      setActiveChip(null);
      showToast("Filter Cleared", "Showing all job listings.");
    } else {
      setActiveChip(chipId);
      showToast("Filter Applied", `Showing jobs matching "${chipId}".`);
    }
  }

  function clearFilters() {
    setActiveChip(null);
    setQuery("");
    setLocation("");
    showToast("Filters Reset", "Showing all 6 featured roles.");
  }

  function searchJobs(event) {
    event.preventDefault();
    const params = new URLSearchParams();
    if (query) params.set("q", query);
    if (location) params.set("location", location);
    if (activeChip) params.set("tag", activeChip);
    navigate(`/jobs?${params.toString()}`);
  }

  function quickFill(term) {
    setQuery(term);
    document.getElementById("jobs-section")?.scrollIntoView({ behavior: "smooth" });
  }

  return (
    <main>
      <section
        id="hero"
        className="relative bg-gradient-to-b from-blue-50/60 via-white to-gray-50 pt-10 pb-16 lg:pt-16 lg:pb-24 border-b border-gray-200"
      >
        <div className="max-w-[1440px] mx-auto px-6 lg:px-12">
          <div className="grid grid-cols-1 lg:grid-cols-12 gap-12 lg:gap-8 items-center">
            <div className="lg:col-span-7 space-y-8">
              <div className="inline-flex items-center gap-2 bg-brand-teal-light border border-brand-teal/20 text-brand-teal px-4 py-2 rounded-full font-bold text-sm shadow-sm">
                <span className="w-2.5 h-2.5 rounded-full bg-brand-teal animate-pulse" />
                <span>Over 1,200+ Verified Accessible Positions Active Today</span>
              </div>

              <h1 className="text-4xl sm:text-5xl lg:text-6xl font-black text-brand-dark tracking-tight leading-[1.15]">
                Find work that <br className="hidden sm:inline" />
                <span className="text-brand-blue underline decoration-brand-yellow decoration-wavy decoration-2">
                  works for you.
                </span>
              </h1>

              <p className="text-xl text-gray-700 font-medium leading-relaxed max-w-2xl">
                Search jobs from certified employers who care about accessibility. Every listing clearly details
                workplace accommodations including remote setups, wheelchair access, flexible hours, and sign language
                support.
              </p>

              <form
                onSubmit={searchJobs}
                className="bg-white p-4 sm:p-5 rounded-2xl shadow-soft-xl border border-gray-200 space-y-4 sm:space-y-0 sm:flex sm:items-end sm:gap-4"
              >
                <div className="flex-1 relative">
                  <label htmlFor="heroSearchInput" className="block text-xs font-extrabold uppercase tracking-wider text-brand-teal mb-1">
                    Job Title or Skill
                  </label>
                  <div className="relative flex items-center">
                    <svg className="w-5 h-5 text-gray-400 absolute left-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                    </svg>
                    <input
                      type="text"
                      id="heroSearchInput"
                      value={query}
                      onChange={(event) => setQuery(event.target.value)}
                      placeholder="e.g. Accessibility QA, Data Analyst..."
                      className="w-full pl-10 pr-3 py-3 bg-gray-50 border-2 border-gray-200 rounded-xl font-medium text-base text-brand-dark focus:bg-white focus:border-brand-blue transition"
                    />
                  </div>
                </div>

                <div className="flex-1 relative">
                  <label htmlFor="heroLocationInput" className="block text-xs font-extrabold uppercase tracking-wider text-brand-teal mb-1">
                    Location Preference
                  </label>
                  <div className="relative flex items-center">
                    <svg className="w-5 h-5 text-gray-400 absolute left-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
                    </svg>
                    <input
                      type="text"
                      id="heroLocationInput"
                      value={location}
                      onChange={(event) => setLocation(event.target.value)}
                      placeholder="e.g. Remote, Manila, Hybrid..."
                      className="w-full pl-10 pr-3 py-3 bg-gray-50 border-2 border-gray-200 rounded-xl font-medium text-base text-brand-dark focus:bg-white focus:border-brand-blue transition"
                    />
                  </div>
                </div>

                <button
                  type="submit"
                  className="w-full sm:w-auto bg-brand-teal hover:bg-brand-teal-hover text-white font-extrabold text-lg px-8 py-3.5 rounded-xl shadow-soft-lg transition flex items-center justify-center gap-2"
                >
                  <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2.5" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                  </svg>
                  <span>Search Jobs</span>
                </button>
              </form>

              <div className="flex items-center gap-3 text-sm text-gray-600 font-medium">
                <span className="font-bold text-brand-blue">Popular Searches:</span>
                <div className="flex flex-wrap gap-2">
                  {["Remote", "QA", "Developer"].map((term) => (
                    <button
                      key={term}
                      type="button"
                      onClick={() => quickFill(term === "QA" ? "QA" : term)}
                      className="bg-white hover:bg-blue-50 text-gray-700 px-3 py-1 rounded-lg border border-gray-300 text-xs font-bold transition"
                    >
                      {term === "QA" ? "Accessibility QA" : term}
                    </button>
                  ))}
                </div>
              </div>
            </div>

            <div className="lg:col-span-5 flex justify-center">
              <HeroIllustration />
            </div>
          </div>
        </div>
      </section>

      <FilterChips activeChip={activeChip} onToggle={toggleChip} onClear={clearFilters} />

      <section id="jobs-section" className="bg-brand-gray-bg py-16 border-b border-gray-200">
        <div className="max-w-[1440px] mx-auto px-6 lg:px-12">
          <div className="flex flex-col sm:flex-row sm:items-end justify-between gap-4 mb-10">
            <div>
              <span className="text-xs font-black uppercase tracking-widest text-brand-teal block mb-1">
                Empowering Inclusive Employment
              </span>
              <h2 className="text-3xl sm:text-4xl font-black text-brand-dark tracking-tight">Latest Job Openings</h2>
              <p className="text-gray-600 text-lg mt-1">
                Explore verified roles from top inclusive organizations with clear accommodation transparent guarantees.
              </p>
            </div>
            <div className="bg-white px-4 py-2 rounded-xl border border-gray-200 shadow-sm text-sm font-bold text-brand-dark flex items-center gap-2">
              <span className="w-2.5 h-2.5 rounded-full bg-brand-teal" />
              <span>
                Showing <strong className="text-brand-blue">{filteredJobs.length}</strong> of {jobsDataset.length} Featured
                Roles
              </span>
            </div>
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

          <div className="mt-12 text-center">
            <Link
              to="/jobs"
              className="bg-white hover:bg-gray-50 text-brand-blue border-2 border-brand-blue font-black text-lg px-10 py-4 rounded-2xl shadow-soft-lg transition duration-200 inline-flex items-center gap-3"
            >
              <span>View All Job Listings (1,200+)</span>
              <svg className="w-5 h-5 text-brand-blue" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2.5" d="M17 8l4 4m0 0l-4 4m4-4H3" />
              </svg>
            </Link>
          </div>
        </div>
      </section>

      <HowItWorks />
      <EmployerCta />
      <ImpactStats />
    </main>
  );
}

export default HomePage;
