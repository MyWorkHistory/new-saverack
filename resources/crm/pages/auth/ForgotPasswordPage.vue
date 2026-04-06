<script setup>
import { ref } from "vue";
import { RouterLink } from "vue-router";
import api from "../../services/api";
import AuthVuexyShell from "../../components/auth/AuthVuexyShell.vue";

const email = ref("");
const message = ref("");
const loading = ref(false);

const submit = async () => {
  loading.value = true;
  message.value = "";
  try {
    await api.post("/auth/forgot-password", { email: email.value });
    message.value = "If your email exists, reset instructions were sent.";
  } finally {
    loading.value = false;
  }
};
</script>

<template>
  <AuthVuexyShell>
    <h1 class="auth-vuexy-heading">Forgot password? 🔒</h1>
    <p class="auth-vuexy-lead">
      Enter your email and we’ll send reset instructions if an account exists.
    </p>

    <div
      v-if="message"
      class="alert alert-success small mt-4 mb-0"
      role="status"
    >
      {{ message }}
    </div>

    <form class="mt-4" @submit.prevent="submit">
      <div class="mb-4">
        <label class="form-label small fw-medium text-body-secondary" for="forgot-email">
          Email<span class="text-danger" aria-hidden="true">*</span>
        </label>
        <input
          id="forgot-email"
          v-model="email"
          type="email"
          required
          autocomplete="email"
          placeholder="Enter your email"
          class="form-control"
        />
      </div>

      <button
        type="submit"
        class="btn btn-primary auth-vuexy-btn-primary w-100"
        :disabled="loading"
      >
        {{ loading ? "Sending…" : "Send reset link" }}
      </button>

      <p class="text-center mt-4 mb-0">
        <RouterLink to="/login" class="auth-vuexy-link small">
          Back to sign in
        </RouterLink>
      </p>
    </form>
  </AuthVuexyShell>
</template>
