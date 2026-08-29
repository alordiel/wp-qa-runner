/**
 * Runs and the working result list.
 *
 * The result list lives here rather than in a view so the 15-second poller and the case
 * detail view share one source of truth.
 */

import {defineStore} from 'pinia';
import {computed, ref} from 'vue';

import {api} from '../api/client.js';

const POLL_INTERVAL = 15000;

export const useRunStore = defineStore('runs', () => {
  const runs = ref([]);
  const run = ref(null);
  const results = ref([]);
  const previousStatus = ref({});
  const loadingResults = ref(false);

  /**
   * Result identifiers with a status write in flight. The poller leaves these alone so a
   * slow response can never overwrite what the tester just chose.
   *
   * @type {import('vue').Ref<Set<number>>}
   */
  const pending = ref(new Set());

  let pollTimer = null;
  let visibilityHandler = null;

  const resultsByCaseId = computed(() =>
    Object.fromEntries(results.value.map((result) => [result.case.id, result]))
  );

  const orderedCaseIds = computed(() => results.value.map((result) => result.case.id));

  const counts = computed(() => run.value?.counts ?? {});

  /**
   * Loads the run list.
   *
   * @param {string} [status] 'open', 'completed' or '' for all.
   * @returns {Promise<Array>}
   */
  async function loadRuns(status = 'open') {
    runs.value = await api.runs.list(status || undefined);

    return runs.value;
  }

  /**
   * Loads one run with its result list.
   *
   * @param {number} id Run identifier.
   * @returns {Promise<void>}
   */
  async function loadRun(id) {
    loadingResults.value = true;

    try {
      const payload = await api.runs.results(id);

      run.value = payload.run;
      results.value = payload.results;
    } finally {
      loadingResults.value = false;
    }
  }

  /**
   * Loads the previous tested status for every case in the run.
   *
   * @param {number} id Run identifier.
   * @returns {Promise<void>}
   */
  async function loadPreviousStatus(id) {
    previousStatus.value = (await api.runs.previousStatus(id)) ?? {};
  }

  /**
   * Merges a freshly polled result list into local state.
   *
   * Merging rather than replacing keeps optimistic writes and in-flight edits intact.
   *
   * @param {Array} incoming Result rows from the server.
   * @returns {void}
   */
  function mergeResults(incoming) {
    const byId = new Map(results.value.map((result) => [result.id, result]));

    results.value = incoming.map((fresh) => {
      const current = byId.get(fresh.id);

      if (!current) {
        return fresh;
      }

      if (pending.value.has(fresh.id)) {
        // Keep the optimistic status and attribution; take everything else.
        return {
          ...fresh,
          status: current.status,
          tested_by: current.tested_by,
          tested_at: current.tested_at
        };
      }

      return fresh;
    });
  }

  /**
   * Sets a result status optimistically, rolling back if the server refuses.
   *
   * @param {number} resultId Result identifier.
   * @param {string} status New status.
   * @returns {Promise<Object>} The server's version of the result.
   */
  async function setStatus(resultId, status) {
    const index = results.value.findIndex((result) => result.id === resultId);

    if (index === -1) {
      return api.results.setStatus(resultId, status);
    }

    const snapshot = {...results.value[index]};
    const me = window.qaRunner?.currentUser ?? null;

    results.value[index] = {
      ...snapshot,
      status,
      tested_by: status === 'untested' ? null : {id: me?.id ?? 0, name: me?.name ?? ''},
      tested_at: status === 'untested' ? null : new Date().toISOString()
    };

    applyCountDelta(snapshot.status, status);
    pending.value.add(resultId);

    try {
      const fresh = await api.results.setStatus(resultId, status);

      results.value[index] = fresh;

      return fresh;
    } catch (error) {
      results.value[index] = snapshot;
      applyCountDelta(status, snapshot.status);

      throw error;
    } finally {
      pending.value.delete(resultId);
    }
  }

  /**
   * Keeps the header progress bar in step with an optimistic status change.
   *
   * @param {string} from Previous status.
   * @param {string} to New status.
   * @returns {void}
   */
  function applyCountDelta(from, to) {
    if (!run.value?.counts || from === to) {
      return;
    }

    const next = {...run.value.counts};

    next[from] = Math.max(0, (next[from] ?? 0) - 1);
    next[to] = (next[to] ?? 0) + 1;

    run.value = {...run.value, counts: next};
  }

  /**
   * Replaces one result row from a server response.
   *
   * @param {Object} fresh Result row.
   * @returns {void}
   */
  function replaceResult(fresh) {
    results.value = results.value.map((result) => (result.id === fresh.id ? fresh : result));
  }

  /**
   * Starts polling the run's result list.
   *
   * Polling pauses while the tab is hidden: a background tab does not need fresh locks,
   * and a team of five does not need the request volume.
   *
   * @param {number} id Run identifier.
   * @returns {void}
   */
  function startPolling(id) {
    stopPolling();

    const tick = async () => {
      if (document.hidden) {
        return;
      }

      try {
        const payload = await api.runs.results(id);

        run.value = payload.run;
        mergeResults(payload.results);
      } catch {
        // A failed poll is not worth a toast; the next tick will retry.
      }
    };

    pollTimer = window.setInterval(tick, POLL_INTERVAL);

    visibilityHandler = () => {
      if (!document.hidden) {
        tick();
      }
    };

    document.addEventListener('visibilitychange', visibilityHandler);
  }

  /**
   * Stops polling and detaches the visibility listener.
   *
   * @returns {void}
   */
  function stopPolling() {
    if (pollTimer !== null) {
      window.clearInterval(pollTimer);
      pollTimer = null;
    }

    if (visibilityHandler) {
      document.removeEventListener('visibilitychange', visibilityHandler);
      visibilityHandler = null;
    }
  }

  /**
   * Updates a run and refreshes the local copy.
   *
   * @param {number} id Run identifier.
   * @param {Object} data Fields to change.
   * @returns {Promise<Object>}
   */
  async function updateRun(id, data) {
    const updated = await api.runs.update(id, data);

    if (run.value?.id === id) {
      run.value = updated;
    }

    runs.value = runs.value.map((item) => (item.id === id ? updated : item));

    return updated;
  }

  /**
   * Clears the detail state when leaving a run.
   *
   * @returns {void}
   */
  function reset() {
    stopPolling();
    run.value = null;
    results.value = [];
    previousStatus.value = {};
  }

  return {
    runs,
    run,
    results,
    previousStatus,
    loadingResults,
    resultsByCaseId,
    orderedCaseIds,
    counts,
    loadRuns,
    loadRun,
    loadPreviousStatus,
    setStatus,
    replaceResult,
    updateRun,
    startPolling,
    stopPolling,
    reset
  };
});
