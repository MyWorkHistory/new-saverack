<script setup>
import { reactive, ref } from "vue";
import api from "../../services/api";

const loading = ref(false);
const error = ref("");
const form = reactive({
  email: "",
  password: "",
});

const submit = async () => {
  error.value = "";
  loading.value = true;
  try {
    const { data } = await api.post("/auth/login", form);
    localStorage.setItem("auth_token", data.token);
    window.location.href = "/dashboard";
  } catch (e) {
    error.value = e?.response?.data?.message || "Login failed.";
  } finally {
    loading.value = false;
  }
};
</script>

<template>
  <div class="min-h-screen flex items-center justify-center bg-slate-100 p-4">
    <div class="w-full max-w-md bg-white rounded-xl shadow p-6">
      <h1 class="text-2xl font-semibold mb-4">CRM Login</h1>
      <p v-if="error" class="mb-3 text-red-600 text-sm">{{ error }}</p>
      <form class="space-y-4" @submit.prevent="submit">
        <input v-model="form.email" type="email" required placeholder="Email" class="w-full border rounded-lg px-3 py-2" />
        <input v-model="form.password" type="password" required placeholder="Password" class="w-full border rounded-lg px-3 py-2" />
        <button :disabled="loading" class="w-full bg-slate-900 text-white rounded-lg py-2">
          {{ loading ? "Signing in..." : "Login" }}
        </button>
      </form>
      <a href="/forgot-password" class="text-sm text-slate-600 mt-4 inline-block">Forgot password?</a>
    </div>
  </div>
</template>

