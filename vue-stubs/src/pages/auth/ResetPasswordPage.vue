<script setup>
import { reactive, ref } from "vue";
import api from "../../services/api";

const message = ref("");
const loading = ref(false);
const form = reactive({
  email: "",
  token: "",
  password: "",
  password_confirmation: "",
});

const submit = async () => {
  loading.value = true;
  message.value = "";
  try {
    await api.post("/auth/reset-password", form);
    message.value = "Password reset successful. You can login now.";
  } finally {
    loading.value = false;
  }
};
</script>

<template>
  <div class="min-h-screen flex items-center justify-center bg-slate-100 p-4">
    <div class="w-full max-w-md bg-white rounded-xl shadow p-6">
      <h1 class="text-xl font-semibold mb-4">Reset Password</h1>
      <p v-if="message" class="text-green-600 text-sm mb-3">{{ message }}</p>
      <form class="space-y-3" @submit.prevent="submit">
        <input v-model="form.email" type="email" required placeholder="Email" class="w-full border rounded-lg px-3 py-2" />
        <input v-model="form.token" required placeholder="Reset token" class="w-full border rounded-lg px-3 py-2" />
        <input v-model="form.password" type="password" required placeholder="New password" class="w-full border rounded-lg px-3 py-2" />
        <input v-model="form.password_confirmation" type="password" required placeholder="Confirm password" class="w-full border rounded-lg px-3 py-2" />
        <button :disabled="loading" class="w-full bg-slate-900 text-white rounded-lg py-2">
          {{ loading ? "Resetting..." : "Reset password" }}
        </button>
      </form>
    </div>
  </div>
</template>

