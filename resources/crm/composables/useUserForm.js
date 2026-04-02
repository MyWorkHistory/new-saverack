import { reactive, ref } from "vue";
import api from "../services/api";
import { useToast } from "./useToast";
import {
  birthdayFromMonthDay,
  parseBirthdayParts,
} from "../utils/formatUserDates";

export function useUserForm() {
  const toast = useToast();
  const loading = ref(false);
  const saving = ref(false);
  const errorMsg = ref("");
  const fieldErrors = ref({});
  const roles = ref([]);

  const pendingAvatarFile = ref(null);
  const profileAvatarUrl = ref("");

  const form = reactive({
    name: "",
    email: "",
    password: "",
    phone: "",
    personal_email: "",
    birthday_month: "",
    birthday_day: "",
    address: "",
    city: "",
    state: "",
    zip: "",
    region: "",
    employee_type: "",
    job_position: "",
    hire_date: "",
    terminate_date: "",
    bio: "",
    status: "pending",
    role_ids: [],
  });

  async function loadRoles() {
    try {
      const { data } = await api.get("/roles");
      roles.value = Array.isArray(data) ? data : [];
    } catch {
      roles.value = [];
    }
  }

  /** Normalize API date strings for `<input type="date">` (YYYY-MM-DD). */
  function formatDateForInput(val) {
    if (val == null || val === "") return "";
    const s = String(val);
    const iso = s.match(/^(\d{4}-\d{2}-\d{2})/);
    return iso ? iso[1] : "";
  }

  /** Hydrate profile-backed fields from `GET /users/:id` → `profile`. */
  function applyProfileToForm(profile) {
    const p = profile && typeof profile === "object" ? profile : {};
    form.phone = p.phone != null && p.phone !== "" ? String(p.phone) : "";
    form.personal_email =
      p.personal_email != null && p.personal_email !== ""
        ? String(p.personal_email)
        : "";
    const bp = parseBirthdayParts(formatDateForInput(p.birthday));
    form.birthday_month = bp.month;
    form.birthday_day = bp.day;
    form.address = p.address != null && p.address !== "" ? String(p.address) : "";
    form.city = p.city != null && p.city !== "" ? String(p.city) : "";
    form.state = p.state != null && p.state !== "" ? String(p.state) : "";
    form.zip = p.zip != null && p.zip !== "" ? String(p.zip) : "";
    form.region = p.region != null && p.region !== "" ? String(p.region) : "";
    form.employee_type =
      p.employee_type != null && p.employee_type !== ""
        ? String(p.employee_type)
        : "";
    form.job_position =
      p.job_position != null && p.job_position !== ""
        ? String(p.job_position)
        : "";
    form.hire_date = formatDateForInput(p.hire_date);
    form.terminate_date = formatDateForInput(p.terminate_date);
    form.bio = p.bio != null && p.bio !== "" ? String(p.bio) : "";
  }

  async function loadUser(userId) {
    if (!userId) return;
    const { data } = await api.get(`/users/${userId}`);
    form.name = data.name || "";
    form.email = data.email || "";
    form.password = "";
    form.status = data.status || "pending";
    form.role_ids = (data.roles || []).map((r) => r.id);
    applyProfileToForm(data.profile);
    profileAvatarUrl.value = data.profile?.avatar_url || "";
  }

  async function uploadAvatarFile(userId, file, options = {}) {
    const { successMessage = "Photo Updated." } = options;
    const fd = new FormData();
    fd.append("avatar", file);
    try {
      const { data } = await api.post(`/users/${userId}/avatar`, fd);
      profileAvatarUrl.value = data.profile?.avatar_url || "";
      applyProfileToForm(data.profile);
      if (successMessage) {
        toast.success(successMessage);
      }
    } catch (e) {
      toast.errorFrom(e, "Could Not Upload Photo.");
      throw e;
    }
  }

  async function deleteAvatarFile(userId) {
    try {
      const { data } = await api.delete(`/users/${userId}/avatar`);
      profileAvatarUrl.value = data.profile?.avatar_url || "";
      applyProfileToForm(data.profile);
      toast.success("Photo Removed.");
    } catch (e) {
      toast.errorFrom(e, "Could Not Remove Photo.");
      throw e;
    }
  }

  function resetForCreate() {
    form.name = "";
    form.email = "";
    form.password = "";
    form.status = "pending";
    form.role_ids = [];
    applyProfileToForm({});
    pendingAvatarFile.value = null;
    profileAvatarUrl.value = "";
    errorMsg.value = "";
    fieldErrors.value = {};
  }

  function toggleRole(id) {
    const n = Number(id);
    const i = form.role_ids.indexOf(n);
    if (i === -1) {
      form.role_ids.push(n);
    } else {
      form.role_ids.splice(i, 1);
    }
  }

  function roleChecked(id) {
    return form.role_ids.includes(Number(id));
  }

  function clearFieldError(key) {
    if (!fieldErrors.value[key]) return;
    const next = { ...fieldErrors.value };
    delete next[key];
    fieldErrors.value = next;
  }

  function firstError(key) {
    const v = fieldErrors.value[key];
    return Array.isArray(v) && v.length ? v[0] : "";
  }

  /**
   * @returns {Promise<boolean>}
   */
  async function submit({ isEdit, userId }) {
    saving.value = true;
    errorMsg.value = "";
    fieldErrors.value = {};
    try {
      const birthdayIso = birthdayFromMonthDay(
        form.birthday_month,
        form.birthday_day,
      );
      const payload = {
        name: form.name.trim(),
        email: form.email.trim(),
        status: form.status,
        role_ids: form.role_ids,
        phone: form.phone?.trim() || null,
        personal_email: form.personal_email?.trim() || null,
        birthday: birthdayIso,
        address: form.address?.trim() || null,
        city: form.city?.trim() || null,
        state: form.state?.trim() || null,
        zip: form.zip?.trim() || null,
        region: form.region?.trim() || null,
        employee_type: form.employee_type?.trim() || null,
        job_position: form.job_position?.trim() || null,
        hire_date: form.hire_date?.trim() || null,
        terminate_date: form.terminate_date?.trim() || null,
        bio: form.bio?.trim() || null,
      };
      if (isEdit && userId) {
        if (form.password.trim()) {
          payload.password = form.password;
        }
        const { data: updated } = await api.put(`/users/${userId}`, payload);
        profileAvatarUrl.value =
          updated.profile?.avatar_url || profileAvatarUrl.value;
        applyProfileToForm(updated.profile);
        toast.success("User updated successfully.");
      } else {
        payload.password = form.password;
        const { data: created } = await api.post("/users", payload);
        if (pendingAvatarFile.value) {
          try {
            await uploadAvatarFile(String(created.id), pendingAvatarFile.value, {
              successMessage: null,
            });
          } catch {
            // Handled inside uploadAvatarFile / interceptors
          }
          pendingAvatarFile.value = null;
        }
        toast.success("User Created Successfully.");
      }
      return true;
    } catch (e) {
      if (e.response?.status === 422 && e.response.data?.errors) {
        fieldErrors.value = e.response.data.errors;
        const first = Object.values(e.response.data.errors)[0];
        const msg = Array.isArray(first) ? first[0] : String(first);
        toast.error(typeof msg === "string" ? msg : "Validation failed.");
      } else {
        const msg =
          e.response?.data?.message ||
          e.response?.data?.error ||
          "Could Not Save User.";
        errorMsg.value =
          typeof msg === "string" ? msg : "Could Not Save User.";
        toast.errorFrom(e, "Could Not Save User.");
      }
      return false;
    } finally {
      saving.value = false;
    }
  }

  return {
    loading,
    saving,
    errorMsg,
    fieldErrors,
    roles,
    form,
    pendingAvatarFile,
    profileAvatarUrl,
    loadRoles,
    loadUser,
    resetForCreate,
    submit,
    uploadAvatarFile,
    deleteAvatarFile,
    toggleRole,
    roleChecked,
    clearFieldError,
    firstError,
  };
}
