<script setup>
import { computed, onMounted, ref } from "vue";
import { useRoute, useRouter } from "vue-router";
import api from "../../services/api";
import ConfirmModal from "../../components/common/ConfirmModal.vue";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import ShopifyPackagingFormModal from "../../components/shopify/ShopifyPackagingFormModal.vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast";
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

onMounted(load);
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
                <input ref="imageInput" type="file" accept="image/*" class="d-none" @change="onImageSelected" />
              </div>

              <div class="sid-product__info">
                <div class="sid-product__title-row">
                  <h1 class="sid-product__title">{{ item.name || "Packaging" }}</h1>
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
                  <div class="sid-onhand__value">{{ Number(item.on_hand || 0).toLocaleString("en-US") }}</div>
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
    </template>
  </div>
</template>

<style scoped>
.sid-header {
  display: flex;
  align-items: center;
  margin-bottom: 1rem;
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
  display: flex;
  gap: 1.5rem;
  margin-top: 1.1rem;
}
.sid-meta {
  display: flex;
  align-items: center;
  gap: 0.55rem;
}
.sid-meta__icon {
  display: inline-flex;
  color: #9ca3af;
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
  .sid-grid,
  .sid-specs,
  .sid-product {
    grid-template-columns: 1fr;
    display: flex;
    flex-direction: column;
  }
  .sid-specs {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}
</style>
