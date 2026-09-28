<script setup>
/**
 * Edits the run's case selection — add cases from the library, drop cases already on it.
 *
 * Ticks are a draft until Save, for the same reason as the assignee picker: dropping a case
 * destroys everything the run recorded against it — the status, the comments and any issue
 * raised here — and re-ticking cannot bring that back. Holding the changes lets Cancel mean
 * something, and lets the warning below name what Save is about to delete. Removals also
 * need the confirmation ticked, so the destructive half is never one stray click.
 *
 * Built on the native <dialog> element, which brings focus trapping, Esc-to-close and a
 * backdrop with no script of our own.
 */

import {computed, nextTick, ref, watch} from 'vue';

import PriorityDot from './PriorityDot.vue';
import StatusBadge from './StatusBadge.vue';

const props = defineProps({
  open: {type: Boolean, default: false},
  /** The run's current result rows, each carrying its case and what was recorded on it. */
  results: {type: Array, default: () => []},
  /** Active cases in the library, as {id, title, priority, suite_id, suite_name}. */
  library: {type: Array, default: () => []},
  /** Suites, for the add filter. */
  suites: {type: Array, default: () => []},
  loading: {type: Boolean, default: false},
  saving: {type: Boolean, default: false}
});

const emit = defineEmits(['close', 'save']);

const dialog = ref(null);
const body = ref(null);

/**
 * The draft selection, as case identifiers. Reseeded from the run each time it opens.
 *
 * @type {import('vue').Ref<number[]>}
 */
const selected = ref([]);

const confirmed = ref(false);
const search = ref('');
const suiteFilter = ref('');

const inRun = computed(() => new Set(props.results.map((result) => result.case.id)));

const removed = computed(() =>
  props.results.filter((result) => !selected.value.includes(result.case.id))
);

const added = computed(() =>
  props.library.filter((item) => selected.value.includes(item.id) && !inRun.value.has(item.id))
);

const hasFilter = computed(() => search.value.trim() !== '' || suiteFilter.value !== '');

/**
 * Library cases not already on the run, under the add filter.
 *
 * Nothing is listed until a filter is set: the library outgrows a dialog long before the
 * run does, and the add step is always a search for something specific.
 *
 * @type {import('vue').ComputedRef<Array>}
 */
const candidates = computed(() => {
  if (!hasFilter.value) {
    return [];
  }

  const term = search.value.trim().toLowerCase();

  return props.library.filter((item) => {
    if (inRun.value.has(item.id)) {
      return false;
    }

    if (suiteFilter.value && String(item.suite_id) !== suiteFilter.value) {
      return false;
    }

    return !term || item.title.toLowerCase().includes(term);
  });
});

const dirty = computed(() => added.value.length > 0 || removed.value.length > 0);

const canSave = computed(
  () => dirty.value && !props.saving && (removed.value.length === 0 || confirmed.value)
);

/**
 * What a removal is about to destroy, worded for the warning.
 *
 * @type {import('vue').ComputedRef<string>}
 */
const removalWarning = computed(() => {
  const names = removed.value
    .map((result) => `<li><strong>${escapeHtml(result.case.title)}</strong></li>`)
    .join(' ');
  const warning = wp.i18n._n(
    'This will delete everything this run recorded against it: the status, every comment and any issue raised on it here. The case itself is not deleted, but its history in this run is erased.',
    'This will delete everything this run recorded against them: the status, every comment and any issue raised on them here. The cases themselves are not deleted, but their history in this run is erased.',
    removed.value.length,
    'mandragora-qa-test-manager'
  );

  return `${escapeHtml(wp.i18n.__('Removing:', 'mandragora-qa-test-manager'))}<ul>${names}</ul>${escapeHtml(warning)}`;
});

/**
 * Escapes text for the v-html warning above. Case titles are user input.
 *
 * @param {string} text Plain text.
 * @returns {string} HTML-safe text.
 */
function escapeHtml(text) {
  const element = document.createElement('span');

  element.textContent = text;

  return element.innerHTML;
}

/**
 * What one row would lose, as a short list of phrases.
 *
 * @param {Object} result Result row.
 * @returns {string[]}
 */
function recorded(result) {
  const parts = [];

  if (result.comment_count > 0) {
    parts.push(
      wp.i18n.sprintf(
        wp.i18n._n('%d comment', '%d comments', result.comment_count, 'mandragora-qa-test-manager'),
        result.comment_count
      )
    );
  }

  if (result.open_issue_count > 0) {
    parts.push(
      wp.i18n.sprintf(
        wp.i18n._n('%d open issue', '%d open issues', result.open_issue_count, 'mandragora-qa-test-manager'),
        result.open_issue_count
      )
    );
  }

  return parts;
}

/**
 * Discards the draft and closes.
 *
 * @returns {void}
 */
function cancel() {
  emit('close');
}

/**
 * Hands the parent the cases to add and the cases to remove.
 *
 * @returns {void}
 */
function save() {
  if (canSave.value) {
    emit('save', {
      add: added.value.map((item) => item.id),
      remove: removed.value.map((result) => result.case.id)
    });
  }
}

// The confirmation sits at the very bottom of a scrolling body, so unticking a case near
// the top would otherwise hide the one control that lets the save go through. Each new
// removal brings it into view.
watch(
  () => removed.value.length,
  (count, before) => {
    if (count > before) {
      nextTick(() => body.value?.scrollTo({top: body.value.scrollHeight, behavior: 'smooth'}));
    }
  }
);

// showModal() cannot be set declaratively, so the open prop drives it imperatively. The
// draft is reseeded on open so a cancelled edit never leaks into the next one.
watch(
  () => props.open,
  (open) => {
    const element = dialog.value;

    if (open) {
      selected.value = props.results.map((result) => result.case.id);
      confirmed.value = false;
      search.value = '';
      suiteFilter.value = '';
    }

    if (!element) {
      return;
    }

    if (open && !element.open) {
      element.showModal();
    } else if (!open && element.open) {
      element.close();
    }
  }
);
</script>

<template>
  <dialog ref="dialog" class="qa-dialog qa-dialog--wide" @close="cancel" @cancel="cancel">
    <div class="qa-dialog__head">
      <h3 class="qa-dialog__title">{{ __('Cases in this run', 'mandragora-qa-test-manager') }}</h3>
      <button
        type="button"
        class="qa-dialog__close"
        :aria-label="__('Close', 'mandragora-qa-test-manager')"
        @click="cancel"
      >
        ×
      </button>
    </div>

    <div ref="body" class="qa-dialog__body qa-stack">
      <div class="qa-stack qa-stack--tight">
        <span class="qa-field__label">{{
          sprintf(__('On the run (%d)', 'mandragora-qa-test-manager'), results.length)
        }}</span>
        <p class="qa-field__hint">
          {{ __('Untick a case to take it off this run.', 'mandragora-qa-test-manager') }}
        </p>

        <p v-if="!results.length" class="qa-muted">
          {{ __('This run has no cases yet.', 'mandragora-qa-test-manager') }}
        </p>

        <ul v-else class="qa-dialog__list">
          <li v-for="result in results" :key="result.id">
            <label class="qa-checkbox">
              <input
                v-model="selected"
                type="checkbox"
                :value="result.case.id"
                :disabled="saving"
              />
              <PriorityDot :priority="result.case.priority" />
              <span class="qa-dialog__case">
                <span>{{ result.case.title }}</span>
                <span class="qa-case-row__meta">
                  <span>{{ result.case.suite_name }}</span>
                  <StatusBadge :status="result.status" />
                  <span v-for="part in recorded(result)" :key="part">{{ part }}</span>
                </span>
              </span>
            </label>
          </li>
        </ul>
      </div>

      <div class="qa-stack qa-stack--tight">
        <span class="qa-field__label">{{ __('Add from the library', 'mandragora-qa-test-manager') }}</span>

        <div class="qa-row">
          <label class="qa-sr-only" for="run-cases-search">{{
            __('Search cases', 'mandragora-qa-test-manager')
          }}</label>
          <input
            id="run-cases-search"
            v-model="search"
            class="qa-input"
            type="search"
            :placeholder="__('Search by title', 'mandragora-qa-test-manager')"
            :disabled="saving"
            style="flex: 1; min-width: 140px"
          />

          <label class="qa-sr-only" for="run-cases-suite">{{ __('Suite', 'mandragora-qa-test-manager') }}</label>
          <select
            id="run-cases-suite"
            v-model="suiteFilter"
            class="qa-select"
            :disabled="saving"
            style="width: auto"
          >
            <option value="">{{ __('All suites', 'mandragora-qa-test-manager') }}</option>
            <option v-for="suite in suites" :key="suite.id" :value="String(suite.id)">
              {{ suite.name }}
            </option>
          </select>
        </div>

        <p v-if="loading" class="qa-muted">{{ __('Loading the case library…', 'mandragora-qa-test-manager') }}</p>
        <p v-else-if="!hasFilter" class="qa-field__hint">
          {{ __('Search or pick a suite to see the cases you can add.', 'mandragora-qa-test-manager') }}
        </p>
        <p v-else-if="!candidates.length" class="qa-muted">
          {{ __('No cases match, or they are all on this run already.', 'mandragora-qa-test-manager') }}
        </p>

        <ul v-else class="qa-dialog__list">
          <li v-for="item in candidates" :key="item.id">
            <label class="qa-checkbox">
              <input v-model="selected" type="checkbox" :value="item.id" :disabled="saving" />
              <PriorityDot :priority="item.priority" />
              <span class="qa-dialog__case">
                <span>{{ item.title }}</span>
                <span class="qa-case-row__meta">{{ item.suite_name }}</span>
              </span>
            </label>
          </li>
        </ul>
      </div>

      <div v-if="removed.length" class="qa-dialog__warning">
        <p style="margin: 0" v-html="removalWarning"></p>
        <label class="qa-checkbox" style="margin-top: 8px">
          <input v-model="confirmed" type="checkbox" :disabled="saving" />
          <span>
            {{
              sprintf(
                _n(
                  'Yes, remove %d case and delete what this run recorded against it.',
                  'Yes, remove %d cases and delete what this run recorded against them.',
                  removed.length,
                  'mandragora-qa-test-manager'
                ),
                removed.length
              )
            }}
          </span>
        </label>
      </div>
    </div>

    <div class="qa-dialog__foot">
      <button type="button" class="qa-button qa-button--quiet" :disabled="saving" @click="cancel">
        {{ __('Cancel', 'mandragora-qa-test-manager') }}
      </button>
      <button type="button" class="qa-button qa-button--primary" :disabled="!canSave" @click="save">
        {{ saving ? __('Saving…', 'mandragora-qa-test-manager') : __('Save', 'mandragora-qa-test-manager') }}
      </button>
    </div>
  </dialog>
</template>
