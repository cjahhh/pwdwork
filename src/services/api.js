/**
 * Shared Axios client for the Laravel API.
 *
 * baseURL comes from VITE_API_URL (see .env.example).
 * A Bearer token interceptor will be added in the authentication phase.
 * Pages should call service functions, not this file directly, once those exist.
 */
import axios from "axios";

const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL || "http://localhost:8000/api",
  headers: {
    "Content-Type": "application/json",
    Accept: "application/json",
  },
  withCredentials: true,
});

export default api;
