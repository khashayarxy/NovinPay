import {test} from 'node:test';
import assert from 'node:assert/strict';
import {messageGroups,operatorSenderName} from '../../resources/support-chat/support-client/message-groups.js';

const time='2026-11-08T08:00:00Z';
const rows=[
 {id:1,type:'user',text:'customer',time},
 {id:2,type:'admin',text:'current',time,sender_group:'own',sender:{id:'a',name:'اپراتور اول'}},
 {id:3,type:'admin',text:'other',time,sender_group:'other',sender:{id:'b',name:'اپراتور دوم'}},
 {id:4,type:'system',origin:'faq_auto',text:'automatic',time},
 {id:5,type:'admin',text:'historical tombstone',time,sender:{id:'c',name:'اپراتور سابق'}},
 {id:6,type:'admin',text:'unknown history',time}
];
test('operator transcript uses the support side for every operator and automated reply',()=>{
 for(const ownGroup of ['own','other',null]){
  const groups=messageGroups(rows,{operator:true,ownGroup});
  assert.deepEqual(groups.map(r=>r.outgoing),[false,true,true,true,true,true]);
  assert.equal(groups[2].participant,'operator:b');
  assert.equal(groups[5].participant,null,'Unknown history must not be grouped under the viewer identity');
 }
});
test('server sender names survive other/deactivated identities and never fall back to the viewer',()=>{
 assert.deepEqual(rows.slice(1).map(m=>operatorSenderName(m,'اپراتور فعلی')),['اپراتور اول','اپراتور دوم','پاسخ خودکار','اپراتور سابق','پشتیبان']);
 assert.equal(operatorSenderName({type:'admin',_row:{},sender:null},'اپراتور فعلی'),'اپراتور فعلی');
 assert.equal(operatorSenderName({type:'admin',_row:{},_ack:true,sender:null},'اپراتور فعلی'),'اپراتور فعلی');
 assert.equal(operatorSenderName({type:'admin',_row:{},sender:null}), '');
});
test('customer-side direction and stored message data remain unchanged',()=>{
 const before=structuredClone(rows);
 assert.deepEqual(messageGroups(rows,{operator:false}).map(r=>r.outgoing),[true,false,false,false,false,false]);
 assert.deepEqual(rows,before);
});

test('consecutive operator and automatic groups survive interruptions without sharing identities',()=>{
 const messages=[rows[1],{...rows[1],id:7,text:'second'},rows[2],rows[0],{...rows[1],id:8},rows[3],{...rows[3],id:9}];
 const groups=messageGroups(messages.map(m=>({...m,accepted_at:time})),{operator:true,ownGroup:'own'});
 assert.deepEqual(groups.map(g=>g.first),[true,false,true,true,true,true,false]);
 assert.equal(groups[5].participant,groups[6].participant);
 assert.notEqual(groups[1].participant,groups[2].participant);
});

test('customer groups use adjacent times, Tehran days, and stable sender identity',()=>{
 const support=(id,accepted_at,extra={})=>({id,type:'admin',accepted_at,sender_group:'a',...extra});
 const messages=[
  support(1,'2026-11-08T20:21:00Z',{sender:{id:'profile-a'}}),
  support(2,'2026-11-08T20:22:00Z',{edited_at:'2026-11-09T06:00:00Z'}),
  support(3,'2026-11-08T20:27:00Z'),
  support(4,'2026-11-08T20:27:00Z',{sender_group:'b'}),
  support(5,'2026-11-08T20:29:59Z'),
  support(6,'2026-11-08T20:30:00Z'),
  support(7,'2026-11-08T20:35:01Z'),
  support(8,'2026-11-08T20:35:01Z',{type:'system',origin:'faq_auto'}),
  support(9,'2026-11-08T20:36:01Z',{type:'system',origin:'faq_auto'}),
  support(10,'2026-11-08T20:36:01Z',{type:'user'}),
  support(11,'2026-11-08T20:36:02Z',{sender_group:null}),
  support(12,'2026-11-08T20:36:03Z',{sender_group:null}),
 ];
 const groups=messageGroups(messages);
 assert.deepEqual(groups.map(g=>g.first),[true,false,false,true,true,true,true,true,false,true,true,true]);
 assert.deepEqual(groups.map(g=>g.last),[false,false,true,true,true,true,true,false,true,true,true,true]);
 assert.notEqual(groups[4].day,groups[5].day);
});

test('historical pages, aliases and current messages share customer grouping',()=>{
 const account='a'.repeat(32),other='b'.repeat(32);
 const records=[
  {id:1,type:'admin',legacy:true,time:'2026-11-08 23:25:00',sender_account_group:account},
  {id:2,type:'admin',time:'2026-11-08T23:26:00+03:30',sender_group:'previous',sender_account_group:account},
  {id:3,type:'admin',accepted_at:'2026-11-08T19:57:00Z',sender_group:'current',sender_account_group:account},
  {id:4,type:'admin',accepted_at:'2026-11-08T19:58:00Z',sender_group:'current',edited_at:'2026-11-10T00:00:00Z'},
  {id:5,type:'admin',legacy:true,time:'2026-11-09 00:00:00',sender_account_group:account},
  {id:6,type:'admin',accepted_at:'2026-11-08T20:31:00Z',sender_account_group:other},
 ];
 const initial=messageGroups(records.slice(2,5));
 assert.deepEqual(initial.map(r=>r.first),[true,false,true]);
 const paginated=messageGroups([...records.slice(0,2),...records.slice(2,5)]);
 assert.deepEqual(paginated.map(r=>r.first),[true,false,false,false,true]);
 assert.deepEqual(messageGroups(structuredClone(records)).map(r=>r.last),[false,false,false,true,true,true]);
});
