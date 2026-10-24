import { useState } from "react";
import { fetchEmployerJobs } from "../services/jobs.js";
import { usePaginatedResource } from "../hooks/usePaginatedResource.js";
import Pagination from "./Pagination.jsx";
import RequestState from "./RequestState.jsx";

export default function EmployerJobFilter({ selected, onChange, accountId, disabled }) {
  const [query, setQuery] = useState("");
  const resource = usePaginatedResource(fetchEmployerJobs, { query }, accountId);
  const jobs = resource.data?.jobs || [];
  return (
    <fieldset className="panel">
      <legend>Filter by job listing</legend>
      <label>
        <span className="form-label">Find a listing by title</span>
        <input className="form-input" maxLength={255} value={query} disabled={disabled}
          onChange={(event) => setQuery(event.target.value)} />
      </label>
      <RequestState resource={resource} />
      <label>
        <span className="form-label">Job listing</span>
        <select className="form-input" value={selected?.id || ""} disabled={disabled}
          onChange={(event) => onChange(jobs.find((job) => String(job.id) === event.target.value) || null)}>
          <option value="">All listings</option>
          {selected && !jobs.some((job) => job.id === selected.id) && (
            <option value={selected.id}>{selected.title} (#{selected.id})</option>
          )}
          {jobs.map((job) => <option key={job.id} value={job.id}>{job.title} (#{job.id})</option>)}
        </select>
      </label>
      <Pagination resource={resource} label="Job filter choices" disabled={disabled} />
    </fieldset>
  );
}
