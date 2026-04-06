import { createApp } from "vue";
import App from "./App.vue";
import router from "./router";
import "./styles/vuexy-entry.scss";
import "./style.css";
import "bootstrap/dist/js/bootstrap.bundle.min.js";
import { BRAND_FAVICON_SRC } from "./utils/brandAssets.js";

function faviconHref() {
  return BRAND_FAVICON_SRC();
}

let link = document.querySelector('link[rel="icon"]');
if (!link) {
  link = document.createElement("link");
  link.rel = "icon";
  document.head.appendChild(link);
}
link.type = "image/jpeg";
link.href = faviconHref();

createApp(App).use(router).mount("#app");
