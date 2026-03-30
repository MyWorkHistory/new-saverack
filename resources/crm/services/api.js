import axios from "axios";

/** Same-origin /api when the app is at / or under a subpath (e.g. /project/public/tickets-app/...) */
function resolveApiBase() {
  const p = location.pathname;
  const mark = "/tickets-app";
  const i = p.indexOf(mark);
  if (i !== -1) {
    return p.slice(0, i) + "/api";
  }
  const dir = p.replace(/\/[^/]*$/, "") || "";
  return dir + "/api";
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
