/**
 * Display formatting. The API always sends ISO 8601 in UTC; formatting for the reader
 * happens here and nowhere else.
 */

const RELATIVE = new Intl.RelativeTimeFormat(undefined, {numeric: 'auto'});

const UNITS = [
  ['year', 31536000],
  ['month', 2592000],
  ['week', 604800],
  ['day', 86400],
  ['hour', 3600],
  ['minute', 60]
];

/**
 * Formats an ISO timestamp as "3 hours ago".
 *
 * @param {string|null} iso ISO 8601 timestamp.
 * @returns {string} Empty string when there is no timestamp.
 */
export function relativeTime(iso) {
  if (!iso) {
    return '';
  }

  const seconds = (Date.parse(iso) - Date.now()) / 1000;
  const magnitude = Math.abs(seconds);

  if (magnitude < 60) {
    return 'just now';
  }

  const [unit, size] = UNITS.find(([, unitSize]) => magnitude >= unitSize) ?? ['minute', 60];

  return RELATIVE.format(Math.round(seconds / size), unit);
}

/**
 * Formats an ISO timestamp as an absolute local date and time, for tooltips.
 *
 * @param {string|null} iso ISO 8601 timestamp.
 * @returns {string}
 */
export function absoluteTime(iso) {
  if (!iso) {
    return '';
  }

  return new Date(iso).toLocaleString(undefined, {
    dateStyle: 'medium',
    timeStyle: 'short'
  });
}

/**
 * Formats an ISO timestamp as a date alone.
 *
 * @param {string|null} iso ISO 8601 timestamp.
 * @returns {string}
 */
export function shortDate(iso) {
  if (!iso) {
    return '';
  }

  return new Date(iso).toLocaleDateString(undefined, {dateStyle: 'medium'});
}

/**
 * Pluralises a countable noun.
 *
 * @param {number} count Quantity.
 * @param {string} singular Singular noun.
 * @param {string} [plural] Plural noun, defaulting to singular + 's'.
 * @returns {string}
 */
export function plural(count, singular, plural_) {
  return count === 1 ? singular : (plural_ ?? `${singular}s`);
}
