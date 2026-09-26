<script setup>
/**
 * The QA team: who has access and with which role, plus granting a role to an existing
 * WordPress user. Never creates users. Site administrators only — the server checks
 * promote_users on every route.
 *
 * Changes save immediately rather than through the Settings form's Save button: a role is
 * a per-person action, not a preference to batch.
 */

import {computed, onMounted, ref, watch} from 'vue';

import {api, bootstrap} from '../api/client.js';
import {useUiStore} from '../stores/ui.js';

const ui = useUiStore();

const ROLE_OPTIONS = [
  {value: 'qa_tester', label: wp.i18n.__('QA Tester', 'qa-runner')},
  {value: 'qa_admin', label: wp.i18n.__('QA Admin', 'qa-runner')}
];

const ROLE_HINTS = {
  qa_tester: wp.i18n.__(
    'Runs tests: creates runs, records results, comments and raises issues.',
    'qa-runner'
  ),
  qa_admin: wp.i18n.__(
    'Everything a tester can do, plus the case library, suites and settings.',
    'qa-runner'
  )
};

const members = ref([]);
const loading = ref(true);
const savingId = ref(0);

const search = ref('');
const candidates = ref([]);
const loadingCandidates = ref(false);
const newUserId = ref(0);
const newRole = ref('qa_tester');
const adding = ref(false);

const selectedCandidate = computed(() =>
  candidates.value.find((user) => user.id === newUserId.value)
);

/**
 * Loads the current team.
 *
 * @returns {Promise<void>}
 */
async function loadMembers() {
  try {
    members.value = await api.team.list();
  } catch (error) {
    ui.toastError(error, wp.i18n.__('The QA team could not be loaded.', 'qa-runner'));
  } finally {
    loading.value = false;
  }
}

/**
 * Loads users without QA access, narrowed by the search box.
 *
 * @returns {Promise<void>}
 */
async function loadCandidates() {
  loadingCandidates.value = true;

  try {
    candidates.value = await api.team.candidates(search.value.trim());

    if (!candidates.value.some((user) => user.id === newUserId.value)) {
      newUserId.value = candidates.value[0]?.id ?? 0;
    }
  } catch (error) {
    ui.toastError(error, wp.i18n.__('The user list could not be loaded.', 'qa-runner'));
  } finally {
    loadingCandidates.value = false;
  }
}

let searchTimer = 0;

watch(search, () => {
  window.clearTimeout(searchTimer);
  searchTimer = window.setTimeout(loadCandidates, 250);
});

/**
 * Grants the chosen role to the chosen user.
 *
 * @returns {Promise<void>}
 */
async function addMember() {
  if (!newUserId.value) {
    return;
  }

  adding.value = true;

  try {
    const member = await api.team.add(newUserId.value, newRole.value);

    members.value = [...members.value, member];
    /* translators: %s: user display name. */
    ui.toast(wp.i18n.sprintf(wp.i18n.__('%s added to the QA team.', 'qa-runner'), member.name));
    search.value = '';
    await loadCandidates();
  } catch (error) {
    ui.toastError(error, wp.i18n.__('That person could not be added.', 'qa-runner'));
  } finally {
    adding.value = false;
  }
}

/**
 * Switches a member between QA Tester and QA Admin.
 *
 * @param {Object} member Team member.
 * @param {string} role New QA role.
 * @returns {Promise<void>}
 */
async function changeRole(member, role) {
  savingId.value = member.id;

  try {
    const updated = await api.team.update(member.id, role);

    members.value = members.value.map((item) => (item.id === updated.id ? updated : item));
    ui.toast(
      wp.i18n.sprintf(
        /* translators: 1: user display name, 2: QA role name. */
        wp.i18n.__('%1$s is now a %2$s.', 'qa-runner'),
        updated.name,
        roleLabel(updated.qa_role)
      )
    );
  } catch (error) {
    ui.toastError(error, wp.i18n.__('The role could not be changed.', 'qa-runner'));
  } finally {
    savingId.value = 0;
  }
}

/**
 * Takes a member's QA role away. Their WordPress role is untouched.
 *
 * @param {Object} member Team member.
 * @returns {Promise<void>}
 */
async function removeMember(member) {
  const question = member.wp_roles.length
    ? wp.i18n.sprintf(
        /* translators: 1: user display name, 2: their WordPress role names. */
        wp.i18n.__(
          'Remove %1$s from the QA team? They keep their WordPress role (%2$s), but lose access to QA Runner.',
          'qa-runner'
        ),
        member.name,
        member.wp_roles.join(', ')
      )
    : wp.i18n.sprintf(
        /* translators: %s: user display name. */
        wp.i18n.__(
          'Remove %s from the QA team? They have no other WordPress role, so they become a Subscriber and lose access to QA Runner.',
          'qa-runner'
        ),
        member.name
      );

  if (!window.confirm(question)) {
    return;
  }

  savingId.value = member.id;

  try {
    await api.team.remove(member.id);

    members.value = members.value.filter((item) => item.id !== member.id);
    /* translators: %s: user display name. */
    ui.toast(wp.i18n.sprintf(wp.i18n.__('%s removed from the QA team.', 'qa-runner'), member.name));
    await loadCandidates();
  } catch (error) {
    ui.toastError(error, wp.i18n.__('That person could not be removed.', 'qa-runner'));
  } finally {
    savingId.value = 0;
  }
}

/**
 * Display label for a QA role.
 *
 * @param {string} role Role slug.
 * @returns {string}
 */
function roleLabel(role) {
  return (
    ROLE_OPTIONS.find((option) => option.value === role)?.label ??
    wp.i18n.__('Administrator', 'qa-runner')
  );
}

/**
 * Whether this row's role may be changed here.
 *
 * @param {Object} member Team member.
 * @returns {boolean}
 */
function isEditable(member) {
  return member.qa_role !== 'administrator' && member.id !== bootstrap.currentUser?.id;
}

onMounted(() => {
  loadMembers();
  loadCandidates();
});
</script>

<template>
  <div class="qa-card">
    <div class="qa-card__head">
      <h3>{{ __('QA team', 'qa-runner') }}</h3>
    </div>

    <p v-if="loading" class="qa-card__body qa-skeleton">
      {{ __('Loading the QA team…', 'qa-runner') }}
    </p>

    <div v-else class="qa-table-scroll">
      <table class="qa-table">
        <thead>
          <tr>
            <th scope="col">{{ __('User', 'qa-runner') }}</th>
            <th scope="col">{{ __('WordPress role', 'qa-runner') }}</th>
            <th scope="col">{{ __('QA role', 'qa-runner') }}</th>
            <th scope="col">
              <span class="screen-reader-text">{{ __('Actions', 'qa-runner') }}</span>
            </th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="member in members" :key="member.id">
            <td>
              <div class="qa-team-user">
                <img
                  class="qa-team-user__avatar"
                  :src="member.avatar"
                  alt=""
                  width="28"
                  height="28"
                  loading="lazy"
                />
                <div class="qa-team-user__text">
                  <span class="qa-team-user__name">{{ member.name }}</span>
                  <span class="qa-muted">{{ member.email }}</span>
                </div>
              </div>
            </td>
            <td class="qa-muted">{{ member.wp_roles.join(', ') || '—' }}</td>
            <td>
              <select
                v-if="isEditable(member)"
                class="qa-select"
                :value="member.qa_role"
                :disabled="savingId === member.id"
                :aria-label="sprintf(__('QA role for %s', 'qa-runner'), member.name)"
                @change="changeRole(member, $event.target.value)"
              >
                <option v-for="option in ROLE_OPTIONS" :key="option.value" :value="option.value">
                  {{ option.label }}
                </option>
              </select>
              <span v-else-if="member.qa_role === 'administrator'" class="qa-muted">
                {{ __('Full access (site administrator)', 'qa-runner') }}
              </span>
              <span v-else
                >{{ roleLabel(member.qa_role) }}
                <span class="qa-muted">{{ __('(you)', 'qa-runner') }}</span></span
              >
            </td>
            <td class="qa-team-actions">
              <button
                v-if="isEditable(member)"
                type="button"
                class="qa-icon-button qa-icon-button--danger"
                :title="sprintf(__('Remove %s from the QA team', 'qa-runner'), member.name)"
                :aria-label="sprintf(__('Remove %s from the QA team', 'qa-runner'), member.name)"
                :disabled="savingId === member.id"
                @click="removeMember(member)"
              >
                <span class="dashicons dashicons-trash" aria-hidden="true" />
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <form class="qa-card__body qa-team-add" @submit.prevent="addMember">
      <h4 class="qa-team-add__title">{{ __('Add an existing user', 'qa-runner') }}</h4>
      <div class="qa-team-add__fields">
        <div class="qa-field">
          <label class="qa-field__label" for="team-search">{{
            __('Search users', 'qa-runner')
          }}</label>
          <input
            id="team-search"
            v-model="search"
            class="qa-input"
            type="search"
            :placeholder="__('Name, username or email', 'qa-runner')"
            autocomplete="off"
          />
        </div>

        <div class="qa-field">
          <label class="qa-field__label" for="team-user">{{ __('User', 'qa-runner') }}</label>
          <select
            id="team-user"
            v-model.number="newUserId"
            class="qa-select"
            :disabled="loadingCandidates || !candidates.length"
          >
            <option v-if="!candidates.length" :value="0">
              {{
                loadingCandidates
                  ? __('Loading…', 'qa-runner')
                  : __('No matching users without QA access', 'qa-runner')
              }}
            </option>
            <option v-for="user in candidates" :key="user.id" :value="user.id">
              {{ user.name }} ({{ user.email }})
            </option>
          </select>
        </div>

        <div class="qa-field">
          <label class="qa-field__label" for="team-role">{{ __('QA role', 'qa-runner') }}</label>
          <select id="team-role" v-model="newRole" class="qa-select">
            <option v-for="option in ROLE_OPTIONS" :key="option.value" :value="option.value">
              {{ option.label }}
            </option>
          </select>
        </div>

        <button
          type="submit"
          class="qa-button qa-button--primary"
          :disabled="adding || !selectedCandidate"
        >
          {{ adding ? __('Adding…', 'qa-runner') : __('Add to team', 'qa-runner') }}
        </button>
      </div>
      <p class="qa-field__hint">
        {{ ROLE_HINTS[newRole] }}
        {{
          __(
            'The QA role is added alongside their current WordPress role. Site administrators always have full access.',
            'qa-runner'
          )
        }}
      </p>
    </form>
  </div>
</template>
