/**
 * QA Runner admin app entry point.
 */

import {createPinia} from 'pinia';
import {createApp} from 'vue';

import App from './App.vue';
import {router} from './router/index.js';
import './styles/main.css';

const mount = document.getElementById('qa-runner-app');

if (mount) {
  createApp(App).use(createPinia()).use(router).mount(mount);
}
