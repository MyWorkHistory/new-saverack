import { crmIsAdmin } from "./crmUser.js";

/** True when the user may edit or delete this calendar event (creator, admin, or CRM owner). */
export function canManageCalendarEvent(user, event) {
  if (!user || !event) return false;
  if (crmIsAdmin(user) || user.is_crm_owner) return true;
  return Number(event.created_by_user_id) === Number(user.id);
}
