'use strict';
const assert = require('node:assert/strict');
const { parse, cleanQuery } = require('../assets/js/embed-reference.js');
const token = 'FixtureCalendar_123';
const url = 'https://api.shootcal.com/embed/' + token;
assert.deepEqual(parse(token), { token, query: {} });
assert.deepEqual(parse(url + '?months=12&mode=full&first_day=1&view=calendar&theme=dark&sc_card=ffffff'), {
  token, query: { months: '12', mode: 'full', first_day: '1', view: 'calendar', theme: 'dark', sc_card: 'ffffff' }
});
assert.deepEqual(parse('http://feed.shootcal.com/' + token + '.ics?months=12'), { token, query: {} });
assert.deepEqual(parse('<iframe src="' + url + '?mode=full&amp;first_day=1"></iframe>'), { token, query: { mode: 'full', first_day: '1' } });
assert.deepEqual(parse('<script src="https://api.shootcal.com/embed.js" data-src="' + url + '?mode=full&amp;first_day=1" async></script>'), { token, query: { mode: 'full', first_day: '1' } });
assert.deepEqual(parse('<script async data-shootcal="' + token + '" src="https://api.shootcal.com/embed.js"></script>'), { token, query: {} });
for (const input of [
  '', 'short', 'a'.repeat(129), '<script>alert(1)</script>',
  '<script src="https://evil.example/embed.js" data-src="' + url + '"></script>',
  '<script src="https://api.shootcal.com/embed.js" data-src="https://evil.example/embed/' + token + '"></script>',
  '<iframe src="' + url + '" src="https://evil.example/"></iframe>',
  'https://evil.example/?next=' + url, url + '/extra', url + '#fragment',
  'https://api.shootcal.com.evil.example/embed/' + token,
  'https://user:password@api.shootcal.com/embed/' + token,
  'https://api.shootcal.com:8443/embed/' + token,
  'https://api.shootcal.com:443/embed/' + token,
  'https://api.shootcal.com/book/' + token,
  '<iframe data-src="' + url + '"></iframe>',
  'https:\\api.shootcal.com\\embed\\' + token
]) assert.equal(parse(input), null, input);
assert.deepEqual(cleanQuery({ months: '999', mode: ['full'], first_day: '0', theme: 'bogus', view: 'calendar', sc_ink: 'abc123', sc_font: 'var(--x)', unknown: 'no' }), { months: '36', first_day: '0', view: 'calendar', sc_ink: 'abc123' });
assert.deepEqual(parse(url + '?mode[]=full&theme=light&mode=availability'), { token, query: { mode: 'availability', theme: 'light' } });
console.log('Embed reference tests passed.');
