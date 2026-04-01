import axios from "axios";

/**
 * Same-origin API root.
 * Never derive from `location.pathname` — Vue routes like `/users/5/edit` produced
 * `/users/5/api` and broke auth and user loads.
 *
 * `/tickets-app/` build: assets stay under `/tickets-app/` but Laravel API stays at `/api`.
 */
function resolveApiBase() {
  const raw = import.meta.env.BASE_URL || "/";
  let normalized = raw.replace(/\/$/, "");
  if (normalized === "") {
    normalized = "/";
  }
  if (normalized === "/tickets-app") {
    return "/api";
  }
  const prefix = normalized === "/" ? "" : normalized;
  return `${prefix}/api`;
}

const api = axios.create({
  baseURL: resolveApiBase(),
  headers: {
    "Content-Type": "application/json",
    Accept: "application/json",
  },
});

api.interceptors.request.use((config) => {
  config.baseURL = resolveApiBase();
  const token = localStorage.getItem("auth_token");
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  return config;
});

export default api;
