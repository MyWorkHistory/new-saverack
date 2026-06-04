<script setup>
import { computed, onMounted, ref } from "vue";
import { useRoute, useRouter } from "vue-router";
import api from "../../services/api";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast.js";
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
  <div class="staff-page staff-page--wide admin-returns-page">
    <div class="mb-3">
      <button
        type="button"
        class="btn btn-link btn-sm px-0 text-secondary"
        @click="router.push({ name: 'admin-process-returns' })"
      >
        ← Process Returns
      </button>
    </div>

    <div v-if="loading" class="py-5">
      <CrmLoadingSpinner message="Loading return…" />
    </div>

    <template v-else-if="ret">
      <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-4">
        <div>
          <h1 class="h4 mb-2 fw-semibold text-body">
            {{ formatRmaLabel(ret.rma_number) || "Process Return" }}
          </h1>
          <div class="d-flex flex-wrap gap-2 align-items-center">
            <span class="badge rounded-pill" :class="returnStatusBadgeClass(ret.status)">
              {{ returnStatusLabel(ret.status) }}
            </span>
            <span class="text-secondary small">Order # {{ ret.order_number || "—" }}</span>
          </div>
        </div>
      </div>

      <div class="staff-table-card staff-datatable-card staff-datatable-card--white w-100 mb-4">
        <div class="p-4">
          <dl class="row mb-0 small">
            <dt class="col-sm-3 text-secondary">Account</dt>
            <dd class="col-sm-9">{{ ret.client_account_company_name || "—" }}</dd>
            <dt class="col-sm-3 text-secondary">Customer</dt>
            <dd class="col-sm-9">{{ ret.customer_name || "—" }}</dd>
            <dt class="col-sm-3 text-secondary">Items</dt>
            <dd class="col-sm-9">{{ ret.items_count ?? "—" }}</dd>
          </dl>
        </div>
      </div>

      <div class="staff-table-card staff-datatable-card staff-datatable-card--white w-100">
        <div class="p-5 text-center text-secondary">
          <p class="mb-0 fw-semibold text-body">More info coming soon</p>
          <p class="small mb-0 mt-2">Return processing workflow will be added here.</p>
        </div>
      </div>
    </template>
  </div>
</template>
