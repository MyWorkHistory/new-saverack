/**
 * Open a PDF returned by the API in a new tab (avoids popup blockers after async fetch).
 * @param {import('axios').AxiosInstance} apiClient
 * @param {string} path
 * @param {{ params?: Record<string, unknown>, data?: Record<string, unknown>, method?: 'get'|'post', download?: string }} [options] Pass `download` to save file; omit to open in a new tab.
 */
export async function openApiPdfBlob(apiClient, path, options = {}) {
  const method = String(options.method || "get").toLowerCase() === "post" ? "post" : "get";
  const config = {
    responseType: "blob",
    headers: { Accept: "application/pdf, application/json" },
    validateStatus: () => true,
  };
  if (options.params) {
    config.params = options.params;
  }

  const res =
    method === "post"
      ? await apiClient.post(path, options.data ?? {}, config)
      : await apiClient.get(path, config);

  let blob = res.data instanceof Blob ? res.data : new Blob([res.data], { type: "application/pdf" });
  const contentType = String(res.headers?.["content-type"] || blob.type || "");
  const status = Number(res.status || 0);

  async function messageFromBlob(fallback) {
    const text = await blob.text();
    try {
      const json = JSON.parse(text);
      if (typeof json?.message === "string" && json.message.trim()) {
        return json.message.trim();
      }
    } catch {
      // no-op
    }
    if (text && text.length < 400 && !text.trim().startsWith("%PDF")) {
      return text.trim() || fallback;
    }
    return fallback;
  }

  if (status >= 400 || contentType.includes("application/json") || blob.type.includes("json")) {
    throw new Error(await messageFromBlob(`Could not open PDF (${status || "error"}).`));
  }

  if (blob.size < 64) {
    const text = await blob.text();
    if (text.trim().startsWith("{")) {
      blob = new Blob([text], { type: "application/json" });
      throw new Error(await messageFromBlob("Could not open PDF."));
    }
  }

  if (!blob.type || blob.type === "application/octet-stream") {
    blob = new Blob([blob], { type: "application/pdf" });
  }

  const url = URL.createObjectURL(blob);
  const anchor = document.createElement("a");
  anchor.href = url;
  anchor.rel = "noopener noreferrer";
  if (options.download) {
    anchor.download = options.download;
  } else {
    anchor.target = "_blank";
  }
  document.body.appendChild(anchor);
  anchor.click();
  anchor.remove();
  setTimeout(() => URL.revokeObjectURL(url), 60000);
}
