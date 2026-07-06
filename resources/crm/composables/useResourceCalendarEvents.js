import { ref } from "vue";
import api from "../services/api";
import { errorMessage } from "../utils/apiError";
import { useToast } from "./useToast";

function addOneDay(dateStr) {
  const d = new Date(`${dateStr}T00:00:00`);
  d.setDate(d.getDate() + 1);
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, "0");
  const day = String(d.getDate()).padStart(2, "0");
  return `${y}-${m}-${day}`;
}

export function toFullCalendarEvent(event) {
  return {
    id: String(event.id),
    title: event.title,
    start: event.start_date,
    end: addOneDay(event.end_date),
    allDay: true,
    backgroundColor: event.category_color,
    borderColor: event.category_color,
    extendedProps: { raw: event },
  };
}

export function useResourceCalendarEvents() {
  const toast = useToast();
  const loading = ref(false);
  const saving = ref(false);
  const deleting = ref(false);
  const events = ref([]);
  const categories = ref([]);

  async function loadMeta() {
    const { data } = await api.get("/resources/calendar-events/meta");
    categories.value = Array.isArray(data?.categories) ? data.categories : [];
    return categories.value;
  }

  async function loadRange(start, end) {
    loading.value = true;
    try {
      const { data } = await api.get("/resources/calendar-events", {
        params: { start, end },
      });
      events.value = Array.isArray(data?.data) ? data.data : [];
      return events.value;
    } catch (e) {
      toast.errorFrom(e, "Could not load calendar events.");
      throw e;
    } finally {
      loading.value = false;
    }
  }

  async function loadUpcoming(limit = 4) {
    const { data } = await api.get("/resources/calendar-events", {
      params: { upcoming: 1, limit },
    });
    return Array.isArray(data?.data) ? data.data : [];
  }

  async function createEvent(payload) {
    saving.value = true;
    try {
      const { data } = await api.post("/resources/calendar-events", payload);
      toast.success("Event saved.");
      return data;
    } catch (e) {
      toast.errorFrom(e, "Could not save event.");
      throw e;
    } finally {
      saving.value = false;
    }
  }

  async function updateEvent(id, payload) {
    saving.value = true;
    try {
      const { data } = await api.patch(`/resources/calendar-events/${id}`, payload);
      toast.success("Event updated.");
      return data;
    } catch (e) {
      toast.errorFrom(e, "Could not update event.");
      throw e;
    } finally {
      saving.value = false;
    }
  }

  async function deleteEvent(id) {
    deleting.value = true;
    try {
      await api.delete(`/resources/calendar-events/${id}`);
      toast.success("Event deleted.");
    } catch (e) {
      toast.errorFrom(e, "Could not delete event.");
      throw e;
    } finally {
      deleting.value = false;
    }
  }

  return {
    loading,
    saving,
    deleting,
    events,
    categories,
    loadMeta,
    loadRange,
    loadUpcoming,
    createEvent,
    updateEvent,
    deleteEvent,
    errorMessage,
  };
}
