import { useState } from "react";
import { Link } from "react-router-dom";
import { useAuth } from "../context/AuthContext.jsx";
import { useUi } from "../context/UiContext.jsx";
import { apiErrorMessage } from "../services/api.js";
import { usePaginatedResource } from "../hooks/usePaginatedResource.js";
import Pagination from "../components/Pagination.jsx";
import EmployerJobFilter from "../components/EmployerJobFilter.jsx";
import RequestState from "../components/RequestState.jsx";
import {
  fetchEmployerApplications,
  downloadApplicantResume,
  scheduleInterview,
  updateEmployerApplication,
} from "../services/jobs.js";

const EMPTY = [];
const statuses = [
  "All",
  "New",
  "Reviewing",
  "Interview",
  "Shortlisted",
  "Hired",
  "Rejected",
];
function csvCell(value) {
  if (/^[=+@\-\t\r]/.test(String(value ?? ""))) value = "'" + value;
  return `"${String(value ?? "").replaceAll('"', '""')}"`;
}

export default function EmployerApplicantsEnhancedPage() {
  const { user } = useAuth();
  const { showToast } = useUi();
  const [filter, setFilter] = useState("All");
  const [query, setQuery] = useState("");
  const [jobFilter, setJobFilter] = useState(null);
  const filters = {
    ...(filter !== "All" ? { status: filter === "New" ? "submitted" : filter.toLowerCase() } : {}),
    ...(jobFilter ? { job_id: jobFilter.id } : {}),
    query,
  };
  const resource = usePaginatedResource(fetchEmployerApplications, filters, user.id);
  const applicants = resource.data?.applicants || EMPTY;
  const schedules = Object.fromEntries(
    applicants.map((item) => [
      item.id,
      {
        date: item.interviewDate,
        time: item.interviewTime?.slice(0, 5),
        notes: item.interviewNotes,
      },
    ]),
  );
  const [error, setError] = useState("");
  const [updating, setUpdating] = useState(false);
  const [exporting, setExporting] = useState(false);
  const [scheduleTarget, setScheduleTarget] = useState(null);
  const [scheduleDate, setScheduleDate] = useState("");
  const [scheduleTime, setScheduleTime] = useState("09:00");
  const [scheduleSaving, setScheduleSaving] = useState(false);
  const visible = applicants;
  async function updateStatus(id, status) {
    if (status === "Interview") {
      beginSchedule(applicants.find((item) => item.id === id));
      return;
    }
    setUpdating(true);
    setError("");
    try {
      await updateEmployerApplication(id, status);
      resource.reload();
      showToast("Applicant updated", `Status changed to ${status}.`);
    } catch (e) {
      setError(apiErrorMessage(e));
    } finally {
      setUpdating(false);
    }
  }
  function beginSchedule(applicant) {
    setScheduleTarget(applicant);
    setScheduleDate(schedules[applicant.id]?.date || "");
    setScheduleTime(schedules[applicant.id]?.time || "09:00");
  }
  async function saveSchedule(event) {
    event.preventDefault();
    if (!scheduleTarget) return;
    setScheduleSaving(true);
    setError("");
    const scheduleData = {
      date: scheduleDate,
      time: scheduleTime,
      notes: schedules[scheduleTarget.id]?.notes || "",
    };
    try {
      await scheduleInterview(scheduleTarget.id, scheduleData);
      resource.reload();
      showToast(
        "Interview scheduled",
        `${scheduleTarget.name} is scheduled for ${scheduleDate} at ${scheduleTime}.`,
      );
      setScheduleTarget(null);
    } catch (e) {
      setError(apiErrorMessage(e));
    } finally {
      setScheduleSaving(false);
    }
  }
  async function exportCsv() {
    setExporting(true);
    setError("");
    try {
      const exported = [];
      let page = 1, lastPage = 1;
      do {
        const data = await fetchEmployerApplications({ ...filters, page, per_page: 50 });
        exported.push(...data.applicants);
        lastPage = data.meta.last_page;
        page += 1;
      } while (page <= lastPage);
      const rows = [
        [
          "Applicant",
          "Email",
          "Job",
          "Applied",
          "Status",
          "Interview date",
          "Interview time",
        ],
        ...exported.map((item) => [
          item.name,
          item.email,
          item.jobTitle,
          item.appliedAt,
          item.status,
          item.interviewDate,
          item.interviewTime,
        ]),
      ];
      const blob = new Blob(
        [rows.map((row) => row.map(csvCell).join(",")).join("\n")],
        { type: "text/csv;charset=utf-8" },
      );
      const url = URL.createObjectURL(blob);
      const link = document.createElement("a");
      link.href = url;
      link.download = "pwdwork-applicants.csv";
      link.click();
      URL.revokeObjectURL(url);
      showToast("CSV exported", `${exported.length} applicants exported.`);
    } catch (e) {
      setError(apiErrorMessage(e));
    } finally {
      setExporting(false);
    }
  }
  async function downloadResume(id) {
    setError("");
    try {
      await downloadApplicantResume(id);
    } catch (e) {
      setError(apiErrorMessage(e, "Resume unavailable. Please try again or ask the applicant to update it."));
    }
  }
  return (
    <main className="bg-brand-gray-bg py-12 min-h-[60vh]">
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
              Find the right fit
            </p>
            <h1 className="text-3xl font-black text-brand-dark">Applicants</h1>
            <p className="text-gray-600 mt-1">
              Search, filter, schedule interviews, and export your hiring
              pipeline.
            </p>
          </div>
          <button
            type="button"
            onClick={exportCsv}
            disabled={exporting || updating || scheduleSaving}
            className="bg-brand-blue text-white font-black px-5 py-3 rounded-xl"
          >
            {exporting ? "Exporting..." : "Export filtered CSV"}
          </button>
        </div>
        <div className="bg-white rounded-2xl border border-gray-200 p-4 mb-6 grid sm:grid-cols-2 gap-3">
          <label>
            <span className="form-label">Search applicants</span>
            <input
              value={query}
              maxLength={255}
              disabled={updating || scheduleSaving || exporting}
              onChange={(event) => setQuery(event.target.value)}
              className="form-input"
              placeholder="Name, email, experience..."
            />
          </label>
          <EmployerJobFilter selected={jobFilter} onChange={setJobFilter} accountId={user.id}
            disabled={updating || scheduleSaving || exporting} />
        </div>
        <div className="flex flex-wrap gap-2 mb-6">
          {statuses.map((item) => (
            <button
              key={item}
              type="button"
              aria-pressed={filter === item}
              disabled={updating || scheduleSaving || exporting}
              onClick={() => setFilter(item)}
              className={`px-4 py-2 rounded-full text-sm font-extrabold border ${filter === item ? "bg-brand-teal text-white border-brand-teal" : "bg-white text-brand-dark border-gray-200"}`}
            >
              {item}
            </button>
          ))}
        </div>
        {scheduleTarget && (
          <form
            onSubmit={saveSchedule}
            className="bg-white rounded-2xl border-2 border-brand-teal p-5 mb-6 grid sm:grid-cols-3 gap-4 items-end"
          >
            <div className="sm:col-span-3">
              <h2 className="text-lg font-black text-brand-dark">
                Schedule interview with {scheduleTarget.name}
              </h2>
              <p className="text-sm text-gray-600">
                Dates and times use Asia/Manila. The appointment is saved to the
                shared application record.
              </p>
            </div>
            <label>
              <span className="form-label">Interview date</span>
              <input
                required
                type="date"
                value={scheduleDate}
                onChange={(event) => setScheduleDate(event.target.value)}
                className="form-input"
              />
            </label>
            <label>
              <span className="form-label">Interview time</span>
              <input
                required
                type="time"
                value={scheduleTime}
                onChange={(event) => setScheduleTime(event.target.value)}
                className="form-input"
              />
            </label>
            <div className="flex gap-2">
              <button
                disabled={scheduleSaving}
                type="button"
                onClick={() => setScheduleTarget(null)}
                className="border border-gray-300 text-brand-dark font-black px-4 py-3 rounded-xl"
              >
                Cancel
              </button>
              <button
                type="submit"
                disabled={scheduleSaving}
                className="bg-brand-teal text-white font-black px-4 py-3 rounded-xl"
              >
                {scheduleSaving ? "Saving..." : "Confirm interview"}
              </button>
            </div>
          </form>
        )}
        {visible.length ? (
          <div className="space-y-4">
            {visible.map((applicant) => (
              <article
                key={applicant.id}
                className="bg-white rounded-2xl border border-gray-200 shadow-soft-lg p-5 sm:p-6"
              >
                <div className="flex flex-col lg:flex-row lg:items-start justify-between gap-5">
                  <div className="flex gap-4">
                    <div className="w-12 h-12 shrink-0 rounded-full bg-brand-blue text-white flex items-center justify-center font-black">
                      {applicant.name
                        .split(" ")
                        .map((name) => name[0])
                        .join("")}
                    </div>
                    <div>
                      <h2 className="text-lg font-black text-brand-dark">
                        {applicant.name}
                      </h2>
                      <p className="text-sm font-semibold text-brand-teal">
                        {applicant.jobTitle}
                      </p>
                      <p className="text-sm text-gray-600 mt-2">
                        Experience: {applicant.experience || "Not provided"}
                      </p>
                      {applicant.headline && <p>{applicant.headline}</p>}
                      <p>Skills: {applicant.skills.length ? applicant.skills.join(", ") : "Not provided"}</p>
                      <p className="whitespace-pre-wrap">Professional summary: {applicant.summary || "Not provided"}</p>
                      <p className="whitespace-pre-wrap">Cover letter: {applicant.coverLetter || "Not provided"}</p>
                      {applicant.resumeAvailable ? (
                        <button type="button" onClick={() => downloadResume(applicant.id)}>Download resume</button>
                      ) : <p>Resume: Not provided</p>}
                      {applicant.accommodationsShared ? (
                        <p>Shared accommodation requests: {applicant.accommodations.join(", ") || "None selected"}</p>
                      ) : <p>Accommodation requests are private.</p>}
                      {(applicant.interviewDate ||
                        schedules[applicant.id]?.date) && (
                        <p className="text-sm font-bold text-brand-blue mt-2">
                          Interview:{" "}
                          {applicant.interviewDate ||
                            schedules[applicant.id]?.date}{" "}
                          at{" "}
                          {applicant.interviewTime ||
                            schedules[applicant.id]?.time}
                        </p>
                      )}
                    </div>
                  </div>
                  <div className="flex flex-col items-start lg:items-end gap-2">
                    <span className="text-xs text-gray-500">
                      Applied {applicant.appliedAt}
                    </span>
                    <div className="flex flex-wrap gap-2">
                      <button
                        disabled={updating || scheduleSaving || exporting}
                        type="button"
                        onClick={() => beginSchedule(applicant)}
                        className="border border-brand-teal text-brand-teal font-extrabold px-3 py-2 rounded-lg"
                      >
                        Schedule interview
                      </button>
                      <select
                        disabled={updating || scheduleSaving || exporting}
                        aria-label={`Status for ${applicant.name}`}
                        value={applicant.status}
                        onChange={(event) =>
                          updateStatus(applicant.id, event.target.value)
                        }
                        className="border border-gray-300 rounded-lg px-3 py-2 text-sm font-bold"
                      >
                        <option>New</option>
                        <option>Reviewing</option>
                        <option>Interview</option>
                        <option>Shortlisted</option>
                        <option>Hired</option>
                        <option>Rejected</option>
                        {!statuses.includes(applicant.status) && <option>{applicant.status}</option>}
                      </select>
                    </div>
                  </div>
                </div>
              </article>
            ))}
          </div>
        ) : (
          !resource.loading &&
          !resource.error && (
            <div className="bg-white rounded-2xl border border-dashed border-gray-300 p-10 text-center">
              <h2 className="text-lg font-black text-brand-dark">
                No matching applicants
              </h2>
              <p className="text-gray-600 mt-2">
                Try another search or filter.
              </p>
            </div>
          )
        )}
        <Pagination resource={resource} label="Employer applicant pages" disabled={updating || scheduleSaving || exporting} />
      </div>
    </main>
  );
}
