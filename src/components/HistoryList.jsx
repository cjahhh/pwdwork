import { usePaginatedResource } from "../hooks/usePaginatedResource.js";
import Pagination from "./Pagination.jsx";
import RequestState from "./RequestState.jsx";
import { displayStatus } from "../services/moderation.js";

export default function HistoryList({ fetcher, params = {}, scope = "", title = "Review history" }) {
  const resource = usePaginatedResource(fetcher, params, scope);
  return (
    <section className="mt-6" aria-label={title}>
      <h3>{title}</h3>
      <RequestState resource={resource} />
      {resource.data?.meta.total === 0 && <p>No recorded decisions yet.</p>}
      <ol className="space-y-3">
        {resource.data?.logs.map((entry) => <li key={entry.id} className="panel">
          <p><strong>{entry.action}</strong> · {entry.actorName} ({entry.actorRole})</p>
          <p>{entry.targetType} #{entry.targetId}</p>
          <p>{displayStatus(entry.beforeStatus) || "New"} → {displayStatus(entry.afterStatus)} · <time>{entry.createdAt}</time></p>
          {entry.reason && <p className="whitespace-pre-wrap">{entry.reason}</p>}
        </li>)}
      </ol>
      <Pagination resource={resource} label={`${title} pages`} />
    </section>
  );
}
