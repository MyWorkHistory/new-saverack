<script setup>
import { computed, onMounted, onUnmounted, ref } from "vue";
import { useRoute, useRouter } from "vue-router";
import api from "../../services/api";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import ShopifyInventoryEditProductModal from "../../components/shopify/ShopifyInventoryEditProductModal.vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast";

const route = useRoute();
const router = useRouter();
const toast = useToast();

const loading = ref(true);
const saveBusy = ref(false);
const actionBusy = ref(false);
const variant = ref(null);
const editOpen = ref(false);
const actionsOpen = ref(false);
const actionsRoot = ref(null);

/** Placeholder inventory until CRM location wiring exists. */
const inventoryStats = {
  total_on_hand: 0,
  allocated: 0,
  available: 0,
  backorder: 0,
  asn: 0,
};

const locationGroups = [
  { key: "pick", label: "Pick Locations", icon: "cart", count: 0, rows: [] },
  { key: "backstock", label: "Backstock Locations", icon: "cube", count: 0, rows: [] },
  { key: "other", label: "Other Locations", icon: "bag", count: 0, rows: [] },
];

const dimUnitLabel = computed(() =>
  String(variant.value?.dimension_unit || "").toUpperCase() === "CENTIMETERS" ? "cm" : "in",
);

const weightUnitLabel = computed(() => {
  switch (String(variant.value?.weight_unit || "").toUpperCase()) {
    case "OUNCES":
      return "oz";
    case "GRAMS":
      return "g";
    case "KILOGRAMS":
      return "kg";
    default:
      return "lbs";
  }
});

function formatNum(val, digits = 2) {
  if (val === "" || val == null || Number.isNaN(Number(val))) return "—";
  const n = Number(val);
  return n.toLocaleString("en-US", {
    minimumFractionDigits: 0,
    maximumFractionDigits: digits,
  });
}

const cubicFeet = computed(() => {
  const l = Number(variant.value?.length);
  const w = Number(variant.value?.width);
  const h = Number(variant.value?.height);
  if (![l, w, h].every((n) => Number.isFinite(n) && n > 0)) return null;
  const unit = String(variant.value?.dimension_unit || "").toUpperCase();
  let inchesL = l;
  let inchesW = w;
  let inchesH = h;
  if (unit === "CENTIMETERS") {
    inchesL = l / 2.54;
    inchesW = w / 2.54;
    inchesH = h / 2.54;
  }
  return (inchesL * inchesW * inchesH) / 1728;
});

async function load() {
  loading.value = true;
  try {
    const { data } = await api.get(`/shopify/inventory/${route.params.id}`);
    variant.value = data?.variant || null;
  } catch (e) {
    toast.errorFrom(e, "Could not load variant.");
    variant.value = null;
  } finally {
    loading.value = false;
  }
}

function openEdit() {
  actionsOpen.value = false;
  editOpen.value = true;
}

async function onSaveProduct(payload) {
  if (!variant.value?.id || saveBusy.value) return;
  saveBusy.value = true;
  try {
    const { data } = await api.patch(`/shopify/inventory/${variant.value.id}`, payload);
    toast.success(data?.message || "Product updated.");
    editOpen.value = false;
    await load();
  } catch (e) {
    toast.errorFrom(e, "Could not save product.");
  } finally {
    saveBusy.value = false;
  }
}

async function syncProductInfo() {
  actionsOpen.value = false;
  const accountId = variant.value?.client_account_id;
  const connectionId = variant.value?.connection_id;
  if (!accountId || !connectionId) {
    toast.error("Missing Shopify connection for this product.");
    return;
  }
  actionBusy.value = true;
  try {
    const { data } = await api.post(
      `/client-accounts/${accountId}/shopify-connections/${connectionId}/sync-products`,
    );
    toast.success(data?.message || "Product info synced.");
    await load();
  } catch (e) {
    toast.errorFrom(e, "Could not sync product info.");
  } finally {
    actionBusy.value = false;
  }
}

async function pushInventory() {
  actionsOpen.value = false;
  const accountId = variant.value?.client_account_id;
  const connectionId = variant.value?.connection_id;
  if (!accountId || !connectionId) {
    toast.error("Missing Shopify connection for this product.");
    return;
  }
  actionBusy.value = true;
  try {
    const { data } = await api.post(
      `/client-accounts/${accountId}/shopify-connections/${connectionId}/push-inventory`,
    );
    toast.success(data?.message || "Inventory pushed.");
  } catch (e) {
    toast.errorFrom(e, "Could not push inventory.");
  } finally {
    actionBusy.value = false;
  }
}

function comingSoon(label) {
  toast.warning(`${label} will be available when CRM inventory is connected.`);
}

function onDocClick(event) {
  if (!actionsRoot.value?.contains?.(event.target)) {
    actionsOpen.value = false;
  }
}

onMounted(() => {
  setCrmPageMeta({
    title: "Save Rack | Shopify Variant",
    description: "Shopify product inventory details.",
  });
  document.addEventListener("click", onDocClick);
  void load();
});

onUnmounted(() => {
  document.removeEventListener("click", onDocClick);
});
</script>

<template>
  <div class="staff-page staff-page--wide shopify-inv-detail">
    <div v-if="loading" class="p-5 d-flex justify-content-center">
      <CrmLoadingSpinner message="Loading…" />
    </div>

    <template v-else-if="!variant">
      <button
        type="button"
        class="btn btn-link btn-sm text-primary px-0 mb-3 text-decoration-none"
        @click="router.push({ name: 'shopify-inventory' })"
      >
        ← Back to Products
      </button>
      <p class="text-secondary">Product not found.</p>
    </template>

    <template v-else>
      <header class="shopify-inv-detail__header mb-4">
        <button
          type="button"
          class="btn btn-link btn-sm text-primary px-0 py-0 mb-3 text-decoration-none"
          @click="router.push({ name: 'shopify-inventory' })"
        >
          ← Back to Products
        </button>
        <div class="d-flex flex-wrap justify-content-end align-items-center gap-2">
          <button
            type="button"
            class="btn shopify-inv-detail__btn-edit fw-semibold"
            @click="openEdit"
          >
            <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z" />
            </svg>
            Edit Product
          </button>
          <div ref="actionsRoot" class="position-relative">
            <button
              type="button"
              class="btn btn-primary staff-page-primary shopify-inv-detail__btn-actions fw-semibold"
              :disabled="actionBusy"
              :aria-expanded="actionsOpen"
              @click.stop="actionsOpen = !actionsOpen"
            >
              {{ actionBusy ? "Working…" : "Actions" }}
              <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
              </svg>
            </button>
            <div
              v-if="actionsOpen"
              class="shopify-inv-detail__actions-menu"
              role="menu"
            >
              <button
                type="button"
                class="shopify-inv-detail__actions-item"
                role="menuitem"
                @click="syncProductInfo"
              >
                <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182" />
                </svg>
                Sync Product Info
              </button>
              <button
                type="button"
                class="shopify-inv-detail__actions-item"
                role="menuitem"
                @click="pushInventory"
              >
                <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5" />
                </svg>
                Push Inventory
              </button>
            </div>
          </div>
        </div>
      </header>

      <div class="row g-3 g-xl-4">
        <!-- Left -->
        <div class="col-12 col-lg-7">
          <section class="shopify-inv-card mb-3">
            <div class="shopify-inv-hero">
              <div class="shopify-inv-hero__thumb" aria-hidden="true">
                <img
                  v-if="variant.image_url"
                  :src="variant.image_url"
                  :alt="variant.product_title || 'Product'"
                />
                <span v-else class="shopify-inv-hero__thumb-empty">
                  <svg width="28" height="28" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909M3.75 21h16.5A2.25 2.25 0 0022.5 18.75V5.25A2.25 2.25 0 0020.25 3H3.75A2.25 2.25 0 001.5 5.25v13.5A2.25 2.25 0 003.75 21z" />
                  </svg>
                </span>
              </div>
              <div class="min-w-0 flex-grow-1">
                <div class="d-flex flex-wrap align-items-start justify-content-between gap-2 mb-3">
                  <h1 class="shopify-inv-hero__title mb-0">
                    {{ variant.product_title || variant.title || "Product" }}
                  </h1>
                  <button
                    type="button"
                    class="btn shopify-inv-detail__btn-edit shopify-inv-detail__btn-edit--sm fw-semibold"
                    @click="openEdit"
                  >
                    <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z" />
                    </svg>
                    Edit
                  </button>
                </div>
                <div class="row g-3 mb-3">
                  <div class="col-6">
                    <div class="shopify-inv-meta__label">SKU</div>
                    <div class="shopify-inv-meta__sku">{{ variant.sku || "—" }}</div>
                  </div>
                  <div class="col-6">
                    <div class="shopify-inv-meta__label">Barcode</div>
                    <div class="shopify-inv-meta__barcode">
                      <svg width="16" height="16" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path d="M4 6h2v12H4V6zm3 0h1v12H7V6zm2 0h3v12H9V6zm4 0h1v12h-1V6zm2 0h2v12h-2V6zm3 0h1v12h-1V6z" />
                      </svg>
                      {{ variant.barcode || "—" }}
                    </div>
                  </div>
                </div>
                <div class="shopify-inv-hero__footer">
                  <div class="shopify-inv-hero__chip">
                    <span class="shopify-inv-hero__chip-icon" aria-hidden="true">
                      <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 21v-7.5A.75.75 0 0114.25 12h5.25a.75.75 0 01.75.75V21M3 16.5V21m0 0h18M3 21h18M3.75 6.75h16.5M3.75 12h16.5" />
                      </svg>
                    </span>
                    <div>
                      <div class="shopify-inv-meta__label">Account</div>
                      <div class="shopify-inv-meta__value">{{ variant.account_name || "—" }}</div>
                    </div>
                  </div>
                  <div class="shopify-inv-hero__chip">
                    <span class="shopify-inv-hero__chip-icon" aria-hidden="true">
                      <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9.568 3H5.25A2.25 2.25 0 003 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 005.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 009.568 3z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 6h.008v.008H6V6z" />
                      </svg>
                    </span>
                    <div>
                      <div class="shopify-inv-meta__label">Type</div>
                      <div class="shopify-inv-meta__value">Product</div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </section>

          <section class="shopify-inv-card shopify-inv-dims mb-3">
            <div class="shopify-inv-dims__item">
              <span class="shopify-inv-dims__icon" aria-hidden="true">
                <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3.75v16.5h16.5" />
                </svg>
              </span>
              <div class="shopify-inv-meta__label">Length</div>
              <div class="shopify-inv-dims__value">
                {{ formatNum(variant.length) }}
                <span v-if="variant.length != null && variant.length !== ''">{{ dimUnitLabel }}</span>
              </div>
            </div>
            <div class="shopify-inv-dims__item">
              <span class="shopify-inv-dims__icon" aria-hidden="true">
                <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3.75h16.5v16.5" />
                </svg>
              </span>
              <div class="shopify-inv-meta__label">Width</div>
              <div class="shopify-inv-dims__value">
                {{ formatNum(variant.width) }}
                <span v-if="variant.width != null && variant.width !== ''">{{ dimUnitLabel }}</span>
              </div>
            </div>
            <div class="shopify-inv-dims__item">
              <span class="shopify-inv-dims__icon" aria-hidden="true">
                <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M3 7.5L7.5 3m0 0L12 7.5M7.5 3v13.5" />
                </svg>
              </span>
              <div class="shopify-inv-meta__label">Height</div>
              <div class="shopify-inv-dims__value">
                {{ formatNum(variant.height) }}
                <span v-if="variant.height != null && variant.height !== ''">{{ dimUnitLabel }}</span>
              </div>
            </div>
            <div class="shopify-inv-dims__item">
              <span class="shopify-inv-dims__icon" aria-hidden="true">
                <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M21 7.5l-9-5.25L3 7.5m18 0l-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9" />
                </svg>
              </span>
              <div class="shopify-inv-meta__label">Cubic Ft</div>
              <div class="shopify-inv-dims__value">
                <template v-if="cubicFeet != null">
                  {{ formatNum(cubicFeet, 3) }} ft³
                </template>
                <template v-else>—</template>
              </div>
            </div>
            <div class="shopify-inv-dims__item">
              <span class="shopify-inv-dims__icon" aria-hidden="true">
                <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v17.25m0 0c-1.472 0-2.882.265-4.185.75M12 20.25c1.472 0 2.882.265 4.185.75M18.75 4.97A48.416 48.416 0 0012 4.5c-2.291 0-4.545.16-6.75.47m13.5 0c1.01.143 2.01.317 3 .52m-3-.52l1.5 9.75M5.25 4.97l-1.5 9.75" />
                </svg>
              </span>
              <div class="shopify-inv-meta__label">Weight</div>
              <div class="shopify-inv-dims__value">
                {{ formatNum(variant.weight) }}
                <span v-if="variant.weight != null && variant.weight !== ''">{{ weightUnitLabel }}</span>
              </div>
            </div>
          </section>

          <section class="shopify-inv-card">
            <div class="shopify-inv-card__head">
              <div class="d-flex align-items-center gap-2">
                <span class="shopify-inv-card__head-icon" aria-hidden="true">
                  <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 10.5V6a3.75 3.75 0 10-7.5 0v4.5m11.356-1.993l1.263 12c.07.665-.45 1.243-1.119 1.243H4.25a1.125 1.125 0 01-1.12-1.243l1.264-12A1.125 1.125 0 015.513 7.5h12.974c.576 0 1.059.435 1.119 1.007z" />
                  </svg>
                </span>
                <h2 class="shopify-inv-card__title mb-0">Bundle</h2>
              </div>
              <button
                type="button"
                class="btn shopify-inv-detail__link-btn fw-semibold"
                @click="comingSoon('Add Items')"
              >
                + Add Items
              </button>
            </div>
            <div class="shopify-inv-bundle-empty">
              <div class="shopify-inv-meta__label mb-2">Items in this bundle</div>
              <div class="shopify-inv-bundle-empty__box">
                <svg width="36" height="36" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.3" aria-hidden="true">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M21 7.5l-9-5.25L3 7.5m18 0l-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9" />
                </svg>
                <p class="mb-0">This item is not a bundle.</p>
              </div>
            </div>
          </section>
        </div>

        <!-- Right -->
        <div class="col-12 col-lg-5">
          <section class="shopify-inv-card mb-3">
            <div class="shopify-inv-onhand">
              <span class="shopify-inv-onhand__icon" aria-hidden="true">
                <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M21 7.5l-9-5.25L3 7.5m18 0l-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9" />
                </svg>
              </span>
              <div>
                <div class="shopify-inv-meta__label">Total On Hand</div>
                <div class="shopify-inv-onhand__value">
                  {{ inventoryStats.total_on_hand.toLocaleString("en-US") }}
                </div>
              </div>
            </div>
            <div class="shopify-inv-stats">
              <div class="shopify-inv-stats__item">
                <span class="shopify-inv-stats__icon shopify-inv-stats__icon--alloc" aria-hidden="true">
                  <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 6h6v6H6zM12 12h6v6h-6z" />
                  </svg>
                </span>
                <div>
                  <div class="shopify-inv-meta__label">Allocated</div>
                  <div class="shopify-inv-stats__value">{{ inventoryStats.allocated }}</div>
                </div>
              </div>
              <div class="shopify-inv-stats__item">
                <span class="shopify-inv-stats__icon shopify-inv-stats__icon--avail" aria-hidden="true">
                  <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                  </svg>
                </span>
                <div>
                  <div class="shopify-inv-meta__label">Available</div>
                  <div class="shopify-inv-stats__value">{{ inventoryStats.available }}</div>
                </div>
              </div>
              <div class="shopify-inv-stats__item">
                <span class="shopify-inv-stats__icon shopify-inv-stats__icon--back" aria-hidden="true">
                  <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" />
                  </svg>
                </span>
                <div>
                  <div class="shopify-inv-meta__label">Backorder</div>
                  <div class="shopify-inv-stats__value">{{ inventoryStats.backorder }}</div>
                </div>
              </div>
              <div class="shopify-inv-stats__item">
                <span class="shopify-inv-stats__icon shopify-inv-stats__icon--asn" aria-hidden="true">
                  <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 18.75a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 01-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 00-3.213-9.193 2.056 2.056 0 00-1.58-.86H14.25M16.5 18.75h-2.25m0-11.177v-.886c0-.9-.682-1.738-1.53-1.972a49.001 49.001 0 00-6.94-.052C4.682 4.95 4 5.787 4 6.687v.887" />
                  </svg>
                </span>
                <div>
                  <div class="shopify-inv-meta__label">ASN</div>
                  <div class="shopify-inv-stats__value">{{ inventoryStats.asn }}</div>
                </div>
              </div>
            </div>
          </section>

          <section class="shopify-inv-card">
            <div class="shopify-inv-card__head">
              <div>
                <div class="d-flex align-items-center gap-2 mb-1">
                  <span class="shopify-inv-card__head-icon" aria-hidden="true">
                    <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z" />
                      <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z" />
                    </svg>
                  </span>
                  <h2 class="shopify-inv-card__title mb-0">Locations</h2>
                </div>
                <p class="shopify-inv-card__sub mb-0">Manage inventory by location.</p>
              </div>
              <button
                type="button"
                class="btn shopify-inv-detail__link-btn fw-semibold"
                @click="comingSoon('Add Location')"
              >
                + Add Location
              </button>
            </div>

            <div class="shopify-inv-loc-groups">
              <button
                v-for="group in locationGroups"
                :key="group.key"
                type="button"
                class="shopify-inv-loc-group"
                @click="comingSoon(group.label)"
              >
                <span
                  class="shopify-inv-loc-group__icon"
                  :class="`shopify-inv-loc-group__icon--${group.key}`"
                  aria-hidden="true"
                >
                  <svg v-if="group.icon === 'cart'" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 3h1.386c.51 0 .955.343 1.087.835l.383 1.437M7.5 14.25a3 3 0 00-3 3h15.75m-12.75-3h11.218c1.121-2.3 2.1-4.684 2.924-7.138a60.114 60.114 0 00-16.536-1.84M7.5 14.25L5.106 5.272M6 20.25a.75.75 0 11-1.5 0 .75.75 0 011.5 0zm12.75 0a.75.75 0 11-1.5 0 .75.75 0 011.5 0z" />
                  </svg>
                  <svg v-else-if="group.icon === 'cube'" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 7.5l-9-5.25L3 7.5m18 0l-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9" />
                  </svg>
                  <svg v-else width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 10.5V6a3.75 3.75 0 10-7.5 0v4.5m11.356-1.993l1.263 12c.07.665-.45 1.243-1.119 1.243H4.25a1.125 1.125 0 01-1.12-1.243l1.264-12A1.125 1.125 0 015.513 7.5h12.974c.576 0 1.059.435 1.119 1.007z" />
                  </svg>
                </span>
                <div class="min-w-0 flex-grow-1 text-start">
                  <div class="shopify-inv-loc-group__title">
                    {{ group.label }} ({{ group.count }})
                  </div>
                  <div class="shopify-inv-loc-group__empty text-secondary">
                    No locations yet
                  </div>
                </div>
                <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                </svg>
              </button>
            </div>

            <button
              type="button"
              class="btn shopify-inv-detail__view-all fw-semibold"
              @click="router.push({ name: 'shopify-locations' })"
            >
              View All Locations
              <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" />
              </svg>
            </button>
          </section>
        </div>
      </div>
    </template>

    <ShopifyInventoryEditProductModal
      v-model:open="editOpen"
      :busy="saveBusy"
      :variant="variant"
      @save="onSaveProduct"
    />
  </div>
</template>

<style scoped>
.shopify-inv-detail__btn-edit {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  border: 1px solid #93c5fd;
  background: #fff;
  color: #2563eb;
  border-radius: 0.5rem;
  padding: 0.45rem 0.85rem;
}
.shopify-inv-detail__btn-edit:hover {
  background: #eff6ff;
  color: #1d4ed8;
}
.shopify-inv-detail__btn-edit--sm {
  padding: 0.28rem 0.65rem;
  font-size: 0.82rem;
}
.shopify-inv-detail__btn-actions {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  border-radius: 0.5rem;
}
.shopify-inv-detail__actions-menu {
  position: absolute;
  right: 0;
  top: calc(100% + 4px);
  z-index: 20;
  min-width: 12.5rem;
  padding: 0.35rem;
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 0.55rem;
  box-shadow: 0 10px 24px rgba(15, 23, 42, 0.12);
}
.shopify-inv-detail__actions-item {
  display: flex;
  align-items: center;
  gap: 0.55rem;
  width: 100%;
  border: 0;
  background: transparent;
  text-align: left;
  padding: 0.55rem 0.65rem;
  border-radius: 0.4rem;
  font-size: 0.875rem;
  font-weight: 600;
  color: #111827;
}
.shopify-inv-detail__actions-item:hover {
  background: #f3f4f6;
}
.shopify-inv-card {
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 0.85rem;
  padding: 1.15rem 1.25rem;
  box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
}
.shopify-inv-card__head {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  justify-content: space-between;
  gap: 0.75rem;
  margin-bottom: 1rem;
}
.shopify-inv-card__title {
  font-size: 1rem;
  font-weight: 700;
  color: #111827;
}
.shopify-inv-card__sub {
  font-size: 0.8rem;
  color: #6b7280;
  margin-left: 1.65rem;
}
.shopify-inv-card__head-icon {
  display: inline-flex;
  color: #2563eb;
}
.shopify-inv-detail__link-btn {
  border: 0;
  background: transparent;
  color: #2563eb;
  padding: 0.2rem 0.35rem;
  font-size: 0.875rem;
}
.shopify-inv-detail__link-btn:hover {
  color: #1d4ed8;
  background: #eff6ff;
  border-radius: 0.35rem;
}
.shopify-inv-hero {
  display: flex;
  flex-wrap: wrap;
  gap: 1.15rem;
}
.shopify-inv-hero__thumb {
  width: 7.5rem;
  height: 7.5rem;
  border-radius: 0.65rem;
  overflow: hidden;
  background: #f3f4f6;
  flex-shrink: 0;
  display: flex;
  align-items: center;
  justify-content: center;
}
.shopify-inv-hero__thumb img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}
.shopify-inv-hero__thumb-empty {
  color: #9ca3af;
}
.shopify-inv-hero__title {
  font-size: 1.35rem;
  font-weight: 700;
  color: #111827;
  line-height: 1.25;
}
.shopify-inv-meta__label {
  font-size: 0.72rem;
  font-weight: 600;
  color: #6b7280;
  text-transform: none;
  margin-bottom: 0.15rem;
}
.shopify-inv-meta__sku {
  font-size: 0.95rem;
  font-weight: 700;
  color: #2563eb;
}
.shopify-inv-meta__barcode {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  font-size: 0.95rem;
  font-weight: 600;
  color: #111827;
}
.shopify-inv-meta__barcode svg {
  color: #2563eb;
}
.shopify-inv-meta__value {
  font-size: 0.9rem;
  font-weight: 600;
  color: #111827;
}
.shopify-inv-hero__footer {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.75rem;
  padding-top: 0.85rem;
  border-top: 1px solid #f3f4f6;
}
.shopify-inv-hero__chip {
  display: flex;
  align-items: flex-start;
  gap: 0.5rem;
}
.shopify-inv-hero__chip-icon {
  display: inline-flex;
  color: #6b7280;
  margin-top: 0.15rem;
}
.shopify-inv-dims {
  display: grid;
  grid-template-columns: repeat(5, minmax(0, 1fr));
  gap: 0.5rem;
  padding-top: 1rem;
  padding-bottom: 1rem;
}
.shopify-inv-dims__item {
  text-align: center;
  min-width: 0;
}
.shopify-inv-dims__icon {
  display: inline-flex;
  color: #9ca3af;
  margin-bottom: 0.2rem;
}
.shopify-inv-dims__value {
  font-size: 0.9rem;
  font-weight: 700;
  color: #111827;
}
.shopify-inv-bundle-empty__box {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 0.5rem;
  min-height: 8.5rem;
  border: 1px dashed #d1d5db;
  border-radius: 0.65rem;
  color: #9ca3af;
  font-size: 0.9rem;
  background: #fafafa;
}
.shopify-inv-onhand {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding-bottom: 1rem;
  margin-bottom: 1rem;
  border-bottom: 1px solid #f3f4f6;
}
.shopify-inv-onhand__icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 2.5rem;
  height: 2.5rem;
  border-radius: 0.55rem;
  background: #eff6ff;
  color: #2563eb;
}
.shopify-inv-onhand__value {
  font-size: 1.75rem;
  font-weight: 700;
  color: #2563eb;
  line-height: 1.1;
}
.shopify-inv-stats {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.65rem;
}
.shopify-inv-stats__item {
  display: flex;
  align-items: flex-start;
  gap: 0.55rem;
  padding: 0.7rem 0.75rem;
  border: 1px solid #f3f4f6;
  border-radius: 0.55rem;
  background: #fafafa;
}
.shopify-inv-stats__icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 1.65rem;
  height: 1.65rem;
  border-radius: 0.4rem;
  flex-shrink: 0;
}
.shopify-inv-stats__icon--alloc {
  background: #ffedd5;
  color: #ea580c;
}
.shopify-inv-stats__icon--avail {
  background: #dcfce7;
  color: #16a34a;
}
.shopify-inv-stats__icon--back {
  background: #fee2e2;
  color: #dc2626;
}
.shopify-inv-stats__icon--asn {
  background: #ede9fe;
  color: #7c3aed;
}
.shopify-inv-stats__value {
  font-size: 1.05rem;
  font-weight: 700;
  color: #111827;
}
.shopify-inv-loc-groups {
  display: flex;
  flex-direction: column;
  gap: 0.55rem;
}
.shopify-inv-loc-group {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  width: 100%;
  padding: 0.85rem 0.9rem;
  border: 1px solid #e5e7eb;
  border-radius: 0.65rem;
  background: #fff;
  color: #6b7280;
  text-align: left;
}
.shopify-inv-loc-group:hover {
  background: #f9fafb;
  border-color: #d1d5db;
}
.shopify-inv-loc-group__icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 2.15rem;
  height: 2.15rem;
  border-radius: 0.5rem;
  flex-shrink: 0;
}
.shopify-inv-loc-group__icon--pick {
  background: #eff6ff;
  color: #2563eb;
}
.shopify-inv-loc-group__icon--backstock {
  background: #eff6ff;
  color: #2563eb;
}
.shopify-inv-loc-group__icon--other {
  background: #f3e8ff;
  color: #7c3aed;
}
.shopify-inv-loc-group__title {
  font-size: 0.9rem;
  font-weight: 700;
  color: #111827;
}
.shopify-inv-loc-group__empty {
  font-size: 0.78rem;
}
.shopify-inv-detail__view-all {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  margin-top: 0.85rem;
  padding: 0;
  border: 0;
  background: transparent;
  color: #2563eb;
  font-size: 0.875rem;
}
.shopify-inv-detail__view-all:hover {
  color: #1d4ed8;
}
@media (max-width: 991.98px) {
  .shopify-inv-dims {
    grid-template-columns: repeat(3, minmax(0, 1fr));
    row-gap: 0.85rem;
  }
}
@media (max-width: 575.98px) {
  .shopify-inv-dims {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
  .shopify-inv-hero__footer {
    grid-template-columns: 1fr;
  }
}
</style>
