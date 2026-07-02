export const ONBOARDING_ACTIVATION_BLOCKED_MESSAGE =
  "Please complete onboarding to active account.";

export function isOnboardingReadyForActivation(onboardingPayload) {
  const tasks = Array.isArray(onboardingPayload?.tasks) ? onboardingPayload.tasks : [];
  if (!tasks.length) {
    return false;
  }

  return tasks.every((task) => task?.status === "completed" && task?.verified === true);
}

export async function checkOnboardingReadyForActivation(api, clientAccountId) {
  const id = Number(clientAccountId || 0);
  if (!id) {
    return false;
  }

  try {
    const { data } = await api.get(`/client-accounts/${id}/onboarding`);
    return isOnboardingReadyForActivation(data);
  } catch {
    return false;
  }
}
