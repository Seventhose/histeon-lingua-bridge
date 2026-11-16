// fetch for the HTTP suites: one more try when Node reuses a keep-alive connection that Apache has just closed
// (between two requests the tests run WP-CLI, which can take longer than Apache's keep-alive). Only for that
// socket error, and only for reads (a write is never sent twice): any answer of the server is returned as it is.
export async function fetchRetry(url, options) {
  try {
    return await fetch(url, options);
  } catch (error) {
    const method = String(options?.method ?? 'GET').toUpperCase();
    if (error?.cause?.code !== 'UND_ERR_SOCKET' || (method !== 'GET' && method !== 'HEAD')) throw error;
    return fetch(url, options);
  }
}
