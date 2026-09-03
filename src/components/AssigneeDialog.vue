<script setup>
/**
 * Picks people from a list, one checkbox each.
 *
 * Used both for handing a case to the testers on a run and for setting who is on the run in
 * the first place; the caller supplies the candidates and decides what a tick means.
 *
 * Ticks are a draft until Save. Writing on each tick reads as more direct, but removing
 * somebody from a run also drops the cases they had claimed on it, and re-ticking cannot
 * bring those back — so a mis-click was unrecoverable. Holding the changes lets Cancel mean
 * something, and lets the warning below say what Save is about to do.
 *
 * Built on the native <dialog> element, which brings focus trapping, Esc-to-close and a
 * backdrop with no script of our own.
 */

import {computed, ref, watch} from 'vue';

const props = defineProps({
  open: {type: Boolean, default: false},
  title: {type: String, default: 'Assign'},
  /** Shown in place of the list when there are no candidates at all. */
  emptyText: {type: String, default: 'There is nobody to choose from.'},
  /** Warning shown when a save would remove people. '%s' becomes their names. */
  removalWarning: {type: String, default: ''},
  /** People who can be picked, as {id, name, avatar}. */
  candidates: {type: Array, default: () => []},
  /** People already picked, as {id, name, avatar}. */
  assigned: {type: Array, default: () => []},
  saving: {type: Boolean, default: false}
});

const emit = defineEmits(['close', 'save']);

const dialog = ref(null);

/**
 * The draft selection, as identifiers. Reseeded from `assigned` each time the dialog opens.
 *
 * @type {import('vue').Ref<number[]>}
 */
const selected = ref([]);

const added = computed(() =>
  props.candidates.filter(
    (person) =>
      selected.value.includes(person.id) && !props.assigned.some((item) => item.id === person.id)
  )
);

const removed = computed(() =>
  props.assigned.filter((person) => !selected.value.includes(person.id))
);

const dirty = computed(() => added.value.length > 0 || removed.value.length > 0);

/**
 * Discards the draft and closes.
 *
 * @returns {void}
 */
function cancel() {
  emit('close');
}

/**
 * Hands the parent the people to add and the people to remove.
 *
 * @returns {void}
 */
function save() {
  if (dirty.value && !props.saving) {
    emit('save', {add: added.value, remove: removed.value});
  }
}

// showModal() cannot be set declaratively, so the open prop drives it imperatively. The
// draft is reseeded on open so a cancelled edit never leaks into the next one.
watch(
  () => props.open,
  (open) => {
    const element = dialog.value;

    if (open) {
      selected.value = props.assigned.map((person) => person.id);
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
  <dialog ref="dialog" class="qa-dialog" @close="cancel" @cancel="cancel">
    <div class="qa-dialog__head">
      <h3 class="qa-dialog__title">{{ title }}</h3>
      <button type="button" class="qa-dialog__close" aria-label="Close" @click="cancel">×</button>
    </div>

    <div class="qa-dialog__body">
      <p v-if="!candidates.length" class="qa-muted">{{ emptyText }}</p>

      <ul v-else class="qa-dialog__list">
        <li v-for="person in candidates" :key="person.id">
          <label class="qa-checkbox">
            <input v-model="selected" type="checkbox" :value="person.id" :disabled="saving" />
            <img
              class="qa-avatars__item"
              :src="person.avatar"
              alt=""
              width="24"
              height="24"
              loading="lazy"
            />
            <span>{{ person.name }}</span>
          </label>
        </li>
      </ul>

      <p v-if="removalWarning && removed.length" class="qa-dialog__warning">
        {{ removalWarning.replace('%s', removed.map((person) => person.name).join(', ')) }}
      </p>
    </div>

    <div class="qa-dialog__foot">
      <button type="button" class="qa-button qa-button--quiet" :disabled="saving" @click="cancel">
        Cancel
      </button>
      <button
        type="button"
        class="qa-button qa-button--primary"
        :disabled="!dirty || saving"
        @click="save"
      >
        {{ saving ? 'Saving…' : 'Save' }}
      </button>
    </div>
  </dialog>
</template>
