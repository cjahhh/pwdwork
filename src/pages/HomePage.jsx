import { useState } from "react";
import { Link, useNavigate } from "react-router-dom";
import { fetchJobs } from "../services/jobs.js";
import { useResource } from "../hooks/useResource.js";
import RequestState from "../components/RequestState.jsx";
import JobGrid from "../components/JobGrid.jsx";

function HomePage() {
  const navigate = useNavigate();
  const [query, setQuery] = useState("");
  const [location, setLocation] = useState("");

  const resource = useResource(fetchJobs);
  const featuredJobs = (resource.data || []).slice(0, 3);

  function clearFilters() {
    setQuery("");
    setLocation("");
  }

  function searchJobs(event) {
    event.preventDefault();
    const params = new URLSearchParams();
    if (query) params.set("q", query);
    if (location) params.set("location", location);
    navigate(`/jobs?${params.toString()}`);
  }

  return (
    <main>
      <section
        id="hero"
        className="relative bg-gradient-to-b from-blue-50/60 via-white to-gray-50 pt-10 pb-16 lg:pt-16 lg:pb-24 border-b border-gray-200"
      >
        <div className="max-w-[1440px] mx-auto px-6 lg:px-12">
          <div className="max-w-4xl mx-auto">
            <div className="space-y-6">
              <p className="text-sm font-bold text-brand-teal">Accessible jobs from inclusive employers</p>

              <h1 className="text-4xl sm:text-5xl lg:text-6xl font-black text-brand-dark tracking-tight leading-[1.15]">
                Find work that <br className="hidden sm:inline" />
                <span className="text-brand-blue underline decoration-brand-yellow decoration-wavy decoration-2">
                  works for you.
                </span>
              </h1>

              <p className="text-lg text-gray-700 leading-relaxed max-w-2xl">
                Search available roles and choose the work arrangements and accommodations that fit you.
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
                      placeholder="e.g. Customer service, cashier..."
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

              <p className="text-sm text-gray-600">Try a search such as “Customer service” or “Warehouse”.</p>
            </div>
          </div>
        </div>
      </section>

      <section id="jobs-section" className="bg-brand-gray-bg py-12 border-b border-gray-200">
        <div className="max-w-[1440px] mx-auto px-6 lg:px-12">
          <div className="flex flex-col sm:flex-row sm:items-end justify-between gap-4 mb-7">
            <div>
              <h2 className="text-3xl font-black text-brand-dark tracking-tight">Featured jobs</h2>
              <p className="text-gray-600 mt-1">Roles with clear accessibility information.</p>
            </div>
            <div className="bg-white px-4 py-2 rounded-xl border border-gray-200 shadow-sm text-sm font-bold text-brand-dark flex items-center gap-2">
              <span className="w-2.5 h-2.5 rounded-full bg-brand-teal" />
              <span>
                Showing <strong className="text-brand-blue">{featuredJobs.length}</strong> featured roles
              </span>
            </div>
          </div>

          <RequestState resource={resource} />
          {!resource.loading && !resource.error && <JobGrid
            jobs={featuredJobs}
            totalCount={resource.data?.length || 0}
            emptyAction={
              <button
                type="button"
                onClick={clearFilters}
                className="bg-brand-blue text-white font-extrabold px-6 py-3 rounded-xl hover:bg-brand-blue-dark transition"
              >
                Reset All Filters
              </button>
            }
          />}

          <div className="mt-8 text-center">
            <Link
              to="/jobs"
              className="bg-white hover:bg-gray-50 text-brand-blue border-2 border-brand-blue font-black text-lg px-10 py-4 rounded-2xl shadow-soft-lg transition duration-200 inline-flex items-center gap-3"
            >
              <span>View All Job Listings</span>
              <svg className="w-5 h-5 text-brand-blue" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2.5" d="M17 8l4 4m0 0l-4 4m4-4H3" />
              </svg>
            </Link>
          </div>
        </div>
      </section>

    </main>
  );
}

export default HomePage;
