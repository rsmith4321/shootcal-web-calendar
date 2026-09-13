'use strict';
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const Reference = require('../assets/js/embed-reference.js');
let registration;
const wp = {
  element: { createElement: (type, props, ...children) => ({type, props: props || {}, children}), Fragment: 'Fragment' },
  blocks: { registerBlockType: (name, value) => { registration = value; } },
  i18n: { __: value => value },
  blockEditor: { InspectorControls: 'InspectorControls', PanelColorSettings: 'PanelColorSettings', useBlockProps: () => ({}) },
  components: Object.fromEntries(['TextControl','SelectControl','PanelBody','ToggleControl','Placeholder'].map(name => [name,name]))
};
function loadEditor(monthsDefault = 12) {
  vm.runInNewContext(fs.readFileSync(require.resolve('../assets/js/block-editor.js'), 'utf8'), {window: {wp, ShootCalEmbedReference:Reference, ShootCalWebCalendarBlock:{monthsDefault}}});
}
loadEditor();
function editor(attributes) {
  let update;
  const tree=registration.edit({attributes, setAttributes: value => { update=value; }});
  function find(label, root = tree) {
    function visit(node) {
      if (!node || typeof node !== 'object') return undefined;
      if (node.props && node.props.label === label) return node;
      for(const child of node.children || []) { const found=visit(child); if(found) return found; }
    }
    return visit(root);
  }
  return {find, inlineFind: label => find(label, tree.children[1]), sidebarFind: label => find(label, tree.children[0]), update: () => JSON.parse(JSON.stringify(update))};
}
const empty=editor({source:'auto',mode:'availability'});
assert.equal(empty.find('Calendar source').props.value,'shootcal');
assert.ok(empty.inlineFind('Calendar source'));
assert.ok(empty.inlineFind('ShootCal calendar ID'));
assert.equal(empty.sidebarFind('Calendar source'),undefined);
assert.equal(empty.sidebarFind('ShootCal calendar ID'),undefined);
assert.ok(empty.sidebarFind('ShootCal display'));
assert.equal(empty.find('Timezone override'),undefined);
const generic=editor({url:'https://example.com/feed.ics',mode:'availability'});
assert.equal(generic.find('Calendar source').props.value,'ical');
assert.ok(generic.inlineFind('Calendar source'));
assert.ok(generic.inlineFind('iCal feed URL'));
assert.equal(generic.sidebarFind('iCal feed URL'),undefined);
assert.ok(generic.find('Timezone override'));
assert.equal(generic.find('Months to show').props.value,'12');
const token='FixtureCalendar_123';
assert.equal(editor({calendarId:token,embedView:'calendar'}).find('Months to show').props.value,'12');
assert.equal(editor({calendarId:token,embedView:'calendar',months:4}).find('Months to show').props.value,'4');
assert.equal(editor({url:'https://api.shootcal.com/embed/'+token+'?view=calendar&months=6'}).find('Months to show').props.value,'6');
loadEditor(9);
assert.equal(editor({source:'ical',url:'https://example.com/feed.ics'}).find('Months to show').props.value,'9');
assert.equal(editor({calendarId:token,embedView:'calendar'}).find('Months to show').props.value,'12');
loadEditor();
const legacy=editor({url:'https://api.shootcal.com/embed/'+token+'?view=calendar&theme=dark&months=12&first_day=1&sc_card=abcdef'});
assert.equal(legacy.find('Calendar theme'),undefined);
assert.equal(legacy.find('Months to show').props.value,'12');
legacy.find('Months to show').props.onChange('');
assert.deepEqual(legacy.update(),{calendarId:token,url:'',embedParams:{first_day:'1',view:'calendar',theme:'dark',sc_card:'abcdef'}});
assert.equal(editor(legacy.update()).find('Months to show').props.value,'12');
const imported=editor({source:'shootcal'});
imported.find('ShootCal calendar ID').props.onChange('<script src="https://api.shootcal.com/embed.js" data-src="https://api.shootcal.com/embed/'+token+'?view=calendar&amp;theme=dark&amp;first_day=1"></script>');
assert.deepEqual(imported.update(),{source:'shootcal',calendarId:token,url:'',embedParams:{first_day:'1',view:'calendar',theme:'dark'},embedView:'calendar',theme:'dark',firstDay:1,mode:'availability'});
const switched=editor({source:'ical',url:'https://example.com/feed.ics',calendarId:token,embedParams:{mode:'full',view:'calendar'},mode:'availability'});
assert.equal(switched.find('Calendar source').props.value,'ical');
assert.equal(switched.find('Display mode').props.value,'availability');
assert.equal(switched.find('ShootCal display'),undefined);
// Switching sources keeps the other input available without presenting it in
// the wrong field. Editing a ShootCal ID must not erase the saved iCal URL.
let state={source:'ical',url:'https://example.com/private.ics',mode:'availability'};
let current=editor(state);
current.inlineFind('Calendar source').props.onChange('shootcal');
state={...state,...current.update()};
current=editor(state);
assert.equal(current.inlineFind('ShootCal calendar ID').props.value,'');
current.inlineFind('ShootCal calendar ID').props.onChange(token);
state={...state,...current.update()};
assert.equal(state.url,'https://example.com/private.ics');
current=editor(state);
current.inlineFind('Calendar source').props.onChange('ical');
state={...state,...current.update()};
current=editor(state);
assert.equal(current.inlineFind('iCal feed URL').props.value,'https://example.com/private.ics');
current.inlineFind('Calendar source').props.onChange('shootcal');
state={...state,...current.update()};
assert.equal(editor(state).inlineFind('ShootCal calendar ID').props.value,token);
// Legacy hosted URLs migrate before the iCal URL field is reused.
current=editor({url:'https://api.shootcal.com/embed/'+token+'?view=calendar&months=6'});
current.inlineFind('Calendar source').props.onChange('ical');
assert.deepEqual(current.update(),{source:'ical',calendarId:token,url:'',embedParams:{months:'6',view:'calendar'}});
assert.equal(editor(current.update()).inlineFind('iCal feed URL').props.value,'');
// An explicitly selected iCal feed on ShootCal's host remains a feed until the
// user switches, and is retained if they switch back.
state={source:'ical',url:'https://feed.shootcal.com/'+token+'.ics'};
current=editor(state);
current.inlineFind('Calendar source').props.onChange('shootcal');
assert.equal(current.update().url,state.url);
assert.equal(current.update().calendarId,token);
console.log('Block editor source, inline controls, and import tests passed.');
