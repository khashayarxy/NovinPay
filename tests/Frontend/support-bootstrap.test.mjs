import {test} from 'node:test';
import assert from 'node:assert/strict';
import {Transport} from '../../resources/support-chat/support-client/network.js';

const context={role:'customer',protocol_version:2,scope_id:'a'.repeat(64),session_epoch:'b'.repeat(32)};
const body=()=>({status:'success',token:'c'.repeat(64),context,user_id:null,draft_user_id:`user_${'d'.repeat(32)}_0`});
const fixture=(value=body(),role='customer')=>{
 const calls=[];
 const transport=new Transport(role,{fetch:async path=>{calls.push(path);return new Response(JSON.stringify(value));}});
 return {calls,transport};
};

test('initial customer bootstrap replaces two requests with one single-use session',async()=>{
 const legacy=fixture();await legacy.transport.refresh();await legacy.transport.request('api.php?get_session=1');
 const next=fixture();await next.transport.refresh(null,{session:true});
 assert.deepEqual(next.calls,['api.php?get_session=1&bootstrap=1']);assert.equal(legacy.calls.length,2);
 assert.deepEqual(next.transport.takeBootstrapSession(),body());assert.equal(next.transport.takeBootstrapSession(),null);
 assert.equal(next.transport.token,body().token);
});

test('subsequent identity refresh clears earlier bootstrap state',async()=>{
 const f=fixture();await f.transport.refresh(null,{session:true});await f.transport.refresh();
 assert.equal(f.transport.takeBootstrapSession(),null);assert.match(f.calls.at(-1),/get_csrf=1/);
});

test('malformed session and changed identity cannot populate bootstrap state',async()=>{
 for(const value of [{...body(),draft_user_id:'foreign'},{...body(),token:'bad'}]){
  const f=fixture(value);await assert.rejects(()=>f.transport.refresh(null,{session:true}));assert.equal(f.transport.takeBootstrapSession(),null);
 }
 const f=fixture();await assert.rejects(()=>f.transport.refresh('e'.repeat(64),{session:true}),e=>e.code==='identity_changed');assert.equal(f.transport.takeBootstrapSession(),null);
});

test('operator bootstrap remains on the original operator identity endpoint',async()=>{
 const f=fixture({...body(),context:{...context,role:'operator'}},'operator');await f.transport.refresh(null,{session:true});
 assert.deepEqual(f.calls,['api.php?get_csrf=1&scope=operator']);assert.equal(f.transport.takeBootstrapSession(),null);
});
