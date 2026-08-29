/**
 * The status vocabulary.
 *
 * Colour never carries meaning on its own here: every status pairs a colour with a
 * distinct glyph and a written label, because the people most likely to file a bug about
 * a colour-only interface are colour-blind testers.
 */

export const RESULT_STATUSES = [
  {value: 'pass', label: 'Pass', glyph: '✓', tone: 'pass'},
  {value: 'fail', label: 'Fail', glyph: '✕', tone: 'fail'},
  {value: 'blocked', label: 'Blocked', glyph: '▲', tone: 'blocked'},
  {value: 'skipped', label: 'Skipped', glyph: '–', tone: 'skipped'},
  {value: 'untested', label: 'Clear', glyph: '○', tone: 'untested'}
];

const BY_VALUE = Object.fromEntries(RESULT_STATUSES.map((status) => [status.value, status]));

/**
 * Looks up a status descriptor.
 *
 * @param {string} value Status value.
 * @returns {{value: string, label: string, glyph: string, tone: string}}
 */
export function statusMeta(value) {
  return BY_VALUE[value] ?? BY_VALUE.untested;
}

/**
 * The label shown when reporting an existing result, where 'untested' reads as untested
 * rather than as the "Clear" action on the status control.
 *
 * @param {string} value Status value.
 * @returns {string}
 */
export function statusLabel(value) {
  return value === 'untested' ? 'Untested' : statusMeta(value).label;
}

export const PRIORITIES = [
  {value: 'critical', label: 'Critical'},
  {value: 'normal', label: 'Normal'},
  {value: 'low', label: 'Low'}
];

export const RUN_STATUSES = [
  {value: 'open', label: 'Open'},
  {value: 'completed', label: 'Completed'},
  {value: 'abandoned', label: 'Abandoned'}
];
