/**
 * Vite configuration for OXPulse Imager admin SPA.
 *
 * Self-contained IIFE bundle — React + react-dom bundled in, no
 * `wp.element`/`wp.apiFetch`/`wp.i18n` dependencies. Loaded directly
 * by WordPress as a finished browser script.
 *
 * Ported from UTM Linker (build/vite.config.js) — same pattern:
 * - process.env.NODE_ENV forced to 'production' (lib output doesn't
 *   inline it automatically; without this React ships its dev build)
 * - Terser minifier (deterministic output across invocations, unlike
 *   esbuild which can vary internal helper naming)
 * - Content-hashed filenames (immutable caching safe — URL changes
 *   only when content does)
 * - Scratch output to dist-admin/, copied to assets/ by write-manifest.mjs
 */

import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import path from 'path';

export default defineConfig({
  plugins: [react()],

  define: {
    'process.env.NODE_ENV': JSON.stringify('production'),
  },

  resolve: {
    alias: {
      '@utils': path.resolve(__dirname, '../src/admin/utils'),
      '@store': path.resolve(__dirname, '../src/admin/store'),
      '@components': path.resolve(__dirname, '../src/admin/components'),
      '@sections': path.resolve(__dirname, '../src/admin/sections'),
    },
  },

  build: {
    outDir: path.resolve(__dirname, '..', 'dist-admin'),
    sourcemap: true,
    minify: 'terser',

    terserOptions: {
      compress: {
        drop_console: true,
        drop_debugger: true,
        passes: 2,
        ecma: 2015,
      },
      mangle: {
        properties: {
          regex: /^_/,
        },
      },
      format: {
        comments: false,
      },
    },

    lib: {
      entry: path.resolve(__dirname, '../src/admin/index.jsx'),
      name: 'OXPulseAdmin',
      formats: ['iife'],
    },

    rollupOptions: {
      output: {
        entryFileNames: 'js/admin-app.[hash].js',
        assetFileNames: 'css/admin-app.[hash][extname]',
      },
    },
  },

  server: {
    port: 3001,
    open: false,
  },
});
