export default function StatusBadge({ children, status = '' }) {
  const value = String(status).toLowerCase().replaceAll(' ', '_');
  const tone = ['hired', 'verified', 'approved', 'agreed', 'active', 'read', 'shared'].includes(value) ? 'positive'
    : ['rejected', 'suspended', 'unable_to_provide', 'revoked', 'withdrawn', 'hidden'].includes(value) ? 'attention'
      : ['interview', 'shortlisted', 'alternative_proposed', 'under_discussion', 'reviewing', 'unread'].includes(value) ? 'progress' : 'neutral';
  return <span className={`status-badge status-${tone}`}>{children || status}</span>;
}
