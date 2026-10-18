import { jobsDataset } from "./jobs.js";

function storageKey(user, section) {
  return `pwdwork_employer_${section}_${user?.email || "guest"}`;
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

export function getEmployerProfile(user) {
  return read(user, "profile", {
    companyName: user?.company_name || "",
    website: "",
    location: "",
    description: "Tell jobseekers what makes your workplace inclusive.",
    verificationStatus: "Pending review",
  });
}

export function saveEmployerProfile(user, profile) {
  write(user, "profile", profile);
}

export function getEmployerListings(user) {
  return read(user, "listings", []);
}

export function saveEmployerListings(user, listings) {
  write(user, "listings", listings);
}

export function getEmployerApplicants(user) {
  return read(user, "applicants", [
    { id: "app-1", name: "Jordan Cruz", jobTitle: jobsDataset[0].title, appliedAt: "2026-10-14", status: "Reviewing", experience: "UX and accessibility design", accommodations: ["Remote work", "Flexible hours"] },
    { id: "app-2", name: "Taylor Morgan", jobTitle: jobsDataset[1].title, appliedAt: "2026-10-12", status: "New", experience: "Frontend development", accommodations: ["Screen reader support"] },
  ]);
}

export function saveEmployerApplicants(user, applicants) {
  write(user, "applicants", applicants);
}

export function getInterviewSchedules(user) {
  return read(user, "interviews", {});
}

export function saveInterviewSchedules(user, schedules) {
  write(user, "interviews", schedules);
}
