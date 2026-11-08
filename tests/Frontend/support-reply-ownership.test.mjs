import {test} from 'node:test';
import assert from 'node:assert/strict';
import {ReplyOwnership} from '../../resources/support-chat/operator/reply-ownership.js';
import {conversationView,ChatEngine} from '../../resources/support-chat/support-client/engine.js';

const owner={operator_id:'a'.repeat(32),name:'اپراتور دیگر',expires_at:400,server_now:100,checked_at:100000,is_owner:false};
test('ownership expires using elapsed time, ignores stale/duplicate snapshots and stays conversation-scoped',()=>{
 let now=0,expiration,changes=0;
 const model=new ReplyOwnership({now:()=>now,changed:()=>changes++,timer:fn=>{expiration=fn;return 1;},cancel:()=>{}});
 model.select('conversation-a');assert.equal(model.update('conversation-a',owner),true);
 assert.equal(model.blocked(),true);assert.match(model.notice(),/اپراتور دیگر/);
 assert.equal(model.blocked('conversation-b'),false);
 now=299999;assert.equal(model.blocked(),true);
 assert.equal(model.update('conversation-a',owner),false,'duplicate payload must never extend expiry');
 now=300000;expiration();assert.equal(model.blocked(),false);assert.equal(model.notice(),'');
 assert.equal(model.update('conversation-a',{...owner,checked_at:99999}),false);
 model.update('conversation-a',{...owner,checked_at:401000,server_now:401,expires_at:701,is_owner:true});
 assert.equal(model.blocked(),false);assert.equal(model.notice(),'');
 model.clear();assert.equal(model.states.size,0);assert.ok(changes>=3);
});
test('optimistic, failed, ACK and confirmed rows retain one authoritative sender and stable UUID',()=>{
 const rendered=[],sender={id:'b'.repeat(32),name:'اپراتور فعلی',avatar:null};
 const engine={role:'operator',scope:'scope',sync:{id:'conversation'},knownAcks:new Map(),operatorSender:sender,operatorSenderGroup:'group',transport:{presentationTime:x=>x}};
 const host={replaceChildren(){}};
 const view=conversationView(host,engine,{renderer:(_,messages)=>rendered.push(messages)});
 const row={key:'key',uuid:'uuid',scope:'scope',conversation:'conversation',role:'operator',ordinal:1,created:Date.now(),text:'synthetic',attachments:[],state:'pending'};
 view.queue([row],'conversation');view.queue([{...row,state:'needs_action'}],'conversation');
 const ack={message_id:1,accepted_at:new Date().toISOString()};engine.knownAcks.set('key',ack);view.accepted(row);
 view.messages([{id:1,type:'admin',text:'synthetic',time:ack.accepted_at,accepted_at:ack.accepted_at,client_message_id:'uuid',sender,sender_group:'group'}],false);
 for(const messages of rendered){assert.equal(messages.length,1);assert.equal(messages[0].sender.name,sender.name);assert.equal(messages[0].sender.id,sender.id);assert.equal(messages[0].client_message_id,'uuid');}
});
test('ownership conflicts are not network failures and cannot block unrelated conversations',()=>{
 const calls=[],engine=Object.create(ChatEngine.prototype);
 Object.assign(engine,{role:'operator',hooks:{ownership:(...args)=>calls.push(args),warning(){}},senderNotBefore:0});
 engine.handleError({code:'conversation_owned',status:409,replyOwnership:owner,message:'busy'},'conversation');
 assert.deepEqual(calls,[['conversation',owner]]);assert.equal(engine.senderNotBefore,0);
});
