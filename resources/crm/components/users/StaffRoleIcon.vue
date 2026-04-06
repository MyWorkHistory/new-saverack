<script setup>
import { computed } from "vue";
import {
  getPrimaryRoleIconMeta,
  getRoleIconMetaForRole,
} from "../../utils/roleIconMeta.js";

const props = defineProps({
  /** Full role list — uses first role */
  roles: { type: Array, default: null },
  /** Single role row */
  role: { type: Object, default: null },
});

const meta = computed(() => {
  if (props.role) {
    if (!props.role || (!props.role.name && !props.role.label)) {
      return { kind: "none", wrap: "staff-role-icon--none" };
    }
    return getRoleIconMetaForRole(props.role);
  }
  return getPrimaryRoleIconMeta(props.roles);
});

const ICON_SIZE = 15;
</script>

<template>
  <span
    class="staff-role-icon flex-shrink-0"
    :class="meta.wrap"
    aria-hidden="true"
  >
    <!-- Maintainer: person outline (green in theme) -->
    <svg
      v-if="meta.kind === 'maintainer'"
      :width="ICON_SIZE"
      :height="ICON_SIZE"
      fill="none"
      viewBox="0 0 24 24"
      stroke="currentColor"
      stroke-width="1.5"
      stroke-linecap="round"
      stroke-linejoin="round"
    >
      <path
        d="M16 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2M12 11a4 4 0 100-8 4 4 0 000 8z"
      />
    </svg>

    <!-- Subscriber: crown outline (purple) -->
    <svg
      v-else-if="meta.kind === 'subscriber'"
      :width="ICON_SIZE"
      :height="ICON_SIZE"
      fill="none"
      viewBox="0 0 24 24"
      stroke="currentColor"
      stroke-width="1.5"
      stroke-linecap="round"
      stroke-linejoin="round"
    >
      <path
        d="M2 16l2-10 5.5 4L12 4l2.5 6L20 6l2 10H2z"
      />
      <path d="M6 16h12" />
    </svg>

    <!-- Editor: pie / analytics segment (teal) -->
    <svg
      v-else-if="meta.kind === 'editor'"
      :width="ICON_SIZE"
      :height="ICON_SIZE"
      fill="none"
      viewBox="0 0 24 24"
      stroke="currentColor"
      stroke-width="1.5"
      stroke-linecap="round"
      stroke-linejoin="round"
    >
      <path d="M21 12a9 9 0 00-9-9v9h9z" />
    </svg>

    <!-- Author: compose / pencil (orange) -->
    <svg
      v-else-if="meta.kind === 'author'"
      :width="ICON_SIZE"
      :height="ICON_SIZE"
      fill="none"
      viewBox="0 0 24 24"
      stroke="currentColor"
      stroke-width="1.5"
      stroke-linecap="round"
      stroke-linejoin="round"
    >
      <path
        d="M11 4H4v14a2 2 0 002 2h12a2 2 0 002-2v-7"
      />
      <path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z" />
    </svg>

    <!-- Admin: monitor (red) -->
    <svg
      v-else-if="meta.kind === 'admin'"
      :width="ICON_SIZE"
      :height="ICON_SIZE"
      fill="none"
      viewBox="0 0 24 24"
      stroke="currentColor"
      stroke-width="1.5"
      stroke-linecap="round"
      stroke-linejoin="round"
    >
      <rect x="2" y="5" width="20" height="12" rx="1.5" />
      <path d="M8 21h8M12 17v4" />
    </svg>

    <!-- No role -->
    <svg
      v-else
      :width="ICON_SIZE"
      :height="ICON_SIZE"
      fill="none"
      viewBox="0 0 24 24"
      stroke="currentColor"
      stroke-width="1.5"
      stroke-linecap="round"
    >
      <path d="M8 12h8" />
    </svg>
  </span>
</template>
