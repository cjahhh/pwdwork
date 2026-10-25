export default function EmptyState({ title, children, action }) {
  return <div className="empty-state"><h2>{title}</h2>{children && <p>{children}</p>}{action && <div className="empty-state-action">{action}</div>}</div>;
}
