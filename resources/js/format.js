/**
 * Rough "3 hours" / "12 minutes" for a span the server measured, so the copy
 * never depends on the browser clock agreeing with the site's.
 */
export function formatDuration(seconds) {
  const minutes = Math.floor(seconds / 60)
  if (minutes < 60) {
    return `${Math.max(1, minutes)} minute${minutes === 1 ? '' : 's'}`
  }
  const hours = Math.floor(minutes / 60)
  if (hours < 24) {
    return `${hours} hour${hours === 1 ? '' : 's'}`
  }
  const days = Math.floor(hours / 24)
  return `${days} day${days === 1 ? '' : 's'}`
}

/**
 * The API sends UTC "YYYY-MM-DD HH:MM:SS"; show it in local time.
 */
export function formatDate(value) {
  if (!value) {
    return '—'
  }
  const date = new Date(value.replace(' ', 'T') + 'Z')
  return isNaN(date)
    ? value
    : date.toLocaleString(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
      })
}
