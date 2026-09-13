'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const Reference = require('../assets/js/embed-reference.js');
const fields = new Map(), handlers = [], document = {};
function field(selector) {
  if (!fields.has(selector)) fields.set(selector, {id: selector.slice(1), value: '', checked: true});
  return fields.get(selector);
}
function $(input) {
  if (typeof input === 'function') { input(); return; }
  const node = typeof input === 'string' ? field(input) : input;
  const result = {
    val(value) { if (!arguments.length) return node.value; node.value = String(value); return result; },
    text() { return result; }, toggle() { return result; }, hide() { return result; }, show() { return result; },
    removeClass() { return result; }, addClass() { return result; }, prop() { return result; }, css() { return result; },
    closest() { return result; }, find() { return result; }, is() { return node.checked; },
    on(events, selectors, callback) { handlers.push({events:events.split(' '),selectors:selectors.split(', '),callback}); return result; }
  };
  return result;
}
$.post = () => {
  const result = {done(callback) { callback({success:true,data:{message:'Fixture feed checked'}}); return result; },fail() { return result; },always(callback) { callback(); return result; }};
  return result;
};
function change(id, value) {
  field('#' + id).value = value;
  trigger(id, 'change');
}
function trigger(id, event) {
  for (const handler of handlers) if (handler.events.includes(event) && handler.selectors.includes('#' + id)) {
    handler.callback.call(field('#' + id), {preventDefault() {}});
  }
}
field('#shootcal-gen-source').value = 'shootcal';
field('#shootcal-gen-view').value = 'default';
vm.runInNewContext(fs.readFileSync(require.resolve('../assets/js/admin.js'), 'utf8'), {
  window:{ShootCalWebCalendar:{monthsDefault:6,i18n:{}},ShootCalEmbedReference:Reference},jQuery:$,document,URL,navigator:{}
});
const months = () => field('#shootcal-gen-months').value;
const shortcode = () => { trigger('shootcal-generate', 'click'); return field('#shootcal-gen-shortcode').value; };
const token = 'FixtureCalendar_123';
assert.equal(months(), '12', 'new hosted generator displays 12');
change('shootcal-gen-view', 'calendar');
change('shootcal-gen-calendar-id', token);
assert.match(shortcode(), /months="12"/, 'bare ID generates 12 months');
assert.match(shortcode(), /view="calendar"/, 'entering an ID preserves an explicitly chosen calendar-only view');
change('shootcal-gen-source', 'ical');
assert.equal(months(), '6', 'generic source displays the saved site default');
change('shootcal-gen-url', 'https://example.com/calendar.ics');
assert.match(shortcode(), /months="6"/, 'generic generated output matches the displayed default');
change('shootcal-gen-months', '3');
change('shootcal-gen-source', 'shootcal');
change('shootcal-gen-calendar-id', 'AnotherFixture123');
assert.equal(months(), '3', 'entering a bare ID preserves chosen months');
assert.match(shortcode(), /months="3"/);
change('shootcal-gen-calendar-id', '<script src="https://api.shootcal.com/embed.js" data-src="https://api.shootcal.com/embed/'+token+'?view=calendar&amp;months=8"></script>');
assert.equal(months(), '8', 'imported script months are retained');
assert.match(shortcode(), /months="8"/);
change('shootcal-gen-months', '');
assert.equal(months(), '12', 'clearing an override restores the hosted default');
assert.match(shortcode(), /months="12"/);
change('shootcal-gen-source', 'ical');
assert.equal(months(), '6', 'cleared override restores saved generic default on source change');
console.log('Admin generator month defaults, explicit choices, and imported script tests passed.');
