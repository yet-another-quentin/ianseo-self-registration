// Translations and release versioning: node --test tests/*.test.js
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('fs');
const path = require('path');
const I = require('../SelfRegistration/i18n.js');
const { nightlyVersion } = require('../scripts/nightly-version.js');

const dir = path.join(__dirname, '../SelfRegistration');
const read = f => fs.readFileSync(path.join(dir, f), 'utf8');

test('French and English dictionaries have the same keys', () => {
  assert.deepEqual(Object.keys(I.DICTS.fr).sort(), Object.keys(I.DICTS.en).sort());
});

test('every message key used by the pages and the APIs exists', () => {
  const js = ['register.js', 'admin.js'].map(read).join('\n');
  const php = ['register-api.php', 'security.php', 'core.php', 'lib.php'].map(read).join('\n');
  const used = new Set();
  for (const m of js.matchAll(/\b(?:th|t)\('([a-z]+\.[\w.]+)'/g)) used.add(m[1]);
  for (const m of (js + php).matchAll(/'(err\.\w+)'/g)) used.add(m[1]);
  // keys built at runtime ('session.' + reason…) end with a dot and are checked below
  const missing = [...used].filter(k => !k.endsWith('.') && !(k in I.DICTS.en));
  assert.deepEqual(missing, []);
});

test('keys built at runtime exist', () => {
  const keys = [
    ...['closed', 'deadline', 'over', 'unavailable'].map(s => 'state.' + s),        // register.php states
    ...['full', 'quota', 'already'].map(s => 'session.' + s),                        // sr_availability() reasons
    ...['deadline', 'over'].map(s => 'alert.closed.' + s),                           // sr_closed_reason()
    ...['deadline', 'over', 'closed'].map(s => 'err.' + s),                          // register-api.php
    ...['pending', 'waitlist', 'validated', 'refused', 'all'].map(s => 'requests.' + s),
    ...['pending', 'waitlist', 'validated', 'refused'].map(s => 'requests.st.' + s),
    ...['high', 'normal', 'low'].map(s => 'priority.' + s),                          // waiting-list priorities
    'update.channel.stable', 'update.channel.nightly',
    ...['received', 'validated', 'promoted', 'refused', 'organiser'].map(s => 'mails.' + s),     // email settings
  ];
  assert.deepEqual(keys.filter(k => !(k in I.DICTS.en)), []);
});

test('language follows IANSEO: French for fr*, English otherwise', () => {
  assert.equal(I.make('fr').lang, 'fr');
  assert.equal(I.make('fr-ca').lang, 'fr');
  assert.equal(I.make('it').lang, 'en');
  assert.equal(I.make('').lang, 'en');
});

test('placeholders are replaced, missing keys fall back to the key', () => {
  const fr = I.make('fr');
  assert.equal(fr.t('session.quotaFree', { n: 2, div: 'CO' }), '2 place(s) restante(s) en CO');
  assert.equal(fr.t('nope.key'), 'nope.key');
});

test('nightly version follows the last stable tag', () => {
  assert.equal(nightlyVersion('v1.2.3', '20261004', 'abc1234'), '1.2.4-nightly.20261004.abc1234');
  assert.equal(nightlyVersion('', '20261004', 'abc1234'), '0.0.1-nightly.20261004.abc1234');
});
