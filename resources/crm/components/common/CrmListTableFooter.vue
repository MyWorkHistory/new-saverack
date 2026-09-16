<script setup>
import { computed } from "vue";
import { LIST_PAGE_SIZE_DEFAULT, LIST_PAGE_SIZE_OPTIONS } from "../../constants/pagination.js";

const props = defineProps({
  total: { type: Number, default: 0 },
  currentPage: { type: Number, default: 1 },
  lastPage: { type: Number, default: 1 },
  perPage: { type: Number, default: LIST_PAGE_SIZE_DEFAULT },
  loading: { type: Boolean, default: false },
  noun: { type: String, default: "results" },
});

const emit = defineEmits(["page", "per-page"]);

const showingFrom = computed(() => {
  if (!props.total) return 0;
  return (props.currentPage - 1) * props.perPage + 1;
});

const showingTo = computed(() => {
  if (!props.total) return 0;
  return Math.min(props.currentPage * props.perPage, props.total);
});

const pageItems = computed(() => {
  const last = Math.max(1, props.lastPage || 1);
  const current = Math.min(Math.max(1, props.currentPage || 1), last);
  const start = Math.max(1, current - 1);
  const end = Math.min(last, start + 2);
  const pages = [];
  for (let i = start; i <= end; i += 1) pages.push(i);
  return pages;
});

function go(page) {
  if (props.loading) return;
  if (page < 1 || page > props.lastPage || page === props.currentPage) return;
  emit("page", page);
}

function onPerPageChange(event) {
  const next = Number(event.target.value);
  emit("per-page", LIST_PAGE_SIZE_OPTIONS.includes(next) ? next : LIST_PAGE_SIZE_DEFAULT);
}
</script>

<template>
  <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-lg-between gap-3 border-top staff-table-footer">
    <p class="small text-secondary mb-0">
      Showing
      <span class="fw-semibold text-body">{{ showingFrom }}</span>
      to
      <span class="fw-semibold text-body">{{ showingTo }}</span>
      of
      <span class="fw-semibold text-body">{{ total }}</span>
      {{ noun }}.
    </p>
    <div class="d-flex align-items-center gap-3">
      <select
        class="form-select form-select-sm staff-table-footer-per-page staff-table-footer-per-page--labeled"
        :value="perPage"
        :disabled="loading"
        aria-label="Results per page"
        @change="onPerPageChange"
      >
        <option v-for="n in LIST_PAGE_SIZE_OPTIONS" :key="n" :value="n">{{ n }} per page</option>
      </select>
      <nav class="staff-page-pager staff-page-pager--cluster" :aria-label="`${noun} pages`">
        <button
          type="button"
          class="staff-page-pager-tile staff-page-pager-tile--nav"
          :disabled="loading || currentPage <= 1"
          aria-label="Previous page"
          @click="go(currentPage - 1)"
        >
          ‹
        </button>
        <button
          v-for="p in pageItems"
          :key="p"
          type="button"
          class="staff-page-pager-tile"
          :class="{ 'staff-page-pager-tile--active': p === currentPage }"
          :disabled="loading"
          :aria-current="p === currentPage ? 'page' : undefined"
          @click="go(p)"
        >
          {{ p }}
        </button>
        <button
          type="button"
          class="staff-page-pager-tile staff-page-pager-tile--nav"
          :disabled="loading || currentPage >= lastPage"
          aria-label="Next page"
          @click="go(currentPage + 1)"
        >
          ›
        </button>
      </nav>
    </div>
  </div>
</template>
