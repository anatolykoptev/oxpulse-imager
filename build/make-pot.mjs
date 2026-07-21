/**
 * Generate the .pot file for translations.
 *
 * Uses the wp-pot library to scan PHP files for gettext calls
 * (__('text', 'oxpulse-imager'), __('text', 'oxpulse-imager'), etc.)
 * and writes a POT file to languages/oxpulse-imager.pot.
 *
 * Run: npm run make-pot
 */

import { WP_Pot } from 'wp-pot';
import { mkdirSync } from 'fs';
import { dirname, resolve } from 'path';

const dest = resolve(process.cwd(), 'languages/oxpulse-imager.pot');

// Ensure the languages/ directory exists.
mkdirSync(dirname(dest), { recursive: true });

const pot = new WP_Pot({
  pot: {
    package: 'OXPulse Imager',
    domain: 'oxpulse-imager',
    lastTranslator: 'Anatoly Koptev <koptev@koptev.org>',
    team: 'Anatoly Koptev <koptev@koptev.org>',
  },
});

pot.parse(['src/**/*.php', 'oxpulse-imager.php']);
pot.writePot(dest);

console.log(`✓ Generated ${dest}`);