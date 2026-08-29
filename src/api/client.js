/**
 * The one wrapper around fetch for the QA Runner REST API.
 *
 * Everything goes through here so nonce handling, error shape and the expired-session
 * banner live in exactly one place.
 */

const bootstrap = window.qaRunner ?? {};

/**
 * Error thrown for any non-2xx response, carrying the WordPress error payload.
 */
export class ApiError extends Error {
  /**
   * @param {string} message Human-readable message from WordPress.
   * @param {number} status HTTP status.
   * @param {string} code WordPress error code.
   * @param {*} data Additional payload.
   */
  constructor(message, status, code, data) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.code = code;
    this.data = data;
  }

  /**
   * Whether the run this write targeted is no longer open.
   *
   * @returns {boolean}
   */
  get isRunClosed() {
    return this.status === 409;
  }

  /**
   * Whether the WordPress nonce has expired.
   *
   * Nonces die after 24 hours and testers leave tabs open overnight, so this is a normal
   * condition rather than an exceptional one.
   *
   * @returns {boolean}
   */
  get isExpiredSession() {
    return this.status === 403 && this.code === 'rest_cookie_invalid_nonce';
  }
}

/**
 * Listeners notified once when the session expires.
 *
 * @type {Set<Function>}
 */
const expiryListeners = new Set();

/**
 * Registers a callback for session expiry.
 *
 * @param {Function} listener Called with no arguments.
 * @returns {void}
 */
export function onSessionExpired(listener) {
  expiryListeners.add(listener);
}

/**
 * Builds a query string from a plain object, dropping empty values.
 *
 * @param {Object} params Query parameters.
 * @returns {string} Leading '?' included, or an empty string.
 */
function toQuery(params) {
  const search = new URLSearchParams();

  Object.entries(params ?? {}).forEach(([key, value]) => {
    if (value === undefined || value === null || value === '') {
      return;
    }

    if (Array.isArray(value)) {
      value.forEach((item) => search.append(`${key}[]`, String(item)));
      return;
    }

    search.append(key, String(value));
  });

  const query = search.toString();

  return query ? `?${query}` : '';
}

/**
 * Performs one API request.
 *
 * @param {string} path Path relative to the REST namespace, without a leading slash.
 * @param {Object} [options] Request options.
 * @param {string} [options.method] HTTP method.
 * @param {Object} [options.body] JSON body.
 * @param {Object} [options.params] Query parameters.
 * @returns {Promise<*>} Parsed JSON response.
 * @throws {ApiError} On any non-2xx response.
 */
async function request(path, {method = 'GET', body, params} = {}) {
  const url = `${bootstrap.root ?? ''}${path}${toQuery(params)}`;

  const init = {
    method,
    credentials: 'same-origin',
    headers: {
      'Content-Type': 'application/json',
      'X-WP-Nonce': bootstrap.nonce ?? ''
    }
  };

  if (body !== undefined) {
    init.body = JSON.stringify(body);
  }

  let response;

  try {
    response = await fetch(url, init);
  } catch {
    throw new ApiError(
      'Could not reach the server. Check your connection and try again.',
      0,
      'network_error',
      null
    );
  }

  const text = await response.text();
  let payload = null;

  if (text) {
    try {
      payload = JSON.parse(text);
    } catch {
      payload = null;
    }
  }

  if (!response.ok) {
    const error = new ApiError(
      payload?.message ?? 'Something went wrong. Please try again.',
      response.status,
      payload?.code ?? 'unknown_error',
      payload?.data ?? null
    );

    if (error.isExpiredSession) {
      // Surfaced as a persistent banner, never an auto-reload: a tester may be mid-edit.
      expiryListeners.forEach((listener) => listener(error));
    }

    throw error;
  }

  return payload;
}

export const api = {
  ping: () => request('ping'),

  suites: {
    list: () => request('suites'),
    create: (data) => request('suites', {method: 'POST', body: data}),
    update: (id, data) => request(`suites/${id}`, {method: 'PUT', body: data}),
    remove: (id) => request(`suites/${id}`, {method: 'DELETE'})
  },

  cases: {
    list: (params) => request('cases', {params}),
    get: (id) => request(`cases/${id}`),
    create: (data) => request('cases', {method: 'POST', body: data}),
    update: (id, data) => request(`cases/${id}`, {method: 'PUT', body: data}),
    archive: (id) => request(`cases/${id}`, {method: 'DELETE'}),
    issues: (id, status) => request(`cases/${id}/issues`, {params: {status}}),
    raiseIssue: (id, data) => request(`cases/${id}/issues`, {method: 'POST', body: data})
  },

  runs: {
    list: (status) => request('runs', {params: {status}}),
    get: (id) => request(`runs/${id}`),
    create: (data) => request('runs', {method: 'POST', body: data}),
    update: (id, data) => request(`runs/${id}`, {method: 'PUT', body: data}),
    results: (id) => request(`runs/${id}/results`),
    previousStatus: (id) => request(`runs/${id}/previous-status`),
    addCases: (id, caseIds) =>
      request(`runs/${id}/cases`, {method: 'POST', body: {case_ids: caseIds}}),
    removeCase: (id, caseId) => request(`runs/${id}/cases/${caseId}`, {method: 'DELETE'}),
    addAssignees: (id, userIds) =>
      request(`runs/${id}/assignees`, {method: 'POST', body: {assignee_ids: userIds}}),
    removeAssignee: (id, userId) => request(`runs/${id}/assignees/${userId}`, {method: 'DELETE'}),
    clone: (id, data) => request(`runs/${id}/clone`, {method: 'POST', body: data ?? {}})
  },

  results: {
    setStatus: (id, status) => request(`results/${id}`, {method: 'PUT', body: {status}}),
    lock: (id) => request(`results/${id}/lock`, {method: 'PUT'}),
    unlock: (id) => request(`results/${id}/lock`, {method: 'DELETE'}),
    comments: (id) => request(`results/${id}/comments`),
    addComment: (id, content) =>
      request(`results/${id}/comments`, {method: 'POST', body: {content}})
  },

  comments: {
    update: (id, content) => request(`comments/${id}`, {method: 'PUT', body: {content}}),
    remove: (id) => request(`comments/${id}`, {method: 'DELETE'})
  },

  issues: {
    update: (id, data) => request(`issues/${id}`, {method: 'PUT', body: data})
  },

  users: {
    list: () => request('users')
  },

  settings: {
    get: () => request('settings'),
    update: (data) => request('settings', {method: 'PUT', body: data})
  }
};

export {bootstrap};
