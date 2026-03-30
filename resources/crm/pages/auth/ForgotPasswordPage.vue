<script setup>
import { ref } from "vue";
import api from "../../services/api";

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
  <div class="min-h-screen flex items-center justify-center bg-slate-100 p-4">
    <div class="w-full max-w-md bg-white rounded-xl shadow p-6">
      <h1 class="text-xl font-semibold mb-4">Forgot Password</h1>
      <p v-if="message" class="text-green-600 text-sm mb-3">{{ message }}</p>
      <form class="space-y-3" @submit.prevent="submit">
        <input v-model="email" type="email" required placeholder="Email" class="w-full border rounded-lg px-3 py-2" />
        <button :disabled="loading" class="w-full bg-slate-900 text-white rounded-lg py-2">
          {{ loading ? "Sending..." : "Send reset link" }}
        </button>
      </form>
    </div>
  </div>
</template>

