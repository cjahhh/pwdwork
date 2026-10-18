import { getJobById } from "./jobs.js";

function storageKey(user, section) {
  return `pwdwork_${section}_${user?.email || "guest"}`;
}

function read(user, section, fallback) {
  try {
    const value = localStorage.getItem(storageKey(user, section));
    return value ? JSON.parse(value) : fallback;
  } catch {
    return fallback;
  }
}

function write(user, section, value) {
  localStorage.setItem(storageKey(user, section), JSON.stringify(value));
}

export function getApplicantProfile(user) {
  return read(user, "profile", {
    name: user?.name || "",
    email: user?.email || "",
    headline: "Accessibility-minded professional",
    location: "",
    bio: "",
    skills: [],
    accommodations: [],
  });
}

export function saveApplicantProfile(user, profile) {
  write(user, "profile", profile);
}

export function getSavedJobIds(user) {
  return read(user, "saved_jobs", []);
}

export function isJobSaved(user, jobId) {
  return getSavedJobIds(user).includes(String(jobId));
}

export function toggleSavedJob(user, jobId) {
  const id = String(jobId);
  const saved = getSavedJobIds(user);
  const next = saved.includes(id) ? saved.filter((savedId) => savedId !== id) : [id, ...saved];
  write(user, "saved_jobs", next);
  return next;
}

export function getSavedJobs(user) {
  return getSavedJobIds(user).map(getJobById).filter(Boolean);
}

export function getApplications(user) {
  return read(user, "applications", []);
}

export function addApplication(user, job) {
  const applications = getApplications(user);
  if (applications.some((application) => String(application.jobId) === String(job.id))) return applications;
  const next = [{ jobId: String(job.id), appliedAt: new Date().toISOString(), status: "Under review" }, ...applications];
  write(user, "applications", next);
  return next;
}

export function getApplicationJob(application) {
  return getJobById(application.jobId);
}
