<script setup>
import { computed, ref, watch } from "vue";
import api from "../../services/api";
import { useToast } from "../../composables/useToast";

const props = defineProps({
  open: { type: Boolean, default: false },
  accounts: { type: Array, default: () => [] },
});

const emit = defineEmits(["update:open", "pushed"]);

const toast = useToast();
const busy = ref(false);
const accountId = ref("");

const accountOptions = computed(() =>
  (Array.isArray(props.accounts) ? props.accounts : []).filter((a) => a && a.id && a.connection_id),
);

const selectedAccount = computed(() =>
  accountOptions.value.find((a) => String(a.id) === String(accountId.value)) || null,
);

watch(
  () => props.open,
  (isOpen) => {
    if (!isOpen) return;
    busy.value = false;
    if (!accountId.value && accountOptions.value.length) {
      accountId.value = String(accountOptions.value[0].id);
    }
  },
);

function close() {
  if (busy.value) return;
  emit("update:open", false);
}

async function submit() {
  const account = selectedAccount.value;
  if (!account) {
    toast.error("Select an account.");
    return;
  }
  busy.value = true;
  try {
    const { data } = await api.post(
      `/client-accounts/${account.id}/shopify-connections/${account.connection_id}/push-inventory`,
    );
    toast.success(data?.message || "Inventory pushed to Shopify.");
    emit("pushed");
    emit("update:open", false);
  } catch (e) {
    toast.errorFrom(e, "Could not push inventory to Shopify.");
  } finally {
    busy.value = false;
  }
}
</script>

<template>
  <Teleport to="body">
    <div
      v-if="open"
      class="sip-modal-overlay"
      role="dialog"
      aria-modal="true"
      aria-labelledby="sip-sync-title"
      @click.self="close"
    >
      <div class="sip-modal sip-modal--sm" @click.stop>
        <header class="sip-modal__head">
          <h2 id="sip-sync-title" class="sip-modal__title">Sync Account</h2>
          <button
            type="button"
            class="sip-modal__close"
            aria-label="Close"
            :disabled="busy"
            @click="close"
          >
            <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </header>

        <div class="sip-modal__body">
          <p class="sip-modal__lead">
            Push your inventory to Shopify for the selected account.
          </p>

          <label class="form-label" for="sip-sync-account">Account</label>
          <select
            id="sip-sync-account"
            v-model="accountId"
            class="form-select mb-3"
            :disabled="busy || !accountOptions.length"
          >
            <option
              v-if="!accountOptions.length"
              value=""
            >
              No Shopify accounts connected
            </option>
            <option
              v-for="a in accountOptions"
              :key="a.id"
              :value="String(a.id)"
            >
              {{ a.company_name || `Account #${a.id}` }}
            </option>
          </select>

          <div class="sip-modal__info" role="note">
            <span class="sip-modal__info-icon" aria-hidden="true">
              <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                <path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z" />
              </svg>
            </span>
            <p>
              This will push your current inventory quantities to Shopify. Make sure your products are already imported and linked.
            </p>
          </div>
        </div>

        <footer class="sip-modal__foot">
          <button
            type="button"
            class="btn btn-outline-primary"
            :disabled="busy"
            @click="close"
          >
            Cancel
          </button>
          <button
            type="button"
            class="btn btn-primary staff-page-primary fw-semibold"
            :disabled="busy || !selectedAccount"
            @click="submit"
          >
            {{ busy ? "Pushing…" : "Push Inventory to Shopify" }}
          </button>
        </footer>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.sip-modal-overlay {
  position: fixed;
  inset: 0;
  z-index: 1200;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 1rem;
  background: rgba(15, 23, 42, 0.45);
  backdrop-filter: blur(2px);
}
.sip-modal {
  width: 100%;
  max-width: 28rem;
  display: flex;
  flex-direction: column;
  background: #fff;
  border-radius: 0.75rem;
  box-shadow: 0 20px 40px rgba(15, 23, 42, 0.18);
  overflow: hidden;
}
.sip-modal__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  padding: 1rem 1.25rem;
  border-bottom: 1px solid #e5e7eb;
}
.sip-modal__title {
  margin: 0;
  font-size: 1.1rem;
  font-weight: 700;
  color: #111827;
}
.sip-modal__close {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 2rem;
  height: 2rem;
  border: 0;
  border-radius: 0.4rem;
  background: transparent;
  color: #6b7280;
}
.sip-modal__close:hover:not(:disabled) {
  background: #f3f4f6;
  color: #111827;
}
.sip-modal__body {
  padding: 1.1rem 1.25rem;
}
.sip-modal__lead {
  margin: 0 0 1rem;
  font-size: 0.9rem;
  color: #6b7280;
}
.sip-modal__info {
  display: flex;
  gap: 0.65rem;
  align-items: flex-start;
  padding: 0.85rem 0.95rem;
  border-radius: 0.55rem;
  background: #eff6ff;
  color: #1e40af;
  font-size: 0.85rem;
  line-height: 1.4;
}
.sip-modal__info p {
  margin: 0;
}
.sip-modal__info-icon {
  display: inline-flex;
  flex-shrink: 0;
  margin-top: 0.05rem;
  color: #2563eb;
}
.sip-modal__foot {
  display: flex;
  flex-wrap: wrap;
  justify-content: flex-end;
  gap: 0.55rem;
  padding: 0.95rem 1.25rem;
  border-top: 1px solid #e5e7eb;
  background: #fafafa;
}
</style>
