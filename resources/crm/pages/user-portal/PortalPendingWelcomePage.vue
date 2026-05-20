<script setup>
import { computed, inject, onMounted, ref } from "vue";
import { RouterLink } from "vue-router";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useRouter } from "vue-router";

const router = useRouter();
const crmUser = inject("crmUser", ref(null));

function signOut() {
  localStorage.removeItem("auth_token");
  router.push("/login");
}

const companyName = computed(() => {
  const name = String(crmUser.value?.client_account_company_name || "").trim();
  return name || "your company";
});

const awaitingShipHero = computed(() => !crmUser.value?.shiphero_ready);
const awaitingActivation = computed(
  () =>
    crmUser.value?.status === "pending" ||
    crmUser.value?.client_account_status === "pending",
);

onMounted(() => {
  setCrmPageMeta({
    title: "Save Rack | Account setup",
    description: "Your account is being set up.",
  });
});
</script>

<template>
  <div class="staff-page staff-page--wide portal-welcome-page">
    <div class="staff-table-card p-4 p-md-5 mx-auto portal-welcome-page__card">
      <h1 class="h4 fw-semibold mb-2">Thanks for signing up</h1>
      <p class="text-secondary mb-4">
        Your Save Rack workspace for <strong>{{ companyName }}</strong> has been created.
        Our team is finishing setup before inventory and orders are available.
      </p>

      <ul class="portal-welcome-page__list mb-4">
        <li v-if="awaitingActivation">
          <span class="portal-welcome-page__step">Account review</span>
          — Save Rack is reviewing your registration.
        </li>
        <li v-if="awaitingShipHero">
          <span class="portal-welcome-page__step">ShipHero link</span>
          — Your warehouse customer profile is being connected.
        </li>
        <li>
          <span class="portal-welcome-page__step">Portal access</span>
          — You can sign in anytime; the dashboard will unlock when setup is complete.
        </li>
      </ul>

      <p class="small text-secondary mb-4">
        Questions? Contact your Save Rack account manager or email
        <a href="mailto:billing@saverack.com" class="auth-vuexy-link">billing@saverack.com</a>.
      </p>

      <div class="d-flex flex-wrap gap-2">
        <RouterLink to="/users/dashboard" class="btn btn-primary">
          View Dashboard
        </RouterLink>
        <button
          type="button"
          class="btn btn-outline-secondary orders-toolbar-outline-btn"
          @click="signOut"
        >
          Sign Out
        </button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.portal-welcome-page__card {
  max-width: 40rem;
}

.portal-welcome-page__list {
  padding-left: 1.25rem;
  margin: 0;
  color: var(--bs-body-color);
}

.portal-welcome-page__list li {
  margin-bottom: 0.65rem;
  line-height: 1.5;
}

.portal-welcome-page__step {
  font-weight: 600;
}
</style>
