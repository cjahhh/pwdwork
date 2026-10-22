import api from "./api.js";
export async function getEmployerProfile() {
  return (await api.get("/profile")).data.profile;
}
export async function saveEmployerProfile(_user, profile) {
  return (await api.put("/profile", profile)).data.profile;
}
