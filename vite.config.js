import {createRequire} from 'node:module';

import {defineConfig} from 'vite';
import vue from '@vitejs/plugin-vue';

const require = createRequire(import.meta.url);

/**
 * Resolves the Quill wrapper build to link against.
 *
 * `@vueup/vue-quill`'s default (esm-bundler) build reaches Quill through `import('quill')`. In an
 * IIFE bundle that dynamic import has nowhere to go: rolldown inlines it anyway, but still drags in
 * Vite's module-preload helper, which reads `import.meta.url` — meaningless in a classic script.
 * The esm-browser build links Quill statically and drops both.
 *
 * @param {boolean} isDebug
 * @returns {string} absolute path to the build
 */
function resolveVueQuill(isDebug)
{
  return require.resolve(`@vueup/vue-quill/dist/vue-quill.esm-browser${isDebug ? '' : '.prod'}.js`);
}

export default defineConfig(({mode}) => {
  const isDebug = mode === 'debug';
  return {
    plugins: [vue(),],
    server: {
      cors: true,
      host: true
    },
    resolve: {
      alias: [
        {find: /^@vueup\/vue-quill$/, replacement: resolveVueQuill(isDebug)},
        ...(isDebug ? [{find: /^vue$/, replacement: 'vue/dist/vue.esm-bundler.js'}] : []),
      ]
    },
    define: {
      __VUE_OPTIONS_API__: true,
      __VUE_PROD_DEVTOOLS__: isDebug,
      __VUE_PROD_HYDRATION_MISMATCH_DETAILS__: isDebug,
      'process.env.NODE_ENV': isDebug ? '"development"' : '"production"'
    },
    build: {
      minify: isDebug ? false : 'oxc',
      sourcemap: isDebug,
      assetsDir: '',
      outDir: 'build',
      rollupOptions: {
        input: 'src/main.js',
        output: {
          // iife instead of esm so WordPress can enqueue it as a classic script
          format: 'iife',
          entryFileNames: 'qa-admin-page.js',
          assetFileNames: (asset) => asset.names.some((name) => name.endsWith('.css'))
            ? 'qa-admin-page.css'
            : 'qa-admin-page-[name].[ext]'
        }
      },
      cssCodeSplit: false
    },
    base: './'
  };
});
