const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const frames = [0, 1].map(() => ({ contentWindow: {}, style: {}, getBoundingClientRect: () => ({top: -120}) }));
const handlers = {};
const scrolls = [];
vm.runInNewContext(fs.readFileSync(require.resolve('../assets/js/embed.js'), 'utf8'), {
  window: {addEventListener: (name, fn) => handlers[name] = fn, scrollBy: args => scrolls.push(args)},
  document: {querySelectorAll: () => frames},
});
const message = (source, data, origin = 'https://api.shootcal.com') => handlers.message({source, data, origin});
message(frames[1].contentWindow, {shootcalEmbed: 1, height: 810.2});
assert.equal(frames[1].style.height, '811px');
assert.equal(frames[0].style.height, undefined);
message(frames[0].contentWindow, {shootcalEmbed: 1, height: 450});
assert.equal(frames[0].style.height, '450px');
// Session grid -> selected session -> All sessions: the child must be able to
// grow back after the shorter booking view without resizing another instance.
for (const height of [812, 742, 812]) {
  message(frames[0].contentWindow, {shootcalEmbed: 1, height});
  assert.equal(frames[0].style.height, height + 'px');
  assert.equal(frames[1].style.height, '811px');
}
for (const height of [Infinity, NaN, -10, 0, 20001, '810']) message(frames[1].contentWindow, {shootcalEmbed: 1, height});
message({}, {shootcalEmbed: 1, height: 1000});
message(frames[1].contentWindow, {shootcalEmbed: 1, height: 1000}, 'https://evil.example');
assert.equal(frames[1].style.height, '811px');
message(frames[1].contentWindow, {shootcalEmbed: 1, height: 390, scrollToTop: true});
assert.equal(frames[1].style.height, '390px');
assert.deepEqual(JSON.parse(JSON.stringify(scrolls)), [{top: -136, behavior: 'auto'}]);
console.log('PASS: independent frames, shrinking, origin/source checks, bounded height, and booking scroll');
