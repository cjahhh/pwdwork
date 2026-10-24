import api from "./api.js";
export async function getApplicantProfile() {
  return (await api.get("/profile")).data.profile;
}
export async function saveApplicantProfile(_user, profile) {
  return (await api.put("/profile", profile)).data.profile;
}
export async function getSavedJobs(params = {}) {
  return (await api.get("/applicant/saved-jobs", { params })).data;
}
export async function getSavedJobState(id) {
  return (await api.get("/applicant/saved-jobs/" + id)).data.saved;
}
export async function saveJob(id) {
  await api.put("/applicant/saved-jobs/" + id);
}
export async function unsaveJob(id) {
  await api.delete("/applicant/saved-jobs/" + id);
}

export async function getRecommendations(params = {}) {
  return (await api.get("/applicant/recommendations", { params })).data;
}
