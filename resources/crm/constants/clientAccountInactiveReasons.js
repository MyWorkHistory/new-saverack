export const CLIENT_ACCOUNT_INACTIVE_REASONS = [
  { value: "account_closed", label: "Account Closed" },
  { value: "collections", label: "Collections" },
];

export function clientAccountInactiveReasonLabel(value) {
  const match = CLIENT_ACCOUNT_INACTIVE_REASONS.find((opt) => opt.value === value);
  return match?.label ?? null;
}
