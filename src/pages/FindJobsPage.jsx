import { useSearchParams } from "react-router-dom";
import { fetchJobs } from "../services/jobs.js";
import { accommodationOptions, workOptions } from "../services/filters.js";
import { usePaginatedResource } from "../hooks/usePaginatedResource.js";
import Pagination from "../components/Pagination.jsx";
import RequestState from "../components/RequestState.jsx";
import JobGrid from "../components/JobGrid.jsx";
import PageHeader from "../components/PageHeader.jsx";
export default function FindJobsPage() {
  const [params, setParams] = useSearchParams();
  const selected = params.getAll("work"),
    accommodations = params.getAll("accommodation");
  const key = params.toString();
  const resource = usePaginatedResource(
    fetchJobs, {
        query: params.get("q") || "",
        location: params.get("location") || "",
        modes: selected.filter((v) =>
          ["Remote", "Onsite", "Hybrid"].includes(v),
        ),
        types: selected
          .filter((v) => ["Full-time", "Part-time", "Contract"].includes(v))
          .map((v) => ({ "Full-time": "Full Time", "Part-time": "Part Time", Contract: "Contract" })[v]),
        flexible: selected.includes("Flexible schedule") ? 1 : 0,
        accommodations,
      }, key,
  );
  function toggle(name, value) {
    const next = new URLSearchParams(params);
    const values = next.getAll(name);
    next.delete(name);
    (values.includes(value)
      ? values.filter((v) => v !== value)
      : [...values, value]
    ).forEach((v) => next.append(name, v));
    setParams(next);
  }
  function search(event) {
    event.preventDefault();
    const form = new FormData(event.currentTarget),
      next = new URLSearchParams(params);
    ["q", "location"].forEach((name) => {
      const value = form.get(name).trim();
      if (value) next.set(name, value);
      else next.delete(name);
    });
    setParams(next);
  }
  return (
    <main className="page-shell discovery-page">
      <PageHeader title="Find jobs" eyebrow="Find your next possibility" icon="search" description="Combine work arrangements and employer-reported accommodations. Confirm specific support with the employer." />
      <form
        key={params.get("q") + "|" + params.get("location")}
        onSubmit={search}
        className="panel job-search-form"
      >
        <label>
          <span className="form-label">Keyword or skill</span>
          <input
            name="q"
            className="form-input"
            defaultValue={params.get("q") || ""}
          />
        </label>
        <label>
          <span className="form-label">Location</span>
          <input
            name="location"
            className="form-input"
            defaultValue={params.get("location") || ""}
          />
        </label>
        <button className="primary-button">Search jobs</button>
        <button type="button" onClick={() => setParams({})}>
          Reset all filters and search
        </button>
      </form>
      <div className="discovery-layout"><aside className="discovery-filters" aria-label="Filter job results">
        <fieldset className="panel">
          <legend>Work arrangements</legend>
          <p>
            Alternatives within work mode or employment type match any selected
            option.
          </p>
          {workOptions.map((label) => (
            <label className="filter-option" key={label}>
              <input
                type="checkbox"
                checked={selected.includes(label)}
                onChange={() => toggle("work", label)}
              />
              {label}
            </label>
          ))}
        </fieldset>
        <fieldset className="panel">
          <legend>Accessibility accommodations</legend>
          <p>
            Jobs must list every selected accommodation. These are employer
            reports, not independently verified claims.
          </p>
          {accommodationOptions.map((label) => (
            <label className="filter-option" key={label}>
              <input
                type="checkbox"
                checked={accommodations.includes(label)}
                onChange={() => toggle("accommodation", label)}
              />
              {label}
            </label>
          ))}
        </fieldset>
      <button
        type="button"
        onClick={() => {
          const next = new URLSearchParams(params);
          next.delete("work");
          next.delete("accommodation");
          setParams(next);
        }}
      >
        Clear selected filters
      </button>
      </aside><section className="discovery-results" aria-label="Job search results">
      <RequestState resource={resource} />
      {resource.data && (
        <>
          <p className="results-count">{resource.data.meta.total} matching jobs</p>
          <JobGrid jobs={resource.data.jobs} totalCount={resource.data.meta.total} />
        </>
      )}
      <Pagination resource={resource} label="Job search pages" />
      </section></div>
    </main>
  );
}
