import axios from "axios";
const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL || "/api",
  withCredentials: true,
  withXSRFToken: true,
  headers: { Accept: "application/json", "Content-Type": "application/json" },
});
// Credentials live in Laravel's HttpOnly session cookie, never browser storage.
export async function prepareCsrf() {
  await api.get("/csrf-cookie");
}
api.interceptors.response.use(
  (response) => response,
  (error) => {
    if (
      [401, 419].includes(error.response?.status) &&
      ![
        "/user",
        "/login",
        "/register",
        "/forgot-password",
        "/reset-password",
      ].includes(error.config?.url)
    )
      window.dispatchEvent(new Event("pwdwork:unauthorized"));
    return Promise.reject(error);
  },
);
export function apiErrorMessage(
  error,
  fallback = "The request failed. Please try again.",
) {
  if (error.response?.status === 401)
    return "Your session has expired. Please sign in again.";
  if (error.response?.status === 419)
    return "Your security session expired. Reload the page and try again.";
  if (error.response?.status === 403)
    return "You do not have permission to perform this action.";
  if (error.response?.status === 429)
    return "Too many attempts. Please wait before trying again.";
  const data = error.response?.data;
  if (data?.errors) return Object.values(data.errors).flat()[0] || fallback;
  if (data?.message) return data.message;
  return error.response
    ? fallback
    : "Cannot reach the server. Please check your connection and try again.";
}
export default api;
