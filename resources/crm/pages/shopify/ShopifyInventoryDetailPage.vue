<script setup>
import { computed, onMounted, onUnmounted, ref } from "vue";
import { RouterLink, useRoute, useRouter } from "vue-router";
import api from "../../services/api";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import { openApiPdfBlob } from "../../utils/openApiPdfBlob.js";
import ShopifyInventoryAddLocationModal from "../../components/shopify/ShopifyInventoryAddLocationModal.vue";
import ShopifyInventoryEditProductModal from "../../components/shopify/ShopifyInventoryEditProductModal.vue";
import ShopifyInventoryProductSettingsModal from "../../components/shopify/ShopifyInventoryProductSettingsModal.vue";
import ShopifyInventoryBundleItemsModal from "../../components/shopify/ShopifyInventoryBundleItemsModal.vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast";

const route = useRoute();
const router = useRouter();
const toast = useToast();

const loading = ref(true);
const saveBusy = ref(false);
const settingsBusy = ref(false);
const bundleBusy = ref(false);
const imageBusy = ref(false);
const printBusy = ref(false);
const actionBusy = ref(false);
const variant = ref(null);
const editOpen = ref(false);
const settingsOpen = ref(false);
const bundleItemsOpen = ref(false);
const actionsOpen = ref(false);
const actionsRoot = ref(null);
const imageInput = ref(null);
const rowMenuOpenId = ref(null);
const qtyEditOpen = ref(false);
const qtyEditBusy = ref(false);
const qtyEditComponent = ref(null);
const qtyEditValue = ref(1);
const expandedLocationGroup = ref(null);
const addLocationOpen = ref(false);
const addItemReasons = ref([]);

const defaultLocationGroups = () => [
  { key: "pick", label: "Pick Locations", icon: "cart", count: 0, locations: [] },
  { key: "backstock", label: "Backstock Locations", icon: "cube", count: 0, locations: [] },
  { key: "other", label: "Other Locations", icon: "bag", count: 0, locations: [] },
];

const inventoryStats = computed(() => {
  const stats = variant.value?.inventory_stats;
  return {
    total_on_hand: Number(stats?.total_on_hand || 0),
    allocated: Number(stats?.allocated || 0),
    available: Number(stats?.available || 0),
    backorder: Number(stats?.backorder || 0),
    asn: Number(stats?.asn || 0),
  };
});

const locationGroups = computed(() => {
  const groups = Array.isArray(variant.value?.location_groups) ? variant.value.location_groups : [];
  if (!groups.length) return defaultLocationGroups();
  const iconByKey = { pick: "cart", backstock: "cube", other: "bag" };
  return groups.map((group) => ({
    key: group.key,
    label: group.label,
    icon: iconByKey[group.key] || "bag",
    count: Number(group.count || 0),
    locations: Array.isArray(group.locations) ? group.locations : [],
  }));
});

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

const isBundle = computed(
  () => String(variant.value?.product_type || "").toLowerCase() === "bundle" || !!variant.value?.bundle,
);

const bundleComponents = computed(() =>
  Array.isArray(variant.value?.bundle_components) ? variant.value.bundle_components : [],
);

const productTypeLabel = computed(() => {
  if (variant.value?.product_type_label) return variant.value.product_type_label;
  return isBundle.value ? "Bundle" : "Standard Product";
});

function openEdit() {
  actionsOpen.value = false;
  editOpen.value = true;
}

function openSettings() {
  actionsOpen.value = false;
  settingsOpen.value = true;
}

function openBundleItems() {
  rowMenuOpenId.value = null;
  bundleItemsOpen.value = true;
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

async function onSaveSettings(payload) {
  if (!variant.value?.id || settingsBusy.value) return;
  settingsBusy.value = true;
  try {
    const { data } = await api.patch(`/shopify/inventory/${variant.value.id}/settings`, payload);
    toast.success(data?.message || "Product settings saved.");
    settingsOpen.value = false;
    if (data?.variant) {
      variant.value = data.variant;
    } else {
      await load();
    }
  } catch (e) {
    toast.errorFrom(e, "Could not save settings.");
  } finally {
    settingsBusy.value = false;
  }
}

async function onSaveBundleItems(items) {
  if (!variant.value?.id || bundleBusy.value) return;
  bundleBusy.value = true;
  try {
    const { data } = await api.put(`/shopify/inventory/${variant.value.id}/bundle-components`, {
      items,
    });
    toast.success(data?.message || "Bundle components saved.");
    bundleItemsOpen.value = false;
    if (Array.isArray(data?.components) && variant.value) {
      variant.value = { ...variant.value, bundle_components: data.components };
    } else {
      await load();
    }
  } catch (e) {
    toast.errorFrom(e, "Could not save bundle items.");
  } finally {
    bundleBusy.value = false;
  }
}

function pickImage() {
  if (imageBusy.value) return;
  imageInput.value?.click?.();
}

async function onImageSelected(event) {
  const file = event?.target?.files?.[0];
  if (event?.target) event.target.value = "";
  if (!file || !variant.value?.id || imageBusy.value) return;
  imageBusy.value = true;
  try {
    const form = new FormData();
    form.append("image", file);
    const { data } = await api.post(`/shopify/inventory/${variant.value.id}/image`, form, {
      headers: { "Content-Type": "multipart/form-data" },
    });
    toast.success(data?.message || "Image updated.");
    if (data?.variant) {
      variant.value = data.variant;
    } else if (data?.image_url && variant.value) {
      variant.value = { ...variant.value, image_url: data.image_url };
    } else {
      await load();
    }
  } catch (e) {
    toast.errorFrom(e, "Could not upload image.");
  } finally {
    imageBusy.value = false;
  }
}

async function printBarcode() {
  if (!variant.value?.id || printBusy.value) return;
  printBusy.value = true;
  try {
    await openApiPdfBlob(api, `/shopify/inventory/${variant.value.id}/barcode-label.pdf`);
  } catch (e) {
    const msg = e instanceof Error ? e.message : "";
    toast.error(msg || "Could not open barcode label PDF.");
  } finally {
    printBusy.value = false;
  }
}

function toggleRowMenu(id) {
  rowMenuOpenId.value = rowMenuOpenId.value === id ? null : id;
}

function openQtyEdit(component) {
  rowMenuOpenId.value = null;
  qtyEditComponent.value = component;
  qtyEditValue.value = Math.max(1, Number(component.quantity) || 1);
  qtyEditOpen.value = true;
}

async function saveQtyEdit() {
  if (!variant.value?.id || !qtyEditComponent.value?.id || qtyEditBusy.value) return;
  qtyEditBusy.value = true;
  try {
    const { data } = await api.patch(
      `/shopify/inventory/${variant.value.id}/bundle-components/${qtyEditComponent.value.id}`,
      { quantity: Math.max(1, Number(qtyEditValue.value) || 1) },
    );
    toast.success(data?.message || "Quantity updated.");
    qtyEditOpen.value = false;
    if (Array.isArray(data?.components) && variant.value) {
      variant.value = { ...variant.value, bundle_components: data.components };
    } else {
      await load();
    }
  } catch (e) {
    toast.errorFrom(e, "Could not update quantity.");
  } finally {
    qtyEditBusy.value = false;
  }
}

async function deleteBundleComponent(component) {
  rowMenuOpenId.value = null;
  if (!variant.value?.id || !component?.id || bundleBusy.value) return;
  bundleBusy.value = true;
  try {
    const { data } = await api.delete(
      `/shopify/inventory/${variant.value.id}/bundle-components/${component.id}`,
    );
    toast.success(data?.message || "Component removed.");
    if (Array.isArray(data?.components) && variant.value) {
      variant.value = { ...variant.value, bundle_components: data.components };
    } else {
      await load();
    }
  } catch (e) {
    toast.errorFrom(e, "Could not remove component.");
  } finally {
    bundleBusy.value = false;
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

function toggleLocationGroup(key) {
  expandedLocationGroup.value = expandedLocationGroup.value === key ? null : key;
}

function openAddLocation() {
  addLocationOpen.value = true;
}

function formatLocationQty(loc) {
  const name = String(loc?.name || "—");
  const qty = Number(loc?.available || 0);
  return `${name} (${qty.toLocaleString("en-US")})`;
}

function onDocClick(event) {
  if (!actionsRoot.value?.contains?.(event.target)) {
    actionsOpen.value = false;
  }
  const menuEl = event.target?.closest?.("[data-sid-row-menu]");
  if (!menuEl) {
    rowMenuOpenId.value = null;
  }
}

onMounted(async () => {
  setCrmPageMeta({
    title: "Save Rack | Shopify Variant",
    description: "Shopify product inventory details.",
  });
  document.addEventListener("click", onDocClick);
  try {
    const { data } = await api.get("/shopify/locations/meta");
    addItemReasons.value = Array.isArray(data?.add_item_reasons) ? data.add_item_reasons : [];
  } catch {
    addItemReasons.value = [
      "Account Setup",
      "Client Request",
      "Cycle Count",
      "Expired",
      "Kitting / Bundling",
      "Order Fulfillment",
      "Picking Error",
      "Putaway Error",
      "Receiving Discrepancy",
      "Restock",
      "Return",
    ];
  }
  void load();
});

onUnmounted(() => {
  document.removeEventListener("click", onDocClick);
});
</script>

<template>
  <div class="staff-page staff-page--wide sid">
    <div v-if="loading" class="p-5 d-flex justify-content-center">
      <CrmLoadingSpinner message="Loading…" />
    </div>

    <template v-else-if="!variant">
      <button type="button" class="sid-back" @click="router.push({ name: 'shopify-inventory' })">
        <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" />
        </svg>
        Back to Products
      </button>
      <p class="text-secondary mt-3">Product not found.</p>
    </template>

    <template v-else>
      <header class="sid-header">
        <button type="button" class="sid-back" @click="router.push({ name: 'shopify-inventory' })">
          <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" />
          </svg>
          Back to Products
        </button>
        <div class="staff-detail-tab-bar-actions sid-header__actions">
          <button
            type="button"
            class="staff-outline-action-btn"
            :disabled="printBusy"
            @click="printBarcode"
          >
            <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" d="M6.72 13.829c-.24.03-.48.062-.72.096m.72-.096a42.415 42.415 0 0110.56 0m-10.56 0L6.34 18m10.94-4.171c.24.03.48.062.72.096m-.72-.096L17.66 18m0 0l.229 2.523a1.125 1.125 0 01-1.12 1.227H7.231c-.662 0-1.18-.568-1.12-1.227L6.34 18m11.318 0h1.091A2.25 2.25 0 0021 15.75V9.456c0-1.081-.768-2.015-1.837-2.175a48.055 48.055 0 00-1.913-.247M6.34 18H5.25A2.25 2.25 0 013 15.75V9.456c0-1.081.768-2.015 1.837-2.175a48.041 48.041 0 011.913-.247m10.5 0a48.536 48.536 0 00-10.5 0m10.5 0V6.375c0-.621-.504-1.125-1.125-1.125h-8.25c-.621 0-1.125.504-1.125 1.125v.852m10.5 0V9.75m0 0a48.063 48.063 0 01-10.5 0" />
            </svg>
            {{ printBusy ? "Generating Label… Please Wait" : "Print Barcode" }}
          </button>
          <div ref="actionsRoot" class="sid-actions-wrap">
            <button
              type="button"
              class="staff-outline-action-btn"
              :class="{ 'staff-outline-action-btn--active': actionsOpen }"
              :disabled="actionBusy"
              :aria-expanded="actionsOpen"
              @click.stop="actionsOpen = !actionsOpen"
            >
              Actions
              <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
              </svg>
            </button>
            <div v-if="actionsOpen" class="sid-menu" role="menu">
              <button type="button" class="sid-menu__item" role="menuitem" @click="syncProductInfo">
                <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182" />
                </svg>
                Sync Product Info
              </button>
              <button type="button" class="sid-menu__item" role="menuitem" @click="pushInventory">
                <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M12 16.5V9.75m0 0l3 3m-3-3l-3 3M6.75 19.5a4.5 4.5 0 01-1.41-8.775 5.25 5.25 0 0110.233-2.33 3 3 0 013.758 3.848A3.752 3.752 0 0118 19.5H6.75z" />
                </svg>
                Push Inventory
              </button>
              <button type="button" class="sid-menu__item" role="menuitem" @click="openSettings">
                <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M9.568 3H5.25A2.25 2.25 0 003 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 005.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 009.568 3z" />
                  <path stroke-linecap="round" stroke-linejoin="round" d="M6 6h.008v.008H6V6z" />
                </svg>
                Edit Product Type
              </button>
            </div>
          </div>
        </div>
      </header>

      <div class="sid-grid">
        <div class="sid-col">
          <!-- Product + dimensions (one card, divider before specs) -->
          <section class="sid-card">
            <div class="sid-product">
              <button
                type="button"
                class="sid-product__img sid-product__img--clickable"
                :disabled="imageBusy"
                :title="imageBusy ? 'Uploading…' : 'Click to upload image'"
                @click="pickImage"
              >
                <img
                  v-if="variant.image_url"
                  :src="variant.image_url"
                  :alt="variant.product_title || 'Product'"
                />
                <span v-else class="sid-product__img-empty" aria-hidden="true">
                  <svg width="40" height="40" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.35">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909M3.75 21h16.5A2.25 2.25 0 0022.5 18.75V5.25A2.25 2.25 0 0020.25 3H3.75A2.25 2.25 0 001.5 5.25v13.5A2.25 2.25 0 003.75 21z" />
                  </svg>
                </span>
                <span class="sid-product__img-hint">{{ imageBusy ? "Uploading…" : "Click to Upload" }}</span>
              </button>
              <input
                ref="imageInput"
                type="file"
                accept="image/*"
                class="d-none"
                @change="onImageSelected"
              />

              <div class="sid-product__info">
                <div class="sid-product__title-row">
                  <h1 class="sid-product__title">
                    {{ variant.product_title || variant.title || "Product" }}
                  </h1>
                  <button type="button" class="staff-outline-action-btn staff-outline-action-btn--sm" @click="openEdit">
                    <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L6.832 16.323a4.5 4.5 0 01-1.897 1.13L2.25 18l.547-2.685a4.5 4.5 0 011.13-1.897L16.863 4.487z" />
                    </svg>
                    Edit
                  </button>
                </div>

                <div class="sid-field">
                  <div class="sid-field__label">SKU</div>
                  <div class="sid-field__sku">{{ variant.sku || "—" }}</div>
                </div>

                <div class="sid-field sid-field--gap">
                  <div class="sid-field__label">Barcode</div>
                  <div class="sid-field__barcode">
                    <span>{{ variant.barcode || "—" }}</span>
                    <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
                      <path stroke-linecap="round" d="M3.75 5.25v13.5M7.5 5.25v13.5M10.5 5.25v13.5M14.25 5.25v13.5M17.25 5.25v13.5M20.25 5.25v13.5" />
                    </svg>
                  </div>
                </div>

                <div class="sid-product__meta">
                  <div class="sid-meta">
                    <span class="sid-meta__icon" aria-hidden="true">
                      <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12l8.954-8.955a1.125 1.125 0 011.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" />
                      </svg>
                    </span>
                    <div>
                      <div class="sid-field__label">Account</div>
                      <div class="sid-meta__value">{{ variant.account_name || "—" }}</div>
                    </div>
                  </div>
                  <div class="sid-meta">
                    <span class="sid-meta__icon" aria-hidden="true">
                      <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9.568 3H5.25A2.25 2.25 0 003 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 005.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 009.568 3z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 6h.008v.008H6V6z" />
                      </svg>
                    </span>
                    <div>
                      <div class="sid-field__label">Type</div>
                      <div class="sid-meta__value">{{ productTypeLabel }}</div>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <div class="sid-specs">
              <div class="sid-specs__item">
                <span class="sid-specs__icon" aria-hidden="true">
                  <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 12h16.5m0 0l-3.75-3.75M20.25 12L16.5 15.75M3.75 12L7.5 8.25M3.75 12L7.5 15.75" />
                  </svg>
                </span>
                <div class="sid-field__label">Length</div>
                <div class="sid-specs__value">{{ formatDim(variant.length) }}</div>
              </div>
              <div class="sid-specs__item">
                <span class="sid-specs__icon" aria-hidden="true">
                  <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 3.75v16.5m0 0l3.75-3.75M12 20.25L8.25 16.5M12 3.75L8.25 7.5M12 3.75L15.75 7.5" />
                  </svg>
                </span>
                <div class="sid-field__label">Width</div>
                <div class="sid-specs__value">{{ formatDim(variant.width) }}</div>
              </div>
              <div class="sid-specs__item">
                <span class="sid-specs__icon" aria-hidden="true">
                  <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 3.75v16.5m0 0l3.75-3.75M12 20.25L8.25 16.5M12 3.75L8.25 7.5M12 3.75L15.75 7.5" />
                  </svg>
                </span>
                <div class="sid-field__label">Height</div>
                <div class="sid-specs__value">{{ formatDim(variant.height) }}</div>
              </div>
              <div class="sid-specs__item">
                <span class="sid-specs__icon" aria-hidden="true">
                  <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 7.5l-9-5.25L3 7.5m18 0l-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9" />
                  </svg>
                </span>
                <div class="sid-field__label">Cubic Ft</div>
                <div class="sid-specs__value">
                  <template v-if="cubicFeet != null">{{ formatNum(cubicFeet, 3) }} ft³</template>
                  <template v-else>—</template>
                </div>
              </div>
              <div class="sid-specs__item">
                <span class="sid-specs__icon" aria-hidden="true">
                  <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 7.5V6.75A3.75 3.75 0 0112 3h0a3.75 3.75 0 013.75 3.75V7.5M6.75 7.5h10.5c1.243 0 2.25 1.455 2.25 3.25v3c0 3.038-2.015 5.5-4.5 5.5h-6c-2.485 0-4.5-2.462-4.5-5.5v-3c0-1.795 1.007-3.25 2.25-3.25z" />
                  </svg>
                </span>
                <div class="sid-field__label">Weight</div>
                <div class="sid-specs__value">
                  <template v-if="variant.weight != null && variant.weight !== ''">
                    {{ formatNum(variant.weight) }} {{ weightUnitLabel }}
                  </template>
                  <template v-else>—</template>
                </div>
              </div>
            </div>
          </section>

          <!-- Bundle Components (CRM type = Bundle only) -->
          <section v-if="isBundle" class="sid-card">
            <div class="sid-card__head">
              <div>
                <div class="sid-card__head-title">
                  <span class="sid-card__head-icon sid-card__head-icon--bundle" aria-hidden="true">
                    <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.55">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 10.5V6a3.75 3.75 0 10-7.5 0v4.5m11.356-1.993l1.263 12c.07.665-.45 1.243-1.119 1.243H4.25a1.125 1.125 0 01-1.12-1.243l1.264-12A1.125 1.125 0 015.513 7.5h12.974c.576 0 1.059.435 1.119 1.007z" />
                    </svg>
                  </span>
                  <h2>Bundle Components</h2>
                </div>
                <p class="sid-card__sub">Items included in this bundle</p>
              </div>
              <button type="button" class="staff-outline-action-btn staff-outline-action-btn--sm" :disabled="bundleBusy" @click="openBundleItems">
                <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                </svg>
                Add Items
              </button>
            </div>

            <div v-if="!bundleComponents.length" class="sid-bundle-empty">
              <svg width="42" height="42" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M21 7.5l-9-5.25L3 7.5m18 0l-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9" />
              </svg>
              <p>No items in this bundle yet.</p>
            </div>

            <div v-else class="sid-bundle-table-wrap">
              <table class="sid-bundle-table">
                <thead>
                  <tr>
                    <th>Name</th>
                    <th>SKU</th>
                    <th>QTY</th>
                    <th class="sid-bundle-table__actions">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="row in bundleComponents" :key="row.id">
                    <td>
                      <div class="sid-bundle-name">
                        <span class="sid-bundle-name__thumb">
                          <img v-if="row.image_url" :src="row.image_url" alt="" />
                          <span v-else class="sid-bundle-name__thumb-empty" />
                        </span>
                        <span class="sid-bundle-name__text">{{ row.title || "Product" }}</span>
                      </div>
                    </td>
                    <td>{{ row.sku || "—" }}</td>
                    <td>{{ row.quantity }}</td>
                    <td class="sid-bundle-table__actions">
                      <div class="sid-row-menu" data-sid-row-menu>
                        <button
                          type="button"
                          class="sid-row-menu__btn"
                          aria-label="Row Actions"
                          @click.stop="toggleRowMenu(row.id)"
                        >
                          <svg width="16" height="16" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <circle cx="12" cy="5" r="1.6" />
                            <circle cx="12" cy="12" r="1.6" />
                            <circle cx="12" cy="19" r="1.6" />
                          </svg>
                        </button>
                        <div v-if="rowMenuOpenId === row.id" class="sid-row-menu__panel" role="menu">
                          <button type="button" class="sid-menu__item" role="menuitem" @click="openQtyEdit(row)">
                            Edit
                          </button>
                          <button type="button" class="sid-menu__item" role="menuitem" @click="deleteBundleComponent(row)">
                            Delete
                          </button>
                        </div>
                      </div>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </section>
        </div>

        <div class="sid-col">
          <!-- Inventory summary -->
          <section class="sid-card">
            <div class="sid-onhand">
              <span class="sid-onhand__icon" aria-hidden="true">
                <svg width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.55">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M21 7.5l-9-5.25L3 7.5m18 0l-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9" />
                </svg>
              </span>
              <div>
                <div class="sid-field__label">Total On Hand</div>
                <div class="sid-onhand__value">{{ inventoryStats.total_on_hand.toLocaleString("en-US") }}</div>
              </div>
            </div>
            <div class="sid-stats">
              <div class="sid-stat">
                <span class="sid-stat__icon sid-stat__icon--alloc" aria-hidden="true">
                  <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6.429 9.75L2.25 12l4.179 2.25m0-4.5l5.571 3 5.571-3m-11.142 0L2.25 7.5 12 2.25l9.75 5.25-4.179 2.25m0 0L21.75 12l-4.179 2.25m0 0l4.179 2.25L12 21.75 2.25 16.5l4.179-2.25m11.142 0l-5.571 3-5.571-3" />
                  </svg>
                </span>
                <div>
                  <div class="sid-field__label">Allocated</div>
                  <div class="sid-stat__value">{{ inventoryStats.allocated }}</div>
                </div>
              </div>
              <div class="sid-stat">
                <span class="sid-stat__icon sid-stat__icon--avail" aria-hidden="true">
                  <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                  </svg>
                </span>
                <div>
                  <div class="sid-field__label">Available</div>
                  <div class="sid-stat__value">{{ inventoryStats.available }}</div>
                </div>
              </div>
              <div class="sid-stat">
                <span class="sid-stat__icon sid-stat__icon--back" aria-hidden="true">
                  <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                  </svg>
                </span>
                <div>
                  <div class="sid-field__label">Backorder</div>
                  <div class="sid-stat__value">{{ inventoryStats.backorder }}</div>
                </div>
              </div>
              <div class="sid-stat">
                <span class="sid-stat__icon sid-stat__icon--asn" aria-hidden="true">
                  <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 18.75a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 01-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 00-3.213-9.193 2.056 2.056 0 00-1.58-.86H14.25M16.5 18.75h-2.25m0-11.177v-.886c0-.9-.682-1.738-1.53-1.972a49.001 49.001 0 00-6.94-.052C4.682 4.95 4 5.787 4 6.687v.887" />
                  </svg>
                </span>
                <div>
                  <div class="sid-field__label">ASN</div>
                  <div class="sid-stat__value">{{ inventoryStats.asn }}</div>
                </div>
              </div>
            </div>
          </section>

          <!-- Locations -->
          <section class="sid-card">
            <div class="sid-card__head">
              <div>
                <div class="sid-card__head-title">
                  <span class="sid-card__head-icon" aria-hidden="true">
                    <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.55">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z" />
                      <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z" />
                    </svg>
                  </span>
                  <h2>Locations</h2>
                </div>
                <p class="sid-card__sub">Manage inventory by location.</p>
              </div>
              <button type="button" class="staff-outline-action-btn staff-outline-action-btn--sm" @click="openAddLocation">
                <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                </svg>
                Add Location
              </button>
            </div>

            <div class="sid-locs">
              <div
                v-for="group in locationGroups"
                :key="group.key"
                class="sid-loc-wrap"
              >
                <button
                  type="button"
                  class="sid-loc"
                  :class="{ 'sid-loc--expanded': expandedLocationGroup === group.key }"
                  :aria-expanded="expandedLocationGroup === group.key"
                  @click="toggleLocationGroup(group.key)"
                >
                  <span class="sid-loc__icon" :class="`sid-loc__icon--${group.key}`" aria-hidden="true">
                    <svg v-if="group.icon === 'cart'" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 3h1.386c.51 0 .955.343 1.087.835l.383 1.437M7.5 14.25a3 3 0 00-3 3h15.75m-12.75-3h11.218c1.121-2.3 2.1-4.684 2.924-7.138a60.114 60.114 0 00-16.536-1.84M7.5 14.25L5.106 5.272M6 20.25a.75.75 0 11-1.5 0 .75.75 0 011.5 0zm12.75 0a.75.75 0 11-1.5 0 .75.75 0 011.5 0z" />
                    </svg>
                    <svg v-else-if="group.icon === 'cube'" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M21 7.5l-9-5.25L3 7.5m18 0l-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9" />
                    </svg>
                    <svg v-else width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 10.5V6a3.75 3.75 0 10-7.5 0v4.5m11.356-1.993l1.263 12c.07.665-.45 1.243-1.119 1.243H4.25a1.125 1.125 0 01-1.12-1.243l1.264-12A1.125 1.125 0 015.513 7.5h12.974c.576 0 1.059.435 1.119 1.007z" />
                    </svg>
                  </span>
                  <div class="sid-loc__body">
                    <div class="sid-loc__title-row">
                      <span class="sid-loc__title">{{ group.label }}</span>
                      <span class="sid-loc__badge">{{ group.count }}</span>
                    </div>
                    <div v-if="!group.locations.length" class="sid-loc__empty">No locations yet</div>
                    <div v-else-if="expandedLocationGroup !== group.key" class="sid-loc__preview">
                      {{ group.locations.map(formatLocationQty).join(", ") }}
                    </div>
                  </div>
                  <svg
                    class="sid-loc__chevron"
                    :class="{ 'sid-loc__chevron--open': expandedLocationGroup === group.key }"
                    width="16"
                    height="16"
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke="currentColor"
                    stroke-width="2"
                  >
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                  </svg>
                </button>
                <div v-if="expandedLocationGroup === group.key && group.locations.length" class="sid-loc__list">
                  <RouterLink
                    v-for="loc in group.locations"
                    :key="loc.location_id"
                    :to="{ name: 'shopify-location-detail', params: { id: String(loc.location_id) } }"
                    class="sid-loc__item"
                  >
                    <span class="sid-loc__item-name">{{ loc.name }}</span>
                    <span class="sid-loc__item-qty">{{ Number(loc.available || 0).toLocaleString("en-US") }}</span>
                  </RouterLink>
                </div>
              </div>
            </div>

            <button type="button" class="sid-view-all" @click="router.push({ name: 'shopify-locations' })">
              View All Locations
              <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
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
    <ShopifyInventoryProductSettingsModal
      v-model:open="settingsOpen"
      :busy="settingsBusy"
      :variant="variant"
      mode="type-only"
      @save="onSaveSettings"
    />
    <ShopifyInventoryBundleItemsModal
      v-model:open="bundleItemsOpen"
      :busy="bundleBusy"
      :variant-id="variant?.id"
      :existing-components="bundleComponents"
      @save="onSaveBundleItems"
    />
    <ShopifyInventoryAddLocationModal
      v-model:open="addLocationOpen"
      :variant-id="variant?.id"
      :client-account-id="variant?.client_account_id"
      :add-item-reasons="addItemReasons"
      @saved="load"
    />

    <Teleport to="body">
      <div
        v-if="qtyEditOpen"
        class="sid-qty-overlay"
        role="dialog"
        aria-modal="true"
        @click.self="qtyEditOpen = false"
      >
        <div class="sid-qty-modal" @click.stop>
          <h3 class="sid-qty-modal__title">Edit Qty</h3>
          <label class="form-label" for="sid-qty-input">Quantity</label>
          <input
            id="sid-qty-input"
            v-model.number="qtyEditValue"
            type="number"
            min="1"
            class="form-control mb-3"
            :disabled="qtyEditBusy"
          />
          <div class="sid-qty-modal__foot">
            <button
              type="button"
              class="btn btn-outline-primary"
              :disabled="qtyEditBusy"
              @click="qtyEditOpen = false"
            >
              Cancel
            </button>
            <button
              type="button"
              class="btn btn-primary staff-page-primary fw-semibold"
              :disabled="qtyEditBusy"
              @click="saveQtyEdit"
            >
              {{ qtyEditBusy ? "Saving…" : "Save" }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<style scoped>
.sid-header {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  margin-bottom: 1.15rem;
}
.sid-back {
  display: inline-flex;
  align-items: center;
  gap: 0.2rem;
  border: 0;
  background: transparent;
  color: #3b82f6;
  font-size: 0.9rem;
  font-weight: 500;
  padding: 0;
  cursor: pointer;
}
.sid-back:hover {
  color: #2563eb;
}
.sid-header__actions {
  margin-left: auto;
}
.sid-actions-wrap {
  position: relative;
}
.sid-menu {
  position: absolute;
  right: 0;
  top: calc(100% + 6px);
  z-index: 30;
  min-width: 13.75rem;
  padding: 0.35rem;
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 0.6rem;
  box-shadow: 0 10px 30px rgba(15, 23, 42, 0.12);
}
.sid-menu__item {
  display: flex;
  align-items: center;
  gap: 0.65rem;
  width: 100%;
  border: 0;
  background: transparent;
  text-align: left;
  padding: 0.65rem 0.75rem;
  border-radius: 0.4rem;
  font-size: 0.875rem;
  font-weight: 500;
  color: #374151;
  cursor: pointer;
}
.sid-menu__item svg {
  color: #9ca3af;
  flex-shrink: 0;
}
.sid-menu__item:hover {
  background: #f9fafb;
  color: #111827;
}
.sid-grid {
  display: grid;
  grid-template-columns: minmax(0, 1.55fr) minmax(280px, 1fr);
  gap: 1rem;
  align-items: start;
}
.sid-col {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  min-width: 0;
}
.sid-card {
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 0.75rem;
  padding: 1.35rem 1.4rem;
}
.sid-card__head {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  justify-content: space-between;
  gap: 0.75rem;
  margin-bottom: 1rem;
}
.sid-card__head-title {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}
.sid-card__head-title h2 {
  margin: 0;
  font-size: 1.05rem;
  font-weight: 700;
  color: #111827;
}
.sid-card__head-icon {
  display: inline-flex;
  color: #3b82f6;
}
.sid-card__head-icon--bundle {
  color: #7c3aed;
}
.sid-card__sub {
  margin: 0.2rem 0 0 1.7rem;
  font-size: 0.8rem;
  color: #9ca3af;
}
.sid-field__label {
  font-size: 0.72rem;
  font-weight: 500;
  color: #9ca3af;
  margin-bottom: 0.15rem;
  line-height: 1.2;
}
.sid-field--gap {
  margin-top: 0.85rem;
}
.sid-product {
  display: flex;
  gap: 1.35rem;
  align-items: flex-start;
}
.sid-product__img {
  width: 10.5rem;
  height: 10.5rem;
  border-radius: 0.7rem;
  overflow: hidden;
  background: #f3f4f6;
  border: 1px solid #eceff3;
  flex-shrink: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  position: relative;
  padding: 0;
}
.sid-product__img--clickable {
  cursor: pointer;
}
.sid-product__img--clickable:hover .sid-product__img-hint,
.sid-product__img--clickable:focus-visible .sid-product__img-hint {
  opacity: 1;
}
.sid-product__img--clickable:disabled {
  cursor: wait;
  opacity: 0.85;
}
.sid-product__img-hint {
  position: absolute;
  left: 0;
  right: 0;
  bottom: 0;
  padding: 0.35rem 0.4rem;
  background: rgba(15, 23, 42, 0.55);
  color: #fff;
  font-size: 0.68rem;
  font-weight: 600;
  text-align: center;
  opacity: 0;
  transition: opacity 0.15s ease;
}
.sid-product__img img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}
.sid-product__img-empty {
  color: #c0c4cc;
}
.sid-product__info {
  flex: 1;
  min-width: 0;
}
.sid-product__title-row {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  justify-content: space-between;
  gap: 0.6rem;
  margin-bottom: 0.95rem;
}
.sid-product__title {
  margin: 0;
  font-size: 1.4rem;
  font-weight: 700;
  color: #111827;
  line-height: 1.25;
}
.sid-field__sku {
  font-size: 1.45rem;
  font-weight: 700;
  color: #2563eb;
  letter-spacing: 0.01em;
  word-break: break-word;
  line-height: 1.2;
}
.sid-field__barcode {
  display: inline-flex;
  align-items: center;
  gap: 0.45rem;
  font-size: 0.98rem;
  font-weight: 600;
  color: #111827;
  word-break: break-all;
}
.sid-field__barcode svg {
  color: #3b82f6;
  flex-shrink: 0;
}
.sid-product__meta {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.85rem 1.25rem;
  margin-top: 1.05rem;
}
.sid-meta {
  display: flex;
  align-items: flex-start;
  gap: 0.55rem;
}
.sid-meta__icon {
  display: inline-flex;
  color: #9ca3af;
  margin-top: 0.05rem;
}
.sid-meta__value {
  font-size: 0.95rem;
  font-weight: 600;
  color: #111827;
}
/* Specs row inside product card */
.sid-specs {
  display: grid;
  grid-template-columns: repeat(5, minmax(0, 1fr));
  gap: 0.5rem;
  margin-top: 1.3rem;
  padding-top: 1.2rem;
  border-top: 1px solid #e5e7eb;
}
.sid-specs__item {
  text-align: center;
  min-width: 0;
}
.sid-specs__icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 2.25rem;
  height: 2.25rem;
  margin: 0 auto 0.35rem;
  border-radius: 999px;
  background: #f3f4f6;
  color: #9ca3af;
}
.sid-specs__icon svg {
  width: 1.25rem;
  height: 1.25rem;
}
.sid-specs .sid-field__label {
  margin-bottom: 0.2rem;
}
.sid-specs__value {
  font-size: 0.92rem;
  font-weight: 700;
  color: #111827;
  line-height: 1.25;
}
.sid-bundle-label {
  margin-bottom: 0.55rem;
}
.sid-bundle-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 0.55rem;
  min-height: 9.5rem;
  border: 1px dashed #d1d5db;
  border-radius: 0.65rem;
  background: #fafafa;
  color: #9ca3af;
  font-size: 0.9rem;
  text-align: center;
  padding: 1.25rem;
}
.sid-bundle-empty p {
  margin: 0;
}
.sid-bundle-table-wrap {
  overflow-x: auto;
}
.sid-bundle-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.875rem;
}
.sid-bundle-table th {
  text-align: left;
  font-size: 0.72rem;
  font-weight: 600;
  color: #9ca3af;
  padding: 0.45rem 0.5rem;
  border-bottom: 1px solid #e5e7eb;
}
.sid-bundle-table td {
  padding: 0.7rem 0.5rem;
  border-bottom: 1px solid #f3f4f6;
  color: #374151;
  vertical-align: middle;
}
.sid-bundle-table__actions {
  width: 3rem;
  text-align: right;
}
.sid-bundle-name {
  display: flex;
  align-items: center;
  gap: 0.65rem;
  min-width: 0;
}
.sid-bundle-name__thumb {
  width: 2.35rem;
  height: 2.35rem;
  border-radius: 0.4rem;
  overflow: hidden;
  background: #f3f4f6;
  border: 1px solid #eceff3;
  flex-shrink: 0;
  display: flex;
  align-items: center;
  justify-content: center;
}
.sid-bundle-name__thumb img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}
.sid-bundle-name__thumb-empty {
  width: 100%;
  height: 100%;
  background: #e5e7eb;
}
.sid-bundle-name__text {
  font-weight: 600;
  color: #111827;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.sid-row-menu {
  position: relative;
  display: inline-flex;
  justify-content: flex-end;
}
.sid-row-menu__btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 2rem;
  height: 2rem;
  border: 1px solid #3b82f6;
  border-radius: 0.4rem;
  background: #fff;
  color: #3b82f6;
  cursor: pointer;
}
.sid-row-menu__btn:hover {
  background: #eff6ff;
}
.sid-row-menu__panel {
  position: absolute;
  right: 0;
  top: calc(100% + 4px);
  z-index: 20;
  min-width: 8.5rem;
  padding: 0.3rem;
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 0.5rem;
  box-shadow: 0 10px 24px rgba(15, 23, 42, 0.12);
}
.sid-qty-overlay {
  position: fixed;
  inset: 0;
  z-index: 1300;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 1rem;
  background: rgba(15, 23, 42, 0.45);
}
.sid-qty-modal {
  width: 100%;
  max-width: 20rem;
  background: #fff;
  border-radius: 0.75rem;
  padding: 1.15rem 1.25rem;
  box-shadow: 0 20px 40px rgba(15, 23, 42, 0.18);
}
.sid-qty-modal__title {
  margin: 0 0 0.85rem;
  font-size: 1.05rem;
  font-weight: 700;
  color: #111827;
}
.sid-qty-modal__foot {
  display: flex;
  justify-content: flex-end;
  gap: 0.55rem;
}
.sid-onhand {
  display: flex;
  align-items: center;
  gap: 0.85rem;
  padding-bottom: 1.1rem;
  margin-bottom: 1.1rem;
  border-bottom: 1px solid #eef0f3;
}
.sid-onhand__icon {
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
.sid-onhand__icon svg {
  width: 1.75rem;
  height: 1.75rem;
}
.sid-onhand__value {
  font-size: 1.95rem;
  font-weight: 700;
  color: #2563eb;
  line-height: 1.05;
}
.sid-stats {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.65rem;
}
.sid-stat {
  display: flex;
  align-items: flex-start;
  gap: 0.55rem;
  padding: 0.8rem;
  border: 1px solid #eef0f3;
  border-radius: 0.6rem;
  background: #fafafa;
}
.sid-stat__icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 2.25rem;
  height: 2.25rem;
  border-radius: 999px;
  flex-shrink: 0;
}
.sid-stat__icon svg {
  width: 1.25rem;
  height: 1.25rem;
}
.sid-stat__icon--alloc {
  background: #ffedd5;
  color: #ea580c;
}
.sid-stat__icon--avail {
  background: #dcfce7;
  color: #16a34a;
}
.sid-stat__icon--back {
  background: #fee2e2;
  color: #dc2626;
}
.sid-stat__icon--asn {
  background: #ede9fe;
  color: #7c3aed;
}
.sid-stat__value {
  font-size: 1.1rem;
  font-weight: 700;
  color: #111827;
}
.sid-locs {
  display: flex;
  flex-direction: column;
  gap: 0.55rem;
}
.sid-loc-wrap {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}
.sid-loc {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  width: 100%;
  padding: 0.9rem 0.95rem;
  border: 1px solid #e5e7eb;
  border-radius: 0.65rem;
  background: #fff;
  text-align: left;
  cursor: pointer;
}
.sid-loc:hover {
  background: #f9fafb;
}
.sid-loc--expanded {
  background: #f9fafb;
  border-color: #dbeafe;
}
.sid-loc__preview {
  margin-top: 0.15rem;
  font-size: 0.78rem;
  color: #6b7280;
  line-height: 1.35;
}
.sid-loc__list {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  padding: 0 0.35rem 0.15rem;
}
.sid-loc__item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  padding: 0.55rem 0.75rem;
  border: 1px solid #e5e7eb;
  border-radius: 0.5rem;
  background: #fff;
  text-decoration: none;
  color: inherit;
  font-size: 0.85rem;
}
.sid-loc__item:hover {
  background: #eff6ff;
  border-color: #bfdbfe;
}
.sid-loc__item-name {
  font-weight: 600;
  color: #111827;
}
.sid-loc__item-qty {
  font-weight: 700;
  color: #2563eb;
  flex-shrink: 0;
}
.sid-loc__icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 2.25rem;
  height: 2.25rem;
  border-radius: 0.5rem;
  flex-shrink: 0;
}
.sid-loc__icon svg {
  width: 1.35rem;
  height: 1.35rem;
}
.sid-loc__icon--pick,
.sid-loc__icon--backstock {
  background: #eff6ff;
  color: #2563eb;
}
.sid-loc__icon--other {
  background: #f3e8ff;
  color: #7c3aed;
}
.sid-loc__body {
  flex: 1;
  min-width: 0;
}
.sid-loc__title-row {
  display: flex;
  align-items: center;
  gap: 0.45rem;
  flex-wrap: wrap;
}
.sid-loc__title {
  font-size: 0.9rem;
  font-weight: 700;
  color: #111827;
}
.sid-loc__badge {
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
.sid-loc__empty {
  margin-top: 0.15rem;
  font-size: 0.78rem;
  color: #9ca3af;
}
.sid-loc__chevron {
  color: #3b82f6;
  flex-shrink: 0;
  transition: transform 0.15s ease;
}
.sid-loc__chevron--open {
  transform: rotate(90deg);
}
.sid-view-all {
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
  cursor: pointer;
}
.sid-view-all:hover {
  color: #1d4ed8;
}
@media (max-width: 991.98px) {
  .sid-grid {
    grid-template-columns: 1fr;
  }
  .sid-specs {
    grid-template-columns: repeat(3, minmax(0, 1fr));
    row-gap: 0.9rem;
  }
}
@media (max-width: 575.98px) {
  .sid-product {
    flex-direction: column;
  }
  .sid-product__img {
    width: 100%;
    height: 12rem;
  }
  .sid-product__meta {
    grid-template-columns: 1fr;
  }
  .sid-specs {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}
</style>
