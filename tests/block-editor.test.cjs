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
vm.runInNewContext(fs.readFileSync(require.resolve('../assets/js/block-editor.js'), 'utf8'), {window: {wp, ShootCalEmbedReference:Reference}});
function editor(attributes) {
  let update;
  const tree=registration.edit({attributes, setAttributes: value => { update=value; }});
  function find(label) {
    function visit(node) {
      if (!node || typeof node !== 'object') return undefined;
      if (node.props && node.props.label === label) return node;
      for(const child of node.children || []) { const found=visit(child); if(found) return found; }
    }
    return visit(tree);
  }
  return {find, update: () => JSON.parse(JSON.stringify(update))};
}
const empty=editor({source:'auto',mode:'availability'});
assert.equal(empty.find('Calendar source').props.value,'shootcal');
assert.ok(empty.find('ShootCal calendar ID'));
assert.equal(empty.find('Timezone override'),undefined);
const generic=editor({url:'https://example.com/feed.ics',mode:'availability'});
assert.equal(generic.find('Calendar source').props.value,'ical');
assert.ok(generic.find('iCal feed URL'));
assert.ok(generic.find('Timezone override'));
const token='FixtureCalendar_123';
const legacy=editor({url:'https://api.shootcal.com/embed/'+token+'?view=calendar&theme=dark&months=12&first_day=1&sc_card=abcdef'});
assert.equal(legacy.find('Calendar theme'),undefined);
assert.equal(legacy.find('Months to show').props.value,'12');
legacy.find('Months to show').props.onChange('');
assert.deepEqual(legacy.update(),{calendarId:token,url:'',embedParams:{first_day:'1',view:'calendar',theme:'dark',sc_card:'abcdef'}});
const imported=editor({source:'shootcal'});
imported.find('ShootCal calendar ID').props.onChange('<script src="https://api.shootcal.com/embed.js" data-src="https://api.shootcal.com/embed/'+token+'?view=calendar&amp;theme=dark&amp;first_day=1"></script>');
assert.deepEqual(imported.update(),{source:'shootcal',calendarId:token,url:'',embedParams:{first_day:'1',view:'calendar',theme:'dark'},embedView:'calendar',theme:'dark',firstDay:1,mode:'availability'});
const switched=editor({source:'ical',url:'https://example.com/feed.ics',calendarId:token,embedParams:{mode:'full',view:'calendar'},mode:'availability'});
assert.equal(switched.find('Calendar source').props.value,'ical');
assert.equal(switched.find('Display mode').props.value,'availability');
assert.equal(switched.find('ShootCal display'),undefined);
console.log('Block editor source and import tests passed.');
