import { useMemo, useState } from "react";
import { Link } from "react-router-dom";
import { useAuth } from "../context/AuthContext.jsx";
import { useUi } from "../context/UiContext.jsx";
import { apiErrorMessage } from "../services/api.js";
import { useResource } from "../hooks/useResource.js";
import RequestState from "../components/RequestState.jsx";
import { accommodationOptions } from "../services/filters.js";
import {
  createEmployerJob,
  fetchEmployerJobs,
  updateEmployerJob,
  updateEmployerJobStatus,
} from "../services/jobs.js";

const EMPTY = [];
const emptyListing = {
  title: "",
  location: "",
  type: "Full Time",
  mode: "Remote",
  description: "",
  status: "Open",
  accommodations: [],
  flexibleSchedule: false,
};

export default function EmployerListingsEnhancedPage() {
  const { user } = useAuth();
  const { showToast } = useUi();
  const resource = useResource(fetchEmployerJobs, user.id);
  const listings = resource.data || EMPTY;
  const setListings = resource.setData;
  const [error, setError] = useState("");
  const [draft, setDraft] = useState(emptyListing);
  const [editingId, setEditingId] = useState(null);
  const [filter, setFilter] = useState("All");
  const [showForm, setShowForm] = useState(false);
  const [saving, setSaving] = useState(false);

  const visible = useMemo(
    () => listings.filter((item) => filter === "All" || item.status === filter),
    [listings, filter],
  );
  const updateDraft = (field, value) =>
    setDraft((current) => ({ ...current, [field]: value }));
  const resetForm = () => {
    setDraft(emptyListing);
    setEditingId(null);
    setShowForm(false);
  };
  const beginEdit = (listing) => {
    setDraft({ ...listing });
    setEditingId(listing.id);
    setShowForm(true);
    window.scrollTo({ top: 0 });
  };

  async function saveListing(event) {
    event.preventDefault();
    setSaving(true);
    setError("");
    try {
      const saved = editingId
        ? await updateEmployerJob(editingId, draft)
        : await createEmployerJob(draft);
      setListings((current) =>
        editingId
          ? current.map((item) => (item.id === editingId ? saved : item))
          : [saved, ...(current || [])],
      );
      showToast(
        editingId ? "Listing updated" : "Listing created",
        "The change is now visible to jobseekers.",
      );
      resetForm();
    } catch (e) {
      setError(apiErrorMessage(e));
    } finally {
      setSaving(false);
    }
  }

  async function toggleStatus(listing) {
    setError("");
    setSaving(true);
    const status = listing.status === "Open" ? "Closed" : "Open";
    try {
      const updated = await updateEmployerJobStatus(listing.id, status);
      setListings((current) =>
        current.map((item) => (item.id === listing.id ? updated : item)),
      );
    } catch (e) {
      setError(apiErrorMessage(e));
    } finally {
      setSaving(false);
    }
  }

  return (
    <main className="bg-brand-gray-bg py-12">
      <div className="max-w-5xl mx-auto px-6">
        <RequestState resource={resource} />
        {error && <p role="alert">{error}</p>}
        <Link
          to="/employer/profile"
          className="text-brand-teal font-bold hover:underline"
        >
          ← Company profile
        </Link>
        <div className="flex flex-col sm:flex-row sm:items-end justify-between gap-4 mt-5 mb-7">
          <div>
            <p className="text-xs font-black uppercase tracking-widest text-brand-teal">
              Reach inclusive talent
            </p>
            <h1 className="text-3xl font-black text-brand-dark">
              Job listings
            </h1>
            <p className="text-gray-600 mt-1">
              Create and maintain clear roles with clear accessibility
              expectations.
            </p>
          </div>
          <button
            type="button"
            onClick={() => (showForm ? resetForm() : setShowForm(true))}
            className="bg-brand-teal hover:bg-brand-teal-hover text-white font-black px-5 py-3 rounded-xl"
          >
            {showForm ? "Close form" : "+ Post a job"}
          </button>
        </div>
        {showForm && (
          <form
            onSubmit={saveListing}
            className="bg-white rounded-2xl border border-teal-100 shadow-soft-lg p-6 mb-6 space-y-4"
          >
            <h2 className="text-lg font-black text-brand-teal">
              {editingId ? "Edit job listing" : "New job listing"}
            </h2>
            <div className="grid sm:grid-cols-2 gap-4">
              <label>
                <span className="form-label">Job title</span>
                <input
                  required
                  value={draft.title}
                  onChange={(event) => updateDraft("title", event.target.value)}
                  className="form-input"
                />
              </label>
              <label>
                <span className="form-label">Location</span>
                <input
                  required
                  value={draft.location}
                  onChange={(event) =>
                    updateDraft("location", event.target.value)
                  }
                  className="form-input"
                />
              </label>
              <label>
                <span className="form-label">Employment type</span>
                <select
                  value={draft.type}
                  onChange={(event) => updateDraft("type", event.target.value)}
                  className="form-input"
                >
                  <option>Full Time</option>
                  <option>Part Time</option>
                  <option>Contract</option>
                </select>
              </label>
              <label>
                <span className="form-label">Work mode</span>
                <select
                  value={draft.mode}
                  onChange={(event) => updateDraft("mode", event.target.value)}
                  className="form-input"
                >
                  <option>Remote</option>
                  <option>Hybrid</option>
                  <option>Onsite</option>
                </select>
              </label>
            </div>
            <label>
              <span className="form-label">Role description</span>
              <textarea
                required
                value={draft.description}
                onChange={(event) =>
                  updateDraft("description", event.target.value)
                }
                className="form-input min-h-28 py-3"
              />
            </label>
            <div className="flex flex-col gap-3">
              <button
                type="button"
                onClick={resetForm}
                className="border border-gray-300 text-brand-dark font-black px-6 py-3 rounded-xl"
              >
                Cancel
              </button>
              <fieldset className="sm:col-span-2">
                <legend>Employer-reported accommodations</legend>
                <p>
                  Select only support you can provide. Older labels remain
                  visible for review; replace them with the standardized options
                  before saving.
                </p>
                {[
                  ...new Set([
                    ...accommodationOptions,
                    ...draft.accommodations.map((item) => item.label || item),
                  ]),
                ].map((label) => (
                  <label key={label} className="filter-option">
                    <input
                      type="checkbox"
                      checked={draft.accommodations.some(
                        (item) => (item.label || item) === label,
                      )}
                      onChange={(e) =>
                        updateDraft(
                          "accommodations",
                          e.target.checked
                            ? [...draft.accommodations, { label }]
                            : draft.accommodations.filter(
                                (item) => (item.label || item) !== label,
                              ),
                        )
                      }
                    />
                    {label}
                  </label>
                ))}
              </fieldset>
              <label className="filter-option">
                <input
                  type="checkbox"
                  checked={draft.flexibleSchedule}
                  onChange={(e) =>
                    updateDraft("flexibleSchedule", e.target.checked)
                  }
                />
                Flexible schedule
              </label>
              <button
                type="submit"
                disabled={saving}
                className="bg-brand-blue text-white font-black px-6 py-3 rounded-xl"
              >
                {saving
                  ? "Saving..."
                  : editingId
                    ? "Save changes"
                    : "Publish listing"}
              </button>
            </div>
          </form>
        )}
        <div className="flex flex-wrap gap-2 mb-6">
          {["All", "Open", "Closed"].map((item) => (
            <button
              key={item}
              type="button"
              aria-pressed={filter === item}
              onClick={() => setFilter(item)}
              className={`px-4 py-2 rounded-full text-sm font-extrabold border ${filter === item ? "bg-brand-teal text-white border-brand-teal" : "bg-white text-brand-dark border-gray-200"}`}
            >
              {item}
            </button>
          ))}
        </div>
        {!resource.loading && !resource.error && visible.length === 0 && (
          <p>No matching job listings.</p>
        )}
        <div className="space-y-4">
          {visible.map((listing) => (
            <article
              key={listing.id}
              className="bg-white rounded-2xl border border-gray-200 shadow-soft-lg p-5 sm:p-6"
            >
              <div className="flex flex-col sm:flex-row sm:items-start justify-between gap-4">
                <div>
                  <span className="text-xs font-extrabold uppercase tracking-wider text-gray-500">
                    ● {listing.status}
                  </span>
                  <h2 className="text-xl font-black text-brand-dark mt-2">
                    {listing.title}
                  </h2>
                  <p className="text-sm font-semibold text-gray-600 mt-1">
                    {listing.location} · {listing.type} · {listing.mode}
                  </p>
                  <p className="text-sm text-gray-600 mt-3 max-w-2xl">
                    {listing.description}
                  </p>
                </div>
                <div className="flex gap-2 self-start">
                  <button
                    type="button"
                    onClick={() => beginEdit(listing)}
                    className="border border-brand-blue text-brand-blue font-extrabold px-4 py-2 rounded-xl"
                  >
                    Edit
                  </button>
                  <button
                    type="button"
                    disabled={saving || resource.loading}
                    onClick={() => toggleStatus(listing)}
                    className="border border-gray-300 text-gray-700 font-extrabold px-4 py-2 rounded-xl"
                  >
                    {listing.status === "Open"
                      ? "Close listing"
                      : "Reopen listing"}
                  </button>
                </div>
              </div>
            </article>
          ))}
        </div>
      </div>
    </main>
  );
}
