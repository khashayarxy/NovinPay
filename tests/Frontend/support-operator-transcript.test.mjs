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
 assert.equal(operatorSenderName({type:'admin',_row:{},_ack:true,sender:null},'اپراتور فعلی'),'پشتیبان');
});
test('customer-side direction and stored message data remain unchanged',()=>{
 const before=structuredClone(rows);
 assert.deepEqual(messageGroups(rows,{operator:false}).map(r=>r.outgoing),[true,false,false,false,false,false]);
 assert.deepEqual(rows,before);
});
