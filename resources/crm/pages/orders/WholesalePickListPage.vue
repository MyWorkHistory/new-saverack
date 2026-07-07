<script setup>
import { computed, onMounted, ref } from "vue";
import { useRoute, useRouter } from "vue-router";
import api from "../../services/api";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import WholesalePickItemsModal from "../../components/orders/WholesalePickItemsModal.vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast.js";

const toast = useToast();
const router = useRouter();
const route = useRoute();

const loading = ref(true);
const orders = ref([]);
const markBusyId = ref(null);

const pickModalOpen = ref(false);
const pickModalOrderId = ref(null);
const pickModalLine = ref(null);

const accountFilter = computed(() => String(route.query.client_account_id || "").trim());

const nf = new Intl.NumberFormat(undefined, { maximumFractionDigits: 0 });

async function load() {
  loading.value = true;
  try {
    const params = {};
    if (accountFilter.value) {
      params.client_account_id = Number(accountFilter.value);
    }
    const { data } = await api.get("/admin/wholesale-orders/pick-list", { params });
    orders.value = Array.isArray(data?.orders) ? data.orders : [];
  } catch (e) {
    toast.errorFrom(e, "Could not load pick list.");
    orders.value = [];
  } finally {
    loading.value = false;
  }
}

function openPickModal(order, line) {
  pickModalOrderId.value = order?.id ?? null;
  pickModalLine.value = line ? { ...line } : null;
  pickModalOpen.value = true;
}

function onPickSaved() {
  void load();
}

function qtyPickedClass(line) {
  return line?.is_fully_picked ? "wholesale-pick-qty--done" : "wholesale-pick-qty--pending";
}

function pickPercent(picked, total) {
  const t = Number(total || 0);
  const p = Number(picked || 0);
  if (t <= 0) return 0;
  return Math.round((p / t) * 100);
}

function formatQtyWithPercent(picked, total) {
  const p = Number(picked || 0);
  const pct = pickPercent(picked, total);
  return `${nf.format(p)} (${pct}%)`;
}

function formatCreatedDate(iso) {
  if (!iso) return "—";
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return "—";
  return new Intl.DateTimeFormat("en-US", {
    month: "short",
    day: "numeric",
    year: "numeric",
  }).format(d);
}

function formatCreatedTime(iso) {
  if (!iso) return "—";
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return "—";
  return new Intl.DateTimeFormat("en-US", {
    hour: "numeric",
    minute: "2-digit",
  }).format(d);
}

function typeBadgeClass(orderType) {
  const t = String(orderType || "").toLowerCase();
  if (t === "amazon") return "wholesale-pick-type-badge--amazon";
  if (t === "tiktok") return "wholesale-pick-type-badge--tiktok";
  if (t === "walmart") return "wholesale-pick-type-badge--walmart";
  return "wholesale-pick-type-badge--default";
}

function locationLabel(value) {
  const v = String(value || "").trim();
  return v || "—";
}

async function markAllPicked(order) {
  if (!order?.id || !order.is_fully_picked || markBusyId.value) return;
  markBusyId.value = order.id;
  try {
    await api.post(`/admin/wholesale-orders/${order.id}/mark-picked`);
    toast.success("Order marked as picked.");
    await router.push({ name: "wholesale-order-detail", params: { id: String(order.id) } });
  } catch (e) {
    toast.errorFrom(e, "Could not mark order as picked.");
  } finally {
    markBusyId.value = null;
  }
}

function goBack() {
  if (accountFilter.value) {
    router.push({ name: "wholesale-orders", query: { client_account_id: accountFilter.value } });
    return;
  }
  router.push({ name: "wholesale-orders" });
}

onMounted(() => {
  setCrmPageMeta({
    title: "Save Rack | Pick List",
    description: "Pick wholesale order line items.",
  });
  void load();
});
</script>

<template>
  <div class="staff-page staff-page--wide wholesale-pick-list-page">
    <header class="wholesale-pick-list-page__head mb-4">
      <button
        type="button"
        class="wholesale-pick-list-page__back btn btn-link p-0 text-decoration-none"
        @click="goBack"
      >
        <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
        </svg>
        <span class="wholesale-pick-list-page__title">Pick List</span>
      </button>
    </header>

    <div v-if="loading" class="d-flex justify-content-center py-5">
      <CrmLoadingSpinner message="Loading pick list…" :center="true" />
    </div>

    <p v-else-if="!orders.length" class="text-center text-secondary py-5 mb-0">
      No orders ready to pick.
    </p>

    <div v-else class="d-flex flex-column gap-4">
      <section
        v-for="order in orders"
        :key="`pick-order-${order.id}`"
        class="wholesale-pick-order"
      >
        <div class="wholesale-pick-order__summary">
          <div class="wholesale-pick-order__summary-item wholesale-pick-order__summary-item--with-icon">
            <span class="wholesale-pick-order__summary-icon" aria-hidden="true">
              <svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
              </svg>
            </span>
            <div class="wholesale-pick-order__summary-item-body min-w-0">
              <p class="wholesale-pick-order__summary-label">Account</p>
              <p class="wholesale-pick-order__summary-value text-truncate">
                {{ order.client_account_company_name || "—" }}
              </p>
            </div>
          </div>

          <div class="wholesale-pick-order__summary-divider" aria-hidden="true" />

          <div class="wholesale-pick-order__summary-item">
            <div class="wholesale-pick-order__summary-item-body">
              <p class="wholesale-pick-order__summary-label">Order #</p>
              <p class="wholesale-pick-order__summary-value">{{ order.order_number || "—" }}</p>
            </div>
          </div>

          <div class="wholesale-pick-order__summary-divider" aria-hidden="true" />

          <div class="wholesale-pick-order__summary-item">
            <div class="wholesale-pick-order__summary-item-body">
              <p class="wholesale-pick-order__summary-label">Type</p>
              <span class="wholesale-pick-type-badge" :class="typeBadgeClass(order.order_type)">
                {{ order.order_type_label || "—" }}
              </span>
            </div>
          </div>

          <div class="wholesale-pick-order__summary-divider" aria-hidden="true" />

          <div class="wholesale-pick-order__summary-item wholesale-pick-order__summary-item--with-icon">
            <span class="wholesale-pick-order__summary-icon" aria-hidden="true">
              <svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
              </svg>
            </span>
            <div class="wholesale-pick-order__summary-item-body">
              <p class="wholesale-pick-order__summary-label">Total Items</p>
              <p class="wholesale-pick-order__summary-value">{{ nf.format(order.line_count || 0) }}</p>
            </div>
          </div>

          <div class="wholesale-pick-order__summary-divider" aria-hidden="true" />

          <div class="wholesale-pick-order__summary-item wholesale-pick-order__summary-item--with-icon">
            <span class="wholesale-pick-order__summary-icon" aria-hidden="true">
              <svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
              </svg>
            </span>
            <div class="wholesale-pick-order__summary-item-body">
              <p class="wholesale-pick-order__summary-label">Date Created</p>
              <p class="wholesale-pick-order__summary-value mb-0">{{ formatCreatedDate(order.created_at) }}</p>
              <p class="wholesale-pick-order__summary-sub mb-0">{{ formatCreatedTime(order.created_at) }}</p>
            </div>
          </div>
        </div>

        <div class="wholesale-pick-order__items-card">
          <div class="wholesale-pick-order__items-head">
            <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
            </svg>
            <h2 class="wholesale-pick-order__items-title mb-0">Items to Pick</h2>
          </div>

          <div
            v-for="(line, lineIdx) in order.lines"
            :key="`pick-line-${line.id}`"
            class="wholesale-pick-item-row"
            :class="{ 'wholesale-pick-item-row--last': lineIdx === order.lines.length - 1 }"
          >
            <div class="wholesale-pick-item-row__product">
              <img
                v-if="line.image_url"
                :src="line.image_url"
                alt=""
                class="wholesale-pick-product__thumb"
                loading="lazy"
              />
              <span
                v-else
                class="wholesale-pick-product__thumb wholesale-pick-product__thumb--empty"
                aria-hidden="true"
              />
              <div class="min-w-0">
                <div class="wholesale-pick-product__sku">{{ line.sku || "—" }}</div>
                <div class="wholesale-pick-product__name">{{ line.name || "—" }}</div>
                <div v-if="line.variant_description" class="wholesale-pick-product__variant">
                  {{ line.variant_description }}
                </div>
              </div>
            </div>

            <div class="wholesale-pick-item-row__metric">
              <p class="wholesale-pick-item-row__metric-label">Qty to Pick</p>
              <p class="wholesale-pick-item-row__metric-value wholesale-pick-item-row__metric-value--target">
                {{ nf.format(line.quantity || 0) }}
              </p>
            </div>

            <div class="wholesale-pick-item-row__metric">
              <p class="wholesale-pick-item-row__metric-label">Qty Picked</p>
              <p class="wholesale-pick-item-row__metric-value" :class="qtyPickedClass(line)">
                {{ formatQtyWithPercent(line.quantity_picked, line.quantity) }}
              </p>
            </div>

            <div class="wholesale-pick-item-row__metric">
              <p class="wholesale-pick-item-row__metric-label">Backstock Location</p>
              <span class="wholesale-pick-location-pill wholesale-pick-location-pill--backstock">
                {{ locationLabel(line.backstock_location) }}
              </span>
            </div>

            <div class="wholesale-pick-item-row__metric">
              <p class="wholesale-pick-item-row__metric-label">Pick Location</p>
              <span class="wholesale-pick-location-pill wholesale-pick-location-pill--pick">
                {{ locationLabel(line.pick_location) }}
              </span>
            </div>

            <div class="wholesale-pick-item-row__action">
              <button
                v-if="!line.is_fully_picked"
                type="button"
                class="btn btn-primary wholesale-pick-item-row__pick-btn"
                @click="openPickModal(order, line)"
              >
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 100 4 2 2 0 000-4z" />
                </svg>
                Pick Items
              </button>
              <span v-else class="wholesale-pick-item-row__picked-label">Picked</span>
            </div>
          </div>
        </div>

        <div class="wholesale-pick-order__footer">
          <div class="wholesale-pick-order__footer-stat">
            <span class="wholesale-pick-order__footer-icon" aria-hidden="true">
              <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
              </svg>
            </span>
            <div>
              <p class="wholesale-pick-order__footer-label">Total to Pick</p>
              <p class="wholesale-pick-order__footer-value">{{ nf.format(order.total_quantity || 0) }}</p>
            </div>
          </div>

          <div class="wholesale-pick-order__footer-stat">
            <span class="wholesale-pick-order__footer-icon" aria-hidden="true">
              <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
              </svg>
            </span>
            <div>
              <p class="wholesale-pick-order__footer-label">Total Picked</p>
              <p class="wholesale-pick-order__footer-value">
                {{ formatQtyWithPercent(order.total_quantity_picked, order.total_quantity) }}
              </p>
            </div>
          </div>

          <button
            type="button"
            class="btn wholesale-pick-order__mark-btn"
            :disabled="!order.is_fully_picked || markBusyId === order.id"
            :title="order.is_fully_picked ? '' : 'Pick all line items before marking complete.'"
            @click="markAllPicked(order)"
          >
            <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
            </svg>
            {{ markBusyId === order.id ? "Saving…" : "Mark All as Picked" }}
          </button>
        </div>
      </section>
    </div>

    <WholesalePickItemsModal
      v-model:open="pickModalOpen"
      :order-id="pickModalOrderId"
      :line="pickModalLine"
      @saved="onPickSaved"
    />
  </div>
</template>
