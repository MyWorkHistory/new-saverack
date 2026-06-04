<script setup>
import { computed, onMounted, ref } from "vue";
import { useRoute, useRouter } from "vue-router";
import api from "../../services/api";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast.js";
import { formatDateUs } from "../../utils/formatUserDates.js";
import {
  formatRmaLabel,
  returnStatusBadgeClass,
  returnStatusLabel,
} from "../../utils/formatReturnDisplay.js";

const route = useRoute();
const router = useRouter();
const toast = useToast();

const loading = ref(true);
const ret = ref(null);

const returnId = computed(() => String(route.params.id || ""));

async function load() {
  loading.value = true;
  try {
    const { data } = await api.get(`/returns/${returnId.value}`);
    ret.value = data;
    setCrmPageMeta({
      title: `Save Rack | ${formatRmaLabel(data.rma_number) || "Process Return"}`,
      description: "Process a pending return.",
    });
  } catch (e) {
    toast.errorFrom(e, "Could not load return.");
    router.push({ name: "admin-process-returns" });
  } finally {
    loading.value = false;
  }
}

onMounted(load);
</script>

<template>
  <div v-if="loading" class="staff-page staff-page--wide py-5 admin-returns-page">
    <CrmLoadingSpinner message="Loading return…" :center="true" />
  </div>

  <div
    v-else-if="ret"
    class="staff-page staff-page--wide admin-returns-page admin-returns-detail-page order-detail-page"
  >
    <div class="staff-table-card staff-datatable-card staff-datatable-card--white user-return-page__header-shell mb-4">
      <div class="p-4 pb-3">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
          <div class="min-w-0">
            <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
              <h1 class="h4 mb-0 fw-semibold text-body">
                {{ formatRmaLabel(ret.rma_number) || "Process Return" }}
              </h1>
              <span class="badge rounded-pill fw-medium" :class="returnStatusBadgeClass(ret.status)">
                {{ returnStatusLabel(ret.status) }}
              </span>
            </div>
            <p class="small text-secondary mb-1 mt-2">
              Order # {{ ret.order_number || "—" }}
            </p>
            <p class="small text-secondary mb-0">
              <strong>{{ ret.client_account_company_name || "—" }}</strong>
              · {{ ret.customer_name || "—" }}
              · {{ Number(ret.items_count ?? 0).toLocaleString() }} items
            </p>
            <button
              type="button"
              class="btn btn-link btn-sm text-secondary px-0 py-0 mt-2 text-decoration-none"
              @click="router.push({ name: 'admin-process-returns' })"
            >
              &lt; Process Returns
            </button>
          </div>
        </div>
      </div>
    </div>

    <div class="staff-table-card staff-datatable-card staff-datatable-card--white w-100">
      <div class="px-4 py-3 border-bottom">
        <h2 class="h6 mb-0 fw-semibold">Processing</h2>
      </div>
      <div class="p-5 text-center text-secondary">
        <p class="mb-0 fw-semibold text-body">More info coming soon</p>
        <p class="small mb-0 mt-2">Return processing workflow will be added here.</p>
        <p v-if="ret.created_at" class="small text-secondary mb-0 mt-3">
          Submitted {{ formatDateUs(ret.created_at) }}
        </p>
      </div>
    </div>
  </div>
</template>
