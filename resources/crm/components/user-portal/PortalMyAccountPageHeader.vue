<script setup>
import { computed, inject, ref } from "vue";

defineProps({
  title: { type: String, required: true },
});

const crmUser = inject("crmUser", ref(null));

const accountLabel = computed(() => {
  const u = crmUser.value;
  if (!u) return "";
  const name = String(u.client_account_company_name || "").trim();
  if (name) return name;
  const id = Number(u.client_account_id || 0);
  return id > 0 ? `Account #${id}` : "";
});
</script>

<template>
  <header class="portal-my-account-page__head mb-4">
    <p class="text-secondary small mb-1">My Account</p>
    <div class="d-flex flex-wrap align-items-baseline gap-2">
      <h1 class="h4 fw-semibold text-body mb-0">{{ title }}</h1>
      <span
        v-if="accountLabel"
        class="badge rounded-pill bg-body-secondary text-body-secondary fw-medium"
      >
        Account: {{ accountLabel }}
      </span>
    </div>
  </header>
</template>
