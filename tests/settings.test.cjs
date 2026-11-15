'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const path = require('node:path');
const code = fs.readFileSync(path.join(__dirname, '../src/usr/local/emhttp/plugins/silo-streams/assets/settings.js'), 'utf8');

function setup(response) {
  let submit, request;
  const result = {textContent:''};
  const key = {value:'sa_fixture_only'};
  const buttons = [{disabled:false},{disabled:false}];
  const help = {textContent:''};
  const form = {
    dataset:{},
    // Named buttons shadow the native form.action property in browsers.
    action:{type:'RadioNodeList'},
    fields:{csrf_token:'fixture_csrf',server_url:'http://silo.test',api_key:key.value,poll_interval:'5'},
    getAttribute(name){return name==='action'?'/plugins/silo-streams/api.php':null;},
    addEventListener(name,fn){if(name==='submit')submit=fn;},
    querySelectorAll(selector){return selector==='button'?buttons:[];},
    elements:{namedItem(name){return name==='api_key'?key:{setAttribute(){}};}}
  };
  class FormDataFixture extends Map { constructor(form){super(Object.entries(form.fields));} }
  vm.runInNewContext(code, {
    document:{getElementById(id){return id==='silo-streams-settings'?form:id==='ss-settings-result'?result:help;}},
    FormData:FormDataFixture, URLSearchParams, AbortController, setTimeout, clearTimeout,
    async fetch(url,options){request={url,options};return {ok:response.ok,json:async()=>response};}
  });
  return {async submit(action){await submit({preventDefault(){},submitter:{value:action}});return request;},key,result,buttons,help};
}

test('save carries independent CSRF proof after Unraid consumes the standard field', async()=>{
  const state=setup({ok:true,message:'Settings saved.'});
  const {url,options}=await state.submit('save');
  assert.equal(url,'/plugins/silo-streams/api.php');
  assert.equal(options.credentials,'same-origin');
  assert.equal(options.body.get('csrf_token'),'fixture_csrf');
  const afterPrepend=new URLSearchParams(options.body);
  afterPrepend.delete('csrf_token');
  assert.equal(afterPrepend.get('silo_csrf_token'),'fixture_csrf');
  assert.equal(afterPrepend.get('action'),'save');
  assert.equal(state.key.value,'');
  assert.equal(state.result.textContent,'Settings saved.');
  assert.ok(state.buttons.every(button=>!button.disabled));
});

test('failed save keeps entered key for correction and reports the failure', async()=>{
  const state=setup({ok:false,error:{message:'Refresh the Unraid page and try again.'}});
  await state.submit('save');
  assert.equal(state.key.value,'sa_fixture_only');
  assert.match(state.result.textContent,/Refresh/);
  assert.ok(state.buttons.every(button=>!button.disabled));
});

test('connection test carries both CSRF fields and keeps the unsaved key', async()=>{
  const state=setup({ok:true,message:'Connected.'});
  const {options}=await state.submit('test');
  assert.equal(options.body.get('action'),'test');
  assert.equal(options.body.get('silo_csrf_token'),options.body.get('csrf_token'));
  assert.equal(state.key.value,'sa_fixture_only');
});
