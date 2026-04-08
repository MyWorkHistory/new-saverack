import api from "../services/api";

function filenameFromContentDisposition(header) {
  if (!header || typeof header !== "string") return null;
  const star = /filename\*=UTF-8''([^;\n]+)/i.exec(header);
  if (star?.[1]) {
    try {
      return decodeURIComponent(star[1].trim());
    } catch {
      return star[1].trim();
    }
  }
  const basic = /filename="([^"]+)"/i.exec(header);
  if (basic?.[1]) return basic[1].trim();
  const basic2 = /filename=([^;\n]+)/i.exec(header);
  if (basic2?.[1]) return basic2[1].trim().replace(/^["']|["']$/g, "");
  return null;
}

/**
 * GET a CSV export (auth via axios) and trigger a browser download.
 *
 * @param {object} opts
 * @param {string} opts.path - e.g. "/users/export-csv"
 * @param {Record<string, string|number|undefined>} [opts.params]
 * @param {string} opts.filenameBase - fallback filename stem
 * @param {{ success: function(string): void, errorFrom: function(Error, string): void }} opts.toast
 */
export async function downloadListCsv({ path, params = {}, filenameBase, toast }) {
  try {
    const res = await api.get(path, {
      params,
      responseType: "blob",
      headers: { Accept: "text/csv" },
    });
    const blob = new Blob([res.data], {
      type: res.headers["content-type"] || "text/csv;charset=utf-8",
    });
    let name =
      filenameFromContentDisposition(res.headers["content-disposition"]) ||
      `${filenameBase}-${new Date().toISOString().slice(0, 10)}.csv`;
    const url = URL.createObjectURL(blob);
    const a = document.createElement("a");
    a.href = url;
    a.download = name;
    a.click();
    URL.revokeObjectURL(url);
    toast.success("CSV downloaded.");
  } catch (e) {
    const data = e?.response?.data;
    if (data instanceof Blob) {
      try {
        const text = await data.text();
        const json = JSON.parse(text);
        e.response.data = json;
      } catch {
        /* keep blob */
      }
    }
    toast.errorFrom(e, "Could not export CSV.");
    throw e;
  }
}
