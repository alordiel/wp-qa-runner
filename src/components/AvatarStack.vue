<script setup>
/**
 * Overlapping assignee avatars, with the names in the accessible name.
 */

import {computed} from 'vue';

const props = defineProps({
  people: {type: Array, default: () => []},
  max: {type: Number, default: 4}
});

const shown = computed(() => props.people.slice(0, props.max));
const overflow = computed(() => Math.max(0, props.people.length - props.max));
const names = computed(() => props.people.map((person) => person.name).join(', '));
</script>

<template>
  <span v-if="people.length" class="qa-avatars" :title="names">
    <span class="qa-person-badge" v-for="person in shown">
    <img
        :key="person.id"
        class="qa-avatars__item"
        :src="person.avatar"
        :alt="person.name"
        width="24"
        height="24"
        loading="lazy"
    />
      <span>{{ person.name }}</span>
    </span>
    <span v-if="overflow" class="qa-avatars__more">+{{ overflow }}</span>
  </span>
  <span v-else class="qa-muted">Nobody assigned</span>
</template>
