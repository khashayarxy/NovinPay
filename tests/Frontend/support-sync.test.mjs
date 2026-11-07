import {test} from 'node:test';
import assert from 'node:assert/strict';
import {ConversationSync,RequestError,Transport} from '../../resources/support-chat/support-client/network.js';

const epoch='a'.repeat(32);
const page=id=>({protocol_version:2,conversation_id:id,messages:[],store_epoch:epoch,next_after:0,high_watermark:0,has_more:false});
test('one explicit cursor reset immediately re-reads a fresh window',async()=>{
 const calls=[];const transport={role:'customer',request:async path=>{calls.push(path);if(calls.length===1)throw new RequestError('reset',409,'cursor_reset_required');return page('fixture');}};
 const sync=new ConversationSync(transport);sync.select('fixture');sync.epoch='b'.repeat(32);sync.cursor=7;
 await sync.sync();assert.equal(calls.length,2);assert.match(calls[0],/after_sequence=7/);assert.doesNotMatch(calls[1],/store_epoch|after_sequence/);assert.equal(sync.epoch,epoch);assert.equal(sync.busy,false);
});
test('repeated resets and genuine conflicts remain visible without retry loops',async()=>{
 for(const code of ['cursor_reset_required','idempotency_conflict','message_integrity']){
  let calls=0;const sync=new ConversationSync({role:'customer',request:async()=>{calls++;throw new RequestError('conflict',409,code);}});sync.select('fixture');
  await assert.rejects(()=>sync.sync(),e=>e.code===code);assert.equal(calls,code==='cursor_reset_required'?2:1);assert.equal(sync.busy,false);
 }
});
test('a response for a previous conversation cannot reset the selected one',async()=>{
 let reject;const sync=new ConversationSync({role:'customer',request:()=>new Promise((_,r)=>{reject=r;})});sync.select('old');const pending=sync.sync();sync.select('new');reject(new RequestError('reset',409,'cursor_reset_required'));assert.equal(await pending,false);assert.equal(sync.id,'new');
});
test('timing diagnostics retain only bounded safe metadata, including failed requests',async()=>{
 globalThis.SupportDeliveryMetrics={ack_ms:[42]};let fail=false;
 const transport=new Transport('customer',{fetch:async()=>new Response(JSON.stringify(fail?{status:'error',code:'cursor_reset_required'}:{status:'success',private_fixture:'never log me'}),{status:fail?409:200,headers:{'Server-Timing':'chat;dur=12.5'}})});
 await transport.request('api.php?get_session=1&private_fixture=secret');fail=true;
 await assert.rejects(()=>transport.request('api.php?get_conversation=1&user_id=private'),e=>e.code==='cursor_reset_required');
 const m=globalThis.SupportDeliveryMetrics;assert.deepEqual(m.ack_ms,[42]);assert.equal(m.requests[0].controller_ms,12.5);assert.equal(m.requests[1].outcome,'cursor_reset_required');assert.doesNotMatch(JSON.stringify(m),/never log|private|secret/);assert.ok(m.requests.every(r=>r.ms>=0));
});
