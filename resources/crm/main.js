import { createApp } from "vue";
import App from "./App.vue";
import router from "./router";
import "./style.css";

/** Favicon URL that works at site root and under a subpath (e.g. /foo/public/...) */
function faviconHref() {
  const p = location.pathname;
  const mark = "/tickets-app";
  const i = p.indexOf(mark);
  if (i !== -1) {
    return p.slice(0, i) + "/images/logo/logo-icon.svg";
  }
  const dir = p.replace(/\/[^/]*$/, "") || "";
  return dir + "/images/logo/logo-icon.svg";
}

let link = document.querySelector('link[rel="icon"]');
if (!link) {
  link = document.createElement("link");
  link.rel = "icon";
  document.head.appendChild(link);
}
link.type = "image/svg+xml";
link.href = faviconHref();

createApp(App).use(router).mount("#app");
