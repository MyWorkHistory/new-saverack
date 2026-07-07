export const CLIENT_ACCOUNT_PAUSE_REASONS = [
  { value: "account_past_due", label: "Account Past Due" },
  { value: "admin", label: "Admin" },
  { value: "user_request", label: "User Request" },
];

export function clientAccountPauseReasonLabel(value) {
  const match = CLIENT_ACCOUNT_PAUSE_REASONS.find((opt) => opt.value === value);
  return match?.label ?? null;
}
