<script setup>
import { computed, inject, onMounted, reactive, ref } from "vue";
import { useRouter } from "vue-router";
import api from "../../services/api";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import TutorialDrawer from "../../components/resources/TutorialDrawer.vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast.js";
import { crmIsAdmin } from "../../utils/crmUser.js";
import { formatDateUs } from "../../utils/formatUserDates.js";
import { DEFAULT_PER_PAGE } from "../../constants/pagination.js";

const crmUser = inject("crmUser", ref(null));
const toast = useToast();
const router = useRouter();

function userHasPerm(key) {
  const u = crmUser.value;
  if (!u) return false;
  if (crmIsAdmin(u) || u.is_crm_owner) return true;
  return Array.isArray(u.permission_keys) && u.permission_keys.includes(key);
}

const canCreate = computed(() => userHasPerm("resources.create"));

const loading = ref(true);
const rows = ref([]);
const categories = ref([]);
const pagination = ref({ current_page: 1, last_page: 1, total: 0 });
const drawerOpen = ref(false);

const query = reactive({
  search: "",
  category: "",
  sort_by: "created_at",
  sort_dir: "desc",
  page: 1,
  per_page: DEFAULT_PER_PAGE,
});

function sortIndicator(column) {
  if (query.sort_by !== column) return "";
  return query.sort_dir === "asc" ? "↑" : "↓";
}

function toggleSort(column) {
  if (query.sort_by !== column) {
    query.sort_by = column;
    query.sort_dir = "asc";
  } else {
    query.sort_dir = query.sort_dir === "asc" ? "desc" : "asc";
  }
  query.page = 1;
  load();
}

async function loadMeta() {
  try {
    const { data } = await api.get("/resources/tutorials/meta");
    categories.value = Array.isArray(data?.categories) ? data.categories : [];
  } catch {
    categories.value = [];
  }
}

async function load() {
  loading.value = true;
  try {
    const params = {
      page: query.page,
      per_page: query.per_page,
      sort_by: query.sort_by,
      sort_dir: query.sort_dir,
    };
    if (query.search.trim()) params.search = query.search.trim();
    if (query.category) params.category = query.category;
    const { data } = await api.get("/resources/tutorials", { params });
    rows.value = Array.isArray(data?.data) ? data.data : [];
    pagination.value = {
      current_page: data?.current_page ?? 1,
      last_page: data?.last_page ?? 1,
      total: data?.total ?? rows.value.length,
    };
  } catch (e) {
    toast.errorFrom(e, "Could not load tutorials.");
    rows.value = [];
  } finally {
    loading.value = false;
  }
}

function openRow(row) {
  if (!row?.id) return;
  router.push({ name: "resources-tutorial-detail", params: { id: String(row.id) } });
}

function applySearch() {
  query.page = 1;
  load();
}

function goPage(p) {
  if (p < 1 || p > pagination.value.last_page) return;
  query.page = p;
  load();
}

onMounted(() => {
  setCrmPageMeta({
    title: "Save Rack | Tutorials",
    description: "Staff training tutorials.",
  });
  loadMeta();
  load();
});
</script>

<template>
  <div class="staff-page staff-page--wide">
    <div class="d-flex flex-wrap align-items-end justify-content-between gap-3 mb-4">
      <div>
        <h1 class="h4 mb-1 fw-semibold text-body staff-page__heading">Tutorials</h1>
        <p class="staff-page__intro mb-0">Training guides for warehouse and CRM workflows.</p>
      </div>
      <button
        v-if="canCreate"
        type="button"
        class="btn btn-primary staff-page-primary d-inline-flex align-items-center gap-2"
        @click="drawerOpen = true"
      >
        Add Tutorial
      </button>
    </div>

    <div class="staff-table-card staff-datatable-card staff-datatable-card--white">
      <div class="staff-table-toolbar">
        <div class="staff-table-toolbar--row">
          <input
            v-model="query.search"
            type="search"
            class="form-control staff-toolbar-search staff-toolbar-search--inline"
            placeholder="Search tutorials"
            autocomplete="off"
            @keydown.enter.prevent="applySearch"
          />
          <select
            v-model="query.category"
            class="form-select staff-datatable-filters__select"
            :disabled="loading"
            @change="applySearch"
          >
            <option value="">All categories</option>
            <option v-for="c in categories" :key="c.value" :value="c.value">{{ c.label }}</option>
          </select>
          <button type="button" class="btn btn-primary staff-page-primary btn-sm" :disabled="loading" @click="applySearch">
            Search
          </button>
        </div>
      </div>

      <div class="table-responsive staff-table-wrap">
        <table class="table table-hover align-middle mb-0 staff-data-table">
          <thead class="table-light staff-table-head">
            <tr>
              <th class="staff-table-head__th staff-table-head__th--sort" scope="col">
                <button type="button" class="staff-sort-btn" :disabled="loading" @click="toggleSort('title')">
                  Title <span v-if="sortIndicator('title')" class="staff-sort-ind">{{ sortIndicator("title") }}</span>
                </button>
              </th>
              <th class="staff-table-head__th staff-table-head__th--sort" scope="col">
                <button type="button" class="staff-sort-btn" :disabled="loading" @click="toggleSort('category')">
                  Category <span v-if="sortIndicator('category')" class="staff-sort-ind">{{ sortIndicator("category") }}</span>
                </button>
              </th>
              <th class="staff-table-head__th staff-table-head__th--sort" scope="col">
                <button type="button" class="staff-sort-btn" :disabled="loading" @click="toggleSort('created_at')">
                  Created <span v-if="sortIndicator('created_at')" class="staff-sort-ind">{{ sortIndicator("created_at") }}</span>
                </button>
              </th>
              <th class="staff-table-head__th" scope="col">Created By</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="loading">
              <td colspan="4" class="py-5">
                <div class="d-flex justify-content-center">
                  <CrmLoadingSpinner message="Loading tutorials…" />
                </div>
              </td>
            </tr>
            <tr v-else-if="!rows.length">
              <td colspan="4" class="text-center text-secondary py-5">No tutorials found.</td>
            </tr>
            <tr
              v-for="row in rows"
              v-else
              :key="row.id"
              class="align-middle"
              role="button"
              tabindex="0"
              @click="openRow(row)"
              @keydown.enter.prevent="openRow(row)"
            >
              <td class="fw-semibold">{{ row.title }}</td>
              <td>{{ row.category_label || "—" }}</td>
              <td>{{ formatDateUs(row.created_at) || "—" }}</td>
              <td>{{ row.creator?.name || "—" }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div
        v-if="pagination.last_page > 1"
        class="d-flex flex-wrap align-items-center justify-content-between gap-2 px-3 py-3 border-top"
      >
        <span class="small text-secondary">Page {{ pagination.current_page }} of {{ pagination.last_page }}</span>
        <div class="d-flex gap-2">
          <button
            type="button"
            class="btn btn-sm btn-outline-secondary"
            :disabled="pagination.current_page <= 1 || loading"
            @click="goPage(pagination.current_page - 1)"
          >
            Previous
          </button>
          <button
            type="button"
            class="btn btn-sm btn-outline-secondary"
            :disabled="pagination.current_page >= pagination.last_page || loading"
            @click="goPage(pagination.current_page + 1)"
          >
            Next
          </button>
        </div>
      </div>
    </div>

    <TutorialDrawer v-model:open="drawerOpen" :categories="categories" @saved="load" />
  </div>
</template>
