import { useState } from "react";
import { Link } from "react-router-dom";
import { useAuth } from "../context/AuthContext.jsx";
import { usePaginatedResource } from "../hooks/usePaginatedResource.js";
import { fetchNotifications, readNotification } from "../services/moderation.js";
import { apiErrorMessage } from "../services/api.js";
import RequestState from "../components/RequestState.jsx";
import Pagination from "../components/Pagination.jsx";

export default function NotificationsPage() {
  const { user } = useAuth();
  const resource = usePaginatedResource(fetchNotifications, {}, user.id);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  async function read(id) {
    setBusy(true); setError("");
    try { await readNotification(id); resource.reload(); }
    catch (exception) { setError(apiErrorMessage(exception)); }
    finally { setBusy(false); }
  }
  return <main className="page-shell">
    <h1>Notifications</h1><button type="button" onClick={resource.reload} disabled={resource.loading}>Refresh notifications</button>
    <RequestState resource={resource} />{error && <p role="alert">{error}</p>}
    {resource.data?.meta.total === 0 && <p>You have no notifications yet.</p>}
    {resource.data?.notifications.map((notice) => <article key={notice.id} className="panel">
      <h2>{notice.title}</h2><p className="whitespace-pre-wrap">{notice.body}</p><p>{notice.createdAt} · {notice.readAt ? "Read" : "Unread"}</p>
      {notice.actionUrl?.startsWith("/") && !notice.actionUrl.startsWith("//") && <Link to={notice.actionUrl} className="underline">View related page</Link>}
      {!notice.readAt && <button type="button" onClick={() => read(notice.id)} disabled={busy}>Mark as read</button>}
    </article>)}
    <Pagination resource={resource} label="Notification pages" disabled={busy} />
  </main>;
}
