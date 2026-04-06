import { computed, ref } from "vue";

const isExpanded = ref(true);
const isMobileOpen = ref(false);

export function useCrmSidebar() {
  function toggleSidebar() {
    if (typeof window !== "undefined" && window.innerWidth >= 992) {
      isExpanded.value = !isExpanded.value;
    } else {
      isMobileOpen.value = !isMobileOpen.value;
    }
  }

  function toggleMobileSidebar() {
    isMobileOpen.value = !isMobileOpen.value;
  }

  function closeMobile() {
    isMobileOpen.value = false;
  }

  const sidebarClass = computed(() => {
    const parts = ["crm-vertical-nav", "vx-sidebar--floating"];
    if (!isExpanded.value) {
      parts.push("crm-vertical-nav--collapsed");
    }
    if (isMobileOpen.value) {
      parts.push("crm-vertical-nav--mobile-open");
    }
    return parts.join(" ");
  });

  const mainWrapClass = computed(() => {
    const parts = ["crm-main-wrap", "vx-main--floating", "d-flex", "flex-column", "min-vh-100"];
    if (!isExpanded.value) {
      parts.push("crm-main-wrap--collapsed");
    }
    return parts.join(" ");
  });

  return {
    isExpanded,
    isMobileOpen,
    toggleSidebar,
    toggleMobileSidebar,
    closeMobile,
    sidebarClass,
    mainWrapClass,
  };
}
