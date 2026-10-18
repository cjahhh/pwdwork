import { useState } from "react";
import { Link } from "react-router-dom";
import { useAuth } from "../context/AuthContext.jsx";
import { useUi } from "../context/UiContext.jsx";
import { getEmployerProfile, saveEmployerProfile } from "../services/employer.js";

function EmployerProfilePage() {
  const { user } = useAuth();
  const { showToast } = useUi();
  const [profile, setProfile] = useState(() => getEmployerProfile(user));
  const update = (field, value) => setProfile((current) => ({ ...current, [field]: value }));
  function save(event) { event.preventDefault(); saveEmployerProfile(user, profile); showToast("Company profile saved", "Your employer profile has been updated."); }
  return (
    <main className="bg-brand-gray-bg py-12"><div className="max-w-4xl mx-auto px-6">
      <Link to="/employer" className="text-brand-teal font-bold hover:underline">← Back to dashboard</Link>
      <div className="mt-5 mb-7"><p className="text-xs font-black uppercase tracking-widest text-brand-teal">Your employer presence</p><h1 className="text-3xl font-black text-brand-dark">Company profile</h1><p className="text-gray-600 mt-1">Give jobseekers a clear picture of your workplace and inclusive hiring practices.</p></div>
      <form onSubmit={save} className="bg-white rounded-3xl p-6 sm:p-8 border border-gray-200 shadow-soft-xl space-y-7">
        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 bg-teal-50 border border-teal-100 rounded-2xl p-4"><div><p className="text-xs font-black uppercase tracking-wider text-brand-teal">Verification status</p><p className="font-black text-brand-dark mt-1">{profile.verificationStatus}</p></div><span className={`bg-white border rounded-full px-3 py-1 text-sm font-extrabold ${String(profile.verificationStatus).toLowerCase() === "verified" ? "text-brand-teal border-teal-200" : "text-gray-600 border-gray-300"}`}>{String(profile.verificationStatus).toLowerCase() === "verified" ? "✓ Verified employer" : "Verification pending"}</span></div>
        <section><h2 className="text-lg font-black text-brand-teal mb-4">Company information</h2><div className="grid sm:grid-cols-2 gap-4"><label className="block"><span className="form-label">Company name</span><input value={profile.companyName} onChange={(event) => update("companyName", event.target.value)} className="form-input" required /></label><label className="block"><span className="form-label">Website</span><input value={profile.website} onChange={(event) => update("website", event.target.value)} className="form-input" placeholder="https://example.com" type="url" /></label><label className="block sm:col-span-2"><span className="form-label">Office or hiring location</span><input value={profile.location} onChange={(event) => update("location", event.target.value)} className="form-input" placeholder="e.g. Remote (Global), Manila" /></label></div><label className="block mt-4"><span className="form-label">About your company</span><textarea value={profile.description} onChange={(event) => update("description", event.target.value)} className="form-input min-h-32 py-3" required /></label></section>
        <div className="flex justify-end border-t border-gray-100 pt-6"><button type="submit" className="bg-brand-teal hover:bg-brand-teal-hover text-white font-black px-7 py-3 rounded-xl shadow-soft-lg">Save company profile</button></div>
      </form>
    </div></main>
  );
}

export default EmployerProfilePage;
