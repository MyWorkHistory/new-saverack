<script setup>
import { computed, reactive, ref, watch } from "vue";

const props = defineProps({
  open: { type: Boolean, default: false },
  busy: { type: Boolean, default: false },
  errorMsg: { type: String, default: "" },
  chargeOptions: { type: Array, default: () => [] },
  existingLines: { type: Array, default: () => [] },
  editLine: { type: Object, default: null },
  defaultQuantities: { type: Object, default: () => ({}) },
});

const emit = defineEmits(["update:open", "submit", "delete"]);

const localError = ref("");
const rows = reactive([]);

const isSingleEdit = computed(() => props.editLine != null);

const modalTitle = computed(() => {
  if (!isSingleEdit.value) return "Add Wholesale Fees";
  const source = String(props.editLine?.source || "").toLowerCase();
  if (source === "packaging") return "Edit Packaging Fee";
  if (source === "custom") return "Edit Custom Fee";
  return "Edit Wholesale Fee";
});

const submitLabel = computed(() => (isSingleEdit.value ? "Save" : "Add Fees"));

const visibleDefs = computed(() => {
  const defs = (props.chargeOptions || []).map((option) => ({
    lineType: option.line_type,
    service: option.display_name || option.line_type,
    qtyLabel: option.qty_label || "Qty",
    source: option.source || "wholesale",
    clientAccountFeeId: option.client_account_fee_id ?? null,
  }));
  if (!isSingleEdit.value) return defs;

  const lt = String(props.editLine?.line_type || "");
  const matched = defs.filter((d) => d.lineType === lt);
  if (matched.length) return matched;

  // Packaging / custom / orphaned line types are not in wholesale chargeOptions.
  if (props.editLine) {
    return [
      {
        lineType: props.editLine.line_type,
        service: props.editLine.name || props.editLine.line_type,
        qtyLabel: "Qty",
        source: props.editLine.source || "wholesale",
        clientAccountFeeId: props.editLine.client_account_fee_id ?? null,
      },
    ];
  }

  return [];
});

function defaultPriceCentsForLineType(lineType) {
  const opt = props.chargeOptions.find((o) => o.line_type === lineType);
  if (opt) return Number(opt?.default_unit_price_cents) || 0;
  if (isSingleEdit.value && props.editLine && String(props.editLine.line_type) === String(lineType)) {
    return Number(props.editLine.unit_price_cents) || 0;
  }
  return 0;
}

function defaultPriceForLineType(lineType) {
  const cents = defaultPriceCentsForLineType(lineType);
  if (cents <= 0) return "";
  return (cents / 100).toFixed(2);
}

function defaultPriceLabel(lineType) {
  const cents = defaultPriceCentsForLineType(lineType);
  if (cents > 0) {
    return `Account default: $${(cents / 100).toFixed(2)}`;
  }
  return "No account price configured";
}

function displayNameForLineType(lineType) {
  const opt = props.chargeOptions.find((o) => o.line_type === lineType);
  if (opt?.display_name) return opt.display_name;
  if (isSingleEdit.value && props.editLine && String(props.editLine.line_type) === String(lineType)) {
    return props.editLine.name || lineType;
  }
  return lineType;
}

function defaultQtyForLineType(lineType) {
  const raw = props.defaultQuantities?.[lineType];
  if (raw == null || raw === "") return "";
  const n = Number(raw);
  return Number.isFinite(n) && n > 0 ? String(n) : "";
}

function resetRows() {
  rows.splice(0, rows.length);
  const existingByType = new Map(
    (props.existingLines || []).map((line) => [String(line.line_type), line]),
  );

  for (const def of visibleDefs.value) {
    const existing = existingByType.get(def.lineType);
    if (isSingleEdit.value && props.editLine) {
      rows.push({
        line_type: def.lineType,
        name: displayNameForLineType(def.lineType),
        qtyLabel: def.qtyLabel,
        service: def.service,
        quantity: String(props.editLine.quantity ?? ""),
        unit_price: ((Number(props.editLine.unit_price_cents) || 0) / 100).toFixed(2),
        item_id: props.editLine.id ?? null,
        source: def.source,
        client_account_fee_id: def.clientAccountFeeId,
        selected: true,
      });
    } else {
      const quantity = existing
        ? String(existing.quantity ?? "")
        : defaultQtyForLineType(def.lineType);
      rows.push({
        line_type: def.lineType,
        name: displayNameForLineType(def.lineType),
        qtyLabel: def.qtyLabel,
        service: def.service,
        quantity,
        unit_price: existing
          ? ((Number(existing.unit_price_cents) || 0) / 100).toFixed(2)
          : defaultPriceForLineType(def.lineType),
        item_id: existing?.id ?? null,
        source: def.source,
        client_account_fee_id: def.clientAccountFeeId,
        selected: Boolean(existing && String(existing.quantity ?? "").trim() !== ""),
      });
    }
  }
}

watch(
  () => [props.open, props.editLine, props.existingLines],
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
      name: row.name,
      source: row.source,
      client_account_fee_id: row.client_account_fee_id,
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
        aria-labelledby="wholesale-fees-title"
        @click.self="close"
      >
        <div class="crm-vx-modal crm-vx-modal--lg wholesale-fees-modal" @click.stop>
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
            <h2 id="wholesale-fees-title" class="crm-vx-modal__title">{{ modalTitle }}</h2>
            <p v-if="!isSingleEdit" class="crm-vx-modal__subtitle small text-secondary mb-0">
              Check the fees to add, then enter quantity and price. Prices default from the account.
            </p>
          </header>

          <div class="crm-vx-modal__body">
            <p v-if="errorMsg || localError" class="small text-danger text-center mb-3">
              {{ errorMsg || localError }}
            </p>

            <div class="table-responsive wholesale-fees-modal__table-wrap">
              <table class="table table-sm align-middle mb-0 wholesale-fees-modal-table">
                <thead>
                  <tr>
                    <th scope="col">Service</th>
                    <th v-if="!isSingleEdit" scope="col" class="text-center wholesale-fees-modal__add-col">Add</th>
                    <th scope="col" class="text-end wholesale-fees-modal__qty-col">Qty</th>
                    <th scope="col" class="text-end wholesale-fees-modal__price-col">Price</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="(row, idx) in rows" :key="row.line_type">
                    <td class="fw-medium text-body">
                      <div>{{ row.service }}</div>
                      <div class="small text-secondary">{{ row.qtyLabel }}</div>
                      <div class="small text-secondary wholesale-fees-modal__account-default">
                        {{ defaultPriceLabel(row.line_type) }}
                      </div>
                    </td>
                    <td v-if="!isSingleEdit" class="text-center align-middle wholesale-fees-modal__add-col">
                      <input
                        v-model="rows[idx].selected"
                        type="checkbox"
                        class="form-check-input wholesale-fees-modal__check"
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
                        class="form-control form-control-sm text-end wholesale-fees-modal__qty-input"
                        placeholder="0"
                        :disabled="busy"
                        :aria-label="`${row.service} ${row.qtyLabel}`"
                      />
                    </td>
                    <td class="text-end align-middle">
                      <div class="input-group input-group-sm flex-nowrap justify-content-end wholesale-fees-modal__price-group">
                        <span class="input-group-text">$</span>
                        <input
                          v-model="rows[idx].unit_price"
                          type="number"
                          min="0"
                          step="0.01"
                          class="form-control form-control-sm text-end wholesale-fees-modal__price-input"
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
.wholesale-fees-modal-table th {
  font-size: 0.75rem;
  text-transform: uppercase;
  letter-spacing: 0.02em;
  color: var(--bs-secondary-color);
  white-space: nowrap;
}

.wholesale-fees-modal__table-wrap {
  max-height: min(60vh, 28rem);
  overflow: auto;
}

.wholesale-fees-modal__add-col {
  width: 3.5rem;
}

.wholesale-fees-modal__check {
  cursor: pointer;
  margin: 0;
}

.wholesale-fees-modal__qty-col {
  width: 7rem;
}

.wholesale-fees-modal__price-col {
  width: 10rem;
}

.wholesale-fees-modal__qty-input {
  width: 5.5rem;
  margin-left: auto;
}

.wholesale-fees-modal__price-input {
  width: 5.5rem;
}

.wholesale-fees-modal__account-default {
  line-height: 1.3;
  margin-top: 0.15rem;
}
</style>
