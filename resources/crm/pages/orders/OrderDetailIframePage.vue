<script setup>
import { computed, onMounted, ref } from "vue";
import { useRoute } from "vue-router";
import api from "../../services/api";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";

const route = useRoute();
const loading = ref(true);
const resolving = ref("Resolving ShipHero order...");
const shipheroUrl = ref("");
const error = ref("");
const redirected = ref(false);
const iframeLoaded = ref(false);

const accountSlug = computed(() => String(route.params.accountSlug || "").trim().toLowerCase());
const routeOrderNumber = computed(() => normalizeOrderNumber(String(route.params.orderNumber || "")));

function normalizeOrderNumber(v) {
  return String(v || "")
    .trim()
    .replace(/^#\s*/, "")
    .toLowerCase();
}

function slugAccountName(v) {
  let s = String(v || "").trim().toLowerCase();
  if (!s) return "";
  s = s.replace(/^https?:\/\//, "");
  s = s.replace(/^www\./, "");
  s = s.replace(/\.myshopify\.com$/, "");
  s = s.replace(/[^a-z0-9]+/g, "-");
  s = s.replace(/^-+|-+$/g, "");
  return s;
}

async function resolveAccountIdFromSlug() {
  const { data } = await api.get("/inventory/client-account-options");
  const rows = Array.isArray(data?.accounts) ? data.accounts : [];
  const match = rows.find((a) => a?.has_shiphero_customer && slugAccountName(a.company_name) === accountSlug.value);
  return match?.id ? Number(match.id) : null;
}

async function findLegacyIdForOrder(accountId) {
  const tabs = ["manage", "awaiting", "on_hold", "shipped"];
  for (const tab of tabs) {
    let after = null;
    let page = 0;
    while (page < 5) {
      const { data } = await api.get("/orders", {
        params: {
          client_account_id: accountId,
          tab,
          first: 100,
          after: after || undefined,
        },
      });
      const rows = Array.isArray(data?.rows) ? data.rows : [];
      const found = rows.find((row) => normalizeOrderNumber(row?.order_number) === routeOrderNumber.value);
      if (found && Number(found.legacy_id) > 0) {
        return Number(found.legacy_id);
      }
      const hasNext = Boolean(data?.pagination?.has_next_page);
      const next = String(data?.pagination?.end_cursor || "");
      if (!hasNext || !next) break;
      after = next;
      page += 1;
    }
  }
  return null;
}

function redirectTo(url) {
  if (redirected.value) return;
  redirected.value = true;
  window.location.assign(url);
}

function onIframeLoad() {
  iframeLoaded.value = true;
}

onMounted(async () => {
  setCrmPageMeta({
    title: "Save Rack | Order Detail",
    description: "ShipHero iframe order detail.",
  });
  try {
    const accountId = await resolveAccountIdFromSlug();
    if (!accountId) {
      error.value = "Could not match account from URL.";
      redirectTo("https://app.shiphero.com/dashboard/orders");
      return;
    }
    resolving.value = "Finding ShipHero order id...";
    const legacyId = await findLegacyIdForOrder(accountId);
    if (!legacyId) {
      error.value = "Order was not found in ShipHero list responses.";
      redirectTo("https://app.shiphero.com/dashboard/orders");
      return;
    }
    shipheroUrl.value = `https://app.shiphero.com/dashboard/orders/details/${legacyId}`;
    loading.value = false;

    window.setTimeout(() => {
      if (!iframeLoaded.value) {
        redirectTo(shipheroUrl.value);
      }
    }, 9000);
  } catch (e) {
    error.value = e?.message || "Could not open ShipHero order page.";
    redirectTo("https://app.shiphero.com/dashboard/orders");
  } finally {
    loading.value = false;
  }
});
</script>

<template>
  <div class="staff-page staff-page--wide">
    <div v-if="loading" class="d-flex justify-content-center py-5">
      <CrmLoadingSpinner :message="resolving" />
    </div>

    <div v-else-if="shipheroUrl" class="staff-table-card staff-datatable-card staff-datatable-card--white p-0">
      <div class="px-3 py-2 border-bottom d-flex justify-content-end">
        <a :href="shipheroUrl" target="_blank" rel="noopener noreferrer" class="btn btn-outline-secondary btn-sm">
          Open in ShipHero
        </a>
      </div>
      <iframe
        class="order-iframe"
        :src="shipheroUrl"
        title="ShipHero order detail"
        loading="eager"
        referrerpolicy="no-referrer"
        @load="onIframeLoad"
      />
    </div>

    <div v-else class="alert alert-warning small">
      {{ error || "Redirecting to ShipHero..." }}
    </div>
  </div>
</template>

<style scoped>
.order-iframe {
  width: 100%;
  min-height: 82vh;
  border: 0;
}
</style>
