<script setup>
import { reactive, ref, watch } from "vue";
import api from "../../services/api";
import CrmLoadingSpinner from "../common/CrmLoadingSpinner.vue";
import { useToast } from "../../composables/useToast";
import {
  DEFAULT_INVOICE_REVIEW_REASON,
  INVOICE_REVIEW_REASONS,
} from "../../constants/invoiceReviewReasons.js";

const props = defineProps({
  open: { type: Boolean, default: false },
  invoice: { type: Object, default: null },
});

const emit = defineEmits(["update:open", "sent"]);

const toast = useToast();
const saving = ref(false);
const errorMsg = ref("");

const form = reactive({
  reason: DEFAULT_INVOICE_REVIEW_REASON,
  note: "",
});

function close() {
  emit("update:open", false);
}

function resetForm() {
  form.reason = DEFAULT_INVOICE_REVIEW_REASON;
  form.note = "";
  errorMsg.value = "";
}

watch(
  () => props.open,
  (isOpen) => {
    if (isOpen) {
      resetForm();
    }
  },
);

async function submit() {
  const invoiceId = props.invoice?.id;
  if (!invoiceId || saving.value) return;

  saving.value = true;
  errorMsg.value = "";
  try {
    const { data } = await api.post(`/invoices/${invoiceId}/invoice-review`, {
      reason: form.reason,
      note: form.note.trim() === "" ? null : form.note.trim(),
    });
    const channelHint =
      data?.slack_channel || data?.slack?.channel
        ? ` (${data.slack_channel || data.slack.channel})`
        : "";
    toast.success(`Invoice review sent to Slack${channelHint}.`);
    emit("sent");
    close();
  } catch (e) {
    errorMsg.value = "Could not send invoice review.";
    toast.errorFrom(e, "Could not send invoice review.");
  } finally {
    saving.value = false;
  }
}
</script>

<template>
  <Teleport to="body">
    <Transition name="crm-vx-confirm">
      <div
        v-if="open && invoice"
        class="crm-vx-modal-overlay"
        role="dialog"
        aria-modal="true"
        aria-labelledby="invoice-review-slack-modal-title"
        @click.self="!saving && close()"
      >
        <div class="crm-vx-modal crm-vx-modal--sm" @click.stop>
          <button
            type="button"
            class="crm-vx-modal__close"
            aria-label="Close"
            :disabled="saving"
            @click="close"
          >
            <svg
              width="20"
              height="20"
              fill="none"
              viewBox="0 0 24 24"
              stroke="currentColor"
              stroke-width="1.75"
              aria-hidden="true"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                d="M6 18L18 6M6 6l12 12"
              />
            </svg>
          </button>
          <header class="crm-vx-modal__head">
            <h2 id="invoice-review-slack-modal-title" class="crm-vx-modal__title">
              Invoice Review
            </h2>
            <p class="crm-vx-modal__subtitle mb-0">
              Send a review note to the #accounting Slack channel.
            </p>
          </header>
          <form class="crm-vx-modal__body" @submit.prevent="submit">
            <p v-if="errorMsg" class="small text-danger mb-3">{{ errorMsg }}</p>
            <div class="mb-3">
              <label class="form-label" for="invoice-review-reason">Reason</label>
              <select
                id="invoice-review-reason"
                v-model="form.reason"
                class="form-select"
                required
                :disabled="saving"
              >
                <option
                  v-for="opt in INVOICE_REVIEW_REASONS"
                  :key="opt.value"
                  :value="opt.value"
                >
                  {{ opt.label }}
                </option>
              </select>
            </div>
            <div class="mb-0">
              <label class="form-label" for="invoice-review-note">Note</label>
              <textarea
                id="invoice-review-note"
                v-model="form.note"
                class="form-control"
                rows="4"
                placeholder="Optional details for accounting…"
                :disabled="saving"
              />
            </div>
            <footer class="crm-vx-modal__footer d-flex gap-2 justify-content-end px-0 pb-0 mt-4">
              <button
                type="button"
                class="crm-vx-modal-btn crm-vx-modal-btn--secondary"
                :disabled="saving"
                @click="close"
              >
                Cancel
              </button>
              <button
                type="submit"
                class="crm-vx-modal-btn crm-vx-modal-btn--primary"
                :disabled="saving"
              >
                {{ saving ? "Sending…" : "Submit" }}
              </button>
            </footer>
          </form>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

