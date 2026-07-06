<script setup>
import { computed, inject, onMounted, ref } from "vue";
import FullCalendar from "@fullcalendar/vue3";
import dayGridPlugin from "@fullcalendar/daygrid";
import interactionPlugin from "@fullcalendar/interaction";
import CalendarEventDrawer from "../../components/resources/CalendarEventDrawer.vue";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import {
  toFullCalendarEvent,
  useResourceCalendarEvents,
} from "../../composables/useResourceCalendarEvents.js";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { crmIsAdmin } from "../../utils/crmUser.js";

const crmUser = inject("crmUser", ref(null));

function userHasPerm(key) {
  const u = crmUser.value;
  if (!u) return false;
  if (crmIsAdmin(u) || u.is_crm_owner) return true;
  return Array.isArray(u.permission_keys) && u.permission_keys.includes(key);
}

const canCreate = computed(() => userHasPerm("resources.create"));
const canUpdate = computed(() => userHasPerm("resources.update"));
const canDelete = computed(() => userHasPerm("resources.delete"));

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

const calendarEvents = computed(() => events.value.map(toFullCalendarEvent));

const drawerCanSave = computed(() => {
  if (drawerMode.value === "create") return canCreate.value;
  const ev = editingEvent.value;
  if (!ev) return false;
  if (ev.is_personal) {
    return Number(ev.created_by_user_id) === Number(crmUser.value?.id);
  }
  return canUpdate.value;
});

const drawerCanDelete = computed(() => {
  const ev = editingEvent.value;
  if (!ev) return false;
  if (ev.is_personal) {
    return Number(ev.created_by_user_id) === Number(crmUser.value?.id);
  }
  return canDelete.value;
});

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
  events: calendarEvents.value,
  dateClick: handleDateClick,
  eventClick: handleEventClick,
  datesSet: handleDatesSet,
}));

let activeRange = { start: "", end: "" };

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
    openEditDrawer(raw);
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
  } catch {
    /* optional */
  }
});
</script>

<template>
  <div class="resources-calendar-page staff-page">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
      <div>
        <h1 class="h4 mb-1">Calendar</h1>
        <p class="text-muted small mb-0">Shared team events and personal reminders.</p>
      </div>
      <button
        v-if="canCreate"
        type="button"
        class="btn btn-primary fw-semibold rounded-3"
        @click="onAddEventClick"
      >
        Add Event
      </button>
    </div>

    <div v-if="categories.length" class="resources-calendar-legend mb-3">
      <span
        v-for="cat in categories"
        :key="cat.value"
        class="resources-calendar-legend__item"
      >
        <span
          class="resources-calendar-legend__dot"
          :style="{ backgroundColor: cat.color }"
          aria-hidden="true"
        />
        {{ cat.label }}
      </span>
    </div>

    <div class="card border-0 shadow-sm resources-calendar-card position-relative">
      <div v-if="loading" class="resources-calendar-card__loading">
        <CrmLoadingSpinner />
      </div>
      <div class="card-body p-3 p-md-4">
        <FullCalendar ref="calendarRef" class="resources-calendar" :options="calendarOptions" />
      </div>
    </div>

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
