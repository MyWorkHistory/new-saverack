<script setup>
import { computed, ref, watch } from "vue";
import { useToast } from "../../composables/useToast";

const props = defineProps({
  open: { type: Boolean, default: false },
  title: { type: String, required: true },
  subtitle: { type: String, default: "" },
  /** [{ label: "SKU", required: true }] — required ones render highlighted. */
  columns: { type: Array, default: () => [] },
  templateName: { type: String, default: "template.csv" },
  submitLabel: { type: String, default: "Upload CSV" },
  busy: { type: Boolean, default: false },
  /** Optional account picker — [{ id, company_name }]. */
  accounts: { type: Array, default: () => [] },
  requireAccount: { type: Boolean, default: false },
  accountLabel: { type: String, default: "Account" },
  defaultAccountId: { type: [String, Number], default: "" },
});

const emit = defineEmits(["update:open", "submit"]);

const toast = useToast();
const file = ref(null);
const fileInput = ref(null);
const dragging = ref(false);
const accountId = ref("");

const requiredColumns = computed(() => props.columns.filter((c) => c.required));

const requiredNote = computed(() =>
  requiredColumns.value.length === 1 ? "(Required field)" : "(Required fields)",
);

function defaultAccount() {
  const preset = String(props.defaultAccountId || "");
  if (preset && props.accounts.some((a) => String(a.id) === preset)) return preset;
  if (props.accounts.length === 1) return String(props.accounts[0].id);
  return "";
}

watch(
  () => props.open,
  (isOpen) => {
    if (!isOpen) return;
    file.value = null;
    dragging.value = false;
    accountId.value = defaultAccount();
    if (fileInput.value) fileInput.value.value = "";
  },
);

function close() {
  if (props.busy) return;
  emit("update:open", false);
}

function browse() {
  fileInput.value?.click();
}

function isCsv(candidate) {
  if (!candidate) return false;
  return /\.csv$/i.test(candidate.name || "") || candidate.type === "text/csv";
}

function setFile(candidate) {
  if (!isCsv(candidate)) {
    toast.error("Choose a CSV (comma delimited) file.");
    return;
  }
  file.value = candidate;
}

function onFileChange(e) {
  const picked = e?.target?.files?.[0] || null;
  if (picked) setFile(picked);
}

function onDrop(e) {
  dragging.value = false;
  if (props.busy) return;
  const dropped = e?.dataTransfer?.files?.[0] || null;
  if (dropped) setFile(dropped);
}

function clearFile() {
  file.value = null;
  if (fileInput.value) fileInput.value.value = "";
}

function downloadTemplate() {
  const header = props.columns.map((c) => c.label).join(",");
  const blob = new Blob([`${header}\n`], { type: "text/csv;charset=utf-8" });
  const url = URL.createObjectURL(blob);
  const a = document.createElement("a");
  a.href = url;
  a.download = props.templateName;
  a.click();
  URL.revokeObjectURL(url);
}

function submit() {
  if (props.busy) return;
  if (!file.value) {
    toast.error("Choose a CSV file to upload.");
    return;
  }
  if (props.requireAccount && !accountId.value) {
    toast.error(`Select an ${props.accountLabel.toLowerCase()}.`);
    return;
  }
  emit("submit", { file: file.value, accountId: accountId.value || null });
}
</script>

<template>
  <Teleport to="body">
    <div
      v-if="open"
      class="scsv-overlay"
      role="dialog"
      aria-modal="true"
      :aria-label="title"
      @click.self="close"
    >
      <div class="scsv-modal" @click.stop>
        <button type="button" class="scsv-close" aria-label="Close" :disabled="busy" @click="close">
          <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>

        <div class="scsv-body">
          <span class="scsv-hero" aria-hidden="true">
            <svg width="60" height="64" viewBox="0 0 60 64" fill="none">
              <path
                d="M8 5.5A3.5 3.5 0 0 1 11.5 2h20.7c.93 0 1.82.37 2.47 1.03l11.8 11.8c.66.65 1.03 1.54 1.03 2.47V52a3.5 3.5 0 0 1-3.5 3.5h-32A3.5 3.5 0 0 1 8 52V5.5Z"
                stroke="#1f6bff"
                stroke-width="2.5"
              />
              <path d="M33 2.5V14a3 3 0 0 0 3 3h11.5" stroke="#1f6bff" stroke-width="2.5" />
              <rect x="16" y="24" width="24" height="18" rx="2" stroke="#1f6bff" stroke-width="2.5" />
              <path d="M16 31h24M16 37h24M24 24v18M32 24v18" stroke="#1f6bff" stroke-width="1.8" />
              <circle cx="45" cy="47" r="11.5" fill="#1f6bff" stroke="#fff" stroke-width="3" />
              <path
                d="M45 52.5v-11m0 0-4 4m4-4 4 4"
                stroke="#fff"
                stroke-width="2.5"
                stroke-linecap="round"
                stroke-linejoin="round"
              />
            </svg>
          </span>

          <h2 class="scsv-title">{{ title }}</h2>
          <p v-if="subtitle" class="scsv-subtitle">{{ subtitle }}</p>

          <div v-if="requireAccount || accounts.length" class="scsv-account">
            <label class="scsv-account__label" for="scsv-account">
              {{ accountLabel }}
              <span v-if="requireAccount" class="scsv-account__req">*</span>
            </label>
            <select id="scsv-account" v-model="accountId" class="form-select" :disabled="busy">
              <option value="">
                {{ accounts.length ? (requireAccount ? "Select an account…" : "All accounts") : "No connected Shopify stores" }}
              </option>
              <option v-for="a in accounts" :key="a.id" :value="String(a.id)">
                {{ a.company_name || `Account #${a.id}` }}
              </option>
            </select>
          </div>

          <div
            class="scsv-drop"
            :class="{ 'is-dragging': dragging, 'is-filled': !!file }"
            role="button"
            tabindex="0"
            @click="browse"
            @keydown.enter.prevent="browse"
            @keydown.space.prevent="browse"
            @dragover.prevent="dragging = true"
            @dragenter.prevent="dragging = true"
            @dragleave.prevent="dragging = false"
            @drop.prevent="onDrop"
          >
            <template v-if="file">
              <svg
                class="scsv-drop__icon"
                width="40"
                height="40"
                fill="none"
                viewBox="0 0 24 24"
                stroke="#1f6bff"
                stroke-width="1.6"
                aria-hidden="true"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"
                />
              </svg>
              <p class="scsv-drop__title">{{ file.name }}</p>
              <p class="scsv-drop__hint">
                <button type="button" class="scsv-link" :disabled="busy" @click.stop="browse">
                  Choose a different file
                </button>
                <span class="scsv-drop__sep">·</span>
                <button type="button" class="scsv-link" :disabled="busy" @click.stop="clearFile">
                  Remove
                </button>
              </p>
            </template>
            <template v-else>
              <svg
                class="scsv-drop__icon"
                width="46"
                height="46"
                fill="none"
                viewBox="0 0 24 24"
                stroke="#1f6bff"
                stroke-width="1.4"
                aria-hidden="true"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  d="M12 16.5V9.75m0 0 3 3m-3-3-3 3M6.75 19.5a4.5 4.5 0 0 1-1.41-8.775 5.25 5.25 0 0 1 10.233-2.33 3 3 0 0 1 3.758 3.848A3.752 3.752 0 0 1 18 19.5H6.75Z"
                />
              </svg>
              <p class="scsv-drop__title">Drag and drop your CSV file here</p>
              <p class="scsv-drop__hint">
                or <span class="scsv-link">browse</span> from your computer
              </p>
            </template>
          </div>

          <input
            ref="fileInput"
            type="file"
            class="d-none"
            accept=".csv,text/csv"
            @change="onFileChange"
          />

          <p class="scsv-format">File must be in CSV (comma delimited) (*.csv) format.</p>

          <div v-if="columns.length" class="scsv-columns">
            <p class="scsv-columns__head">
              Required columns
              <span v-if="requiredColumns.length" class="scsv-columns__note">{{ requiredNote }}</span>
            </p>
            <div class="scsv-chips">
              <span
                v-for="col in columns"
                :key="col.label"
                class="scsv-chip"
                :class="{ 'is-required': col.required }"
              >
                {{ col.label }}
              </span>
            </div>
          </div>

          <button type="button" class="scsv-template" @click="downloadTemplate">
            <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3"
              />
            </svg>
            Download CSV template
          </button>
        </div>

        <footer class="scsv-foot">
          <button type="button" class="btn btn-outline-secondary scsv-btn" :disabled="busy" @click="close">
            Cancel
          </button>
          <button
            type="button"
            class="btn btn-primary staff-page-primary fw-semibold scsv-btn"
            :disabled="busy"
            @click="submit"
          >
            {{ busy ? "Uploading…" : submitLabel }}
          </button>
        </footer>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.scsv-overlay {
  position: fixed;
  inset: 0;
  z-index: 1200;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 1rem;
  overflow-y: auto;
  background: rgba(15, 23, 42, 0.45);
  backdrop-filter: blur(2px);
}
.scsv-modal {
  position: relative;
  width: 100%;
  max-width: 32rem;
  margin: auto;
  display: flex;
  flex-direction: column;
  background: #fff;
  border-radius: 0.85rem;
  box-shadow: 0 20px 45px rgba(15, 23, 42, 0.2);
  overflow: hidden;
}
.scsv-close {
  position: absolute;
  top: 0.75rem;
  right: 0.75rem;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 2rem;
  height: 2rem;
  border: 0;
  border-radius: 0.4rem;
  background: transparent;
  color: #9ca3af;
}
.scsv-close:hover:not(:disabled) {
  background: #f3f4f6;
  color: #111827;
}
.scsv-body {
  padding: 2rem 1.75rem 1.5rem;
  text-align: center;
}
.scsv-hero {
  display: inline-flex;
  margin-bottom: 0.9rem;
}
.scsv-title {
  margin: 0 0 0.4rem;
  font-size: 1.5rem;
  font-weight: 700;
  color: #111827;
}
.scsv-subtitle {
  margin: 0 0 1.25rem;
  font-size: 0.975rem;
  color: #6b7280;
}
.scsv-account {
  margin: 0 0 1.1rem;
  text-align: left;
}
.scsv-account__label {
  display: block;
  margin-bottom: 0.3rem;
  font-size: 0.85rem;
  font-weight: 600;
  color: #374151;
}
.scsv-account__req {
  color: #dc2626;
}
.scsv-drop {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 0.35rem;
  padding: 1.85rem 1rem;
  border: 2px dashed #7ea6ff;
  border-radius: 0.6rem;
  background: #fff;
  cursor: pointer;
  transition:
    border-color 0.15s ease,
    background-color 0.15s ease;
}
.scsv-drop:hover,
.scsv-drop:focus-visible,
.scsv-drop.is-dragging {
  border-color: #1f6bff;
  background: #f5f8ff;
  outline: none;
}
.scsv-drop.is-filled {
  border-style: solid;
  border-color: #bfd3ff;
  background: #f8faff;
}
.scsv-drop__icon {
  margin-bottom: 0.35rem;
}
.scsv-drop__title {
  margin: 0;
  font-size: 1.05rem;
  font-weight: 700;
  color: #111827;
  overflow-wrap: anywhere;
}
.scsv-drop__hint {
  margin: 0;
  font-size: 0.95rem;
  color: #6b7280;
}
.scsv-drop__sep {
  margin: 0 0.35rem;
  color: #d1d5db;
}
.scsv-link {
  padding: 0;
  border: 0;
  background: none;
  color: #1f6bff;
  font: inherit;
  text-decoration: none;
}
.scsv-link:hover:not(:disabled) {
  text-decoration: underline;
}
.scsv-format {
  margin: 0.9rem 0 1.25rem;
  font-size: 0.9rem;
  color: #6b7280;
}
.scsv-columns {
  text-align: left;
}
.scsv-columns__head {
  margin: 0 0 0.6rem;
  font-size: 1rem;
  font-weight: 700;
  color: #111827;
}
.scsv-columns__note {
  margin-left: 0.35rem;
  font-size: 0.9rem;
  font-weight: 600;
  color: #16a34a;
}
.scsv-chips {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}
.scsv-chip {
  padding: 0.5rem 0.85rem;
  border: 1px solid #e5e7eb;
  border-radius: 0.5rem;
  background: #fff;
  font-size: 0.95rem;
  color: #374151;
}
.scsv-chip.is-required {
  border-color: #86d99f;
  background: #eefbf1;
  color: #15803d;
}
.scsv-template {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  margin-top: 1.1rem;
  padding: 0;
  border: 0;
  background: none;
  color: #1f6bff;
  font-size: 1rem;
  font-weight: 500;
}
.scsv-template:hover {
  text-decoration: underline;
}
.scsv-foot {
  display: flex;
  flex-wrap: wrap;
  justify-content: flex-end;
  gap: 0.65rem;
  padding: 1rem 1.75rem;
  border-top: 1px solid #e5e7eb;
}
.scsv-btn {
  min-width: 8.5rem;
  min-height: 2.9rem;
  border-radius: 0.5rem;
  font-size: 1rem;
}
@media (max-width: 575.98px) {
  .scsv-body {
    padding: 1.75rem 1.15rem 1.25rem;
  }
  .scsv-foot {
    padding: 0.9rem 1.15rem;
  }
  .scsv-btn {
    flex: 1 1 auto;
    min-width: 0;
  }
}
</style>
