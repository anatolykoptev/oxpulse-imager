#!/usr/bin/env node
/**
 * Copies the freshly-built, content-hashed admin bundle from dist-admin/
 * into assets/js + assets/css, prunes stale hashed files, and writes
 * assets/manifest.json.
 *
 * Ported from UTM Linker (build/write-manifest.mjs) — simplified for
 * OXPulse which ships only ONE bundle (admin SPA), no frontend engine.
 */

import { existsSync, mkdirSync, readdirSync, copyFileSync, unlinkSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(__dirname, '..');

const ASSETS = [
  {
    logical: 'admin-app.js',
    scratchDir: path.join(ROOT, 'dist-admin', 'js'),
    destDir: path.join(ROOT, 'assets', 'js'),
    mainPattern: /^admin-app\.[A-Za-z0-9_-]+\.js$/,
    mapPattern: /^admin-app\.[A-Za-z0-9_-]+\.js\.map$/,
    stalePattern: /^admin-app\.[A-Za-z0-9_-]+\.js(\.map)?$/,
  },
  {
    logical: 'admin-app.css',
    scratchDir: path.join(ROOT, 'dist-admin', 'css'),
    destDir: path.join(ROOT, 'assets', 'css'),
    mainPattern: /^admin-app\.[A-Za-z0-9_-]+\.css$/,
    mapPattern: /^admin-app\.[A-Za-z0-9_-]+\.css\.map$/,
    stalePattern: /^admin-app\.[A-Za-z0-9_-]+\.css(\.map)?$/,
  },
];

function matchFiles(dir, pattern) {
  if (!existsSync(dir)) {
    return [];
  }
  return readdirSync(dir).filter((name) => pattern.test(name));
}

const manifest = {};

for (const asset of ASSETS) {
  const mainMatches = matchFiles(asset.scratchDir, asset.mainPattern);
  if (mainMatches.length !== 1) {
    // CSS is optional (Vite may not emit it if the bundle has no CSS).
    if (asset.logical === 'admin-app.css' && mainMatches.length === 0) {
      continue;
    }
    throw new Error(
      `write-manifest: expected exactly 1 fresh build of "${asset.logical}" in ` +
        `${asset.scratchDir}, found ${mainMatches.length} (${mainMatches.join(', ') || 'none'}). ` +
        'Did the corresponding vite build step run and succeed?'
    );
  }
  const mainName = mainMatches[0];
  const mapMatches = matchFiles(asset.scratchDir, asset.mapPattern);
  const mapName = mapMatches[0] ?? null;

  mkdirSync(asset.destDir, { recursive: true });

  copyFileSync(path.join(asset.scratchDir, mainName), path.join(asset.destDir, mainName));
  if (mapName) {
    copyFileSync(path.join(asset.scratchDir, mapName), path.join(asset.destDir, mapName));
  }

  const keep = new Set([mainName, mapName].filter(Boolean));
  for (const existing of matchFiles(asset.destDir, asset.stalePattern)) {
    if (!keep.has(existing)) {
      unlinkSync(path.join(asset.destDir, existing));
      console.log(`write-manifest: removed stale ${path.join(path.relative(ROOT, asset.destDir), existing)}`);
    }
  }

  manifest[asset.logical] = mainName;
  console.log(`write-manifest: ${asset.logical} -> ${mainName}`);
}

const manifestPath = path.join(ROOT, 'assets', 'manifest.json');
const sortedManifest = Object.fromEntries(Object.keys(manifest).sort().map((key) => [key, manifest[key]]));
writeFileSync(manifestPath, `${JSON.stringify(sortedManifest, null, 2)}\n`);
console.log(`write-manifest: wrote ${path.relative(ROOT, manifestPath)}`);
