/**
 * Generate the .pot file for translations.
 *
 * Two-pass extraction:
 *   1. PHP strings  — via wp-pot (scans src PHP files + oxpulse-imager.php)
 *   2. JS strings   — via a small babel-parser-based extractor
 *      (scans src/admin JS/JSX for __('...', 'oxpulse-imager'))
 *
 * Both passes merge into a single languages/oxpulse-imager.pot so
 * translators see one coherent catalog covering PHP + the admin SPA.
 *
 * Run: npm run make-pot
 */

import { WP_Pot } from 'wp-pot';
import { mkdirSync, readFileSync, writeFileSync, existsSync } from 'fs';
import { dirname, resolve, join, relative } from 'path';
import { globSync } from 'glob';
import { parse } from '@babel/parser';

const ROOT = process.cwd();
const dest = resolve(ROOT, 'languages/oxpulse-imager.pot');

// Ensure the languages/ directory exists.
mkdirSync(dirname(dest), { recursive: true });

// --- Pass 1: PHP strings via wp-pot ---
const pot = new WP_Pot({
  pot: {
    package: 'OXPulse Imager',
    domain: 'oxpulse-imager',
    lastTranslator: 'Anatoly Koptev <koptev@koptev.org>',
    team: 'Anatoly Koptev <koptev@koptev.org>',
    // Omit `#: file:line` source references so the .pot is a pure function
    // of the translatable string SET — it only changes when a string is
    // added/removed/changed, never on a line shift (issue #85).
    noFilePaths: true,
  },
});

pot.parse(['src/**/*.php', 'oxpulse-imager.php']);
pot.writePot(dest);

console.log(`✓ PHP strings extracted → ${dest}`);

// --- Pass 2: JS strings via babel parser ---
// Scans src/admin/**/*.{js,jsx} for __('text', 'oxpulse-imager') and
// _n('one', 'many', count, 'oxpulse-imager') calls. Appends entries
// to the POT in the same gettext format so translators see one file.
const DOMAIN = 'oxpulse-imager';
const jsFiles = [
  ...globSync('src/admin/**/*.js', { cwd: ROOT }),
  ...globSync('src/admin/**/*.jsx', { cwd: ROOT }),
];

/** @type {Map<string, {msgctxt?: string, msgid: string, msgidPlural?: string, references: Set<string>}>} */
const jsEntries = new Map();

function addEntry(msgid, { msgctxt, msgidPlural } = {}, file, line) {
  const key = (msgctxt ?? '') + '\x00' + msgid + (msgidPlural ? '\x00' + msgidPlural : '');
  const ref = `${file}:${line}`;
  if (!jsEntries.has(key)) {
    jsEntries.set(key, { msgctxt, msgid, msgidPlural, references: new Set([ref]) });
  } else {
    jsEntries.get(key).references.add(ref);
  }
}

// Babel parser plugins: JSX + import.meta (used by some utility files).
const BABEL_OPTIONS = {
  sourceType: 'module',
  plugins: ['jsx'],
};

for (const file of jsFiles) {
  const abs = resolve(ROOT, file);
  const src = readFileSync(abs, 'utf8');
  let ast;
  try {
    ast = parse(src, BABEL_OPTIONS);
  } catch (err) {
    console.warn(`⚠ Skip ${file}: ${err.message}`);
    continue;
  }

  // Walk the AST looking for CallExpressions whose callee name is __, _x, _n, _nx.
  // We do a simple recursive walk — no @babel/traverse dep needed.
  const visit = (node) => {
    if (!node || typeof node !== 'object') return;
    if (Array.isArray(node)) {
      for (const n of node) visit(n);
      return;
    }
    if (node.type === 'CallExpression') {
      const callee = node.callee;
      const name = callee.type === 'Identifier' ? callee.name : null;
      if (name === '__' || name === '_x' || name === '_n' || name === '_nx') {
        const args = node.arguments;
        const first = args[0];
        if (first && first.type === 'StringLiteral') {
          const msgid = first.value;
          const second = args[1];
          // For _x/_nx: second arg is context. For _n/_nx: second is plural.
          // For __: second is domain (we only record if domain matches).
          if (name === '__') {
            // Verify domain matches.
            if (second && second.type === 'StringLiteral' && second.value === DOMAIN) {
              addEntry(msgid, {}, relative(ROOT, abs), node.loc?.start?.line ?? 0);
            }
          } else if (name === '_x') {
            if (second && second.type === 'StringLiteral') {
              addEntry(msgid, { msgctxt: second.value }, relative(ROOT, abs), node.loc?.start?.line ?? 0);
            }
          } else if (name === '_n') {
            if (second && second.type === 'StringLiteral') {
              addEntry(msgid, { msgidPlural: second.value }, relative(ROOT, abs), node.loc?.start?.line ?? 0);
            }
          } else if (name === '_nx') {
            const plural = second;
            const ctx = args[3];
            if (plural && plural.type === 'StringLiteral' && ctx && ctx.type === 'StringLiteral') {
              addEntry(msgid, { msgidPlural: plural.value, msgctxt: ctx.value }, relative(ROOT, abs), node.loc?.start?.line ?? 0);
            }
          }
        }
      }
    }
    // Recurse into all child nodes.
    for (const key of Object.keys(node)) {
      if (key === 'loc' || key === 'start' || key === 'end' || key === 'type' || key === 'range') continue;
      const child = node[key];
      visit(child);
    }
  };
  visit(ast);
}

// Append JS entries to the POT file.
if (jsEntries.size > 0) {
  const lines = [];
  for (const entry of jsEntries.values()) {
    lines.push('');
    if (entry.msgctxt) {
      lines.push(`msgctxt "${escapePo(entry.msgctxt)}"`);
    }
    // No `#: file:line` reference — keep the .pot a pure function of the
    // string set (issue #85). Translators key on msgid.
    lines.push(`msgid "${escapePo(entry.msgid)}"`);
    if (entry.msgidPlural) {
      lines.push(`msgid_plural "${escapePo(entry.msgidPlural)}"`);
      lines.push('msgstr[0] ""');
      lines.push('msgstr[1] ""');
    } else {
      lines.push('msgstr ""');
    }
  }
  const existing = readFileSync(dest, 'utf8');
  writeFileSync(dest, existing.trimEnd() + '\n' + lines.join('\n') + '\n');
  console.log(`✓ JS strings extracted (${jsEntries.size} unique) → appended to ${dest}`);
} else {
  console.log('ℹ No JS strings found.');
}

function escapePo(s) {
  // Escape backslash and double-quote for PO file string literals.
  return s.replace(/\\/g, '\\\\').replace(/"/g, '\\"').replace(/\n/g, '\\n');
}
