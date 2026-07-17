/** Normalize note/comment author fields across CRM APIs. */
export function noteAuthorFromRecord(record) {
  if (!record || typeof record !== "object") {
    return { name: "Staff", email: "", avatarUrl: "" };
  }

  if (record.user && typeof record.user === "object") {
    return {
      name: record.user.name || "User",
      email: record.user.email || "",
      avatarUrl: record.user.avatar_url || "",
    };
  }

  return {
    name: record.author_name || record.user_name || "Staff",
    email: record.author_email || record.user_email || "",
    avatarUrl: record.author_avatar_url || record.user_avatar_url || "",
  };
}
