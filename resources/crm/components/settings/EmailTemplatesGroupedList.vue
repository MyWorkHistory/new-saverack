<script setup>
import { computed, reactive } from "vue";
import CrmIconRowActions from "../common/CrmIconRowActions.vue";
import {
  EMAIL_TEMPLATE_CATEGORIES,
  emailTemplateCategoryLabel,
  emailTemplateCategoryMeta,
} from "../../constants/emailTemplates.js";
import { formatDateTimeUs } from "../../utils/formatUserDates.js";
import { useToast } from "../../composables/useToast.js";

const props = defineProps({
  groups: { type: Array, default: () => [] },
  collapsed: { type: Object, default: () => ({}) },
  canManage: { type: Boolean, default: false },
  manageOpenId: { type: [Number, String], default: null },
  manageMenuRect: { type: Object, default: () => ({ top: 0, left: 0 }) },
  highlightCategory: { type: String, default: "" },
  /** When true, rows expand to show template body. */
  expandable: { type: Boolean, default: false },
  /**
   * Per-template usage keyed by id:
   * { [id]: { last_sent_at: string|null } }
   */
  usages: { type: Object, default: () => ({}) },
  readOnlyActions: { type: Boolean, default: false },
  /** Lead detail: show Email column + Email button. */
  showEmailAction: { type: Boolean, default: false },
  /** Template id currently sending. */
  emailBusyId: { type: [Number, String], default: null },
});

const emit = defineEmits(["toggle-group", "open-manage", "edit", "delete", "email"]);

const toast = useToast();
const expandedRows = reactive({});
const copyBusyId = reactive({});

const colCount = computed(() => (props.showEmailAction ? 4 : 3));

const displayGroups = computed(() => {
  if (Array.isArray(props.groups) && props.groups.length) {
    return props.groups;
  }
  return EMAIL_TEMPLATE_CATEGORIES.map((category) => ({
    category,
    category_label: emailTemplateCategoryLabel(category),
    count: 0,
    templates: [],
  }));
});

function isCollapsed(category) {
  return !!props.collapsed[category];
}

function categoryIconPath(icon) {
  switch (icon) {
    case "star":
      return "M11.48 3.499a.562.562 0 011.04 0l2.125 5.111a.563.563 0 00.475.345l5.518.442c.499.04.701.663.321.988l-4.204 3.602a.563.563 0 00-.182.557l1.285 5.385a.562.562 0 01-.84.61l-4.725-2.885a.563.563 0 00-.586 0L6.982 20.54a.562.562 0 01-.84-.61l1.285-5.386a.562.562 0 00-.182-.557l-4.204-3.602a.563.563 0 01.321-.988l5.518-.442a.563.563 0 00.475-.345L11.48 3.5z";
    case "calendar":
      return "M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5";
    case "clock":
      return "M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z";
    case "mute":
      return "M17.25 9.75L19.5 12m0 0l2.25 2.25M19.5 12l2.25-2.25M19.5 12l-2.25 2.25m-10.5-6v6m4.5-6v6m-9 0h16.5";
    case "x":
      return "M6 18L18 6M6 6l12 12";
    case "slash":
      return "M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636";
    case "check":
      return "M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z";
    case "phone":
    default:
      return "M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 01-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 00-1.091-.852H4.5A2.25 2.25 0 002.25 4.5v2.25z";
  }
}

const manageMenuRow = computed(() => {
  const id = Number(props.manageOpenId || 0);
  if (!id) return null;
  for (const group of displayGroups.value) {
    const found = (group.templates || []).find((t) => Number(t.id) === id);
    if (found) return found;
  }
  return null;
});

function usageFor(row) {
  const id = Number(row?.id || 0);
  if (!id) return null;
  return props.usages?.[id] || props.usages?.[String(id)] || null;
}

function lastSentFor(row) {
  const usage = usageFor(row);
  if (usage?.last_sent_at) return formatDateTimeUs(usage.last_sent_at);
  return "—";
}

function subjectFor(row) {
  return String(row?.subject || row?.description || "").trim() || "—";
}

function isRowExpanded(id) {
  return !!expandedRows[id];
}

function toggleRow(id) {
  if (!props.expandable) return;
  expandedRows[id] = !expandedRows[id];
}

function isEmailBusy(row) {
  return Number(props.emailBusyId || 0) === Number(row?.id || 0);
}

async function copyTemplateBody(row) {
  const body = String(row?.body || "");
  const id = Number(row?.id || 0);
  if (!body || copyBusyId[id]) return;
  copyBusyId[id] = true;
  try {
    const plain = body.replace(/<[^>]+>/g, " ").replace(/&nbsp;/gi, " ").replace(/\s+/g, " ").trim();
    await navigator.clipboard.writeText(plain || body);
    toast.success("Text copied.");
  } catch {
    toast.error("Could not copy text.");
  } finally {
    copyBusyId[id] = false;
  }
}
</script>

<template>
  <div class="email-templates-list staff-table-card staff-datatable-card staff-datatable-card--white">
    <div class="table-responsive staff-table-wrap">
      <table class="table align-middle mb-0 staff-data-table email-templates-table">
        <thead class="table-light staff-table-head">
          <tr>
            <th class="staff-table-head__th" scope="col">Template Name</th>
            <th class="staff-table-head__th" scope="col">Last Sent</th>
            <th v-if="showEmailAction" class="staff-table-head__th text-center" scope="col">Email</th>
            <th class="staff-table-head__th staff-actions-col text-center" scope="col">Actions</th>
          </tr>
        </thead>
        <tbody>
          <template v-for="group in displayGroups" :key="group.category">
            <tr
              class="email-templates-list__group-row"
              :class="{
                'email-templates-list__group-row--highlight':
                  highlightCategory && highlightCategory === group.category,
              }"
            >
              <td :colspan="colCount" class="p-0">
                <button
                  type="button"
                  class="email-templates-list__group-btn w-100 text-start"
                  @click="emit('toggle-group', group.category)"
                >
                  <span
                    class="email-templates-list__group-icon"
                    :style="{
                      background: emailTemplateCategoryMeta(group.category).softBg,
                      color: emailTemplateCategoryMeta(group.category).accent,
                    }"
                    aria-hidden="true"
                  >
                    <svg
                      width="16"
                      height="16"
                      fill="none"
                      stroke="currentColor"
                      stroke-width="1.75"
                      viewBox="0 0 24 24"
                    >
                      <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        :d="categoryIconPath(emailTemplateCategoryMeta(group.category).icon)"
                      />
                    </svg>
                  </span>
                  <span class="email-templates-list__group-label">
                    {{ group.category_label || emailTemplateCategoryLabel(group.category) }}
                    ({{ Number(group.count ?? group.templates?.length ?? 0) }})
                  </span>
                  <svg
                    class="email-templates-list__chevron ms-auto"
                    :class="{ 'is-collapsed': isCollapsed(group.category) }"
                    width="18"
                    height="18"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                    viewBox="0 0 24 24"
                    aria-hidden="true"
                  >
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
                  </svg>
                </button>
              </td>
            </tr>
            <template v-if="!isCollapsed(group.category)">
              <tr v-if="!(group.templates || []).length">
                <td :colspan="colCount" class="text-secondary small py-3 px-4">
                  No templates in this category yet.
                </td>
              </tr>
              <template v-for="row in group.templates || []" :key="row.id">
                <tr
                  :class="{ 'email-templates-list__row--clickable': expandable }"
                  @click="expandable ? toggleRow(row.id) : undefined"
                >
                  <td>
                    <div class="d-flex align-items-start gap-2 min-w-0">
                      <span class="email-templates-list__row-icon flex-shrink-0" aria-hidden="true">
                        <svg
                          width="16"
                          height="16"
                          fill="none"
                          stroke="currentColor"
                          stroke-width="1.75"
                          viewBox="0 0 24 24"
                        >
                          <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75"
                          />
                        </svg>
                      </span>
                      <div class="min-w-0">
                        <div class="fw-semibold text-body text-break d-flex align-items-center gap-1">
                          <span>{{ row.name }}</span>
                          <svg
                            v-if="expandable"
                            class="email-templates-list__row-chevron flex-shrink-0"
                            :class="{ 'is-expanded': isRowExpanded(row.id) }"
                            width="14"
                            height="14"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                            viewBox="0 0 24 24"
                            aria-hidden="true"
                          >
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
                          </svg>
                        </div>
                        <div class="small text-secondary text-break">
                          {{ subjectFor(row) }}
                        </div>
                      </div>
                    </div>
                  </td>
                  <td class="text-secondary small">{{ lastSentFor(row) }}</td>
                  <td v-if="showEmailAction" class="text-center" @click.stop>
                    <button
                      type="button"
                      class="btn btn-sm btn-primary staff-page-primary"
                      :disabled="isEmailBusy(row) || !!emailBusyId"
                      @click="emit('email', row)"
                    >
                      {{ isEmailBusy(row) ? "Sending…" : "Email" }}
                    </button>
                  </td>
                  <td class="staff-actions-cell text-center" @click.stop>
                    <div
                      class="staff-actions-inner justify-content-center gap-2 flex-wrap"
                      :data-email-template-actions="canManage && !readOnlyActions ? '' : undefined"
                    >
                      <button
                        v-if="row.body"
                        type="button"
                        class="btn btn-sm btn-outline-secondary"
                        title="Copy Text"
                        :disabled="!!copyBusyId[row.id]"
                        @click="copyTemplateBody(row)"
                      >
                        Copy Text
                      </button>
                      <button
                        v-if="canManage && !readOnlyActions"
                        type="button"
                        class="staff-action-btn staff-action-btn--more"
                        :class="{ 'is-open': manageOpenId === row.id }"
                        :aria-expanded="manageOpenId === row.id"
                        aria-haspopup="true"
                        aria-label="Row actions"
                        @click="(e) => emit('open-manage', row, e)"
                      >
                        <CrmIconRowActions variant="horizontal" />
                      </button>
                      <span
                        v-if="!row.body && !(canManage && !readOnlyActions)"
                        class="text-secondary"
                      >—</span>
                    </div>
                  </td>
                </tr>
                <tr v-if="expandable && isRowExpanded(row.id)">
                  <td :colspan="colCount" class="email-templates-list__body-cell">
                    <div class="d-flex justify-content-between align-items-start gap-3 mb-2">
                      <span class="small text-secondary">Template body</span>
                      <button
                        v-if="row.body"
                        type="button"
                        class="btn btn-sm btn-outline-secondary flex-shrink-0"
                        :disabled="!!copyBusyId[row.id]"
                        @click="copyTemplateBody(row)"
                      >
                        Copy Text
                      </button>
                    </div>
                    <div
                      v-if="row.body"
                      class="email-templates-list__body email-templates-list__body--html mb-0"
                      v-html="row.body"
                    />
                    <pre v-else class="email-templates-list__body mb-0">—</pre>
                  </td>
                </tr>
              </template>
            </template>
          </template>
        </tbody>
      </table>
    </div>

    <Teleport to="body">
      <div
        v-if="canManage && manageMenuRow"
        data-email-template-actions
        class="staff-row-menu fixed z-[300] overflow-hidden"
        role="menu"
        :style="{
          top: `${manageMenuRect.top}px`,
          left: `${manageMenuRect.left}px`,
        }"
        @click.stop
      >
        <button
          type="button"
          class="staff-row-menu__item"
          role="menuitem"
          @click="emit('edit', manageMenuRow)"
        >
          Edit
        </button>
        <button
          type="button"
          class="staff-row-menu__item staff-row-menu__item--danger"
          role="menuitem"
          @click="emit('delete', manageMenuRow)"
        >
          Delete
        </button>
      </div>
    </Teleport>
  </div>
</template>

<style scoped>
.email-templates-list__group-row td {
  background: var(--bs-tertiary-bg, #f8f9fa);
}
.email-templates-list__group-row--highlight td {
  background: #eef4ff;
}
.email-templates-list__group-btn {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 0.75rem 1rem;
  border: 0;
  background: transparent;
  font-weight: 600;
}
.email-templates-list__group-icon {
  width: 2rem;
  height: 2rem;
  border-radius: 0.5rem;
  display: inline-flex;
  align-items: center;
  justify-content: center;
}
.email-templates-list__chevron {
  transition: transform 0.15s ease;
}
.email-templates-list__chevron.is-collapsed {
  transform: rotate(-90deg);
}
.email-templates-list__row-icon {
  margin-top: 0.15rem;
  color: var(--bs-secondary-color);
}
.email-templates-list__row--clickable {
  cursor: pointer;
}
.email-templates-list__row-chevron {
  transition: transform 0.15s ease;
}
.email-templates-list__row-chevron.is-expanded {
  transform: rotate(180deg);
}
.email-templates-list__body-cell {
  background: var(--bs-tertiary-bg, #f8f9fa);
  padding: 1rem 1.25rem;
}
.email-templates-list__body {
  white-space: pre-wrap;
  font-size: 0.875rem;
}
.email-templates-list__body--html {
  white-space: normal;
  line-height: 1.55;
}
.email-templates-list__body--html :deep(a) {
  color: var(--bs-primary);
}
</style>
