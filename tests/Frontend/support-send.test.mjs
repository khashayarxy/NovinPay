import {test} from 'node:test';
import assert from 'node:assert/strict';
import {ChatEngine} from '../../resources/support-chat/support-client/engine.js';
import {ConversationSync} from '../../resources/support-chat/support-client/network.js';
import {uuid, validUUID} from '../../resources/support-chat/support-client/outbox.js';

globalThis.document={hidden:false};
function fixture({fresh=true,role='customer',attempts=0,epoch='epoch'}={}){
 const calls=[],row={key:'key',uuid:'fixture',conversation:'conversation',scope:'scope',epoch,role,state:'pending',attempts,created:Date.now(),nextAttempt:0,leaseUntil:0,text:'synthetic draft'};
 const context={scope_id:'scope',session_epoch:'epoch'},engine=Object.create(ChatEngine.prototype);
 const ack={message_id:1,accepted_at:new Date().toISOString(),client_message_id:row.uuid};
 Object.assign(engine,{role,context,scope:'scope',freshSubmissions:new Set(fresh?[row.key]:[]),knownAcks:new Map(),ackSamples:[],hooks:{},sendWait:()=>0,renderQueue:async()=>{},scheduleSender:()=>{},poller:{wake(){}},
  transport:{refresh:async()=>{calls.push('identity');return context;},receipt:async()=>{calls.push('receipt');return null;},send:async()=>{calls.push('send');return ack;}},
  box:{tab:'tab',now:Date.now,list:async()=>[row],reserve:async()=>{row.attempts++;row.state='sending';row.leaseOwner='tab';return {...row};},accepted:async()=>{row.state='accepted';},fail:async()=>{row.state='unknown';}},handleError:()=>{}});
 return {engine,row,calls,ack};
}
test('fresh same-tab sends remove one identity round trip for both roles',async()=>{
 for(const role of ['customer','operator']){
  const fresh=fixture({role});await fresh.engine.drain();assert.deepEqual(fresh.calls,['send']);assert.equal(fresh.row.state,'accepted');assert.equal(fresh.engine.freshSubmissions.size,0);
  const restored=fixture({role,fresh:false});await restored.engine.drain();assert.deepEqual(restored.calls,['identity','send']);
 }
});
test('restored or stale-epoch drafts still require identity refresh',async()=>{
 const stale=fixture({epoch:'previous'});stale.engine.box.update=async(_,change)=>Object.assign(stale.row,change(stale.row));await stale.engine.drain();assert.deepEqual(stale.calls,['identity']);assert.equal(stale.row.state,'needs_action');assert.equal(stale.row.text,'synthetic draft');
});
test('a lost ACK retains the draft and reconciles without a second send',async()=>{
 const f=fixture();let sends=0;f.engine.transport.send=async()=>{f.calls.push('send');sends++;throw Error('synthetic lost response');};
 await f.engine.drain();assert.equal(f.row.state,'unknown');assert.equal(f.row.text,'synthetic draft');assert.equal(f.engine.freshSubmissions.size,0);
 f.engine.transport.receipt=async()=>{f.calls.push('receipt');return f.ack;};await f.engine.drain();assert.equal(sends,1);assert.equal(f.row.state,'accepted');assert.ok(f.calls.includes('identity'));assert.ok(f.calls.includes('receipt'));
});

test('another operator ownership stops new submission and retries without losing the queued draft',async()=>{
 const f=fixture({role:'operator'});f.engine.hooks.canSend=()=>false;
 await f.engine.drain();assert.deepEqual(f.calls,[]);assert.equal(f.row.state,'pending');assert.equal(f.row.text,'synthetic draft');
 await assert.rejects(f.engine.retry(f.row));assert.deepEqual(f.calls,[]);
});

test('an accepted lost ACK can still reconcile while a different operator owns the conversation',async()=>{
 const f=fixture({role:'operator',attempts:1});f.engine.hooks.canSend=()=>false;
 f.engine.transport.receipt=async()=>{f.calls.push('receipt');return f.ack;};
 await f.engine.drain();assert.deepEqual(f.calls,['identity','receipt']);assert.equal(f.row.state,'accepted');assert.equal(f.row.attempts,1);
});

test('customer submission and inbox attention work without Object.hasOwn', async () => {
 const nativeHasOwn = Object.hasOwn;
 const source = Object.getOwnPropertyDescriptor(globalThis,'SupportSourcePath');
 try {
  Object.hasOwn = undefined;
  globalThis.SupportSourcePath = '/products';
  const f = fixture({fresh:false});
  f.engine.sync = {id:f.row.conversation};
  f.engine.box.enqueue = async (context,conversation,text,options) => {
   assert.equal(conversation,f.row.conversation);
   assert.equal(options.sourcePath,'/products');
   return {...f.row,text};
  };
  const queued = await f.engine.enqueue('synthetic draft');
  assert.equal(queued.text,'synthetic draft');
  await f.engine.drain();
  assert.deepEqual(f.calls,['send']);
  assert.equal(f.row.state,'accepted');

  const sync = Object.create(ConversationSync.prototype);
  sync.id = f.row.conversation;
  const page = Object.assign(Object.create({operator_unread:99}),{
   conversation_id:sync.id,store_epoch:'epoch',attention_revision:1,customer_unread:0,
  });
  assert.equal(sync.mergeAttention(page),page);
  assert.equal(sync.attention.customer_unread,0);
  assert.equal('operator_unread' in sync.attention,false);
 } finally {
  Object.hasOwn = nativeHasOwn;
  if(source)Object.defineProperty(globalThis,'SupportSourcePath',source);
  else delete globalThis.SupportSourcePath;
 }
});

test('recovery operation IDs retain cryptographic UUID v4 without the native method', () => {
 const nativeCrypto = Object.getOwnPropertyDescriptor(globalThis,'crypto');
 try {
  const secureRandom = globalThis.crypto.getRandomValues.bind(globalThis.crypto);
  Object.defineProperty(globalThis,'crypto',{configurable:true,value:{getRandomValues:secureRandom}});
  const ids = Array.from({length:16},()=>uuid());
  assert.ok(ids.every(validUUID));
  assert.equal(new Set(ids).size,ids.length);
 } finally {
  Object.defineProperty(globalThis,'crypto',nativeCrypto);
 }
});
