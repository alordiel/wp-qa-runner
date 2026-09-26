<script setup>
/**
 * Quill wrapper with a deliberately small toolbar: bold, italic, lists, link, code.
 *
 * No images, no video, no font controls — a QA comment is a sentence and a code snippet,
 * and every extra control is another thing to sanitise on the way in.
 */

import {ref, watch} from 'vue';
import {QuillEditor} from '@vueup/vue-quill';
import '@vueup/vue-quill/dist/vue-quill.snow.css';

const props = defineProps({
  modelValue: {type: String, default: ''},
  placeholder: {type: String, default: ''}
});

defineEmits(['update:modelValue']);

const editor = ref(null);

// vue-quill ignores an empty `content` prop, so clearing the model from outside
// (e.g. after posting a comment) would leave the old text in the editor.
watch(() => props.modelValue, (value) => {
  const quill = editor.value?.getQuill();

  if (!value && quill && quill.getLength() > 1) {
    quill.setText('');
  }
});

const toolbar = [
  ['bold', 'italic'],
  [{list: 'ordered'}, {list: 'bullet'}],
  ['link', 'code-block'],
  ['clean']
];
</script>

<template>
  <div class="qa-editor">
    <QuillEditor
      ref="editor"
      content-type="html"
      theme="snow"
      :content="modelValue"
      :placeholder="placeholder"
      :toolbar="toolbar"
      @update:content="$emit('update:modelValue', $event)"
    />
  </div>
</template>
