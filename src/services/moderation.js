import api from "./api.js";

export async function requestEmployerReview(version) {
  return (await api.post("/employer/verification-request", { expected_version: version })).data;
}
export async function submitListing(id, version) {
  return (await api.post(`/employer/jobs/${id}/submit`, { expected_version: version })).data;
}
export async function fetchVerificationHistory(params = {}) {
  return (await api.get("/employer/verification-history", { params })).data;
}
export async function fetchJobHistory(id, params = {}) {
  return (await api.get(`/employer/jobs/${id}/history`, { params })).data;
}
export async function fetchNotifications(params = {}) {
  return (await api.get("/notifications", { params })).data;
}
export async function readNotification(id) {
  await api.patch(`/notifications/${id}/read`);
}
export async function fetchOwnReports(params = {}) {
  return (await api.get("/reports", { params })).data;
}
export async function submitReport(payload) {
  return (await api.post("/reports", payload)).data;
}
export const reportCategories = {
  job: ["suspicious_job", "misleading_description", "inaccurate_accessibility", "discrimination"],
  employer: ["suspicious_employer", "inaccurate_accessibility", "discrimination"],
  account: ["account_problem", "suspicious_employer", "discrimination"],
  platform: ["platform_problem", "account_problem"],
};
export function displayStatus(value) {
  return String(value || "").replaceAll("_", " ").replace(/^./, (letter) => letter.toUpperCase());
}
