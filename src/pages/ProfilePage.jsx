import { useState } from "react";
import api, { apiErrorMessage } from "../services/api.js";
import { useAuth } from "../context/AuthContext.jsx";
import { useResource } from "../hooks/useResource.js";
import RequestState from "../components/RequestState.jsx";
import { accommodationOptions } from "../services/filters.js";
import { requestEmployerReview, fetchVerificationHistory } from "../services/moderation.js";
import HistoryList from "../components/HistoryList.jsx";
import PageHeader from "../components/PageHeader.jsx";
export default function ProfilePage() {
  const { user } = useAuth();
  const resource = useResource(
    async () => (await api.get("/profile")).data.profile,
    user.id,
  );
  return (
    <main className="page-shell">
      <PageHeader eyebrow="Make a clear first impression" title={user.role === "employer" ? "Company profile" : "Your profile"} icon="user" description="Keep your professional story and preferences up to date." />
      <RequestState resource={resource} />
      {resource.data && (
        <Editor
          key={user.id}
          initial={resource.data}
          employer={user.role === "employer"}
        />
      )}
    </main>
  );
}
function Editor({ initial, employer }) {
  const [profile, setProfile] = useState(initial),
    [busy, setBusy] = useState(false),
    [error, setError] = useState(""),
    [message, setMessage] = useState("");
  const update = (field, value) =>
    setProfile((p) => ({ ...p, [field]: value }));
  async function requestReview() {
    setBusy(true); setError(""); setMessage("");
    try {
      await requestEmployerReview(profile.reviewVersion);
      setProfile((await api.get("/profile")).data.profile);
      setMessage("Your company is back in the verification queue.");
    } catch (exception) { setError(apiErrorMessage(exception)); }
    finally { setBusy(false); }
  }
  async function save(event) {
    event.preventDefault();
    setBusy(true);
    setError("");
    setMessage("");
    try {
      setProfile((await api.put("/profile", profile)).data.profile);
      setMessage("Profile saved to your account.");
    } catch (e) {
      setError(apiErrorMessage(e));
    } finally {
      setBusy(false);
    }
  }
  const fields = employer
    ? [
        ["companyName", "Company name"],
        ["website", "Website"],
        ["location", "Location"],
        ["description", "About your company"],
      ]
    : [
        ["name", "Full name"],
        ["headline", "Professional headline"],
        ["location", "Preferred job location (optional, exact city or location)"],
        ["bio", "Professional summary"],
        ["experience", "Relevant work or volunteer experience"],
      ];
  return (
    <form onSubmit={save} className="panel profile-form space-y-5" aria-busy={busy}>
      {error && <p role="alert">{error}</p>}
      <p role="status" className={message ? 'request-feedback status-positive' : undefined}>{message}</p>
      {employer ? (
        <div>
        <p>
          Employer verification: {profile.verificationStatus}. This does not
          verify individual accommodations.
        </p>
        {profile.verificationReason && <p>Review reason: {profile.verificationReason}</p>}
        <p>Changes to verified company details require another review. Save corrected details before requesting review of a rejected registration.</p>
        {profile.verificationStatus === "rejected" && <button type="button" onClick={requestReview} disabled={busy}>Request another verification review</button>}
        </div>
      ) : (
        <p>Account email: {profile.email}</p>
      )}
      <section className="profile-section"><h2>{employer ? 'Company information' : 'Professional information'}</h2><p>{employer ? 'Help applicants understand your organization and the work you do.' : 'Tell employers about your strengths, experience and professional interests.'}</p><div className="profile-field-grid">{fields.map(([field, label]) => (
        <label className="block" key={field}>
          <span className="form-label">{label}</span>
          {["bio", "description", "experience"].includes(field) ? (
            <textarea
              className="form-input"
              maxLength={10000}
              value={profile[field]}
              onChange={(e) => update(field, e.target.value)}
            />
          ) : (
            <input
              className="form-input"
              type={field === "website" ? "url" : "text"}
              required={["name", "companyName"].includes(field)}
              maxLength={255}
              value={profile[field]}
              onChange={(e) => update(field, e.target.value)}
            />
          )}
        </label>
      ))}</div></section>
      {!employer && (
        <section className="profile-section"><h2>Skills &amp; work preferences</h2>
          <p>Skills and work/location preferences improve Fit &amp; Access Match. Summary and experience help employers understand your background; they are not scored. Accommodation disclosure is optional and never changes your score.</p>
          <label className="block"><span className="form-label">Preferred work arrangement (optional)</span><select className="form-input" value={profile.preferredWorkMode || ""} onChange={(e) => update("preferredWorkMode", e.target.value)}><option value="">No preference</option>{["Remote", "Hybrid", "Onsite"].map(mode => <option key={mode}>{mode}</option>)}</select></label>
          <label className="block">
            <span className="form-label">Skills (comma separated)</span>
            <input
              className="form-input"
              value={profile.skills.join(",")}
              onChange={(e) => update("skills", e.target.value.split(","))}
            />
          </label>
          <fieldset className="profile-privacy">
            <legend>Requested accommodations (optional)</legend>
            <p>These stay private unless you choose to share them for an application. Professional summaries and experience are visible to employers you apply to; do not include medical diagnoses.</p>
            {[
              ...new Set([...accommodationOptions, ...profile.accommodations]),
            ].map((label) => (
              <label className="filter-option" key={label}>
                <input
                  type="checkbox"
                  checked={profile.accommodations.includes(label)}
                  onChange={(e) =>
                    update(
                      "accommodations",
                      e.target.checked
                        ? [...profile.accommodations, label]
                        : profile.accommodations.filter((v) => v !== label),
                    )
                  }
                />
                {label}
              </label>
            ))}
          </fieldset>
        </section>
      )}
      <button className="primary-button" disabled={busy}>
        {busy ? "Saving..." : "Save profile"}
      </button>
      {employer && <HistoryList fetcher={fetchVerificationHistory} scope={profile.reviewVersion} title="Company verification history" />}
    </form>
  );
}
