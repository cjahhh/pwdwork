import axios from "axios";

const TOKEN_KEY = "pwdwork_token";

const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL || "/api",
  headers: {
    "Content-Type": "application/json",
    Accept: "application/json",
  },
});

api.interceptors.request.use((config) => {
  const token = localStorage.getItem(TOKEN_KEY);
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  return config;
});

export function getStoredToken() {
  return localStorage.getItem(TOKEN_KEY);
}

export function setStoredToken(token) {
  if (token) {
    localStorage.setItem(TOKEN_KEY, token);
  } else {
    localStorage.removeItem(TOKEN_KEY);
  }
}

export function apiErrorMessage(error, fallback = "Something went wrong.") {
  const data = error?.response?.data;
  if (data?.errors) {
    const first = Object.values(data.errors)[0];
    if (Array.isArray(first) && first[0]) return first[0];
  }
  if (data?.message) return data.message;
  if (error?.code === "ERR_NETWORK") {
    return "Cannot reach the API. Start Laravel with php artisan serve in the backend folder.";
  }
  return fallback;
}

export { TOKEN_KEY };
export default api;
