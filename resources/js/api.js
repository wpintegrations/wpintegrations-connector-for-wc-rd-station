/**
 * Minimal REST client. Sends the cookie nonce on every request.
 */
const boot = window.wpi_wcrdsBoot || { restUrl: '', nonce: '' }

async function request(path, options = {}) {
  const response = await fetch(`${boot.restUrl}${path}`, {
    credentials: 'same-origin',
    headers: {
      'Content-Type': 'application/json',
      'X-WP-Nonce': boot.nonce
    },
    ...options
  })

  const json = await response.json().catch(() => ({}))
  if (!response.ok || json.success === false) {
    throw new Error(json.message || `Request failed (HTTP ${response.status})`)
  }
  return json.data
}

export const api = {
  get: path => request(path),
  post: (path, body) => request(path, { method: 'POST', body: JSON.stringify(body ?? {}) }),
  put: (path, body) => request(path, { method: 'PUT', body: JSON.stringify(body ?? {}) }),
  del: path => request(path, { method: 'DELETE' })
}

export const bootData = boot
