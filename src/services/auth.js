import api from "./api.js";

export function dashboardPath(role) {
  if (role === "employer") return "/employer";
  if (role === "admin") return "/admin";
  return "/applicant";
}

export async function registerAccount(payload) {
  const { data } = await api.post("/register", payload);
  return data;
}

export async function loginAccount(payload) {
  const { data } = await api.post("/login", payload);
  return data;
}

export async function logoutAccount() {
  await api.post("/logout");
}

export async function fetchCurrentUser() {
  const { data } = await api.get("/user");
  return data.user;
}
