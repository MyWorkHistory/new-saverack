<script setup>
import { computed, onMounted, ref } from "vue";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import PortalOnboardingSectionModal from "../../components/user-portal/PortalOnboardingSectionModal.vue";
import PortalMyAccountPageHeader from "../../components/user-portal/PortalMyAccountPageHeader.vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast";
import api from "../../services/api";

const toast = useToast();
const loading = ref(true);
const onboarding = ref(null);

const preferences = computed(() => onboarding.value?.preferences || {});
const profile = computed(() => onboarding.value?.profile || null);

setCrmPageMeta({
  title: "Save Rack | Shipping Instructions",
  description: "Manage your shipping carrier preferences.",
});

async function loadOnboarding() {
  loading.value = true;
  try {
    const { data } = await api.get("/portal/onboarding");
    onboarding.value = data;
  } catch (e) {
    toast.errorFrom(e, "Could not load shipping instructions.");
  } finally {
    loading.value = false;
  }
}

function onSaved(data) {
  if (data && typeof data === "object") {
    onboarding.value = data;
  }
}

onMounted(() => loadOnboarding());
</script>

<template>
  <div class="staff-page staff-page--wide">
    <PortalMyAccountPageHeader title="Shipping Instructions" />
    <p class="text-secondary small mb-3">
      Choose your preferred shipping carriers, service levels, and delivery preferences for outbound
      shipments.
    </p>

    <div v-if="loading" class="d-flex justify-content-center py-5">
      <CrmLoadingSpinner />
    </div>

    <div v-else class="staff-table-card staff-datatable-card staff-datatable-card--white p-0 overflow-hidden">
      <PortalOnboardingSectionModal
        page-mode
        :open="true"
        section-id="shipping_carrier_preferences"
        :preferences="preferences"
        :profile="profile"
        @saved="onSaved"
      />
    </div>
  </div>
</template>
