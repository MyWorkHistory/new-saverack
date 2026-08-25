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

function formatDim(val) {
  if (val === "" || val == null || Number.isNaN(Number(val))) return "—";
  return `${formatNum(val)} ${dimUnitLabel.value}`;
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
        class="shopify-inv-detail__back"
        @click="router.push({ name: 'shopify-inventory' })"
      >
        <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" />
        </svg>
        Back to Products
      </button>
      <p class="text-secondary mt-3">Product not found.</p>
    </template>

    <template v-else>
      <header class="shopify-inv-detail__header">
        <button
          type="button"
          class="shopify-inv-detail__back"
          @click="router.push({ name: 'shopify-inventory' })"
        >
          <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" />
          </svg>
          Back to Products
        </button>
        <div class="shopify-inv-detail__header-actions">
          <button type="button" class="shopify-inv-detail__btn-edit" @click="openEdit">
            <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L6.832 16.323a4.5 4.5 0 01-1.897 1.13L2.25 18l.547-2.685a4.5 4.5 0 011.13-1.897L16.863 4.487z" />
              <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 7.125L16.875 4.5" />
            </svg>
            Edit Product
          </button>
          <div ref="actionsRoot" class="position-relative">
            <button
              type="button"
              class="shopify-inv-detail__btn-actions"
              :disabled="actionBusy"
              :aria-expanded="actionsOpen"
              @click.stop="actionsOpen = !actionsOpen"
            >
              {{ actionBusy ? "Working…" : "Actions" }}
              <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
              </svg>
            </button>
            <div v-if="actionsOpen" class="shopify-inv-detail__actions-menu" role="menu">
              <button type="button" class="shopify-inv-detail__actions-item" role="menuitem" @click="syncProductInfo">
                <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182" />
                </svg>
                Sync Product Info
              </button>
              <button type="button" class="shopify-inv-detail__actions-item" role="menuitem" @click="pushInventory">
                <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M12 16.5V9.75m0 0l3 3m-3-3l-3 3M6.75 19.5a4.5 4.5 0 01-1.41-8.775 5.25 5.25 0 0110.233-2.33 3 3 0 013.758 3.848A3.752 3.752 0 0118 19.5H6.75z" />
                </svg>
                Push Inventory
              </button>
            </div>
          </div>
        </div>
      </header>

      <div class="shopify-inv-detail__grid">
        <!-- Left -->
        <div class="shopify-inv-detail__col">
          <!-- Product overview -->
          <section class="shopify-inv-card">
            <div class="shopify-inv-hero">
              <div class="shopify-inv-hero__thumb" aria-hidden="true">
                <img
                  v-if="variant.image_url"
                  :src="variant.image_url"
                  :alt="variant.product_title || 'Product'"
                />
                <span v-else class="shopify-inv-hero__thumb-empty">
                  <svg width="32" height="32" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.4">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909M3.75 21h16.5A2.25 2.25 0 0022.5 18.75V5.25A2.25 2.25 0 0020.25 3H3.75A2.25 2.25 0 001.5 5.25v13.5A2.25 2.25 0 003.75 21z" />
                  </svg>
                </span>
              </div>
              <div class="shopify-inv-hero__body">
                <div class="shopify-inv-hero__title-row">
                  <h1 class="shopify-inv-hero__title">
                    {{ variant.product_title || variant.title || "Product" }}
                  </h1>
                  <button type="button" class="shopify-inv-detail__btn-edit shopify-inv-detail__btn-edit--sm" @click="openEdit">
                    <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L6.832 16.323a4.5 4.5 0 01-1.897 1.13L2.25 18l.547-2.685a4.5 4.5 0 011.13-1.897L16.863 4.487z" />
                    </svg>
                    Edit
                  </button>
                </div>

                <div class="shopify-inv-hero__ids">
                  <div>
                    <div class="shopify-inv-label">SKU</div>
                    <div class="shopify-inv-sku">{{ variant.sku || "—" }}</div>
                  </div>
                  <div>
                    <div class="shopify-inv-label">Barcode</div>
                    <div class="shopify-inv-barcode">
                      <span>{{ variant.barcode || "—" }}</span>
                      <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
                        <path stroke-linecap="round" d="M4 7v10M7 7v10M10 7v10M14 7v10M17 7v10M20 7v10" />
                      </svg>
                    </div>
                  </div>
                </div>

                <div class="shopify-inv-hero__meta">
                  <div class="shopify-inv-hero__meta-item">
                    <span class="shopify-inv-hero__meta-icon" aria-hidden="true">
                      <!-- Storefront -->
                      <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 21v-7.5a.75.75 0 01.75-.75h3a.75.75 0 01.75.75V21M3.75 21V9.834c0-.345.134-.676.372-.92l6.75-6.938a1.5 1.5 0 012.256 0l6.75 6.938c.238.244.372.575.372.92V21M3.75 21h16.5M9 21v-4.5a.75.75 0 01.75-.75h1.5a.75.75 0 01.75.75V21" />
                      </svg>
                    </span>
                    <div>
                      <div class="shopify-inv-label">Account</div>
                      <div class="shopify-inv-meta-value">{{ variant.account_name || "—" }}</div>
                    </div>
                  </div>
                  <div class="shopify-inv-hero__meta-item">
                    <span class="shopify-inv-hero__meta-icon" aria-hidden="true">
                      <!-- Price tag -->
                      <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9.568 3H5.25A2.25 2.25 0 003 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 005.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 009.568 3z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 6h.008v.008H6V6z" />
                      </svg>
                    </span>
                    <div>
                      <div class="shopify-inv-label">Type</div>
                      <div class="shopify-inv-meta-value">Product</div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </section>

          <!-- Dimensions -->
          <section class="shopify-inv-card shopify-inv-dims">
            <div class="shopify-inv-dims__item">
              <span class="shopify-inv-dims__icon" aria-hidden="true">
                <!-- Horizontal double arrow -->
                <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M7.5 12H3m0 0l3-3M3 12l3 3M16.5 12H21m0 0l-3-3m3 3l-3 3M8 12h8" />
                </svg>
              </span>
              <div class="shopify-inv-label">Length</div>
              <div class="shopify-inv-dims__value">{{ formatDim(variant.length) }}</div>
            </div>
            <div class="shopify-inv-dims__item">
              <span class="shopify-inv-dims__icon" aria-hidden="true">
                <!-- Vertical double arrow -->
                <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M12 7.5V3m0 0L9 6m3-3l3 3M12 16.5V21m0 0l3-3m-3 3l-3-3M12 8v8" />
                </svg>
              </span>
              <div class="shopify-inv-label">Width</div>
              <div class="shopify-inv-dims__value">{{ formatDim(variant.width) }}</div>
            </div>
            <div class="shopify-inv-dims__item">
              <span class="shopify-inv-dims__icon" aria-hidden="true">
                <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M12 7.5V3m0 0L9 6m3-3l3 3M12 16.5V21m0 0l3-3m-3 3l-3-3M12 8v8" />
                </svg>
              </span>
              <div class="shopify-inv-label">Height</div>
              <div class="shopify-inv-dims__value">{{ formatDim(variant.height) }}</div>
            </div>
            <div class="shopify-inv-dims__item">
              <span class="shopify-inv-dims__icon" aria-hidden="true">
                <!-- 3D box -->
                <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M21 7.5l-9-5.25L3 7.5m18 0l-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9" />
                </svg>
              </span>
              <div class="shopify-inv-label">Cubic Ft</div>
              <div class="shopify-inv-dims__value">
                <template v-if="cubicFeet != null">{{ formatNum(cubicFeet, 3) }} ft³</template>
                <template v-else>—</template>
              </div>
            </div>
            <div class="shopify-inv-dims__item">
              <span class="shopify-inv-dims__icon" aria-hidden="true">
                <!-- Scale / weight -->
                <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v1.5m0 0a3 3 0 013 3v.75M12 4.5a3 3 0 00-3 3v.75m3 12.75V21m-6.75-3.75h13.5M5.25 9.75l1.5 6h10.5l1.5-6H5.25z" />
                </svg>
              </span>
              <div class="shopify-inv-label">Weight</div>
              <div class="shopify-inv-dims__value">
                <template v-if="variant.weight != null && variant.weight !== ''">
                  {{ formatNum(variant.weight) }} {{ weightUnitLabel }}
                </template>
                <template v-else>—</template>
              </div>
            </div>
          </section>

          <!-- Bundle shell -->
          <section class="shopify-inv-card">
            <div class="shopify-inv-card__head">
              <div class="shopify-inv-card__head-left">
                <span class="shopify-inv-card__head-icon" aria-hidden="true">
                  <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 10.5V6a3.75 3.75 0 10-7.5 0v4.5m11.356-1.993l1.263 12c.07.665-.45 1.243-1.119 1.243H4.25a1.125 1.125 0 01-1.12-1.243l1.264-12A1.125 1.125 0 015.513 7.5h12.974c.576 0 1.059.435 1.119 1.007z" />
                  </svg>
                </span>
                <h2 class="shopify-inv-card__title">Bundle</h2>
              </div>
              <button type="button" class="shopify-inv-link-btn" @click="comingSoon('Add Items')">
                + Add Items
              </button>
            </div>
            <div class="shopify-inv-label mb-2">Items in this bundle</div>
            <div class="shopify-inv-bundle-empty">
              <svg width="40" height="40" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.25" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M21 7.5l-9-5.25L3 7.5m18 0l-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9" />
              </svg>
              <p>This item is not a bundle.</p>
            </div>
          </section>
        </div>

        <!-- Right -->
        <div class="shopify-inv-detail__col">
          <section class="shopify-inv-card">
            <div class="shopify-inv-onhand">
              <span class="shopify-inv-onhand__icon" aria-hidden="true">
                <svg width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M21 7.5l-9-5.25L3 7.5m18 0l-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9" />
                </svg>
              </span>
              <div>
                <div class="shopify-inv-label">Total On Hand</div>
                <div class="shopify-inv-onhand__value">
                  {{ inventoryStats.total_on_hand.toLocaleString("en-US") }}
                </div>
              </div>
            </div>
            <div class="shopify-inv-stats">
              <div class="shopify-inv-stats__item">
                <span class="shopify-inv-stats__icon shopify-inv-stats__icon--alloc" aria-hidden="true">
                  <!-- Stacked layers -->
                  <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6.429 9.75L2.25 12l4.179 2.25m0-4.5l5.571 3 5.571-3m-11.142 0L2.25 7.5 12 2.25l9.75 5.25-4.179 2.25m0 0L21.75 12l-4.179 2.25m0 0l4.179 2.25L12 21.75 2.25 16.5l4.179-2.25m11.142 0l-5.571 3-5.571-3" />
                  </svg>
                </span>
                <div>
                  <div class="shopify-inv-label">Allocated</div>
                  <div class="shopify-inv-stats__value">{{ inventoryStats.allocated }}</div>
                </div>
              </div>
              <div class="shopify-inv-stats__item">
                <span class="shopify-inv-stats__icon shopify-inv-stats__icon--avail" aria-hidden="true">
                  <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                  </svg>
                </span>
                <div>
                  <div class="shopify-inv-label">Available</div>
                  <div class="shopify-inv-stats__value">{{ inventoryStats.available }}</div>
                </div>
              </div>
              <div class="shopify-inv-stats__item">
                <span class="shopify-inv-stats__icon shopify-inv-stats__icon--back" aria-hidden="true">
                  <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                  </svg>
                </span>
                <div>
                  <div class="shopify-inv-label">Backorder</div>
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
                  <div class="shopify-inv-label">ASN</div>
                  <div class="shopify-inv-stats__value">{{ inventoryStats.asn }}</div>
                </div>
              </div>
            </div>
          </section>

          <section class="shopify-inv-card">
            <div class="shopify-inv-card__head">
              <div>
                <div class="shopify-inv-card__head-left mb-1">
                  <span class="shopify-inv-card__head-icon" aria-hidden="true">
                    <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z" />
                      <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z" />
                    </svg>
                  </span>
                  <h2 class="shopify-inv-card__title">Locations</h2>
                </div>
                <p class="shopify-inv-card__sub">Manage inventory by location.</p>
              </div>
              <button type="button" class="shopify-inv-link-btn" @click="comingSoon('Add Location')">
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
                  <svg v-if="group.icon === 'cart'" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 3h1.386c.51 0 .955.343 1.087.835l.383 1.437M7.5 14.25a3 3 0 00-3 3h15.75m-12.75-3h11.218c1.121-2.3 2.1-4.684 2.924-7.138a60.114 60.114 0 00-16.536-1.84M7.5 14.25L5.106 5.272M6 20.25a.75.75 0 11-1.5 0 .75.75 0 011.5 0zm12.75 0a.75.75 0 11-1.5 0 .75.75 0 011.5 0z" />
                  </svg>
                  <svg v-else-if="group.icon === 'cube'" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 7.5l-9-5.25L3 7.5m18 0l-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9" />
                  </svg>
                  <svg v-else width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 10.5V6a3.75 3.75 0 10-7.5 0v4.5m11.356-1.993l1.263 12c.07.665-.45 1.243-1.119 1.243H4.25a1.125 1.125 0 01-1.12-1.243l1.264-12A1.125 1.125 0 015.513 7.5h12.974c.576 0 1.059.435 1.119 1.007z" />
                  </svg>
                </span>
                <div class="min-w-0 flex-grow-1 text-start">
                  <div class="shopify-inv-loc-group__title-row">
                    <span class="shopify-inv-loc-group__title">{{ group.label }}</span>
                    <span class="shopify-inv-loc-group__badge">{{ group.count }}</span>
                  </div>
                  <div class="shopify-inv-loc-group__empty">No locations yet</div>
                </div>
                <svg class="shopify-inv-loc-group__chevron" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                </svg>
              </button>
            </div>

            <button
              type="button"
              class="shopify-inv-detail__view-all"
              @click="router.push({ name: 'shopify-locations' })"
            >
              View All Locations
              <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
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
.shopify-inv-detail__header {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  margin-bottom: 1.25rem;
}
.shopify-inv-detail__back {
  display: inline-flex;
  align-items: center;
  gap: 0.25rem;
  border: 0;
  background: transparent;
  color: #2563eb;
  font-size: 0.9rem;
  font-weight: 600;
  padding: 0;
}
.shopify-inv-detail__back:hover {
  color: #1d4ed8;
}
.shopify-inv-detail__header-actions {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.5rem;
}
.shopify-inv-detail__btn-edit {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  border: 1px solid #93c5fd;
  background: #fff;
  color: #2563eb;
  border-radius: 0.5rem;
  padding: 0.5rem 0.9rem;
  font-size: 0.875rem;
  font-weight: 600;
}
.shopify-inv-detail__btn-edit:hover {
  background: #eff6ff;
  color: #1d4ed8;
}
.shopify-inv-detail__btn-edit--sm {
  padding: 0.3rem 0.65rem;
  font-size: 0.8rem;
  flex-shrink: 0;
}
.shopify-inv-detail__btn-actions {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  border: 0;
  border-radius: 0.5rem;
  padding: 0.5rem 0.95rem;
  font-size: 0.875rem;
  font-weight: 600;
  background: #2563eb;
  color: #fff;
}
.shopify-inv-detail__btn-actions:hover:not(:disabled) {
  background: #1d4ed8;
}
.shopify-inv-detail__btn-actions:disabled {
  opacity: 0.7;
}
.shopify-inv-detail__actions-menu {
  position: absolute;
  right: 0;
  top: calc(100% + 4px);
  z-index: 20;
  min-width: 13rem;
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
.shopify-inv-detail__grid {
  display: grid;
  grid-template-columns: minmax(0, 1.35fr) minmax(0, 1fr);
  gap: 1rem;
  align-items: start;
}
.shopify-inv-detail__col {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  min-width: 0;
}
.shopify-inv-card {
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 0.85rem;
  padding: 1.2rem 1.3rem;
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
.shopify-inv-card__head-left {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}
.shopify-inv-card__title {
  margin: 0;
  font-size: 1.05rem;
  font-weight: 700;
  color: #111827;
}
.shopify-inv-card__sub {
  margin: 0 0 0 1.7rem;
  font-size: 0.8rem;
  color: #6b7280;
}
.shopify-inv-card__head-icon {
  display: inline-flex;
  color: #2563eb;
}
.shopify-inv-link-btn {
  border: 0;
  background: transparent;
  color: #2563eb;
  padding: 0.2rem 0.35rem;
  font-size: 0.875rem;
  font-weight: 600;
}
.shopify-inv-link-btn:hover {
  color: #1d4ed8;
  background: #eff6ff;
  border-radius: 0.35rem;
}
.shopify-inv-label {
  font-size: 0.68rem;
  font-weight: 600;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  color: #9ca3af;
  margin-bottom: 0.2rem;
}
.shopify-inv-hero {
  display: flex;
  gap: 1.25rem;
  align-items: stretch;
}
.shopify-inv-hero__thumb {
  width: 8.75rem;
  height: 8.75rem;
  border-radius: 0.75rem;
  overflow: hidden;
  background: #f3f4f6;
  border: 1px solid #e5e7eb;
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
.shopify-inv-hero__body {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
}
.shopify-inv-hero__title-row {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  justify-content: space-between;
  gap: 0.65rem;
  margin-bottom: 1rem;
}
.shopify-inv-hero__title {
  margin: 0;
  font-size: 1.4rem;
  font-weight: 700;
  color: #111827;
  line-height: 1.25;
}
.shopify-inv-hero__ids {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 1rem;
  margin-bottom: 1rem;
}
.shopify-inv-sku {
  font-size: 1.05rem;
  font-weight: 700;
  color: #2563eb;
  word-break: break-word;
}
.shopify-inv-barcode {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  font-size: 0.95rem;
  font-weight: 600;
  color: #111827;
  word-break: break-all;
}
.shopify-inv-barcode svg {
  color: #2563eb;
  flex-shrink: 0;
}
.shopify-inv-hero__meta {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.85rem;
  margin-top: auto;
  padding-top: 0.95rem;
  border-top: 1px solid #f3f4f6;
}
.shopify-inv-hero__meta-item {
  display: flex;
  align-items: flex-start;
  gap: 0.55rem;
}
.shopify-inv-hero__meta-icon {
  display: inline-flex;
  color: #6b7280;
  margin-top: 0.1rem;
}
.shopify-inv-meta-value {
  font-size: 0.92rem;
  font-weight: 600;
  color: #111827;
}
.shopify-inv-dims {
  display: grid;
  grid-template-columns: repeat(5, minmax(0, 1fr));
  gap: 0.35rem;
  padding-top: 1.1rem;
  padding-bottom: 1.1rem;
}
.shopify-inv-dims__item {
  text-align: center;
  min-width: 0;
  padding: 0.15rem;
}
.shopify-inv-dims__icon {
  display: inline-flex;
  color: #9ca3af;
  margin-bottom: 0.35rem;
}
.shopify-inv-dims__value {
  font-size: 0.92rem;
  font-weight: 700;
  color: #111827;
  line-height: 1.25;
}
.shopify-inv-bundle-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 0.55rem;
  min-height: 9rem;
  border: 1px dashed #d1d5db;
  border-radius: 0.7rem;
  color: #9ca3af;
  font-size: 0.9rem;
  background: #fafafa;
  text-align: center;
  padding: 1.25rem;
}
.shopify-inv-bundle-empty p {
  margin: 0;
}
.shopify-inv-onhand {
  display: flex;
  align-items: center;
  gap: 0.85rem;
  padding-bottom: 1.05rem;
  margin-bottom: 1.05rem;
  border-bottom: 1px solid #f3f4f6;
}
.shopify-inv-onhand__icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 3rem;
  height: 3rem;
  border-radius: 999px;
  background: #eff6ff;
  color: #2563eb;
  flex-shrink: 0;
}
.shopify-inv-onhand__value {
  font-size: 1.85rem;
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
  padding: 0.75rem 0.8rem;
  border: 1px solid #f3f4f6;
  border-radius: 0.6rem;
  background: #fafafa;
}
.shopify-inv-stats__icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 1.75rem;
  height: 1.75rem;
  border-radius: 999px;
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
  font-size: 1.1rem;
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
  padding: 0.9rem 0.95rem;
  border: 1px solid #e5e7eb;
  border-radius: 0.7rem;
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
  width: 2.25rem;
  height: 2.25rem;
  border-radius: 0.55rem;
  flex-shrink: 0;
}
.shopify-inv-loc-group__icon--pick,
.shopify-inv-loc-group__icon--backstock {
  background: #eff6ff;
  color: #2563eb;
}
.shopify-inv-loc-group__icon--other {
  background: #f3e8ff;
  color: #7c3aed;
}
.shopify-inv-loc-group__title-row {
  display: flex;
  align-items: center;
  gap: 0.45rem;
  flex-wrap: wrap;
}
.shopify-inv-loc-group__title {
  font-size: 0.9rem;
  font-weight: 700;
  color: #111827;
}
.shopify-inv-loc-group__badge {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 1.35rem;
  height: 1.25rem;
  padding: 0 0.35rem;
  border-radius: 999px;
  background: #dbeafe;
  color: #1d4ed8;
  font-size: 0.72rem;
  font-weight: 700;
}
.shopify-inv-loc-group__empty {
  margin-top: 0.15rem;
  font-size: 0.78rem;
  color: #9ca3af;
}
.shopify-inv-loc-group__chevron {
  color: #2563eb;
  flex-shrink: 0;
}
.shopify-inv-detail__view-all {
  display: inline-flex;
  align-items: center;
  gap: 0.3rem;
  margin-top: 0.9rem;
  padding: 0;
  border: 0;
  background: transparent;
  color: #2563eb;
  font-size: 0.875rem;
  font-weight: 600;
}
.shopify-inv-detail__view-all:hover {
  color: #1d4ed8;
}
@media (max-width: 991.98px) {
  .shopify-inv-detail__grid {
    grid-template-columns: 1fr;
  }
  .shopify-inv-dims {
    grid-template-columns: repeat(3, minmax(0, 1fr));
    row-gap: 0.85rem;
  }
}
@media (max-width: 575.98px) {
  .shopify-inv-hero {
    flex-direction: column;
  }
  .shopify-inv-hero__thumb {
    width: 100%;
    height: 11rem;
  }
  .shopify-inv-hero__ids,
  .shopify-inv-hero__meta {
    grid-template-columns: 1fr;
  }
  .shopify-inv-dims {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}
</style>
