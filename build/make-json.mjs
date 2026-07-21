/**
 * Generate per-locale JS translation JSON files from a compiled .po.
 *
 * WordPress' wp_set_script_translations() expects a file named:
 *   languages/<domain>-<locale>-<domain>.json
 * (e.g. languages/oxpulse-imager-ru_RU-oxpulse-imager.json) containing
 * a Jed-formatted JSON object:
 *   { "locale": "ru_RU", "domain": "oxpulse-imager",
 *     "locale_data": { "oxpulse-imager": { "": { "domain": "...",
 *       "lang": "ru_RU", "plural-forms": "nplurals=3; plural=(n%10==1 && n%100!=11 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 || n%100>=20) ? 1 : 2);" },
 *       "Source string": ["Translation", null, null] }, ... } }
 *
 * This script parses a .po file (compiled by msgfmt or hand-edited) and
 * emits the JSON file. Only entries whose #: references point at JS
 * files (src/admin/) are included — PHP-only strings bloat the JS
 * payload and are not needed at runtime (PHP translates its own strings
 * server-side via load_plugin_textdomain + .mo).
 *
 * Run: npm run make-json -- --locale=ru_RU
 *      (or npm run make-json to process all .po files in languages/)
 */

import { readFileSync, writeFileSync, readdirSync, existsSync } from 'fs';
import { resolve, basename, join } from 'path';

const ROOT = process.cwd();
const LANG_DIR = resolve(ROOT, 'languages');
const DOMAIN = 'oxpulse-imager';

// Parse CLI args: --locale=ru_RU
const argLocale = process.argv
  .find((a) => a.startsWith('--locale='))
  ?.split('=')[1];

/** Parse a .po file into a list of entries. Each entry is:
 *   { msgctxt?, msgid, msgidPlural?, msgstr (string or string[]), references: string[] }
 */
function parsePo(path) {
  const src = readFileSync(path, 'utf8');
  const entries = [];
  let current = null;
  let lastField = null; // which field we're continuing on multi-line

  const lines = src.split('\n');
  for (let i = 0; i < lines.length; i++) {
    let line = lines[i];
    // Strip trailing CR.
    if (line.endsWith('\r')) line = line.slice(0, -1);

    if (line === '') {
      // Blank line = entry separator.
      if (current) {
        entries.push(current);
        current = null;
        lastField = null;
      }
      continue;
    }

    if (line.startsWith('#:')) {
      // Reference line. May contain multiple space-separated paths.
      if (!current) current = { references: [] };
      const refs = line.slice(2).trim().split(/\s+/);
      current.references.push(...refs);
      lastField = 'ref';
      continue;
    }

    if (line.startsWith('msgctxt ')) {
      if (!current) current = { references: [] };
      current.msgctxt = unquote(line.slice('msgctxt '.length));
      lastField = 'msgctxt';
      continue;
    }

    if (line.startsWith('msgid ')) {
      if (!current) current = { references: [] };
      current.msgid = unquote(line.slice('msgid '.length));
      lastField = 'msgid';
      continue;
    }

    if (line.startsWith('msgid_plural ')) {
      if (!current) current = { references: [] };
      current.msgidPlural = unquote(line.slice('msgid_plural '.length));
      lastField = 'msgid_plural';
      continue;
    }

    if (line.startsWith('msgstr ')) {
      if (!current) current = { references: [] };
      current.msgstr = unquote(line.slice('msgstr '.length));
      lastField = 'msgstr';
      continue;
    }

    if (line.startsWith('msgstr[')) {
      if (!current) current = { references: [] };
      if (!Array.isArray(current.msgstr)) current.msgstr = [];
      const m = line.match(/^msgstr\[(\d+)\] (.*)$/);
      if (m) {
        const idx = parseInt(m[1], 10);
        current.msgstr[idx] = unquote(m[2]);
      }
      lastField = 'msgstr_arr';
      continue;
    }

    // Continuation line: a bare quoted string.
    if (line.startsWith('"') && lastField) {
      const piece = unquote(line);
      if (lastField === 'msgid' && current) {
        current.msgid += piece;
      } else if (lastField === 'msgid_plural' && current) {
        current.msgidPlural += piece;
      } else if (lastField === 'msgctxt' && current) {
        current.msgctxt += piece;
      } else if (lastField === 'msgstr' && current) {
        current.msgstr += piece;
      } else if (lastField === 'msgstr_arr' && current && Array.isArray(current.msgstr)) {
        // Append to the last non-empty slot. We don't know which slot
        // this continues — but in practice msgstr[] continuations are
        // rare for our generated .po files.
        for (let k = current.msgstr.length - 1; k >= 0; k--) {
          if (current.msgstr[k] !== undefined) {
            current.msgstr[k] += piece;
            break;
          }
        }
      }
    }
  }
  if (current) entries.push(current);
  return entries;
}

/** Unquote a PO-file string literal: "foo \"bar\"" → foo "bar". */
function unquote(s) {
  s = s.trim();
  if (!s.startsWith('"') || !s.endsWith('"')) return s;
  s = s.slice(1, -1);
  return s
    .replace(/\\n/g, '\n')
    .replace(/\\t/g, '\t')
    .replace(/\\r/g, '\r')
    .replace(/\\"/g, '"')
    .replace(/\\\\/g, '\\');
}

/** Extract the locale from a filename like oxpulse-imager-ru_RU.po. */
function localeFromFilename(name) {
  const m = name.match(new RegExp(`^${DOMAIN}-([a-z]{2,3}_[A-Z]{2,3})\\.po$`));
  return m ? m[1] : null;
}

/** Parse the plural-forms header from the .po (first entry with empty msgid). */
function parseHeaders(entries) {
  const headerEntry = entries.find((e) => e.msgid === '');
  if (!headerEntry || typeof e_msgstr !== 'string') return {};
  const headers = {};
  for (const line of (headerEntry.msgstr || '').split('\n')) {
    const idx = line.indexOf(':');
    if (idx > 0) {
      headers[line.slice(0, idx).trim().toLowerCase()] = line.slice(idx + 1).trim();
    }
  }
  return headers;
}

/** Build the Jed-formatted JSON for a locale from a list of PO entries. */
function buildJedJson(locale, entries, pluralForms) {
  const localeData = {
    '': {
      domain: DOMAIN,
      lang: locale,
      'plural-forms': pluralForms || 'nplurals=2; plural=(n != 1);',
    },
  };

  for (const e of entries) {
    if (e.msgid === '') continue; // header
    const key = e.msgctxt ? e.msgctxt + '\u0004' + e.msgid : e.msgid;
    if (e.msgidPlural) {
      // Plural: array of translations, indexed by plural form.
      const arr = Array.isArray(e.msgstr) ? e.msgstr : [e.msgstr, e.msgstr];
      // Pad to nplurals.
      const nplurals = (pluralForms.match(/nplurals=(\d+)/) || [])[1] | 0 || 2;
      while (arr.length < nplurals) arr.push('');
      localeData[key] = arr;
    } else {
      // Singular: [translation] — Jed expects an array even for singulars.
      const tr = typeof e.msgstr === 'string' ? e.msgstr : '';
      localeData[key] = [tr];
    }
  }

  return {
    translation: { [DOMAIN]: localeData },
    domain: DOMAIN,
    locale_data: {
      [DOMAIN]: localeData,
    },
  };
}

/** Filter entries to those referenced only by JS files (src/admin/). */
function jsOnlyEntries(entries) {
  return entries.filter((e) =>
    (e.references || []).some((r) => r.includes('src/admin/'))
  );
}

function processPo(poPath, locale) {
  console.log(`Processing ${poPath} (locale=${locale})…`);
  const entries = parsePo(poPath);
  const headers = parseHeadersEntries(entries);
  const pluralForms = headers['plural-forms'] || 'nplurals=2; plural=(n != 1);';

  const jsEntries = jsOnlyEntries(entries);
  console.log(`  ${entries.length} total entries, ${jsEntries.length} JS-only.`);

  const json = buildJedJson(locale, jsEntries, pluralForms);
  const outPath = join(LANG_DIR, `${DOMAIN}-${locale}-${DOMAIN}.json`);
  writeFileSync(outPath, JSON.stringify(json, null, 2) + '\n');
  console.log(`✓ Wrote ${outPath}`);
}

// Helper: parse plural-forms from the header entry (msgid="").
function parseHeadersEntries(entries) {
  const headerEntry = entries.find((e) => e.msgid === '');
  if (!headerEntry) return {};
  const msgstr = typeof headerEntry.msgstr === 'string' ? headerEntry.msgstr : '';
  const headers = {};
  for (const line of msgstr.split('\n')) {
    const idx = line.indexOf(':');
    if (idx > 0) {
      headers[line.slice(0, idx).trim().toLowerCase()] = line.slice(idx + 1).trim();
    }
  }
  return headers;
}

// --- Main ---
if (argLocale) {
  const poPath = join(LANG_DIR, `${DOMAIN}-${argLocale}.po`);
  if (!existsSync(poPath)) {
    console.error(`✗ ${poPath} not found`);
    process.exit(1);
  }
  processPo(poPath, argLocale);
} else {
  // Process all .po files in languages/.
  const files = readdirSync(LANG_DIR).filter(
    (f) => f.startsWith(`${DOMAIN}-`) && f.endsWith('.po') && !f.endsWith('.pot')
  );
  if (files.length === 0) {
    console.error('✗ No .po files found. Pass --locale=ru_RU or create one first.');
    process.exit(1);
  }
  for (const f of files) {
    const locale = localeFromFilename(f);
    if (locale) processPo(join(LANG_DIR, f), locale);
  }
}
