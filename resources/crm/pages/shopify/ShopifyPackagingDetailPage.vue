<script setup>
import { computed, nextTick, onMounted, onUnmounted, ref } from "vue";
import { useRoute, useRouter } from "vue-router";
import api from "../../services/api";
import ConfirmModal from "../../components/common/ConfirmModal.vue";
import CrmIconRowActions from "../../components/common/CrmIconRowActions.vue";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import CrmSearchableSelect from "../../components/common/CrmSearchableSelect.vue";
import ShopifyLocationTransferModal from "../../components/shopify/ShopifyLocationTransferModal.vue";
import ShopifyPackagingFormModal from "../../components/shopify/ShopifyPackagingFormModal.vue";
import ShopifyProductLocationEditQtyModal from "../../components/shopify/ShopifyProductLocationEditQtyModal.vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast";
import { openApiPdfBlob } from "../../utils/openApiPdfBlob.js";
import { formatCents } from "../../utils/formatMoney.js";

const route = useRoute();
const router = useRouter();
const toast = useToast();

const loading = ref(true);
const saveBusy = ref(false);
const imageBusy = ref(false);
const editOpen = ref(false);
const removeIconOpen = ref(false);
const item = ref(null);
const imageInput = ref(null);
const form = ref(emptyForm());
const printBusy = ref(false);
const actionsOpen = ref(false);
const deleteOpen = ref(false);
const deleteBusy = ref(false);
const locBusy = ref(false);
const addOpen = ref(false);
const addBusy = ref(false);
const locationOptions = ref([]);
const addForm = ref({ location_id: "", available: 1, reason: "" });
const expandedLocationGroup = ref(null);
const locMenuOpenKey = ref(null);
const locMenuRect = ref({ top: 0, left: 0 });
const activeLocRow = ref(null);
const transferOpen = ref(false);
const transferToId = ref("");
const transferQty = ref("1");
const transferReason = ref("");
const destLocations = ref([]);
const locQtyOpen = ref(false);
const locQtyValue = ref("");
const locQtyReason = ref("");

const defaultLocationGroups = () => [
  { key: "pick", label: "Pick Locations", icon: "cart", count: 0, locations: [] },
  { key: "backstock", label: "Backstock Locations", icon: "cube", count: 0, locations: [] },
  { key: "other", label: "Picking Cart", icon: "bag", count: 0, locations: [] },
];

const locationGroups = computed(() => {
  const groups = Array.isArray(item.value?.location_groups) ? item.value.location_groups : [];
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
const addItemReasons = computed(() => (Array.isArray(item.value?.add_item_reasons) ? item.value.add_item_reasons : []));
const locMenuRow = computed(() => {
  const key = locMenuOpenKey.value;
  if (!key) return null;
  for (const group of locationGroups.value) {
    const found = (group.locations || []).find((loc) => locRowKey(loc) === key);
    if (found) return found;
  }
  return null;
});
const totalOnHand = computed(() => Number(item.value?.total_on_hand ?? item.value?.on_hand ?? 0));

const cubicFeetLabel = computed(() => {
  const n = item.value?.cubic_ft;
  if (n == null || !Number.isFinite(Number(n))) return "—";
  return `${formatNum(n)} ft³`;
});

function emptyForm() {
  return {
    name: "",
    sku: "",
    category: "packaging",
    type: "box",
    cost: "",
    price: "",
    on_hand: "0",
    length: "",
    width: "",
    height: "",
    weight: "",
  };
}

function formatNum(val) {
  const n = Number(val);
  if (!Number.isFinite(n)) return "—";
  return n.toLocaleString("en-US", { maximumFractionDigits: 3 });
}

function formatDim(val) {
  if (val === "" || val == null || Number.isNaN(Number(val))) return "—";
  return `${formatNum(val)} in`;
}

function formatWeight(val) {
  if (val === "" || val == null || Number.isNaN(Number(val))) return "—";
  return `${formatNum(val)} lb`;
}

function fieldOrEmpty(val) {
  return val == null ? "" : String(val);
}

async function load() {
  loading.value = true;
  try {
    const { data } = await api.get(`/shopify/packaging/${route.params.id}`);
    item.value = data?.item || null;
    if (item.value?.name) {
      setCrmPageMeta({
        title: `Save Rack | ${item.value.name}`,
        description: "Packaging detail.",
      });
    }
  } catch (e) {
    toast.errorFrom(e, "Could not load packaging.");
    item.value = null;
  } finally {
    loading.value = false;
  }
}

function openEdit() {
  if (!item.value) return;
  form.value = {
    name: item.value.name || "",
    sku: item.value.sku || "",
    category: item.value.category || "packaging",
    type: item.value.type || "box",
    cost: item.value.cost ?? "",
    price: item.value.price ?? "",
    on_hand: String(item.value.on_hand ?? 0),
    length: fieldOrEmpty(item.value.length),
    width: fieldOrEmpty(item.value.width),
    height: fieldOrEmpty(item.value.height),
    weight: fieldOrEmpty(item.value.weight),
    link_url: item.value.link_url || "",
  };
  editOpen.value = true;
}

async function saveEdit(payload) {
  if (!item.value?.id) return;
  saveBusy.value = true;
  try {
    const { data } = await api.patch(`/shopify/packaging/${item.value.id}`, payload);
    item.value = data?.item || item.value;
    editOpen.value = false;
    toast.success("Packaging updated.");
  } catch (e) {
    toast.errorFrom(e, "Could not update packaging.");
  } finally {
    saveBusy.value = false;
  }
}

function pickImage() {
  imageInput.value?.click();
}

async function onImageSelected(e) {
  const file = e.target?.files?.[0];
  e.target.value = "";
  if (!file || !item.value?.id) return;
  imageBusy.value = true;
  try {
    const body = new FormData();
    body.append("image", file);
    const { data } = await api.post(`/shopify/packaging/${item.value.id}/image`, body);
    item.value = data?.item || item.value;
    toast.success("Icon updated.");
  } catch (err) {
    toast.errorFrom(err, "Could not upload icon.");
  } finally {
    imageBusy.value = false;
  }
}

async function removeIcon() {
  if (!item.value?.id || !item.value.image_url) return;
  imageBusy.value = true;
  try {
    const { data } = await api.patch(`/shopify/packaging/${item.value.id}`, {
      name: item.value.name,
      sku: item.value.sku,
      category: item.value.category,
      type: item.value.type,
      cost: item.value.cost,
      price: item.value.price,
      on_hand: item.value.on_hand,
      length: item.value.length,
      width: item.value.width,
      height: item.value.height,
      weight: item.value.weight,
      link_url: item.value.link_url,
      remove_image: true,
    });
    item.value = data?.item || item.value;
    toast.success("Icon removed.");
    removeIconOpen.value = false;
  } catch (e) {
    toast.errorFrom(e, "Could not remove icon.");
  } finally {
    imageBusy.value = false;
  }
}

function onDocClick(e) {
  if (!e.target?.closest?.("[data-packaging-actions]")) actionsOpen.value = false;
  if (!e.target?.closest?.("[data-sid-loc-row-actions]")) locMenuOpenKey.value = null;
}

function locRowKey(loc) {
  return String(loc?.item_id || `${loc?.location_id || ""}-${loc?.name || ""}`);
}

function toggleLocationGroup(key) {
  expandedLocationGroup.value = expandedLocationGroup.value === key ? null : key;
}

async function printBarcode() {
  if (!item.value?.id || printBusy.value) return;
  if (!String(item.value.sku || "").trim()) {
    toast.error("Add a SKU before printing a label.");
    return;
  }
  printBusy.value = true;
  try {
    await openApiPdfBlob(api, `/shopify/packaging/${item.value.id}/barcode-label.pdf`);
  } catch (e) {
    toast.errorFrom(e, "Could not print barcode.");
  } finally {
    printBusy.value = false;
  }
}

async function confirmDelete() {
  if (!item.value?.id) return;
  deleteBusy.value = true;
  try {
    await api.delete(`/shopify/packaging/${item.value.id}`);
    toast.success("Packaging deleted.");
    router.push({ name: "shopify-packaging" });
  } catch (e) {
    toast.errorFrom(e, "Could not delete packaging.");
  } finally {
    deleteBusy.value = false;
  }
}

async function openAddLocation() {
  addForm.value = {
    location_id: "",
    available: 1,
    reason: addItemReasons.value[0] || "",
  };
  addOpen.value = true;
  try {
    const { data } = await api.get("/shopify/locations/options");
    locationOptions.value = Array.isArray(data?.data) ? data.data : [];
  } catch (e) {
    locationOptions.value = [];
    toast.errorFrom(e, "Could not load locations.");
  }
}

async function saveAddLocation() {
  if (!item.value?.id) return;
  if (!addForm.value.location_id) {
    toast.error("Select a location.");
    return;
  }
  if (!addForm.value.reason) {
    toast.error("Select a reason.");
    return;
  }
  addBusy.value = true;
  try {
    const { data } = await api.post(`/shopify/packaging/${item.value.id}/locations`, {
      location_id: Number(addForm.value.location_id),
      available: Math.max(1, Number(addForm.value.available) || 1),
      reason: addForm.value.reason,
    });
    item.value = data?.item || item.value;
    addOpen.value = false;
    toast.success("Inventory added.");
  } catch (e) {
    toast.errorFrom(e, "Could not add inventory.");
  } finally {
    addBusy.value = false;
  }
}

function placeLocMenu(btn) {
  if (!(btn instanceof HTMLElement)) return;
  const r = btn.getBoundingClientRect();
  let top = r.bottom + 4;
  let left = r.right - 160;
  left = Math.max(8, Math.min(left, window.innerWidth - 168));
  if (top + 88 > window.innerHeight - 8) top = Math.max(8, r.top - 92);
  locMenuRect.value = { top, left };
}

async function toggleLocMenu(loc, e) {
  e?.stopPropagation?.();
  const key = locRowKey(loc);
  if (locMenuOpenKey.value === key) {
    locMenuOpenKey.value = null;
    return;
  }
  const btn = e?.currentTarget;
  locMenuOpenKey.value = key;
  await nextTick();
  requestAnimationFrame(() => {
    if (btn instanceof HTMLElement) placeLocMenu(btn);
  });
}

async function openLocTransfer(loc) {
  activeLocRow.value = loc;
  transferToId.value = "";
  transferQty.value = "1";
  transferReason.value = addItemReasons.value.includes("Restock") ? "Restock" : (addItemReasons.value[0] || "");
  locMenuOpenKey.value = null;
  try {
    const { data } = await api.get("/shopify/locations/options", { params: { exclude: loc.location_id } });
    destLocations.value = Array.isArray(data?.data) ? data.data : [];
  } catch (e) {
    destLocations.value = [];
    toast.errorFrom(e, "Could not load destination locations.");
  }
  transferOpen.value = true;
}

async function submitLocTransfer() {
  if (!item.value?.id || !activeLocRow.value) return;
  const qty = Number(transferQty.value || 0);
  if (!transferToId.value) {
    toast.error("Select a destination location.");
    return;
  }
  if (!qty || qty < 1) {
    toast.error("Enter a quantity to transfer.");
    return;
  }
  locBusy.value = true;
  try {
    const { data } = await api.post(`/shopify/packaging/${item.value.id}/locations/${activeLocRow.value.item_id}/transfer`, {
      to_location_id: Number(transferToId.value),
      quantity: qty,
      reason: transferReason.value,
    });
    item.value = data?.item || item.value;
    transferOpen.value = false;
    toast.success("Inventory transferred.");
  } catch (e) {
    toast.errorFrom(e, "Could not transfer inventory.");
  } finally {
    locBusy.value = false;
  }
}

function openLocQtyEdit(loc) {
  activeLocRow.value = loc;
  locQtyValue.value = String(loc?.available ?? 0);
  locQtyReason.value = addItemReasons.value[0] || "";
  locMenuOpenKey.value = null;
  locQtyOpen.value = true;
}

async function saveLocQty() {
  if (!item.value?.id || !activeLocRow.value) return;
  locBusy.value = true;
  try {
    const { data } = await api.patch(`/shopify/packaging/${item.value.id}/locations/${activeLocRow.value.item_id}`, {
      available: Math.max(0, Number(locQtyValue.value) || 0),
      reason: locQtyReason.value,
    });
    item.value = data?.item || item.value;
    locQtyOpen.value = false;
    toast.success("Quantity updated.");
  } catch (e) {
    toast.errorFrom(e, "Could not update quantity.");
  } finally {
    locBusy.value = false;
  }
}

onMounted(() => {
  document.addEventListener("click", onDocClick);
  load();
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

    <template v-else-if="!item">
      <button type="button" class="sid-back" @click="router.push({ name: 'shopify-packaging' })">
        <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" />
        </svg>
        Back to Packaging
      </button>
      <p class="text-secondary mt-3">Packaging not found.</p>
    </template>

    <template v-else>
      <header class="sid-header">
        <button type="button" class="sid-back" @click="router.push({ name: 'shopify-packaging' })">
          <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" />
          </svg>
          Back to Packaging
        </button>
        <div class="sid-header__actions">
          <button type="button" class="staff-outline-action-btn" :disabled="printBusy" @click="printBarcode">
            {{ printBusy ? "Generating Label…" : "Print Barcode" }}
          </button>
          <div class="position-relative" data-packaging-actions>
            <button type="button" class="staff-outline-action-btn" :aria-expanded="actionsOpen" @click.stop="actionsOpen = !actionsOpen">
              Actions
            </button>
            <div v-if="actionsOpen" class="dropdown-menu show shadow border py-1" style="position: absolute; top: calc(100% + 0.25rem); right: 0; z-index: 20; min-width: 10rem">
              <button type="button" class="dropdown-item text-danger" @click="actionsOpen = false; deleteOpen = true">Delete</button>
            </div>
          </div>
        </div>
      </header>

      <div class="sid-grid">
        <div class="sid-col">
          <section class="sid-card">
            <div class="sid-product">
              <div class="sid-product__media">
                <button
                  type="button"
                  class="sid-product__img sid-product__img--clickable"
                  :disabled="imageBusy"
                  :title="imageBusy ? 'Uploading…' : 'Click to upload icon'"
                  @click="pickImage"
                >
                  <img v-if="item.image_url" :src="item.image_url" :alt="item.name || 'Packaging'" />
                  <span v-else class="sid-product__img-empty" aria-hidden="true">
                    <svg width="40" height="40" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.35">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909M3.75 21h16.5A2.25 2.25 0 0022.5 18.75V5.25A2.25 2.25 0 0020.25 3H3.75A2.25 2.25 0 001.5 5.25v13.5A2.25 2.25 0 003.75 21z" />
                    </svg>
                  </span>
                  <span class="sid-product__img-hint">{{ imageBusy ? "Uploading…" : "Click to Upload" }}</span>
                </button>
                <button
                  v-if="item.image_url"
                  type="button"
                  class="btn btn-link btn-sm px-0 sid-remove-icon"
                  :disabled="imageBusy"
                  @click="removeIconOpen = true"
                >
                  Remove Icon
                </button>
                <input ref="imageInput" type="file" accept="image/jpeg,image/png,image/gif,image/webp,image/avif,.avif" class="d-none" @change="onImageSelected" />
              </div>

              <div class="sid-product__info">
                <div class="sid-product__title-row">
                  <h1 class="sid-product__title">
                    <a
                      v-if="item.link_url"
                      class="sid-account-link"
                      :href="item.link_url"
                      target="_blank"
                      rel="noopener noreferrer"
                    >{{ item.name || "Packaging" }}</a>
                    <template v-else>{{ item.name || "Packaging" }}</template>
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
                  <div class="sid-field__sku">{{ item.sku || "—" }}</div>
                </div>

                <div class="sid-money">
                  <div>
                    <div class="sid-field__label">Cost</div>
                    <div class="sid-money__value">{{ formatCents(item.cost_cents) }}</div>
                  </div>
                  <div>
                    <div class="sid-field__label">Price</div>
                    <div class="sid-money__value">{{ formatCents(item.price_cents) }}</div>
                  </div>
                </div>

                <div class="sid-product__meta">
                  <div class="sid-meta">
                    <span class="sid-meta__icon" aria-hidden="true">
                      <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 7.5l-9-5.25L3 7.5m18 0l-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9" />
                      </svg>
                    </span>
                    <div>
                      <div class="sid-field__label">Category</div>
                      <div class="sid-meta__value">{{ item.category_label || "—" }}</div>
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
                      <div class="sid-meta__value">{{ item.type_label || "—" }}</div>
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
                <div class="sid-specs__value">{{ formatDim(item.length) }}</div>
              </div>
              <div class="sid-specs__item">
                <span class="sid-specs__icon" aria-hidden="true">
                  <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 3.75v16.5m0 0l-3.75-3.75M12 20.25l3.75-3.75M12 3.75L8.25 7.5M12 3.75l3.75 3.75" />
                  </svg>
                </span>
                <div class="sid-field__label">Width</div>
                <div class="sid-specs__value">{{ formatDim(item.width) }}</div>
              </div>
              <div class="sid-specs__item">
                <span class="sid-specs__icon" aria-hidden="true">
                  <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 10.5L12 4.5l7.5 6v9.75a.75.75 0 01-.75.75H5.25a.75.75 0 01-.75-.75V10.5z" />
                  </svg>
                </span>
                <div class="sid-field__label">Height</div>
                <div class="sid-specs__value">{{ formatDim(item.height) }}</div>
              </div>
              <div class="sid-specs__item">
                <span class="sid-specs__icon" aria-hidden="true">
                  <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 7.5l-9-5.25L3 7.5m18 0l-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9" />
                  </svg>
                </span>
                <div class="sid-field__label">Cubic Ft</div>
                <div class="sid-specs__value">{{ cubicFeetLabel }}</div>
              </div>
              <div class="sid-specs__item">
                <span class="sid-specs__icon" aria-hidden="true">
                  <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v18m0 0l-4-4m4 4l4-4M6 8h12M6 12h12" />
                  </svg>
                </span>
                <div class="sid-field__label">Weight</div>
                <div class="sid-specs__value">{{ formatWeight(item.weight) }}</div>
              </div>
            </div>
          </section>
        </div>

        <div class="sid-col">
          <section class="sid-card">
            <div class="sid-onhand sid-onhand--solo">
              <div class="sid-onhand__main">
                <span class="sid-onhand__icon" aria-hidden="true">
                  <svg width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.55">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 7.5l-9-5.25L3 7.5m18 0l-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9" />
                  </svg>
                </span>
                <div>
                  <div class="sid-field__label">Total On Hand</div>
                  <div class="sid-onhand__value">{{ totalOnHand.toLocaleString("en-US") }}</div>
                </div>
              </div>
              <button
                type="button"
                class="staff-outline-action-btn sid-onhand__log"
                @click="router.push({ name: 'shopify-packaging-log', params: { id: String(item.id) } })"
              >
                Inventory Log
              </button>
            </div>
          </section>

          <section class="sid-card">
            <div class="sid-card__head">
              <div>
                <div class="sid-card__head-title">
                  <h2>Locations</h2>
                </div>
                <p class="sid-card__sub">Manage inventory by location.</p>
              </div>
              <button type="button" class="btn btn-primary staff-page-primary btn-sm fw-semibold" @click="openAddLocation">
                Add Inventory
              </button>
            </div>
            <div class="sid-locs">
              <div
                v-for="group in locationGroups"
                :key="group.key"
                class="sid-loc-wrap"
                :class="{ 'sid-loc-wrap--open': expandedLocationGroup === group.key && group.locations.length }"
              >
                <button
                  type="button"
                  class="sid-loc"
                  :disabled="!group.locations.length"
                  @click="group.locations.length && toggleLocationGroup(group.key)"
                >
                  <div class="sid-loc__body">
                    <div class="sid-loc__title-row">
                      <span class="sid-loc__title">{{ group.label }}</span>
                      <span v-if="group.locations.length" class="sid-loc__badge">{{ Number(group.count || 0).toLocaleString("en-US") }}</span>
                      <span v-if="group.locations.length" class="sid-loc__units">total units</span>
                    </div>
                  </div>
                  <span v-if="!group.locations.length" class="sid-loc__empty-right">No locations</span>
                </button>
                <div v-if="expandedLocationGroup === group.key && group.locations.length" class="sid-loc__list">
                  <div v-for="loc in group.locations" :key="locRowKey(loc)" class="sid-loc__item">
                    <span class="sid-loc__item-name">{{ loc.name }}</span>
                    <div class="sid-loc__item-right">
                      <span class="sid-loc__item-qty">{{ Number(loc.available || 0).toLocaleString("en-US") }}</span>
                      <button
                        type="button"
                        class="staff-action-btn staff-action-btn--more"
                        data-sid-loc-row-actions
                        aria-label="Location actions"
                        @click="toggleLocMenu(loc, $event)"
                      >
                        <CrmIconRowActions variant="horizontal" />
                      </button>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </section>
        </div>
      </div>

      <ShopifyPackagingFormModal
        :open="editOpen"
        title="Edit Packaging"
        :busy="saveBusy"
        :item="form"
        @close="editOpen = false"
        @save="saveEdit"
      />

      <ConfirmModal
        :open="removeIconOpen"
        title="Remove Icon?"
        message="Remove this packaging icon? You can upload a new one later."
        confirm-label="Remove"
        :busy="imageBusy"
        danger
        @close="removeIconOpen = false"
        @confirm="removeIcon"
      />

      <ConfirmModal
        :open="deleteOpen"
        title="Delete Packaging?"
        :message="item ? `Delete “${item.name}”? This cannot be undone.` : 'Delete this packaging?'"
        confirm-label="Delete"
        :busy="deleteBusy"
        danger
        @close="deleteOpen = false"
        @confirm="confirmDelete"
      />

      <Teleport to="body">
        <div v-if="addOpen" class="crm-vx-modal-overlay" @click.self="addBusy ? null : (addOpen = false)">
          <div class="crm-vx-modal crm-vx-modal--sm" @click.stop>
            <header class="crm-vx-modal__head" style="text-align: left">
              <h2 class="crm-vx-modal__title">Add Inventory</h2>
            </header>
            <div class="crm-vx-modal__body" style="text-align: left">
              <label class="form-label">Location</label>
              <CrmSearchableSelect
                v-model="addForm.location_id"
                class="mb-3"
                appearance="staff"
                aria-label="Select Location"
                :options="locationOptions"
                :disabled="addBusy"
                :allow-empty="false"
                placeholder="Select Location"
                search-placeholder="Search locations…"
                teleport-panel
              />
              <label class="form-label" for="pkg-add-qty">QTY</label>
              <input id="pkg-add-qty" v-model.number="addForm.available" type="number" min="1" class="form-control mb-3" :disabled="addBusy" />
              <label class="form-label" for="pkg-add-reason">Reason</label>
              <select id="pkg-add-reason" v-model="addForm.reason" class="form-select" :disabled="addBusy">
                <option v-for="reason in addItemReasons" :key="reason" :value="reason">{{ reason }}</option>
              </select>
            </div>
            <footer class="crm-vx-modal__footer justify-content-end">
              <button type="button" class="crm-vx-modal-btn crm-vx-modal-btn--secondary" :disabled="addBusy" @click="addOpen = false">Cancel</button>
              <button type="button" class="crm-vx-modal-btn crm-vx-modal-btn--primary" :disabled="addBusy" @click="saveAddLocation">
                {{ addBusy ? "Saving…" : "Add Inventory" }}
              </button>
            </footer>
          </div>
        </div>
      </Teleport>

      <ShopifyLocationTransferModal
        :open="transferOpen"
        :busy="locBusy"
        :product-title="item?.name || ''"
        :sku="item?.sku || ''"
        :image-url="item?.image_url || ''"
        :from-name="activeLocRow?.name || ''"
        :available="Number(activeLocRow?.available || 0)"
        :to-location-id="transferToId"
        :quantity="transferQty"
        :reason="transferReason"
        :locations="destLocations"
        :reasons="addItemReasons"
        @close="transferOpen = false"
        @submit="submitLocTransfer"
        @all="transferQty = String(activeLocRow?.available || 0)"
        @update:to-location-id="transferToId = $event"
        @update:quantity="transferQty = $event"
        @update:reason="transferReason = $event"
      />

      <ShopifyProductLocationEditQtyModal
        :open="locQtyOpen"
        :busy="locBusy"
        :location-name="activeLocRow?.name || ''"
        :current-qty="Number(activeLocRow?.available || 0)"
        :quantity="locQtyValue"
        :reason="locQtyReason"
        :reasons="addItemReasons"
        @close="locQtyOpen = false"
        @submit="saveLocQty"
        @update:quantity="locQtyValue = $event"
        @update:reason="locQtyReason = $event"
      />

      <Teleport to="body">
        <div
          v-if="locMenuRow"
          data-sid-loc-row-actions
          class="staff-row-menu fixed z-[300] overflow-hidden"
          role="menu"
          :style="{ top: `${locMenuRect.top}px`, left: `${locMenuRect.left}px` }"
          @click.stop
        >
          <button type="button" class="staff-row-menu__item" role="menuitem" @click="openLocTransfer(locMenuRow)">Transfer</button>
          <button type="button" class="staff-row-menu__item" role="menuitem" @click="openLocQtyEdit(locMenuRow)">Edit</button>
        </div>
      </Teleport>
    </template>
  </div>
</template>

<style scoped>
.sid-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  margin-bottom: 1rem;
}
.sid-header__actions {
  display: flex;
  align-items: center;
  gap: 0.5rem;
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
.sid-field__label {
  font-size: 0.72rem;
  font-weight: 500;
  color: #9ca3af;
  margin-bottom: 0.15rem;
  line-height: 1.2;
}
.sid-product {
  display: flex;
  gap: 1.35rem;
  align-items: flex-start;
}
.sid-product__media {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 0.25rem;
  flex-shrink: 0;
}
.sid-product__img {
  width: 10.5rem;
  height: 10.5rem;
  border-radius: 0.7rem;
  overflow: hidden;
  background: #f3f4f6;
  border: 1px solid #eceff3;
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
.sid-remove-icon {
  color: #dc2626;
  text-decoration: none;
  font-weight: 600;
}
.sid-product__info {
  min-width: 0;
  flex: 1;
}
.sid-product__title-row {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 0.75rem;
  margin-bottom: 0.85rem;
}
.sid-product__title {
  margin: 0;
  font-size: 1.35rem;
  font-weight: 700;
  color: #111827;
  line-height: 1.25;
}
.sid-product__title .sid-account-link {
  color: #2563eb;
}
.sid-field__sku,
.sid-money__value,
.sid-meta__value {
  font-size: 0.98rem;
  font-weight: 700;
  color: #111827;
}
.sid-money {
  display: flex;
  gap: 2rem;
  margin-top: 0.85rem;
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
.sid-specs__value {
  font-size: 0.92rem;
  font-weight: 700;
  color: #111827;
}
.sid-onhand--solo {
  display: flex;
  align-items: center;
  justify-content: space-between;
  border-bottom: 0;
  margin-bottom: 0;
  padding-bottom: 0;
}
.sid-onhand__main {
  display: flex;
  align-items: center;
  gap: 0.85rem;
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
.sid-onhand__value {
  font-size: 1.95rem;
  font-weight: 700;
  color: #2563eb;
  line-height: 1.05;
}
@media (max-width: 991.98px) {
  .sid-grid {
    grid-template-columns: 1fr;
    gap: 0.75rem;
  }
  .sid-header {
    flex-direction: row;
    flex-wrap: nowrap;
    align-items: center;
    gap: 0.45rem;
    margin-bottom: 0.85rem;
  }
  .sid-back {
    font-size: 0.8125rem;
    flex-shrink: 0;
  }
  .sid-header__actions {
    margin-left: auto;
    width: auto;
    display: flex;
    flex-wrap: nowrap;
    align-items: center;
    gap: 0.4rem;
    flex: 0 1 auto;
    min-width: 0;
  }
  .sid-header__actions .staff-outline-action-btn {
    width: auto;
    justify-content: center;
    white-space: nowrap;
    font-size: 0.72rem;
    padding: 0.35rem 0.55rem;
    min-height: 2rem;
    gap: 0.3rem;
  }
  .sid-card {
    padding: 0.85rem;
    border-radius: 0.75rem;
  }
  .sid-product {
    gap: 0.85rem;
    align-items: stretch;
  }
  .sid-product__img {
    width: 8.5rem;
    height: auto;
    min-height: 8.5rem;
    border-radius: 0.65rem;
    align-self: stretch;
  }
  .sid-product__info {
    display: flex;
    flex-direction: column;
    min-width: 0;
  }
  .sid-product__title-row {
    margin-bottom: 0.45rem;
    gap: 0.4rem;
  }
  .sid-product__title {
    font-size: 0.98rem;
    line-height: 1.25;
  }
  .sid-product__title-row .staff-outline-action-btn--sm {
    font-size: 0.7rem;
    padding: 0.2rem 0.45rem;
    min-height: 1.65rem;
  }
  .sid-field__sku {
    font-size: 1.05rem;
  }
  .sid-field__label {
    font-size: 0.68rem;
  }
  .sid-money {
    gap: 1.25rem;
    margin-top: 0.55rem;
  }
  .sid-money__value {
    font-size: 0.9rem;
  }
  .sid-product__meta {
    grid-template-columns: 1fr 1fr;
    gap: 0.45rem 0.65rem;
    margin-top: auto;
    padding-top: 0.5rem;
  }
  .sid-meta__value {
    font-size: 0.8125rem;
  }
  .sid-meta__icon svg {
    width: 15px;
    height: 15px;
  }
  .sid-specs {
    grid-template-columns: repeat(3, minmax(0, 1fr));
    row-gap: 0.9rem;
  }
  .sid-onhand--solo {
    gap: 0.65rem;
  }
  .sid-onhand__icon {
    width: 2.15rem;
    height: 2.15rem;
  }
  .sid-onhand__icon svg {
    width: 1.15rem;
    height: 1.15rem;
  }
  .sid-onhand__value {
    font-size: 1.35rem;
  }
  .sid-onhand__log {
    font-size: 0.7rem;
    padding: 0.3rem 0.45rem;
    min-height: 1.85rem;
    white-space: nowrap;
  }
  .sid-card__head .btn-sm {
    white-space: nowrap;
  }
}
@media (max-width: 575.98px) {
  .sid-product__img {
    width: 7.75rem;
    height: auto;
    min-height: 7.75rem;
    align-self: stretch;
  }
  .sid-specs {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}
.sid-account-link {
  color: #2563eb;
  text-decoration: none;
}
.sid-account-link:hover {
  text-decoration: underline;
}
.sid-card__head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 0.75rem;
  margin-bottom: 0.75rem;
}
.sid-card__head-title h2 {
  margin: 0;
  font-size: 1.05rem;
  font-weight: 700;
}
.sid-card__sub {
  margin: 0.2rem 0 0;
  font-size: 0.8rem;
  color: #9ca3af;
}
.sid-loc {
  display: flex;
  align-items: center;
  width: 100%;
  padding: 0.85rem 0.15rem;
  border: 0;
  background: transparent;
  text-align: left;
}
.sid-loc:disabled {
  cursor: default;
}
.sid-loc-wrap {
  border-top: 1px solid #eef2f7;
}
.sid-loc-wrap:first-child {
  border-top: 0;
}
.sid-loc__body {
  flex: 1;
  min-width: 0;
}
.sid-loc__title-row {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 0.45rem;
}
.sid-loc__title {
  font-weight: 700;
}
.sid-loc__badge {
  min-width: 1.45rem;
  height: 1.45rem;
  padding: 0 0.4rem;
  border-radius: 999px;
  background: #dbeafe;
  color: #1d4ed8;
  font-size: 0.75rem;
  font-weight: 700;
  display: inline-flex;
  align-items: center;
  justify-content: center;
}
.sid-loc__units,
.sid-loc__empty-right {
  color: #94a3b8;
  font-size: 0.8rem;
}
.sid-loc__empty-right {
  margin-left: auto;
}
.sid-loc__list {
  padding: 0 0 0.75rem 0.25rem;
}
.sid-loc__item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  padding: 0.45rem 0;
}
.sid-loc__item-name,
.sid-loc__item-qty {
  font-weight: 600;
}
.sid-loc__item-right {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
}
</style>
