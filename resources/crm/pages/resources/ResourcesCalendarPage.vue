<script setup>
import { computed, inject, onMounted, reactive, ref, watch } from "vue";
import FullCalendar from "@fullcalendar/vue3";
import dayGridPlugin from "@fullcalendar/daygrid";
import interactionPlugin from "@fullcalendar/interaction";
import CalendarEventDrawer from "../../components/resources/CalendarEventDrawer.vue";
import CalendarEventDetailModal from "../../components/resources/CalendarEventDetailModal.vue";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import {
  toFullCalendarEvent,
  useResourceCalendarEvents,
} from "../../composables/useResourceCalendarEvents.js";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { canManageCalendarEvent } from "../../utils/calendarEventPermissions.js";
import { crmIsAdmin } from "../../utils/crmUser.js";

const crmUser = inject("crmUser", ref(null));

function userHasPerm(key) {
  const u = crmUser.value;
  if (!u) return false;
  if (crmIsAdmin(u) || u.is_crm_owner) return true;
  return Array.isArray(u.permission_keys) && u.permission_keys.includes(key);
}

const canCreate = computed(() => userHasPerm("resources.create"));

const {
  loading,
  saving,
  deleting,
  events,
  categories,
  loadMeta,
  loadRange,
  createEvent,
  updateEvent,
  deleteEvent,
} = useResourceCalendarEvents();

const calendarRef = ref(null);
const drawerOpen = ref(false);
const drawerMode = ref("create");
const editingEvent = ref(null);
const initialStartDate = ref("");
const initialEndDate = ref("");
const detailOpen = ref(false);
const detailEvent = ref(null);
const filterPersonal = ref(true);
const filterCategories = reactive({});

const viewAllChecked = computed(() => {
  if (!filterPersonal.value) return false;
  return categories.value.every((cat) => filterCategories[cat.value] !== false);
});

const filteredEvents = computed(() =>
  events.value.filter((event) => {
    if (event.is_personal) return filterPersonal.value;
    return filterCategories[event.category] !== false;
  }),
);

const calendarEvents = computed(() => filteredEvents.value.map(toFullCalendarEvent));

const drawerCanSave = computed(() => {
  if (drawerMode.value === "create") return canCreate.value;
  return canManageCalendarEvent(crmUser.value, editingEvent.value);
});

const drawerCanDelete = computed(() => canManageCalendarEvent(crmUser.value, editingEvent.value));

const detailCanEdit = computed(() => canManageCalendarEvent(crmUser.value, detailEvent.value));
const detailCanDelete = computed(() => canManageCalendarEvent(crmUser.value, detailEvent.value));

const calendarOptions = computed(() => ({
  plugins: [dayGridPlugin, interactionPlugin],
  initialView: "dayGridMonth",
  headerToolbar: {
    left: "prev,next today",
    center: "title",
    right: "",
  },
  height: "auto",
  fixedWeekCount: false,
  dayMaxEvents: 3,
  moreLinkClick: "popover",
  events: calendarEvents.value,
  dateClick: handleDateClick,
  eventClick: handleEventClick,
  datesSet: handleDatesSet,
}));

let activeRange = { start: "", end: "" };

function initCategoryFilters() {
  categories.value.forEach((cat) => {
    if (filterCategories[cat.value] === undefined) {
      filterCategories[cat.value] = true;
    }
  });
}

watch(categories, initCategoryFilters, { immediate: true });

function setViewAll(checked) {
  filterPersonal.value = checked;
  categories.value.forEach((cat) => {
    filterCategories[cat.value] = checked;
  });
}

function onViewAllChange(event) {
  setViewAll(event.target.checked);
}

function onCategoryFilterChange() {
  /* view-all checkbox syncs via computed */
}

async function refreshEvents() {
  if (!activeRange.start || !activeRange.end) return;
  await loadRange(activeRange.start, activeRange.end);
}

function handleDatesSet(arg) {
  const start = arg.startStr.slice(0, 10);
  const endExclusive = arg.endStr.slice(0, 10);
  const endDate = new Date(`${endExclusive}T00:00:00`);
  endDate.setDate(endDate.getDate() - 1);
  const y = endDate.getFullYear();
  const m = String(endDate.getMonth() + 1).padStart(2, "0");
  const d = String(endDate.getDate()).padStart(2, "0");
  const end = `${y}-${m}-${d}`;

  activeRange = { start, end };
  void loadRange(start, end);
}

function openCreateDrawer(dateStr) {
  drawerMode.value = "create";
  editingEvent.value = null;
  initialStartDate.value = dateStr;
  initialEndDate.value = dateStr;
  drawerOpen.value = true;
}

function openDetailModal(event) {
  detailEvent.value = event;
  detailOpen.value = true;
}

function closeDetailModal() {
  detailOpen.value = false;
  detailEvent.value = null;
}

function openEditDrawer(event) {
  drawerMode.value = "edit";
  editingEvent.value = event;
  initialStartDate.value = event.start_date;
  initialEndDate.value = event.end_date;
  drawerOpen.value = true;
}

function handleDateClick(info) {
  if (!canCreate.value) return;
  openCreateDrawer(info.dateStr);
}

function handleEventClick(info) {
  info.jsEvent.preventDefault();
  const raw = info.event.extendedProps?.raw;
  if (raw) {
    openDetailModal(raw);
  }
}

function onDetailEdit() {
  const ev = detailEvent.value;
  if (!ev) return;
  closeDetailModal();
  openEditDrawer(ev);
}

async function onDetailDelete() {
  if (!detailEvent.value?.id) return;
  try {
    await deleteEvent(detailEvent.value.id);
    closeDetailModal();
    await refreshEvents();
  } catch {
    /* toast handled */
  }
}

async function onDrawerSave(payload) {
  try {
    if (drawerMode.value === "edit" && editingEvent.value?.id) {
      await updateEvent(editingEvent.value.id, payload);
    } else {
      await createEvent(payload);
    }
    drawerOpen.value = false;
    await refreshEvents();
  } catch {
    /* toast handled */
  }
}

async function onDrawerDelete() {
  if (!editingEvent.value?.id) return;
  try {
    await deleteEvent(editingEvent.value.id);
    drawerOpen.value = false;
    await refreshEvents();
  } catch {
    /* toast handled */
  }
}

function onAddEventClick() {
  const today = new Date();
  const y = today.getFullYear();
  const m = String(today.getMonth() + 1).padStart(2, "0");
  const d = String(today.getDate()).padStart(2, "0");
  openCreateDrawer(`${y}-${m}-${d}`);
}

onMounted(async () => {
  setCrmPageMeta({
    title: "Save Rack | Calendar",
    description: "Staff calendar for meetings, holidays, and operations.",
  });
  try {
    await loadMeta();
    initCategoryFilters();
  } catch {
    /* optional */
  }
});
</script>

<template>
  <div class="resources-calendar-page staff-page staff-page--wide">
    <div class="mb-4">
      <h1 class="h4 mb-1 fw-semibold text-body staff-page__heading">Calendar</h1>
      <p class="staff-page__intro mb-0">Shared team events and personal reminders.</p>
    </div>

    <div class="resources-calendar-layout">
      <aside class="resources-calendar-sidebar card border-0 shadow-sm">
        <div class="resources-calendar-sidebar__body">
          <button
            v-if="canCreate"
            type="button"
            class="btn btn-primary w-100 fw-semibold resources-calendar-add-btn"
            @click="onAddEventClick"
          >
            Add Event
          </button>

          <hr class="resources-calendar-sidebar__divider" />

          <h2 class="resources-calendar-sidebar__heading">Event Filters</h2>

          <ul class="resources-calendar-filters list-unstyled mb-0">
            <li class="resources-calendar-filter">
              <label class="resources-calendar-filter__label">
                <input
                  class="form-check-input resources-calendar-filter__input"
                  type="checkbox"
                  :checked="viewAllChecked"
                  @change="onViewAllChange"
                />
                <span class="resources-calendar-filter__text">View All</span>
              </label>
            </li>

            <li class="resources-calendar-filter">
              <label class="resources-calendar-filter__label">
                <input
                  v-model="filterPersonal"
                  class="form-check-input resources-calendar-filter__input"
                  type="checkbox"
                  @change="onCategoryFilterChange"
                />
                <span
                  class="resources-calendar-filter__dot"
                  style="background-color: #6b7280"
                  aria-hidden="true"
                />
                <span class="resources-calendar-filter__text">Personal</span>
              </label>
            </li>

            <li
              v-for="cat in categories"
              :key="cat.value"
              class="resources-calendar-filter"
            >
              <label class="resources-calendar-filter__label">
                <input
                  v-model="filterCategories[cat.value]"
                  class="form-check-input resources-calendar-filter__input"
                  type="checkbox"
                  @change="onCategoryFilterChange"
                />
                <span
                  class="resources-calendar-filter__dot"
                  :style="{ backgroundColor: cat.color }"
                  aria-hidden="true"
                />
                <span class="resources-calendar-filter__text">{{ cat.label }}</span>
              </label>
            </li>
          </ul>
        </div>
      </aside>

      <div class="resources-calendar-main card border-0 shadow-sm position-relative">
        <div v-if="loading" class="resources-calendar-card__loading">
          <CrmLoadingSpinner />
        </div>
        <div class="card-body p-3 p-md-4">
          <FullCalendar ref="calendarRef" class="resources-calendar" :options="calendarOptions" />
        </div>
      </div>
    </div>

    <CalendarEventDetailModal
      :open="detailOpen"
      :event="detailEvent"
      :can-edit="detailCanEdit"
      :can-delete="detailCanDelete"
      :deleting="deleting"
      @close="closeDetailModal"
      @edit="onDetailEdit"
      @delete="onDetailDelete"
    />

    <CalendarEventDrawer
      v-model:open="drawerOpen"
      :mode="drawerMode"
      :event="editingEvent"
      :categories="categories"
      :initial-start-date="initialStartDate"
      :initial-end-date="initialEndDate"
      :can-save="drawerCanSave"
      :can-delete="drawerCanDelete"
      :busy="saving"
      :deleting="deleting"
      @save="onDrawerSave"
      @delete="onDrawerDelete"
    />
  </div>
</template>
