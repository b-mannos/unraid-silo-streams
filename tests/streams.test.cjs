'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const streams = require('../src/usr/local/emhttp/plugins/silo-streams/assets/streams.js');
class Element {
  constructor(doc,tag){this.ownerDocument=doc;this.tagName=tag;this.children=[];this.attributes={};this.style={};this.className='';this._text='';this.classList={add:name=>{this.className+=' '+name;}};}
  set textContent(text){this._text=String(text);this.children=[];} get textContent(){return this._text+this.children.map(x=>x.textContent).join('');}
  append(...items){this.children.push(...items);} replaceChildren(...items){this._text='';this.children=items;}
  get firstChild(){return this.children[0];} setAttribute(k,v){this.attributes[k]=v;} removeAttribute(k){delete this.attributes[k];}
  addEventListener(){} remove(){}
  querySelectorAll(selector){const name=selector.slice(1);return this.children.flatMap(el=>[...(el.className.split(' ').includes(name)?[el]:[]),...el.querySelectorAll(selector)]);}
  querySelector(selector){return this.querySelectorAll(selector)[0]||null;}
}
function environment(){
 const timers=new Map();let timerId=0;
 const win={setTimeout(fn,delay){timers.set(++timerId,{fn,delay});return timerId;},clearTimeout(id){timers.delete(id);},setInterval(fn){win.tick=fn;return 1000;},clearInterval(){},fetch:null};
 const doc={hidden:false,defaultView:win,createElement(tag){return new Element(doc,tag);},createElementNS(ns,tag){return new Element(doc,tag);},addEventListener(name,fn){doc[name]=fn;},removeEventListener(name){delete doc[name];}};
 return {root:new Element(doc,'div'),doc,win,timers};
}
const sample=require('../preview/sample.json');
const flush=()=>new Promise(resolve=>setImmediate(resolve));
test('formatters preserve unknown and zero values',()=>{
 assert.equal(streams.clock(null),'Unknown');assert.equal(streams.clock(0),'0:00');assert.equal(streams.clock(3661),'1:01:01');assert.equal(streams.clock(-1),'Unknown');
 assert.equal(streams.bitrate(null),'Unknown bitrate');assert.equal(streams.bitrate(0),'0 Mbps');assert.equal(streams.bitrate(8200),'8.2 Mbps');
 assert.match(streams.summaryText({session_count:2,known_bitrate_count:1,known_bitrate_kbps:8000,bitrate_complete:false}),/8 Mbps known subtotal · total unknown/);
 assert.match(streams.summaryText({session_count:2,known_bitrate_count:0,known_bitrate_kbps:0,bitrate_complete:false}),/Total bitrate unknown/);
});
test('poster URLs reject script schemes, credentials and unsafe relative paths',()=>{
 for(const value of ['javascript:alert(1)','data:image/svg+xml,a','https://user:secret@example.com/a','//example.com/a','../private.svg','posters/../../secret.svg','https://example.com/a\n'])assert.equal(streams.safePoster(value),null);
 assert.equal(streams.safePoster('posters/orbit.svg'),'posters/orbit.svg');assert.equal(streams.safePoster('https://example.com/poster.jpg'),'https://example.com/poster.jpg');
 assert.equal(streams.safePoster('/plugins/silo-streams/poster.php?session_id=one%2Btwo'),'/plugins/silo-streams/poster.php?session_id=one%2Btwo');
 for(const value of ['/plugins/silo-streams/poster.php?url=https://evil.test/a','/plugins/silo-streams/poster.php?session_id=x&url=https://evil.test/a','//evil.test/plugins/silo-streams/poster.php?session_id=x'])assert.equal(streams.safePoster(value),null);
});
test('renderer uses text, handles specials, paused state, duplicates and unknown values',()=>{
 const {root}=environment();const payload=structuredClone(sample);payload.items[0].series_name='<img src=x onerror=alert(1)>';payload.items[0].season_number=0;payload.items.push({...payload.items[0]});
 streams.render(root,payload);
 assert.equal(root.querySelectorAll('.silo-streams__row').length,3);
 assert.match(root.textContent,/<img src=x onerror=alert\(1\)>/);assert.match(root.textContent,/S00/);
 assert.equal(root.querySelectorAll('.silo-streams__state')[1].attributes['aria-label'],'Paused');
 payload.items[0].position_seconds=null;payload.items[0].file_duration=null;streams.render(root,payload);
 assert.equal(root.querySelector('.silo-streams__time').textContent,'Unknown / Unknown');
 assert.equal(root.querySelector('.silo-streams__progress').attributes['aria-valuetext'],'Unknown duration or position');
});
test('polling waits for completion, retains cards on failure and freezes clocks',async()=>{
 const {root,win,timers}=environment();let finish;let requests=0;
 win.fetch=()=>{requests++;return new Promise(resolve=>{finish=resolve;});};
 const stop=streams.mount(root,{endpoint:'/sample'});
 assert.equal(requests,1);assert.equal([...timers.values()].filter(x=>x.delay===5000).length,0);
 finish({ok:true,json:async()=>structuredClone(sample)});await flush();
 assert.equal(root.querySelectorAll('.silo-streams__row').length,2);
 assert.equal([...timers.values()].filter(x=>x.delay===5000).length,1);
 const next=[...timers.values()].find(x=>x.delay===5000);win.fetch=async()=>{throw Error('offline');};next.fn();await flush();
 assert.match(root.querySelector('.silo-streams__status').textContent,/last received streams/);
 const frozen=root.querySelector('.silo-streams__time').textContent;win.tick();assert.equal(root.querySelector('.silo-streams__time').textContent,frozen);
 assert.equal(root.querySelectorAll('.silo-streams__row').length,2);stop();
});
test('hidden tabs do not start requests and resume on visibility',async()=>{
 const {root,doc,win}=environment();doc.hidden=true;let requests=0;win.fetch=async()=>{requests++;return{ok:true,json:async()=>sample};};
 const stop=streams.mount(root,{});assert.equal(requests,0);doc.hidden=false;doc.visibilitychange();await flush();assert.equal(requests,1);stop();
});
