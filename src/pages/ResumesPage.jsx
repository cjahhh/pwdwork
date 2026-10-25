import { useCallback, useEffect, useRef, useState } from 'react';
import { Link, useBlocker } from 'react-router-dom';
import api, { apiErrorMessage } from '../services/api.js';
import { downloadBuiltResume, resumePreview } from '../services/resumes.js';
import './ResumesPage.css';
import ReviewDialog from '../components/ReviewDialog.jsx';
import EmptyState from '../components/EmptyState.jsx';

const sections = { education: 'Education', work: 'Work experience', volunteer: 'Volunteer experience', skills: 'Skills', certifications: 'Certifications and training', projects: 'Projects', languages: 'Languages', references: 'References (optional)' };
const empty = () => ({ title: 'My resume', template: 'classic', content: { personal: { name: '', email: '', phone: '', location: '', website: '' }, summary: '', ...Object.fromEntries(Object.keys(sections).map(s => [s, []])) } });

export default function ResumesPage() {
  const [list, setList] = useState([]);
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [draft, setDraft] = useState(null);
  const [dirty, setDirty] = useState(false);
  const [busy, setBusy] = useState(false);
  const lock = useRef(false);
  const [error, setError] = useState('');
  const [status, setStatus] = useState('');
  const [preview, setPreview] = useState('');
  const [loading, setLoading] = useState(true);
  const [confirm, setConfirm] = useState(null);
  const dashboardHeading = useRef(null);
  const previewHeading = useRef(null);
  const pendingDashboardFocus = useRef(false);
  useEffect(() => {
    if (!confirm && pendingDashboardFocus.current) {
      pendingDashboardFocus.current = false;
      requestAnimationFrame(() => dashboardHeading.current?.focus());
    }
  }, [confirm]);
  const heading = useRef(null);
  const blocker = useBlocker(dirty);
  useEffect(() => {
    if (blocker.state === 'blocked') {
      if (window.confirm('Discard unsaved resume changes?')) blocker.proceed();
      else blocker.reset();
    }
  }, [blocker]);
  const load = useCallback(async () => {
    setLoading(true);
    try {
      const { data } = await api.get('/applicant/resumes', { params: { page } });
      setList(data.resumes); setLastPage(data.meta.last_page);
      if (page > data.meta.last_page) setPage(data.meta.last_page);
    } finally { setLoading(false); }
  }, [page]);
  useEffect(() => { void Promise.resolve().then(load).catch(e => setError(apiErrorMessage(e))); }, [load]);
  useEffect(() => {
    if (!dirty) return;
    const before = e => { e.preventDefault(); e.returnValue = ''; };
    window.addEventListener('beforeunload', before);
    return () => { window.removeEventListener('beforeunload', before); };
  }, [dirty]);
  function change(next) { setDraft(next); setDirty(true); setStatus('Unsaved changes.'); }
  function personal(field, value) { change({ ...draft, content: { ...draft.content, personal: { ...draft.content.personal, [field]: value } } }); }
  function entries(section, rows) { change({ ...draft, content: { ...draft.content, [section]: rows } }); }
  async function run(fn) {
    if (lock.current) return;
    lock.current = true; setBusy(true); setError('');
    try { await fn(); } catch (e) { setError(apiErrorMessage(e)); } finally { lock.current = false; setBusy(false); }
  }
  function canLeave() { return !dirty || window.confirm('Discard unsaved resume changes?'); }
  function create() { if (canLeave()) { setDraft(empty()); setDirty(true); setPreview(''); setStatus('New resume. Enter your name and save.'); setTimeout(() => heading.current?.focus(), 0); } }
  async function edit(id) {
    if (!canLeave()) return;
    await run(async () => { const { data } = await api.get(`/applicant/resumes/${id}`); setDraft(data.resume); setDirty(false); setPreview(await resumePreview(`/applicant/resumes/${id}/preview`)); setStatus('Saved version loaded.'); setTimeout(() => heading.current?.focus(), 0); });
  }
  async function save(e) {
    e.preventDefault();
    await run(async () => {
      const path = '/applicant/resumes' + (draft.id ? `/${draft.id}` : '');
      const { data } = await api[draft.id ? 'put' : 'post'](path, { title: draft.title, template: draft.template, content: draft.content, ...(draft.id ? { version: draft.version } : {}) });
      setDraft(data.resume); setDirty(false); setStatus('Resume saved. Loading preview…');
      setPreview(await resumePreview(`/applicant/resumes/${data.resume.id}/preview`));
      setStatus('Resume saved. Preview updated.'); await load();
    });
  }
  async function confirmAction() {
    await run(async () => {
      if (confirm.kind === 'delete') {
        const resume = confirm.resume;
        await api.delete(`/applicant/resumes/${resume.id}`);
        if (draft?.id === resume.id) { setDraft(null); setDirty(false); setPreview(''); }
        pendingDashboardFocus.current = true;
        setConfirm(null); setStatus('Resume deleted.');
        await load();
      } else {
        const { data } = await api.post('/applicant/resumes/import-profile', { consent: true });
        change({ ...draft, content: { ...draft.content, ...data.content, personal: { ...draft.content.personal, ...data.content.personal } } });
        setConfirm(null);
      }
    });
  }
  return <main className="page-shell resume-builder">
    <div className="page-heading"><div><p className="eyebrow">Your experience, well presented</p><h1 ref={dashboardHeading} tabIndex={-1}>My resumes</h1><p className="page-description">Create, refine and share a professional story that is yours to control.</p></div></div>
    <p>Create a resume, choose a template, save and preview, then download or share it from <Link to="/applicant/applications">your applications</Link>. Employment history is optional. Include only information you want employers to see.</p>
    <p>Creating a resume does not share it. Resume edits never modify your profile.</p>
    {error && <p role="alert" className="resume-error">{error}</p>}<p role="status" aria-live="polite">{status}</p>
    <div className="action-row"><button type="button" className="primary-button" disabled={busy} onClick={create}>Create Resume</button><button type="button" disabled={busy || loading} onClick={() => run(load)}>Refresh resumes</button></div>
    <section aria-label="Saved resumes">
      {loading && <p role="status" className="request-feedback loading-state">Loading resumes…</p>}
      {!loading && !error && !list.length && <EmptyState title="No saved resumes on this page.">Create your first resume to get started. Work experience is optional.</EmptyState>}
      {list.map(r => <article key={r.id} className="panel"><h2>{r.title}</h2><p>Version {r.version} · Updated {new Date(r.updated_at).toLocaleString()}</p>
        <div className="resume-actions"><button disabled={busy} onClick={() => edit(r.id)}>Edit / Preview {r.title}</button>
        <button disabled={busy} onClick={() => run(() => downloadBuiltResume(`/applicant/resumes/${r.id}/pdf`, `${r.title}-v${r.version}`))}>Download PDF</button>
        <button disabled={busy} onClick={() => run(async () => { await api.post(`/applicant/resumes/${r.id}/duplicate`); await load(); setStatus('Resume duplicated.'); })}>Duplicate</button>
        <button className="button-danger" disabled={busy} onClick={() => { setError(''); setConfirm({ kind: 'delete', resume: r }); }}>Delete</button>
        <Link to="/applicant/applications">Share with application</Link></div></article>)}
      <nav aria-label="Resume pages" className="action-row"><button disabled={busy || loading || page <= 1} onClick={() => setPage(page - 1)}>Previous</button> Page {page} of {lastPage} <button disabled={busy || loading || page >= lastPage} onClick={() => setPage(page + 1)}>Next</button></nav>
    </section>
    {draft && <section className="panel"><h2 tabIndex={-1} ref={heading}>Resume editor</h2>
      <p>All sections except your name are optional. Use Move up and Move down to reorder entries. Save to update the preview.</p>
      <button type="button" disabled={busy} onClick={() => { setError(''); setConfirm({ kind: 'import' }); }}>Import profile information</button>
      <div className="resume-workspace"><div>
      <form onSubmit={save} aria-busy={busy}><fieldset disabled={busy} className="resume-fields"><legend>Resume settings</legend>
        <label>Resume title<input required maxLength={120} value={draft.title} onChange={e => change({ ...draft, title: e.target.value })} /></label>
        <label>Template<select value={draft.template} onChange={e => change({ ...draft, template: e.target.value })}><option value="classic">Classic Professional</option><option value="modern">Modern Minimal</option></select></label>
      </fieldset><fieldset disabled={busy} className="resume-fields"><legend>Personal and contact details</legend>
        {Object.entries({ name: 'Full name (required)', email: 'Email', phone: 'Phone', location: 'Location', website: 'Website (https://...)' }).map(([field, label]) => <label key={field}>{label}<input required={field === 'name'} type={field === 'email' ? 'email' : field === 'website' ? 'url' : 'text'} maxLength={field === 'name' ? 160 : field === 'email' ? 254 : field === 'phone' ? 60 : field === 'website' ? 300 : 255} value={draft.content.personal[field] || ''} onChange={e => personal(field, e.target.value)} /></label>)}
      </fieldset><label>Professional summary or career objective<textarea disabled={busy} maxLength={4000} value={draft.content.summary || ''} onChange={e => change({ ...draft, content: { ...draft.content, summary: e.target.value } })} /></label>
      {Object.entries(sections).map(([section, label]) => <fieldset key={section} disabled={busy} className="resume-section"><legend>{label}</legend>
        {!(draft.content[section]?.length) && <p className="resume-hint">Optional. Add an entry if you want this section in your resume.</p>}
        {(draft.content[section] || []).map((entry, i, rows) => <fieldset key={i}><legend>{label} entry {i + 1}</legend>
          {Object.entries({ heading: 'Title / qualification / skill', organization: 'Organization or proficiency', dates: 'Dates (optional; free text)', details: 'Details' }).map(([field, fieldLabel]) => <label key={field}>{fieldLabel}{field === 'details' ? <textarea maxLength={4000} value={entry[field] || ''} onChange={e => entries(section, rows.map((r, n) => n === i ? { ...r, [field]: e.target.value } : r))} /> : <input maxLength={field === 'dates' ? 100 : 200} value={entry[field] || ''} onChange={e => entries(section, rows.map((r, n) => n === i ? { ...r, [field]: e.target.value } : r))} />}</label>)}
          <div className="resume-actions"><button type="button" disabled={i === 0} aria-label={`Move ${label} entry ${i + 1} up`} onClick={() => { const next = [...rows]; [next[i - 1], next[i]] = [next[i], next[i - 1]]; entries(section, next); }}>Move up</button>
          <button type="button" disabled={i === rows.length - 1} aria-label={`Move ${label} entry ${i + 1} down`} onClick={() => { const next = [...rows]; [next[i + 1], next[i]] = [next[i], next[i + 1]]; entries(section, next); }}>Move down</button>
          <button type="button" aria-label={`Remove ${label} entry ${i + 1}`} onClick={() => entries(section, rows.filter((_, n) => n !== i))}>Remove entry</button></div>
        </fieldset>)}
        <button type="button" disabled={(draft.content[section]?.length || 0) >= 20} onClick={() => entries(section, [...(draft.content[section] || []), { heading: '', organization: '', dates: '', details: '' }])}>Add {label}</button>
      </fieldset>)}
      <div className="resume-actions"><button className="primary-button" disabled={busy} type="submit">{busy ? 'Please wait…' : 'Save resume and preview'}</button>{preview && <button type="button" disabled={busy} onClick={() => previewHeading.current?.focus()}>Go to saved preview</button>}<button type="button" disabled={busy} onClick={() => { if (canLeave()) { setDraft(null); setDirty(false); setPreview(''); } }}>Close editor</button></div>
      </form></div><aside className="resume-preview-panel" aria-label="Saved resume preview and export">
      <h2 ref={previewHeading} tabIndex={-1}>Preview and export</h2>
      {draft.id && <button disabled={busy || dirty} onClick={() => run(() => downloadBuiltResume(`/applicant/resumes/${draft.id}/pdf`, `${draft.title}-v${draft.version}`))}>Download saved PDF</button>}
      {dirty && <p>Save changes before downloading the updated version. The preview shows the last saved version.</p>}
      {preview ? <section><h3>Saved resume preview</h3><p>The PDF uses this document. Page breaks may differ from this continuous preview. Editor accessibility settings do not change print typography.</p><iframe title="Saved resume preview" className="resume-preview" sandbox="" srcDoc={preview} /></section> : <p>Save your resume to see the preview here. Completely empty sections are omitted.</p>}
      </aside></div>
    </section>}
    {confirm && <ReviewDialog title={confirm.kind === 'delete' ? `Delete ${confirm.resume.title}?` : 'Import profile information?'} onClose={() => setConfirm(null)} busy={busy}>
      <p>{confirm.kind === 'delete' ? 'This deletes your personal resume. Shared application snapshots remain available until you revoke them from Your applications. Unsaved edits to this resume will be discarded.' : 'Import name, email, location, summary, skills and combined experience. Matching resume fields will be replaced. PWD identification, private phone and accommodation discussions are excluded. Review all imported text before sharing; your profile stays unchanged.'}</p>
      {error && <p role="alert">{error}</p>}
      <div className="action-row"><button type="button" disabled={busy} onClick={() => setConfirm(null)}>Cancel</button><button className={confirm.kind === 'delete' ? 'button-danger' : 'primary-button'} type="button" disabled={busy} onClick={confirmAction}>{confirm.kind === 'delete' ? 'Confirm delete resume' : 'Confirm import'}</button></div>
    </ReviewDialog>}
  </main>;
}
