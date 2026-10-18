import { useState } from "react";
import { Link } from "react-router-dom";
import { useAuth } from "../context/AuthContext.jsx";
import { useUi } from "../context/UiContext.jsx";
import { getApplicantProfile, saveApplicantProfile } from "../services/applicant.js";

const accommodationOptions = ["Remote work", "Flexible hours", "Wheelchair access", "Screen reader support", "Sign language support"];

function ApplicantProfilePage() {
  const { user } = useAuth();
  const { showToast } = useUi();
  const [profile, setProfile] = useState(() => getApplicantProfile(user));
  const [skills, setSkills] = useState(profile.skills.join(", "));
  const update = (field, value) => setProfile((current) => ({ ...current, [field]: value }));
  const toggleAccommodation = (item) => update("accommodations", profile.accommodations.includes(item) ? profile.accommodations.filter((value) => value !== item) : [...profile.accommodations, item]);
  function save(event) {
    event.preventDefault();
    saveApplicantProfile(user, { ...profile, skills: skills.split(",").map((skill) => skill.trim()).filter(Boolean) });
    showToast("Profile saved", "Your applicant profile has been updated.");
  }

  return (
    <main className="bg-brand-gray-bg py-12">
      <div className="max-w-4xl mx-auto px-6">
        <Link to="/applicant" className="text-brand-teal font-bold hover:underline">← Back to dashboard</Link>
        <div className="mt-5 mb-7"><p className="text-xs font-black uppercase tracking-widest text-brand-teal">Your career profile</p><h1 className="text-3xl font-black text-brand-dark">Profile</h1><p className="text-gray-600 mt-1">Help inclusive employers understand your strengths and support needs.</p></div>
        <form onSubmit={save} className="bg-white rounded-3xl p-6 sm:p-8 border border-gray-200 shadow-soft-xl space-y-7">
          <section><h2 className="text-lg font-black text-brand-blue mb-4">About you</h2><div className="grid sm:grid-cols-2 gap-4">
            <label className="block"><span className="form-label">Full name</span><input value={profile.name} onChange={(event) => update("name", event.target.value)} className="form-input" required /></label>
            <label className="block"><span className="form-label">Email</span><input value={profile.email} onChange={(event) => update("email", event.target.value)} className="form-input" type="email" required /></label>
            <label className="block"><span className="form-label">Professional headline</span><input value={profile.headline} onChange={(event) => update("headline", event.target.value)} className="form-input" placeholder="e.g. Junior data analyst" /></label>
            <label className="block"><span className="form-label">Location</span><input value={profile.location} onChange={(event) => update("location", event.target.value)} className="form-input" placeholder="e.g. Manila or Remote" /></label>
          </div><label className="block mt-4"><span className="form-label">Short bio</span><textarea value={profile.bio} onChange={(event) => update("bio", event.target.value)} className="form-input min-h-28 py-3" placeholder="Tell employers about your experience and the work you enjoy." /></label></section>
          <section className="border-t border-gray-100 pt-6"><h2 className="text-lg font-black text-brand-blue mb-1">Skills</h2><p className="text-sm text-gray-500 mb-3">Separate skills with commas.</p><input value={skills} onChange={(event) => setSkills(event.target.value)} className="form-input" placeholder="Customer support, Excel, Accessibility testing" /></section>
          <section className="border-t border-gray-100 pt-6"><h2 className="text-lg font-black text-brand-blue mb-1">Workplace accommodations</h2><p className="text-sm text-gray-500 mb-4">Select the support you would like employers to plan for.</p><div className="grid sm:grid-cols-2 gap-3">{accommodationOptions.map((item) => <label key={item} className="flex items-center gap-3 rounded-xl border border-gray-200 px-4 py-3 font-semibold text-brand-dark cursor-pointer hover:border-brand-blue"><input type="checkbox" checked={profile.accommodations.includes(item)} onChange={() => toggleAccommodation(item)} className="w-4 h-4 accent-brand-blue" />{item}</label>)}</div></section>
          <div className="flex justify-end border-t border-gray-100 pt-6"><button type="submit" className="bg-brand-blue hover:bg-brand-blue-dark text-white font-black px-7 py-3 rounded-xl shadow-soft-lg">Save profile</button></div>
        </form>
      </div>
    </main>
  );
}

export default ApplicantProfilePage;
