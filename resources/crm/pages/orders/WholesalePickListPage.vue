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
    <div class="mb-4">
      <button
        type="button"
        class="btn btn-link btn-sm text-secondary px-0 py-0 mb-2 text-decoration-none"
        @click="router.push({ name: 'wholesale-orders' })"
      >
        &lt; Wholesale Orders
      </button>
      <h1 class="h4 mb-1 fw-semibold text-body">Pick List</h1>
      <p class="small text-secondary mb-0">Ready-to-ship wholesale orders awaiting pick.</p>
    </div>

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
        class="staff-table-card staff-datatable-card staff-datatable-card--white wholesale-pick-order-card"
      >
        <div class="wholesale-pick-order-card__header px-4 py-3 border-bottom">
          <h2 class="h6 mb-0 fw-semibold">
            Order #{{ order.order_number }}
            <span class="text-secondary fw-normal">· {{ order.client_account_company_name || "—" }}</span>
          </h2>
        </div>

        <div class="table-responsive staff-table-wrap">
          <table class="table table-hover align-middle mb-0 staff-data-table wholesale-pick-table">
            <thead class="table-light staff-table-head">
              <tr>
                <th class="staff-table-head__th" scope="col">Product</th>
                <th class="staff-table-head__th text-center" scope="col">QTY to Pick</th>
                <th class="staff-table-head__th text-center" scope="col">QTY Picked</th>
                <th class="staff-table-head__th text-center" scope="col">Action</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="line in order.lines" :key="`pick-line-${line.id}`">
                <td>
                  <div class="wholesale-pick-product">
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
                      <div class="wholesale-pick-product__name text-truncate">{{ line.name || "—" }}</div>
                      <div class="wholesale-pick-product__sku small text-secondary">{{ line.sku || "—" }}</div>
                    </div>
                  </div>
                </td>
                <td class="text-center wholesale-pick-qty wholesale-pick-qty--target">
                  {{ Number(line.quantity || 0).toLocaleString() }}
                </td>
                <td class="text-center wholesale-pick-qty" :class="qtyPickedClass(line)">
                  {{ Number(line.quantity_picked || 0).toLocaleString() }}
                </td>
                <td class="text-center">
                  <button
                    v-if="!line.is_fully_picked"
                    type="button"
                    class="btn btn-sm btn-outline-secondary fw-semibold orders-toolbar-outline-btn"
                    @click="openPickModal(order, line)"
                  >
                    Pick Items
                  </button>
                  <span v-else class="small text-success fw-semibold">Picked</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div v-if="order.is_fully_picked" class="wholesale-pick-order-card__footer px-4 py-3 border-top">
          <button
            type="button"
            class="btn btn-primary staff-page-primary fw-semibold"
            :disabled="markBusyId === order.id"
            @click="markAllPicked(order)"
          >
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
