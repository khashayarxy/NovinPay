import {test} from 'node:test';
import assert from 'node:assert/strict';
import {ChatEngine} from '../../resources/support-chat/support-client/engine.js';

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
