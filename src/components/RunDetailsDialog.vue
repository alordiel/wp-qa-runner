<script setup>
/**
 * Edits a run's metadata — name, environment, version and notes.
 *
 * The fields mirror the create screen, minus the case picker: those are the four things a
 * run is described by, and all four are worth correcting after the fact (a version typed
 * wrong makes the run unauditable, which is the whole reason the field is required).
 *
 * Edits are a draft until Save, so Cancel and Esc both mean something. Built on the native
 * <dialog> element, which brings focus trapping, Esc-to-close and a backdrop with no script
 * of our own.
 */

import {computed, ref, watch} from 'vue';

const props = defineProps({
  open: {type: Boolean, default: false},
  /** The run being edited, as {name, environment, version, notes}. */
  run: {type: Object, default: null},
  saving: {type: Boolean, default: false}
});

const emit = defineEmits(['close', 'save']);

const dialog = ref(null);

const ENVIRONMENTS = window.qaRunner?.environments ?? ['local', 'staging', 'production'];

const form = ref({name: '', environment: 'staging', version: '', notes: ''});

/**
 * The fields that differ from the run as loaded. Only these are sent, so a concurrent edit
 * to a field this tester did not touch is left alone.
 *
 * @type {import('vue').ComputedRef<Object>}
 */
const changes = computed(() => {
  const changed = {};

  if (!props.run) {
    return changed;
  }

  Object.entries(form.value).forEach(([field, value]) => {
    const next = 'notes' === field ? value : value.trim();

    if (next !== (props.run[field] ?? '')) {
      changed[field] = next;
    }
  });

  return changed;
});

const valid = computed(() => form.value.name.trim() !== '' && form.value.version.trim() !== '');

const dirty = computed(() => Object.keys(changes.value).length > 0);

/**
 * Discards the draft and closes.
 *
 * @returns {void}
 */
function cancel() {
  emit('close');
}

/**
 * Hands the parent the changed fields.
 *
 * @returns {void}
 */
function save() {
  if (dirty.value && valid.value && !props.saving) {
    emit('save', changes.value);
  }
}

// showModal() cannot be set declaratively, so the open prop drives it imperatively. The
// draft is reseeded on open so a cancelled edit never leaks into the next one.
watch(
  () => props.open,
  (open) => {
    const element = dialog.value;

    if (open) {
      form.value = {
        name: props.run?.name ?? '',
        environment: props.run?.environment ?? 'staging',
        version: props.run?.version ?? '',
        notes: props.run?.notes ?? ''
      };
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
    <form @submit.prevent="save">
      <div class="qa-dialog__head">
        <h3 class="qa-dialog__title">Edit run details</h3>
        <button type="button" class="qa-dialog__close" aria-label="Close" @click="cancel">×</button>
      </div>

      <div class="qa-dialog__body qa-stack">
        <div class="qa-field">
          <label class="qa-field__label" for="edit-run-name">Name</label>
          <input
            id="edit-run-name"
            v-model="form.name"
            class="qa-input"
            type="text"
            :disabled="saving"
            required
          />
        </div>

        <div class="qa-row" style="align-items: flex-start; gap: 16px">
          <div class="qa-field" style="flex: 1; min-width: 140px">
            <label class="qa-field__label" for="edit-run-environment">Environment</label>
            <select
              id="edit-run-environment"
              v-model="form.environment"
              class="qa-select"
              :disabled="saving"
            >
              <option v-for="environment in ENVIRONMENTS" :key="environment" :value="environment">
                {{ environment }}
              </option>
            </select>
          </div>

          <div class="qa-field" style="flex: 1; min-width: 140px">
            <label class="qa-field__label" for="edit-run-version">Version</label>
            <input
              id="edit-run-version"
              v-model="form.version"
              class="qa-input"
              type="text"
              placeholder="2.4.0 or a commit ref"
              :disabled="saving"
              required
            />
          </div>
        </div>

        <div class="qa-field">
          <label class="qa-field__label" for="edit-run-notes">Notes</label>
          <textarea
            id="edit-run-notes"
            v-model="form.notes"
            class="qa-textarea"
            placeholder="What this run covers, and anything the testers should know."
            :disabled="saving"
          />
        </div>
      </div>

      <div class="qa-dialog__foot">
        <button type="button" class="qa-button qa-button--quiet" :disabled="saving" @click="cancel">
          Cancel
        </button>
        <button
          type="submit"
          class="qa-button qa-button--primary"
          :disabled="!dirty || !valid || saving"
        >
          {{ saving ? 'Saving…' : 'Save' }}
        </button>
      </div>
    </form>
  </dialog>
</template>
