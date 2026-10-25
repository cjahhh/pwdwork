import { useState } from "react";
import { Link } from "react-router-dom";
import { useAuth } from "../context/AuthContext.jsx";
import { usePaginatedResource } from "../hooks/usePaginatedResource.js";
import { fetchNotifications, readNotification } from "../services/moderation.js";
import { apiErrorMessage } from "../services/api.js";
import RequestState from "../components/RequestState.jsx";
import Pagination from "../components/Pagination.jsx";
import StatusBadge from '../components/StatusBadge.jsx';
import EmptyState from '../components/EmptyState.jsx';
import PageHeader from '../components/PageHeader.jsx';
import Icon from '../components/Icon.jsx';

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
    <PageHeader title="Notifications" eyebrow="Stay in the loop" icon="bell" description="Updates about your account and activity, in one place." actions={<button type="button" className="button-secondary" onClick={resource.reload} disabled={resource.loading}>Refresh notifications</button>} />
    <RequestState resource={resource} />{error && <p role="alert">{error}</p>}
    {resource.data?.meta.total === 0 && <EmptyState title="You have no notifications yet.">Updates about applications, accommodation requests and account reviews will appear here.</EmptyState>}
    {resource.data?.notifications.map((notice) => <article key={notice.id} className={`panel notification-card ${notice.readAt ? '' : 'notification-unread'}`}>
      <span className="metric-icon"><Icon name="bell" /></span><div>
      <h2>{notice.title}</h2><p className="whitespace-pre-wrap">{notice.body}</p><p>{notice.createdAt} <StatusBadge status={notice.readAt ? 'read' : 'unread'}>{notice.readAt ? 'Read' : 'Unread'}</StatusBadge></p>
      <div className="mt-4 flex flex-wrap items-center gap-x-6 gap-y-3">
        {notice.actionUrl?.startsWith("/") && !notice.actionUrl.startsWith("//") && <Link to={notice.actionUrl} className="underline">View related page</Link>}
        {!notice.readAt && <button type="button" onClick={() => read(notice.id)} disabled={busy}>Mark as read</button>}
      </div>
    </div></article>)}
    <Pagination resource={resource} label="Notification pages" disabled={busy} />
  </main>;
}
