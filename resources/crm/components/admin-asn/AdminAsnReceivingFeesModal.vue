<script setup>
import { computed, reactive, ref, watch } from "vue";

const props = defineProps({
  open: { type: Boolean, default: false },
  busy: { type: Boolean, default: false },
  errorMsg: { type: String, default: "" },
  chargeOptions: { type: Array, default: () => [] },
  existingLines: { type: Array, default: () => [] },
  editLine: { type: Object, default: null },
  qtyHints: {
    type: Object,
    default: () => ({ boxes: 0, pallets: 0, sku_count: 0 }),
  },
});

const emit = defineEmits(["update:open", "submit", "delete"]);

const localError = ref("");
const rows = reactive([]);

const isSingleEdit = computed(() => props.editLine != null);

const modalTitle = computed(() =>
  isSingleEdit.value ? "Edit Receiving Fee" : "Add Receiving Fees",
);

const submitLabel = computed(() => (isSingleEdit.value ? "Save" : "Add Fees"));

const visibleOptions = computed(() => {
  const options = Array.isArray(props.chargeOptions) ? props.chargeOptions : [];
  if (!isSingleEdit.value) return options;
  const feeId = Number(props.editLine?.client_account_fee_id || 0);
  const lt = String(props.editLine?.line_type || "");
  const match = options.find((o) => {
    if (feeId > 0 && Number(o.client_account_fee_id) === feeId) return true;
    return String(o.line_type) === lt;
  });
  if (match) return [match];
  return [
    {
      line_type: lt,
      client_account_fee_id: feeId || null,
      display_name: props.editLine?.name || lt,
      qty_label: "Qty",
      qty_mode: "none",
      autofill: false,
      default_unit_price_cents: Number(props.editLine?.unit_price_cents) || 0,
    },
  ];
});

function defaultPriceCentsForOption(option) {
  return Number(option?.default_unit_price_cents) || 0;
}

function defaultPriceForOption(option) {
  return (defaultPriceCentsForOption(option) / 100).toFixed(2);
}

function defaultPriceLabel(option) {
  const cents = defaultPriceCentsForOption(option);
  if (cents > 0) {
    return `Account default: $${(cents / 100).toFixed(2)}`;
  }
  return "No account price configured";
}

function hintedQty(option) {
  if (!option?.autofill) return "";
  const mode = String(option.qty_mode || "");
  const hints = props.qtyHints || {};
  let n = 0;
  if (mode === "boxes") n = Number(hints.boxes) || 0;
  else if (mode === "pallets") n = Number(hints.pallets) || 0;
  else if (mode === "sku") n = Number(hints.sku_count) || 0;
  return n > 0 ? String(n) : "";
}

function findExistingLine(option) {
  const feeId = Number(option?.client_account_fee_id || 0);
  const lt = String(option?.line_type || "");
  const lines = Array.isArray(props.existingLines) ? props.existingLines : [];
  return (
    lines.find((line) => {
      if (feeId > 0 && Number(line.client_account_fee_id) === feeId) return true;
      return String(line.line_type) === lt;
    }) || null
  );
}

function resetRows() {
  rows.splice(0, rows.length);
  for (const option of visibleOptions.value) {
    if (isSingleEdit.value && props.editLine) {
      rows.push({
        line_type: option.line_type,
        client_account_fee_id: option.client_account_fee_id || null,
        name: option.display_name,
        qtyLabel: option.qty_label || "Qty",
        service: option.display_name,
        quantity: String(props.editLine.quantity ?? ""),
        unit_price: defaultPriceForOption(option),
        item_id: props.editLine.id ?? null,
        selected: true,
        option,
      });
      continue;
    }
    const existing = findExistingLine(option);
    const hinted = hintedQty(option);
    const quantity = existing ? String(existing.quantity ?? "") : hinted;
    rows.push({
      line_type: option.line_type,
      client_account_fee_id: option.client_account_fee_id || null,
      name: option.display_name,
      qtyLabel: option.qty_label || "Qty",
      service: option.display_name,
      quantity,
      unit_price: defaultPriceForOption(option),
      item_id: existing?.id ?? null,
      selected: Boolean(existing && String(existing.quantity ?? "").trim() !== "") || Number(hinted) > 0,
      option,
    });
  }
}

watch(
  () => [props.open, props.editLine, props.existingLines, props.chargeOptions, props.qtyHints],
  () => {
    if (props.open) resetRows();
  },
  { deep: true },
);

function close() {
  if (!props.busy) emit("update:open", false);
}

function parseQty(raw) {
  const s = String(raw ?? "").trim();
  if (s === "") return null;
  const n = Number(s);
  if (!Number.isFinite(n) || n <= 0) return NaN;
  return n;
}

function parsePrice(raw) {
  const s = String(raw ?? "").trim();
  if (s === "") return 0;
  const n = Number(s);
  return Number.isFinite(n) && n >= 0 ? n : NaN;
}

function submit() {
  localError.value = "";
  const payloads = [];
  for (const row of rows) {
    if (!isSingleEdit.value && !row.selected) {
      continue;
    }
    const qty = parseQty(row.quantity);
    if (qty === null) {
      if (row.item_id != null) {
        payloads.push({
          action: "delete",
          item_id: row.item_id,
          line_type: row.line_type,
        });
      }
      continue;
    }
    if (Number.isNaN(qty)) {
      localError.value = `Enter a valid quantity for ${row.service}.`;
      return;
    }
    const price = parsePrice(row.unit_price);
    if (Number.isNaN(price)) {
      localError.value = `Enter a valid price for ${row.service}.`;
      return;
    }
    payloads.push({
      action: row.item_id != null ? "update" : "create",
      item_id: row.item_id,
      line_type: row.line_type,
      client_account_fee_id: row.client_account_fee_id || null,
      name: row.name,
      quantity: qty,
      unit_price: price,
    });
  }

  if (!isSingleEdit.value) {
    const hasCreateOrUpdate = payloads.some((p) => p.action === "create" || p.action === "update");
    if (!hasCreateOrUpdate) {
      localError.value = "Select at least one fee and enter a quantity.";
      return;
    }
  } else if (!payloads.some((p) => p.action === "update" || p.action === "delete")) {
    localError.value = "Enter a valid quantity.";
    return;
  }

  emit("submit", payloads);
}

function removeLine() {
  if (!props.editLine?.id) return;
  emit("delete", props.editLine);
}
</script>
<template>
  <Teleport to="body">
    <Transition name="crm-vx-confirm">
      <div
        v-if="open"
        class="crm-vx-modal-overlay"
        role="dialog"
        aria-modal="true"
        aria-labelledby="asn-receiving-fees-title"
        @click.self="close"
      >
        <div class="crm-vx-modal crm-vx-modal--lg admin-asn-fees-modal" @click.stop>
          <button
            type="button"
            class="crm-vx-modal__close"
            aria-label="Close"
            :disabled="busy"
            @click="close"
          >
            <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>

          <header class="crm-vx-modal__head">
            <h2 id="asn-receiving-fees-title" class="crm-vx-modal__title">{{ modalTitle }}</h2>
            <p v-if="!isSingleEdit" class="crm-vx-modal__subtitle small text-secondary mb-0">
              Check the fees to add, then enter quantity and price. Prices default from the account.
            </p>
          </header>

          <div class="crm-vx-modal__body">
            <p v-if="errorMsg || localError" class="small text-danger text-center mb-3">
              {{ errorMsg || localError }}
            </p>

            <p v-if="!isSingleEdit && !rows.length" class="small text-secondary text-center mb-0">
              No receiving fees are set on this account.
            </p>

            <div v-if="rows.length" class="table-responsive admin-asn-fees-modal__table-wrap">
              <table class="table table-sm align-middle mb-0 admin-asn-fees-modal-table">
                <thead>
                  <tr>
                    <th scope="col">Service</th>
                    <th v-if="!isSingleEdit" scope="col" class="text-center admin-asn-fees-modal__add-col">Add</th>
                    <th scope="col" class="text-end admin-asn-fees-modal__qty-col">Qty</th>
                    <th scope="col" class="text-end admin-asn-fees-modal__price-col">Price</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="(row, idx) in rows" :key="`${row.line_type}-${row.client_account_fee_id || idx}`">
                    <td class="fw-medium text-body">
                      <div>{{ row.service }}</div>
                      <div class="small text-secondary">{{ row.qtyLabel }}</div>
                      <div class="small text-secondary admin-asn-fees-modal__account-default">
                        {{ defaultPriceLabel(row.option) }}
                      </div>
                    </td>
                    <td v-if="!isSingleEdit" class="text-center align-middle admin-asn-fees-modal__add-col">
                      <input
                        v-model="rows[idx].selected"
                        type="checkbox"
                        class="form-check-input admin-asn-fees-modal__check"
                        :disabled="busy"
                        :aria-label="`Add ${row.service}`"
                      />
                    </td>
                    <td class="text-end align-middle">
                      <input
                        v-model="rows[idx].quantity"
                        type="number"
                        min="0"
                        step="any"
                        class="form-control form-control-sm text-end admin-asn-fees-modal__qty-input"
                        placeholder=""
                        :disabled="busy"
                        :aria-label="`${row.service} ${row.qtyLabel}`"
                      />
                    </td>
                    <td class="text-end align-middle">
                      <div class="input-group input-group-sm flex-nowrap justify-content-end admin-asn-fees-modal__price-group">
                        <span class="input-group-text">$</span>
                        <input
                          v-model="rows[idx].unit_price"
                          type="number"
                          min="0"
                          step="0.01"
                          class="form-control form-control-sm text-end admin-asn-fees-modal__price-input"
                          :disabled="busy"
                          :aria-label="`${row.service} price`"
                        />
                      </div>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          <footer class="crm-vx-modal__footer d-flex gap-2 justify-content-between flex-wrap">
            <button
              v-if="isSingleEdit"
              type="button"
              class="crm-vx-modal-btn crm-vx-modal-btn--danger"
              :disabled="busy"
              @click="removeLine"
            >
              Remove
            </button>
            <span v-else />
            <div class="d-flex gap-2 ms-auto">
              <button
                type="button"
                class="crm-vx-modal-btn crm-vx-modal-btn--secondary"
                :disabled="busy"
                @click="close"
              >
                Cancel
              </button>
              <button
                type="button"
                class="crm-vx-modal-btn crm-vx-modal-btn--primary"
                :disabled="busy"
                @click="submit"
              >
                {{ busy ? "Saving…" : submitLabel }}
              </button>
            </div>
          </footer>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.admin-asn-fees-modal-table th {
  font-size: 0.75rem;
  text-transform: uppercase;
  letter-spacing: 0.02em;
  color: var(--bs-secondary-color);
  white-space: nowrap;
}

.admin-asn-fees-modal__table-wrap {
  max-height: min(60vh, 28rem);
  overflow: auto;
}

.admin-asn-fees-modal__add-col {
  width: 3.5rem;
}

.admin-asn-fees-modal__check {
  cursor: pointer;
  margin: 0;
}

.admin-asn-fees-modal__qty-col {
  width: 7rem;
}

.admin-asn-fees-modal__price-col {
  width: 10rem;
}

.admin-asn-fees-modal__qty-input {
  width: 5.5rem;
  margin-left: auto;
}

.admin-asn-fees-modal__price-input {
  width: 5.5rem;
}

.admin-asn-fees-modal__account-default {
  line-height: 1.3;
  margin-top: 0.15rem;
}
</style>
