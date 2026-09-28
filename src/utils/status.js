/**
 * The status vocabulary.
 *
 * Colour never carries meaning on its own here: every status pairs a colour with a
 * distinct glyph and a written label, because the people most likely to file a bug about
 * a colour-only interface are colour-blind testers.
 */

export const RESULT_STATUSES = [
  {value: 'pass', label: wp.i18n.__('Pass', 'mandragora-qa-test-manager'), glyph: '✓', tone: 'pass'},
  {value: 'fail', label: wp.i18n.__('Fail', 'mandragora-qa-test-manager'), glyph: '✕', tone: 'fail'},
  {value: 'blocked', label: wp.i18n.__('Blocked', 'mandragora-qa-test-manager'), glyph: '▲', tone: 'blocked'},
  {value: 'skipped', label: wp.i18n.__('Skipped', 'mandragora-qa-test-manager'), glyph: '–', tone: 'skipped'},
  {
    value: 'untested',
    label: wp.i18n._x('Clear', 'reset a result to untested', 'mandragora-qa-test-manager'),
    glyph: '○',
    tone: 'untested'
  }
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
  return value === 'untested' ? wp.i18n.__('Untested', 'mandragora-qa-test-manager') : statusMeta(value).label;
}

export const PRIORITIES = [
  {value: 'critical', label: wp.i18n.__('Critical', 'mandragora-qa-test-manager')},
  {value: 'normal', label: wp.i18n.__('Normal', 'mandragora-qa-test-manager')},
  {value: 'low', label: wp.i18n.__('Low', 'mandragora-qa-test-manager')}
];

export const RUN_STATUSES = [
  {value: 'open', label: wp.i18n._x('Open', 'run status', 'mandragora-qa-test-manager')},
  {value: 'completed', label: wp.i18n.__('Completed', 'mandragora-qa-test-manager')},
  {value: 'abandoned', label: wp.i18n.__('Abandoned', 'mandragora-qa-test-manager')}
];

export const ISSUE_STATUSES = [
  {value: 'open', label: wp.i18n._x('Open', 'issue status', 'mandragora-qa-test-manager')},
  {value: 'resolved', label: wp.i18n.__('Resolved', 'mandragora-qa-test-manager')}
];

/**
 * Translated label for an issue status.
 *
 * @param {string} value Issue status.
 * @returns {string}
 */
export function issueStatusLabel(value) {
  return ISSUE_STATUSES.find((status) => status.value === value)?.label ?? value;
}
