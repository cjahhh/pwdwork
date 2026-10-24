import { Link } from 'react-router-dom';

export default function InterviewArrangements({ arrangements = [], role }) {
  if (!arrangements.length) return null;
  return <section aria-label="Confirmed interview arrangements" className="space-y-2 break-words">
    <h3>Confirmed interview arrangements</h3>
    {arrangements.map(item => <div key={item.id}>
      <p className="whitespace-pre-wrap">{item.arrangement}</p>
      {item.scheduleChanged && <p>The interview schedule changed after confirmation. Discuss and reconfirm this arrangement.</p>}
      <Link to={`/${role}/accommodation-requests/${item.id}`}>Review arrangement #{item.id}</Link>
    </div>)}
  </section>;
}
