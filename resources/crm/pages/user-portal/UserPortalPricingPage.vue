<script setup>
import { computed, onMounted, ref } from "vue";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import PortalFulfillmentPricingModal from "../../components/user-portal/PortalFulfillmentPricingModal.vue";
import PortalMyAccountPageHeader from "../../components/user-portal/PortalMyAccountPageHeader.vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast";
import api from "../../services/api";

const toast = useToast();
const loading = ref(true);
const onboarding = ref(null);

const fulfillmentPricing = computed(() => onboarding.value?.fulfillment_pricing || null);

setCrmPageMeta({
  title: "Save Rack | Pricing",
  description: "Review your account fulfillment pricing.",
});

async function loadOnboarding() {
  loading.value = true;
  try {
    const { data } = await api.get("/portal/onboarding");
    onboarding.value = data;
  } catch (e) {
    toast.errorFrom(e, "Could not load pricing.");
  } finally {
    loading.value = false;
  }
}

function onAccepted(data) {
  if (data && typeof data === "object") {
    onboarding.value = data;
  }
}

onMounted(() => loadOnboarding());
</script>

<template>
  <div class="staff-page staff-page--wide">
    <PortalMyAccountPageHeader title="Pricing" />

    <div v-if="loading" class="d-flex justify-content-center py-5">
      <CrmLoadingSpinner />
    </div>

    <div v-else class="staff-table-card staff-datatable-card staff-datatable-card--white p-0 overflow-hidden">
      <PortalFulfillmentPricingModal
        page-mode
        :pricing="fulfillmentPricing"
        @accepted="onAccepted"
      />
    </div>
  </div>
</template>
