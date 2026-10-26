import api, { prepareCsrf } from "./api.js";

export function dashboardPath(role) {
  if (role === "employer") return "/employer";
  if (role === "admin") return "/admin";
  return "/applicant";
}

export function accountPath(user) {
  return user.email_verification_required ? "/verify-email" : dashboardPath(user.role);
}

export async function resendVerification() {
  await prepareCsrf();
  const { data } = await api.post("/email/verification/resend");
  return data;
}

export async function confirmVerification(proof) {
  await prepareCsrf();
  const { data } = await api.post("/email/verification/confirm", { proof });
  return data;
}

export async function registerAccount(payload) {
  await prepareCsrf();
  const { data } = await api.post("/register", payload);
  return data;
}

export async function loginAccount(payload) {
  await prepareCsrf();
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
