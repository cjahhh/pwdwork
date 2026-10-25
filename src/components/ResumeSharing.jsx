import { useEffect, useRef, useState } from 'react';
import api, { apiErrorMessage } from '../services/api.js';
import { downloadBuiltResume, resumePreview } from '../services/resumes.js';
import '../pages/ResumesPage.css';

export default function ResumeSharing({ applicationId, applicant = false, initialShare = null }) {
  const path = `/applications/${applicationId}/built-resume`;
  const [share, setShare] = useState(initialShare);
  const [choosing, setChoosing] = useState(false);
  const [resumes, setResumes] = useState([]);
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [selected, setSelected] = useState('');
  const [consent, setConsent] = useState(false);
  const [busy, setBusy] = useState(false);
  const lock = useRef(false);
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');
  const [preview, setPreview] = useState('');
  useEffect(() => {
    if (!applicant || !choosing) return;
    let active = true;
    api.get('/applicant/resumes', { params: { page } }).then(({ data }) => {
      if (active) { setResumes(data.resumes); setLastPage(data.meta.last_page); }
    }).catch(e => { if (active) setError(apiErrorMessage(e)); });
    return () => { active = false; };
  }, [applicant, choosing, page]);
  async function action(kind) {
    if (lock.current) return;
    lock.current = true; setBusy(true); setError(''); setMessage('');
    try {
      if (kind === 'share') {
        const { data } = await api.put(path, { resume_id: Number(selected), consent });
        setShare(data.share); setConsent(false); setPreview(''); setMessage('Saved resume version shared with this application.');
      } else if (kind === 'revoke') {
        await api.delete(path); setShare(null); setPreview(''); setMessage('Future access revoked. Downloaded copies cannot be revoked.');
      } else if (kind === 'preview') setPreview(await resumePreview(`${path}/preview`));
      else await downloadBuiltResume(`${path}/pdf`, `${share.title}-v${share.version}`);
    } catch (e) { setError(apiErrorMessage(e)); } finally { lock.current = false; setBusy(false); }
  }
  return <section className="resume-sharing" aria-label="Resume sharing">
    <h3>Resume shared with this application</h3>
    {error && <p role="alert">{error}</p>}<p role="status">{message}</p>
    {share ? <><p>{share.title}, version {share.version}, shared {new Date(share.shared_at).toLocaleString()}</p>
      <button disabled={busy} type="button" onClick={() => action('preview')}>Preview shared resume</button>{' '}
      <button disabled={busy} type="button" onClick={() => action('download')}>Download shared PDF</button>{' '}
      {applicant && <button disabled={busy} type="button" onClick={() => action('revoke')}>Revoke resume access</button>}</> : <p>No builder resume shared.</p>}
    {applicant && <button type="button" disabled={busy} onClick={() => setChoosing(!choosing)}>{choosing ? 'Close resume selection' : 'Choose resume to share'}</button>}
    {applicant && choosing && <form onSubmit={e => { e.preventDefault(); action('share'); }}>
      <p>Choose a saved resume to share. This creates a snapshot; later edits will not change it. Sharing again explicitly replaces the snapshot. Downloaded copies cannot be revoked. Accommodation consent is separate.</p>
      <label>Saved resume<select required value={selected} onChange={e => { setSelected(e.target.value); setConsent(false); }} disabled={busy}>
        <option value="">Choose a resume</option>{resumes.map(r => <option key={r.id} value={r.id}>{r.title} (v{r.version})</option>)}
      </select></label>
      {lastPage > 1 && <div><button type="button" disabled={busy || page === 1} onClick={() => { setPage(page - 1); setSelected(''); }}>Previous resumes</button> Page {page} of {lastPage} <button type="button" disabled={busy || page === lastPage} onClick={() => { setPage(page + 1); setSelected(''); }}>Next resumes</button></div>}
      <label><input type="checkbox" required checked={consent} onChange={e => setConsent(e.target.checked)} disabled={busy} /> I agree to share this saved resume with the employer for this application.</label>
      <button type="submit" disabled={busy || !selected || !consent}>{share ? 'Replace shared snapshot' : 'Share resume'}</button>
    </form>}
    {preview && <><button type="button" onClick={() => setPreview('')}>Close shared preview</button><iframe title="Shared resume preview" sandbox="" srcDoc={preview} className="resume-preview" /></>}
  </section>;
}
