import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';

const launcher=readFileSync(new URL('../../resources/support-chat/support-widget.js',import.meta.url),'utf8');

function loadPage(){
 const origin='https://novinpaypos.test',listeners={},worker={},received=[];
 let resolveConfig;
 const makeElement=tag=>({
  tag,dataset:{},attrs:{},style:{removeProperty(){}},append(){},addEventListener(){},
  setAttribute(key,value){this.attrs[key]=value;},focus(){this.focused=true;},
 });
 const button=makeElement('button'),frame=makeElement('iframe');
 frame.contentWindow={postMessage:event=>received.push(event)};
 const add=(target,name,fn)=>(target[name]??=[]).push(fn);
 const context={
  URL,location:new URL(origin+'/?support_chat=1'),innerWidth:1200,window:{},
  document:{currentScript:{src:origin+'/build/support-chat/test/support-widget.js'},querySelector:()=>null,
   createElement:tag=>tag==='button'?button:tag==='iframe'?frame:makeElement(tag),createElementNS:(_,tag)=>makeElement(tag),
   head:makeElement('head'),body:makeElement('body'),addEventListener(){},hidden:false},
  sessionStorage:{getItem(){throw Error('Open state must not be read');},setItem(){throw Error('Open state must not be stored');}},
  fetch:()=>new Promise(resolve=>{resolveConfig=()=>resolve({json:async()=>({customer_v2:true,name:'NovinPay'})});}),
  addEventListener:(name,fn)=>add(listeners,name,fn),navigator:{serviceWorker:{addEventListener:(name,fn)=>add(worker,name,fn)}},
  setTimeout:fn=>{fn();return 1;},clearTimeout(){},requestAnimationFrame:fn=>{fn();return 1;},matchMedia:()=>({matches:true}),
 };
 vm.runInNewContext(launcher,context);
 return {button,frame,received,resolveConfig,message:data=>listeners.message.forEach(fn=>fn({origin,source:frame.contentWindow,data})),
  pageShow:()=>listeners.pageshow.forEach(fn=>fn({persisted:true})),notify:()=>worker.message.forEach(fn=>fn({data:{type:'support-customer-open'}}))};
}

test('launcher alone opens a newly loaded or restored page; async startup and notifications preserve the closed state',async()=>{
 const first=loadPage();
 assert.equal(first.frame.hidden,true);
 first.button.onclick();
 assert.equal(first.frame.hidden,false);
 assert.equal(first.button.attrs['aria-expanded'],'true');
 first.pageShow();
 assert.equal(first.frame.hidden,true);
 const reload=loadPage();
 assert.equal(reload.frame.hidden,true);
 reload.resolveConfig();await new Promise(resolve=>setImmediate(resolve));
 reload.message({type:'support-widget-ready'});
 reload.message({type:'support-unread',count:2});
 reload.message({type:'support-customer-open'});reload.notify();
 assert.equal(reload.frame.hidden,true);
 assert.match(reload.button.attrs['aria-label'],/[۲2] پیام خوانده‌نشده/);
 assert.ok(reload.received.some(event=>event.type==='support-widget-attention'));
 reload.button.onclick();assert.equal(reload.frame.hidden,false);
 reload.button.onclick();assert.equal(reload.frame.hidden,true);
});
