<script setup>
import { computed, ref, watch } from "vue";
import api from "../../services/api";

const props = defineProps({
  open: { type: Boolean, default: false },
  busy: { type: Boolean, default: false },
  variantId: { type: [Number, String], default: null },
  existingComponents: { type: Array, default: () => [] },
});

const emit = defineEmits(["update:open", "save"]);

const searchQ = ref("");
const searching = ref(false);
const results = ref([]);
const selected = ref([]);
let searchTimer = null;

const selectedIds = computed(() => new Set(selected.value.map((s) => Number(s.id))));

function resetFromExisting() {
  selected.value = (props.existingComponents || []).map((c) => ({
    id: Number(c.component_variant_id),
    title: c.title || "",
    sku: c.sku || "",
    image_url: c.image_url || null,
    quantity: Math.max(1, Number(c.quantity) || 1),
  }));
  searchQ.value = "";
  results.value = [];
}

watch(
  () => props.open,
  (isOpen) => {
    if (isOpen) {
      resetFromExisting();
      void runSearch("");
    }
  },
);

function close() {
  if (props.busy) return;
  emit("update:open", false);
}

function onSearchInput() {
  if (searchTimer) clearTimeout(searchTimer);
  searchTimer = setTimeout(() => {
    void runSearch(searchQ.value);
  }, 250);
}

async function runSearch(q) {
  if (!props.variantId) return;
  searching.value = true;
  try {
    const { data } = await api.get(`/shopify/inventory/${props.variantId}/bundle-candidates`, {
      params: { q: String(q || "").trim() || undefined },
    });
    const already = selectedIds.value;
    results.value = (data?.products || []).filter((p) => !already.has(Number(p.id)));
  } catch {
    results.value = [];
  } finally {
    searching.value = false;
  }
}

function toggleResult(row) {
  const id = Number(row.id);
  const idx = selected.value.findIndex((s) => Number(s.id) === id);
  if (idx >= 0) {
    selected.value.splice(idx, 1);
  } else {
    selected.value.push({
      id,
      title: row.title || "",
      sku: row.sku || "",
      image_url: row.image_url || null,
      quantity: 1,
    });
  }
  results.value = results.value.filter((r) => Number(r.id) !== id);
}

function isChecked(row) {
  return selectedIds.value.has(Number(row.id));
}

function bumpQty(item, delta) {
  const next = Math.max(1, Number(item.quantity || 1) + delta);
  item.quantity = next;
}

function removeSelected(id) {
  selected.value = selected.value.filter((s) => Number(s.id) !== Number(id));
  void runSearch(searchQ.value);
}

function submit() {
  emit(
    "save",
    selected.value.map((s) => ({
      component_variant_id: Number(s.id),
      quantity: Math.max(1, Number(s.quantity) || 1),
    })),
  );
}
</script>

<template>
  <Teleport to="body">
    <div
      v-if="open"
      class="shopify-bundle-items-overlay"
      role="dialog"
      aria-modal="true"
      aria-labelledby="shopify-bundle-items-title"
      @click.self="close"
    >
      <div class="shopify-bundle-items" @click.stop>
        <header class="shopify-bundle-items__head">
          <h2 id="shopify-bundle-items-title" class="shopify-bundle-items__title">
            Add Bundle Items
          </h2>
          <button
            type="button"
            class="shopify-bundle-items__close"
            aria-label="Close"
            :disabled="busy"
            @click="close"
          >
            <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </header>

        <div class="shopify-bundle-items__body">
          <label class="form-label" for="shopify-bundle-items-search">Search Products</label>
          <div class="shopify-bundle-items__search">
            <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
            </svg>
            <input
              id="shopify-bundle-items-search"
              v-model="searchQ"
              type="search"
              class="form-control"
              placeholder="Search by name or SKU…"
              :disabled="busy"
              @input="onSearchInput"
            />
          </div>

          <div class="shopify-bundle-items__results">
            <div v-if="searching" class="shopify-bundle-items__hint">Searching…</div>
            <div v-else-if="!results.length" class="shopify-bundle-items__hint">No matching products.</div>
            <button
              v-for="row in results"
              :key="row.id"
              type="button"
              class="shopify-bundle-items__result"
              :disabled="busy"
              @click="toggleResult(row)"
            >
              <span class="shopify-bundle-items__check" :class="{ 'is-on': isChecked(row) }" aria-hidden="true">
                <svg v-if="isChecked(row)" width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                </svg>
              </span>
              <span class="shopify-bundle-items__thumb">
                <img v-if="row.image_url" :src="row.image_url" alt="" />
                <span v-else class="shopify-bundle-items__thumb-empty" />
              </span>
              <span class="shopify-bundle-items__meta">
                <span class="shopify-bundle-items__name">{{ row.title || "Product" }}</span>
                <span class="shopify-bundle-items__sku">{{ row.sku || "—" }}</span>
              </span>
            </button>
          </div>

          <div v-if="selected.length" class="shopify-bundle-items__selected">
            <div class="shopify-bundle-items__selected-title">Selected ({{ selected.length }})</div>
            <div v-for="item in selected" :key="item.id" class="shopify-bundle-items__selected-row">
              <span class="shopify-bundle-items__thumb">
                <img v-if="item.image_url" :src="item.image_url" alt="" />
                <span v-else class="shopify-bundle-items__thumb-empty" />
              </span>
              <span class="shopify-bundle-items__meta">
                <span class="shopify-bundle-items__name">{{ item.title || "Product" }}</span>
                <span class="shopify-bundle-items__sku">{{ item.sku || "—" }}</span>
              </span>
              <div class="shopify-bundle-items__qty">
                <button type="button" class="shopify-bundle-items__qty-btn" :disabled="busy" @click="bumpQty(item, -1)">−</button>
                <span>{{ item.quantity }}</span>
                <button type="button" class="shopify-bundle-items__qty-btn" :disabled="busy" @click="bumpQty(item, 1)">+</button>
              </div>
              <button
                type="button"
                class="shopify-bundle-items__remove"
                aria-label="Remove"
                :disabled="busy"
                @click="removeSelected(item.id)"
              >
                <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
              </button>
            </div>
          </div>
        </div>

        <footer class="shopify-bundle-items__foot">
          <button type="button" class="btn btn-outline-primary" :disabled="busy" @click="close">
            Cancel
          </button>
          <button
            type="button"
            class="btn btn-primary staff-page-primary fw-semibold"
            :disabled="busy"
            @click="submit"
          >
            {{ busy ? "Saving…" : "Save Bundle Items" }}
          </button>
        </footer>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.shopify-bundle-items-overlay {
  position: fixed;
  inset: 0;
  z-index: 1200;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 1rem;
  background: rgba(15, 23, 42, 0.45);
  backdrop-filter: blur(2px);
}
.shopify-bundle-items {
  width: 100%;
  max-width: 36rem;
  max-height: min(92vh, 44rem);
  display: flex;
  flex-direction: column;
  background: #fff;
  border-radius: 0.75rem;
  box-shadow: 0 20px 40px rgba(15, 23, 42, 0.18);
  overflow: hidden;
}
.shopify-bundle-items__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  padding: 1rem 1.25rem;
  border-bottom: 1px solid #e5e7eb;
  flex-shrink: 0;
}
.shopify-bundle-items__title {
  margin: 0;
  font-size: 1.1rem;
  font-weight: 700;
  color: #111827;
}
.shopify-bundle-items__close {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 2rem;
  height: 2rem;
  border: 0;
  border-radius: 0.4rem;
  background: transparent;
  color: #6b7280;
}
.shopify-bundle-items__close:hover:not(:disabled) {
  background: #f3f4f6;
  color: #111827;
}
.shopify-bundle-items__body {
  padding: 1rem 1.25rem;
  overflow-y: auto;
  min-height: 0;
}
.shopify-bundle-items__search {
  position: relative;
  margin-bottom: 0.85rem;
}
.shopify-bundle-items__search svg {
  position: absolute;
  left: 0.75rem;
  top: 50%;
  transform: translateY(-50%);
  color: #9ca3af;
  pointer-events: none;
}
.shopify-bundle-items__search .form-control {
  padding-left: 2.25rem;
}
.shopify-bundle-items__results {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
  max-height: 14rem;
  overflow-y: auto;
  margin-bottom: 1rem;
}
.shopify-bundle-items__hint {
  padding: 0.75rem;
  color: #9ca3af;
  font-size: 0.875rem;
}
.shopify-bundle-items__result {
  display: flex;
  align-items: center;
  gap: 0.65rem;
  width: 100%;
  border: 1px solid #e5e7eb;
  border-radius: 0.55rem;
  background: #fff;
  padding: 0.55rem 0.65rem;
  text-align: left;
  cursor: pointer;
}
.shopify-bundle-items__result:hover:not(:disabled) {
  background: #f9fafb;
}
.shopify-bundle-items__check {
  width: 1.15rem;
  height: 1.15rem;
  border: 1.5px solid #cbd5e1;
  border-radius: 0.25rem;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  color: #fff;
}
.shopify-bundle-items__check.is-on {
  background: #3b82f6;
  border-color: #3b82f6;
}
.shopify-bundle-items__thumb {
  width: 2.5rem;
  height: 2.5rem;
  border-radius: 0.4rem;
  overflow: hidden;
  background: #f3f4f6;
  border: 1px solid #eceff3;
  flex-shrink: 0;
  display: flex;
  align-items: center;
  justify-content: center;
}
.shopify-bundle-items__thumb img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}
.shopify-bundle-items__thumb-empty {
  width: 100%;
  height: 100%;
  background: #e5e7eb;
}
.shopify-bundle-items__meta {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 0.1rem;
}
.shopify-bundle-items__name {
  font-size: 0.875rem;
  font-weight: 600;
  color: #111827;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.shopify-bundle-items__sku {
  font-size: 0.75rem;
  color: #9ca3af;
}
.shopify-bundle-items__selected-title {
  font-size: 0.8rem;
  font-weight: 600;
  color: #6b7280;
  margin-bottom: 0.5rem;
}
.shopify-bundle-items__selected-row {
  display: flex;
  align-items: center;
  gap: 0.55rem;
  padding: 0.45rem 0;
  border-top: 1px solid #f3f4f6;
}
.shopify-bundle-items__qty {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  font-weight: 600;
  font-size: 0.875rem;
  color: #111827;
}
.shopify-bundle-items__qty-btn {
  width: 1.6rem;
  height: 1.6rem;
  border: 1px solid #3b82f6;
  border-radius: 0.35rem;
  background: #fff;
  color: #3b82f6;
  font-weight: 700;
  line-height: 1;
  cursor: pointer;
}
.shopify-bundle-items__qty-btn:hover:not(:disabled) {
  background: #eff6ff;
}
.shopify-bundle-items__remove {
  border: 0;
  background: transparent;
  color: #9ca3af;
  padding: 0.25rem;
  cursor: pointer;
}
.shopify-bundle-items__remove:hover:not(:disabled) {
  color: #ef4444;
}
.shopify-bundle-items__foot {
  display: flex;
  justify-content: flex-end;
  gap: 0.6rem;
  padding: 0.9rem 1.25rem 1.15rem;
  border-top: 1px solid #e5e7eb;
  flex-shrink: 0;
}
</style>
