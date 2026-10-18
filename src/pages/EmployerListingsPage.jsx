import { useEffect, useMemo, useState } from "react";
import { Link } from "react-router-dom";
import { useAuth } from "../context/AuthContext.jsx";
import { useUi } from "../context/UiContext.jsx";
import { getEmployerListings, saveEmployerListings } from "../services/employer.js";
import { createEmployerJob, dedupeListings, fetchEmployerJobs, updateEmployerJob, updateEmployerJobStatus } from "../services/jobs.js";
import { useAutoRefresh } from "../hooks/useAutoRefresh.js";

const emptyListing = { title: "", location: "", type: "Full Time", mode: "Remote", description: "", status: "Open" };

function EmployerListingsPage() {
  const { user } = useAuth();
  const { showToast } = useUi();
  const [listings, setListings] = useState(() => getEmployerListings(user).filter((listing) => String(listing.id).startsWith("local-")));
  const [filter, setFilter] = useState("All");
  const [draft, setDraft] = useState(emptyListing);
  const [editingId, setEditingId] = useState(null);
  const [showForm, setShowForm] = useState(false);
  const [saving, setSaving] = useState(false);
  useEffect(() => {
    fetchEmployerJobs().then(async (serverListings) => {
      const localListings = getEmployerListings(user).filter((listing) => String(listing.id).startsWith("local-"));
      const pendingLocalListings = localListings.filter((listing) => !serverListings.some((serverListing) => serverListing.title === listing.title));
      if (pendingLocalListings.length) {
        const migrated = await Promise.all(pendingLocalListings.map((listing) => createEmployerJob(listing).catch(() => null)));
        setListings(dedupeListings([...migrated.filter(Boolean), ...serverListings]));
      } else {
        setListings(serverListings);
      }
    }).catch(() => {});
  }, []);
  useAutoRefresh(fetchEmployerJobs, (serverListings) => setListings(dedupeListings(serverListings)));
  const visible = useMemo(() => listings.filter((listing) => filter === "All" || listing.status === filter), [listings, filter]);
  function updateDraft(field, value) { setDraft((current) => ({ ...current, [field]: value })); }
  function editListing(listing) { setEditingId(listing.id); setDraft({ ...listing }); setShowForm(true); window.scrollTo({ top: 0, behavior: "smooth" }); }
  function closeForm() { setEditingId(null); setDraft(emptyListing); setShowForm(false); }
  async function createListing(event) { event.preventDefault(); setSaving(true); try { if (editingId) { const updated = await updateEmployerJob(editingId, draft); setListings((current) => current.map((item) => item.id === editingId ? updated : item)); showToast("Listing updated", "Your changes are now visible to jobseekers."); } else { const created = await createEmployerJob(draft); setListings((current) => dedupeListings([created, ...current])); showToast("Listing created", "Your job is now visible to applicants and in Find Jobs."); } closeForm(); } catch { const local = { ...draft, id: editingId || `local-${Date.now()}`, company: user?.company_name || "Your company", companyLogo: "CO", companyColor: "bg-teal-600", tagList: [draft.mode], accommodations: [] }; const next = dedupeListings(editingId ? listings.map((item) => item.id === editingId ? local : item) : [local, ...listings]); setListings(next); saveEmployerListings(user, next); closeForm(); showToast("Saved locally", "The API is unavailable, so this change is only visible in this browser."); } finally { setSaving(false); } }
  async function toggleStatus(listing) { const nextStatus = listing.status === "Open" ? "Closed" : "Open"; try { const updated = await updateEmployerJobStatus(listing.id, nextStatus); setListings((current) => current.map((item) => item.id === listing.id ? updated : item)); } catch { const next = listings.map((item) => item.id === listing.id ? { ...item, status: nextStatus } : item); setListings(next); saveEmployerListings(user, next); } }
  return (
    <main className="bg-brand-gray-bg py-12"><div className="max-w-5xl mx-auto px-6">
      <Link to="/employer" className="text-brand-teal font-bold hover:underline">← Back to dashboard</Link>
      <div className="flex flex-col sm:flex-row sm:items-end justify-between gap-4 mt-5 mb-7"><div><p className="text-xs font-black uppercase tracking-widest text-brand-teal">Reach inclusive talent</p><h1 className="text-3xl font-black text-brand-dark">Job listings</h1><p className="text-gray-600 mt-1">Create clear roles with verified accessibility expectations.</p></div><button type="button" onClick={() => setShowForm((current) => !current)} className="bg-brand-teal hover:bg-brand-teal-hover text-white font-black px-5 py-3 rounded-xl">{showForm ? "Close form" : "+ Post a job"}</button></div>
      {showForm ? <form onSubmit={createListing} className="bg-white rounded-2xl border border-teal-100 shadow-soft-lg p-6 mb-6 space-y-4"><h2 className="text-lg font-black text-brand-teal">New job listing</h2><div className="grid sm:grid-cols-2 gap-4"><label><span className="form-label">Job title</span><input required value={draft.title} onChange={(event) => updateDraft("title", event.target.value)} className="form-input" placeholder="e.g. Accessibility QA Engineer" /></label><label><span className="form-label">Location</span><input required value={draft.location} onChange={(event) => updateDraft("location", event.target.value)} className="form-input" placeholder="Remote or Manila" /></label><label><span className="form-label">Employment type</span><select value={draft.type} onChange={(event) => updateDraft("type", event.target.value)} className="form-input"><option>Full Time</option><option>Part Time</option><option>Contract</option></select></label><label><span className="form-label">Work mode</span><select value={draft.mode} onChange={(event) => updateDraft("mode", event.target.value)} className="form-input"><option>Remote</option><option>Hybrid</option><option>Onsite</option></select></label></div><label><span className="form-label">Role description</span><textarea required value={draft.description} onChange={(event) => updateDraft("description", event.target.value)} className="form-input min-h-28 py-3" placeholder="Describe the responsibilities and inclusive workplace support." /></label><div className="flex justify-end"><button type="submit" disabled={saving} className="bg-brand-blue text-white font-black px-6 py-3 rounded-xl disabled:opacity-60">{saving ? "Publishing..." : "Publish listing"}</button></div></form> : null}
      <div className="flex flex-wrap gap-2 mb-6">{["All", "Open", "Closed"].map((item) => <button key={item} type="button" onClick={() => setFilter(item)} className={`px-4 py-2 rounded-full text-sm font-extrabold border ${filter === item ? "bg-brand-teal text-white border-brand-teal" : "bg-white text-brand-dark border-gray-200 hover:border-brand-teal"}`}>{item}</button>)}</div>
      <div className="space-y-4">{visible.map((listing) => <article key={listing.id} className="bg-white rounded-2xl border border-gray-200 shadow-soft-lg p-5 sm:p-6"><div className="flex flex-col sm:flex-row sm:items-start justify-between gap-4"><div><div className="flex items-center gap-2"><span className={`w-2.5 h-2.5 rounded-full ${listing.status === "Open" ? "bg-brand-teal" : "bg-gray-400"}`} /><span className="text-xs font-extrabold uppercase tracking-wider text-gray-500">{listing.status}</span></div><h2 className="text-xl font-black text-brand-dark mt-2">{listing.title}</h2><p className="text-sm font-semibold text-gray-600 mt-1">{listing.location} · {listing.type} · {listing.mode}</p><p className="text-sm text-gray-600 mt-3 max-w-2xl">{listing.description}</p></div><button type="button" onClick={() => toggleStatus(listing)} className="self-start border border-gray-300 text-gray-700 hover:border-brand-teal hover:text-brand-teal font-extrabold px-4 py-2 rounded-xl">{listing.status === "Open" ? "Close listing" : "Reopen listing"}</button></div></article>)}</div>
    </div></main>
  );
}

export default EmployerListingsPage;
