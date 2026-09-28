/**
 * Mandragora QA Test Manager admin app entry point.
 */

import {createPinia} from 'pinia';
import {createApp} from 'vue';

import App from './App.vue';
import {router} from './router/index.js';
import './styles/main.css';

const mount = document.getElementById('mqatm-app');

if (mount) {
  const app = createApp(App);

  // Templates call __() / _n() / _x() / sprintf() through these globals. They compile to
  // `_ctx.__('…', 'mandragora-qa-test-manager')`, a member call that `wp i18n make-pot` still recognises in
  // the minified bundle. Script code calls `wp.i18n.__(…)` directly for the same reason:
  // an imported or destructured `__` would be renamed by the minifier and its strings lost.
  Object.assign(app.config.globalProperties, {
    __: wp.i18n.__,
    _n: wp.i18n._n,
    _x: wp.i18n._x,
    sprintf: wp.i18n.sprintf
  });

  app.use(createPinia()).use(router).mount(mount);
}
