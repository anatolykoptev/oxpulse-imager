/**
 * README nginx origin-fallback recipe: the path filters are executable
 * documentation. This test pulls the njs function and every
 * `$oxp_origin` map out of README.md and runs them, so a recipe edit
 * that stops refusing `.php` names (or `..`) turns it RED (#164).
 *
 * Run with: node --test tests/admin/readme-origin-fallback.test.mjs
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const readme = readFileSync(new URL('../../README.md', import.meta.url), 'utf8');

/** Every `map <src> $oxp_origin { ... }` body in the README. */
function originMaps() {
  const maps = [];
  const re = /map\s+("?[^\n{]*?"?)\s+\$oxp_origin\s*\{([^}]*)\}/g;
  let m;
  while ((m = re.exec(readme)) !== null) {
    const entries = [];
    for (const line of m[2].split('\n')) {
      const e = line.trim().replace(/;$/, '').match(/^(\S+)\s+(.+)$/);
      if (e) entries.push({ key: e[1], value: e[2].trim() });
    }
    maps.push({ source: m[1], entries });
  }
  return maps;
}

/** nginx `map` semantics: first matching regex wins, else `default`. */
function mapLookup(entries, input) {
  for (const { key, value } of entries) {
    if (key === 'default') continue;
    const insensitive = key.startsWith('~*');
    const re = new RegExp(key.slice(insensitive ? 2 : 1), insensitive ? 'i' : '');
    if (re.test(input)) return resolve(value, input);
  }
  const def = entries.find((e) => e.key === 'default');
  return def ? resolve(def.value, input) : '';
}

function resolve(value, input) {
  if (value === '""') return '';
  return value.startsWith('$') ? input : value; // `default $oxp_*_path` = pass through
}

const OK = '/wp-content/uploads/2025/a.jpg';
const REFUSED = [
  '/wp-content/uploads/shell.php.jpg',
  '/wp-content/uploads/2025/SHELL.PHP.jpg',
  '/wp-content/uploads/x.php5.png',
  '/wp-content/uploads/../wp-config.jpg',
];

test('README ships the plain-source and the two njs-source $oxp_origin maps', () => {
  assert.equal(originMaps().length, 3);
});

test('every $oxp_origin map passes a plain upload and refuses .php names and ..', () => {
  for (const { source, entries } of originMaps()) {
    assert.equal(mapLookup(entries, OK), OK, `map ${source} must pass a plain upload`);
    for (const bad of REFUSED) {
      assert.equal(mapLookup(entries, bad), '', `map ${source} must refuse ${bad}`);
    }
  }
});

test('the njs origin() refuses .php names and .. and passes a plain upload', () => {
  const js = readme.match(/```js\n(function origin[\s\S]*?)```/);
  assert.ok(js, 'njs block present');
  const origin = new Function(`${js[1].replace('export default { origin };', '')}\nreturn origin;`)();
  const run = (path) => origin({
    variables: {
      request_uri: `/imgproxy/sig/rs:fit:100/${Buffer.from('local://' + path).toString('base64url')}.webp`,
    },
  });
  assert.equal(run(OK), OK);
  for (const bad of REFUSED) {
    assert.equal(run(bad), '', `njs origin() must refuse ${bad}`);
  }
});
