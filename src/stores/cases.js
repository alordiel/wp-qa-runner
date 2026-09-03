/**
 * Suites, the case library and the user list.
 *
 * Suites and users change rarely, so they are fetched once and reused across screens.
 */

import {defineStore} from 'pinia';
import {computed, ref} from 'vue';

import {api} from '../api/client.js';

export const useCaseStore = defineStore('cases', () => {
  const suites = ref([]);
  const cases = ref([]);
  const users = ref([]);
  const loaded = ref({suites: false, users: false});

  const suitesById = computed(() =>
    Object.fromEntries(suites.value.map((suite) => [suite.id, suite]))
  );

  const activeCases = computed(() => cases.value.filter((item) => item.is_active));

  /**
   * Loads suites, at most once unless forced.
   *
   * @param {boolean} [force] Refetch even if already loaded.
   * @returns {Promise<void>}
   */
  async function loadSuites(force = false) {
    if (loaded.value.suites && !force) {
      return;
    }

    suites.value = await api.suites.list();
    loaded.value.suites = true;
  }

  /**
   * Loads the assignable user list, at most once unless forced.
   *
   * @param {boolean} [force] Refetch even if already loaded.
   * @returns {Promise<void>}
   */
  async function loadUsers(force = false) {
    if (loaded.value.users && !force) {
      return;
    }

    users.value = await api.users.list();
    loaded.value.users = true;
  }

  /**
   * Loads the case library.
   *
   * @param {Object} [params] suite_id, priority, search, active.
   * @returns {Promise<Array>}
   */
  async function loadCases(params = {}) {
    cases.value = await api.cases.list(params);

    return cases.value;
  }

  /**
   * Creates a suite and keeps the local list sorted.
   *
   * @param {Object} data Suite fields.
   * @returns {Promise<Object>}
   */
  async function createSuite(data) {
    const suite = await api.suites.create(data);

    suites.value = [...suites.value, suite].sort(
      (a, b) => a.sort_order - b.sort_order || a.name.localeCompare(b.name)
    );

    return suite;
  }

  /**
   * Updates a suite in place.
   *
   * @param {number} id Suite identifier.
   * @param {Object} data Fields to change.
   * @returns {Promise<Object>}
   */
  async function updateSuite(id, data) {
    const suite = await api.suites.update(id, data);

    suites.value = suites.value.map((item) => (item.id === id ? suite : item));

    return suite;
  }

  /**
   * Deletes a suite that has no live cases.
   *
   * @param {number} id Suite identifier.
   * @param {number} [reassignTo] Suite to move any archived cases into first.
   * @returns {Promise<void>}
   */
  async function deleteSuite(id, reassignTo = 0) {
    await api.suites.remove(id, reassignTo || undefined);

    suites.value = suites.value.filter((item) => item.id !== id);

    if (reassignTo) {
      // The destination suite's counts just changed.
      await loadSuites(true);
    }
  }

  /**
   * Clones a case and adds the copy to the local library.
   *
   * @param {number} id Case identifier to copy.
   * @returns {Promise<Object>} The new case.
   */
  async function cloneCase(id) {
    const copy = await api.cases.clone(id);

    cases.value = [...cases.value, copy];

    return copy;
  }

  /**
   * Archives a case, keeping its history intact.
   *
   * @param {number} id Case identifier.
   * @returns {Promise<void>}
   */
  async function archiveCase(id) {
    const updated = await api.cases.archive(id);

    cases.value = cases.value.map((item) => (item.id === id ? {...item, ...updated} : item));
  }

  return {
    suites,
    cases,
    users,
    suitesById,
    activeCases,
    loadSuites,
    loadUsers,
    loadCases,
    createSuite,
    updateSuite,
    deleteSuite,
    cloneCase,
    archiveCase
  };
});
