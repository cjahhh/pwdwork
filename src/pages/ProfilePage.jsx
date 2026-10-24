import { useState } from "react";
import api, { apiErrorMessage } from "../services/api.js";
import { useAuth } from "../context/AuthContext.jsx";
import { useResource } from "../hooks/useResource.js";
import RequestState from "../components/RequestState.jsx";
import { accommodationOptions } from "../services/filters.js";
import { requestEmployerReview, fetchVerificationHistory } from "../services/moderation.js";
import HistoryList from "../components/HistoryList.jsx";
export default function ProfilePage() {
  const { user } = useAuth();
  const resource = useResource(
    async () => (await api.get("/profile")).data.profile,
    user.id,
  );
  return (
    <main className="page-shell">
      <h1>{user.role === "employer" ? "Company profile" : "Your profile"}</h1>
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
        ["location", "Location"],
        ["bio", "Professional summary"],
        ["experience", "Relevant work or volunteer experience"],
      ];
  return (
    <form onSubmit={save} className="panel space-y-5" aria-busy={busy}>
      {error && <p role="alert">{error}</p>}
      <p role="status">{message}</p>
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
      {fields.map(([field, label]) => (
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
      ))}
      {!employer && (
        <>
          <label className="block">
            <span className="form-label">Skills (comma separated)</span>
            <input
              className="form-input"
              value={profile.skills.join(",")}
              onChange={(e) => update("skills", e.target.value.split(","))}
            />
          </label>
          <fieldset>
            <legend>Requested accommodations</legend>
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
        </>
      )}
      <button className="primary-button" disabled={busy}>
        {busy ? "Saving..." : "Save profile"}
      </button>
      {employer && <HistoryList fetcher={fetchVerificationHistory} scope={profile.reviewVersion} title="Company verification history" />}
    </form>
  );
}
