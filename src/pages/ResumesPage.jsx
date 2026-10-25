import { useCallback, useEffect, useRef, useState } from 'react';
import { Link, useBlocker } from 'react-router-dom';
import api, { apiErrorMessage } from '../services/api.js';
import { downloadBuiltResume, resumePreview } from '../services/resumes.js';
import './ResumesPage.css';

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
  const heading = useRef(null);
  const blocker = useBlocker(dirty);
  useEffect(() => {
    if (blocker.state === 'blocked') {
      if (window.confirm('Discard unsaved resume changes?')) blocker.proceed();
      else blocker.reset();
    }
  }, [blocker]);
  const load = useCallback(async () => {
    const { data } = await api.get('/applicant/resumes', { params: { page } });
    setList(data.resumes); setLastPage(data.meta.last_page);
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
      setDraft(data.resume); setDirty(false); setStatus('Resume saved. Preview updated.');
      setPreview(await resumePreview(`/applicant/resumes/${data.resume.id}/preview`)); await load();
    });
  }
  return <main className="page-shell resume-builder">
    <h1>My resumes</h1>
    <p>Create a resume, choose a template, save and preview, then download or share it from <Link to="/applicant/applications">your applications</Link>. Employment history is optional. Include only information you want employers to see.</p>
    <p>Creating a resume does not share it. Resume edits never modify your profile.</p>
    {error && <p role="alert" className="resume-error">{error}</p>}<p role="status" aria-live="polite">{status}</p>
    <button type="button" disabled={busy} onClick={create}>Create Resume</button>
    <section aria-label="Saved resumes">
      {!list.length && <p>No saved resumes on this page. Create your first resume to get started.</p>}
      {list.map(r => <article key={r.id} className="panel"><h2>{r.title}</h2><p>Version {r.version} · Updated {new Date(r.updated_at).toLocaleString()}</p>
        <div className="resume-actions"><button disabled={busy} onClick={() => edit(r.id)}>Edit / Preview {r.title}</button>
        <button disabled={busy} onClick={() => run(() => downloadBuiltResume(`/applicant/resumes/${r.id}/pdf`, `${r.title}-v${r.version}`))}>Download PDF</button>
        <button disabled={busy} onClick={() => run(async () => { await api.post(`/applicant/resumes/${r.id}/duplicate`); await load(); setStatus('Resume duplicated.'); })}>Duplicate</button>
        <button disabled={busy} onClick={() => { if (window.confirm(`Delete ${r.title}? Shared application snapshots remain until revoked from your applications.`)) run(async () => { await api.delete(`/applicant/resumes/${r.id}`); if (draft?.id === r.id) { setDraft(null); setDirty(false); setPreview(''); } await load(); setStatus('Resume deleted.'); }); }}>Delete</button>
        <Link to="/applicant/applications">Share with application</Link></div></article>)}
      <nav aria-label="Resume pages"><button disabled={busy || page <= 1} onClick={() => setPage(page - 1)}>Previous</button> Page {page} of {lastPage} <button disabled={busy || page >= lastPage} onClick={() => setPage(page + 1)}>Next</button></nav>
    </section>
    {draft && <section className="panel"><h2 tabIndex={-1} ref={heading}>Resume editor</h2>
      <p>All sections except your name are optional. Use Move up and Move down to reorder entries. Save to update the preview.</p>
      <button type="button" disabled={busy} onClick={() => {
        if (window.confirm('Import your name, email, location, summary, skills and combined work/volunteer experience? These fields will replace matching resume fields. Private accommodation details and PWD identification are excluded. Review imported text before sharing.')) run(async () => {
          const { data } = await api.post('/applicant/resumes/import-profile', { consent: true });
          change({ ...draft, content: { ...draft.content, ...data.content, personal: { ...draft.content.personal, ...data.content.personal } } });
        });
      }}>Import profile information</button>
      <form onSubmit={save}><fieldset disabled={busy}><legend>Resume settings</legend>
        <label>Resume title<input required maxLength={120} value={draft.title} onChange={e => change({ ...draft, title: e.target.value })} /></label>
        <label>Template<select value={draft.template} onChange={e => change({ ...draft, template: e.target.value })}><option value="classic">Classic Professional</option><option value="modern">Modern Minimal</option></select></label>
      </fieldset><fieldset disabled={busy}><legend>Personal and contact details</legend>
        {Object.entries({ name: 'Full name (required)', email: 'Email', phone: 'Phone', location: 'Location', website: 'Website (https://...)' }).map(([field, label]) => <label key={field}>{label}<input required={field === 'name'} type={field === 'email' ? 'email' : field === 'website' ? 'url' : 'text'} maxLength={field === 'name' ? 160 : field === 'email' ? 254 : field === 'phone' ? 60 : field === 'website' ? 300 : 255} value={draft.content.personal[field] || ''} onChange={e => personal(field, e.target.value)} /></label>)}
      </fieldset><label>Professional summary or career objective<textarea disabled={busy} maxLength={4000} value={draft.content.summary || ''} onChange={e => change({ ...draft, content: { ...draft.content, summary: e.target.value } })} /></label>
      {Object.entries(sections).map(([section, label]) => <fieldset key={section} disabled={busy}><legend>{label}</legend>
        {(draft.content[section] || []).map((entry, i, rows) => <fieldset key={i}><legend>{label} entry {i + 1}</legend>
          {Object.entries({ heading: 'Title / qualification / skill', organization: 'Organization or proficiency', dates: 'Dates (optional; free text)', details: 'Details' }).map(([field, fieldLabel]) => <label key={field}>{fieldLabel}{field === 'details' ? <textarea maxLength={4000} value={entry[field] || ''} onChange={e => entries(section, rows.map((r, n) => n === i ? { ...r, [field]: e.target.value } : r))} /> : <input maxLength={field === 'dates' ? 100 : 200} value={entry[field] || ''} onChange={e => entries(section, rows.map((r, n) => n === i ? { ...r, [field]: e.target.value } : r))} />}</label>)}
          <div className="resume-actions"><button type="button" disabled={i === 0} aria-label={`Move ${label} entry ${i + 1} up`} onClick={() => { const next = [...rows]; [next[i - 1], next[i]] = [next[i], next[i - 1]]; entries(section, next); }}>Move up</button>
          <button type="button" disabled={i === rows.length - 1} aria-label={`Move ${label} entry ${i + 1} down`} onClick={() => { const next = [...rows]; [next[i + 1], next[i]] = [next[i], next[i + 1]]; entries(section, next); }}>Move down</button>
          <button type="button" aria-label={`Remove ${label} entry ${i + 1}`} onClick={() => entries(section, rows.filter((_, n) => n !== i))}>Remove entry</button></div>
        </fieldset>)}
        <button type="button" disabled={(draft.content[section]?.length || 0) >= 20} onClick={() => entries(section, [...(draft.content[section] || []), { heading: '', organization: '', dates: '', details: '' }])}>Add {label}</button>
      </fieldset>)}
      <div className="resume-actions"><button disabled={busy} type="submit">{busy ? 'Please wait…' : 'Save resume and preview'}</button><button type="button" disabled={busy} onClick={() => { if (canLeave()) { setDraft(null); setDirty(false); setPreview(''); } }}>Close editor</button></div>
      </form>
      {draft.id && <button disabled={busy || dirty} onClick={() => run(() => downloadBuiltResume(`/applicant/resumes/${draft.id}/pdf`, `${draft.title}-v${draft.version}`))}>Download saved PDF</button>}
      {dirty && <p>Save changes before downloading the updated version. The preview shows the last saved version.</p>}
      {preview && <section><h2>Saved resume preview</h2><p>The PDF uses this same document. Page breaks may differ from this continuous preview. Web accessibility preferences apply to the editor; the document uses a fixed print layout.</p><iframe title="Saved resume preview" className="resume-preview" sandbox="" srcDoc={preview} /></section>}
    </section>}
  </main>;
}
