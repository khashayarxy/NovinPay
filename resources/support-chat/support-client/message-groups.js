import {persianDate,tehranDay,messageDate} from './persian-date.js?v=ux1';
// Presentation only: no mutation of records, receipts, identity or timestamps.
export const messageStamp=messageDate;
const CUSTOMER_GROUP_GAP_MS=5*60*1000;
export const supportSide=m=>m.type==='admin'||m.type==='system'&&m.origin==='faq_auto';
export function operatorSenderName(m,currentName=null){
 if(m.type==='system'&&m.origin==='faq_auto')return 'پاسخ خودکار';
 const name=typeof m.sender?.name==='string'?m.sender.name.trim():'';
 return name||(m._row?(typeof currentName==='string'?currentName.trim():''):'پشتیبان');
}
export const deliveryState=m=>m._row&&!m._ack?(Date.now()-m._row.created>=10000||['needs_action','cancelled'].includes(m._row.state)?'failed':'pending'):m.read_receipt?'read':m.accepted_at||m._ack?'sent':'legacy';
export function messageGroups(messages,{operator=false,ownGroup=null,conversation=null,now=new Date()}={}){
 const today=tehranDay(now),ownGroups=Array.isArray(ownGroup)?ownGroup:[ownGroup].filter(Boolean);
 const knownSenders=new Map(messages.filter(m=>m.sender_group&&m.sender?.id).map(m=>[m.sender_group,m.sender.id]));
 const ownSender=ownGroups.map(group=>knownSenders.get(group)).find(Boolean);
 const rows=messages.map(m=>{
  const stamp=messageStamp(m),valid=!Number.isNaN(stamp.getTime()),day=valid?tehranDay(stamp):null;
  const automatic=m.type==='system'&&m.origin==='faq_auto';
  const pending=!!m._row&&!m._ack,outgoing=operator?supportSide(m):m.type==='user';
  const state=outgoing&&!automatic?deliveryState(m):null;
  // A public profile can be absent on one copy of a message. Resolve its
  // opaque group through any matching profile in the transcript.
  const sender=m.sender?.id||(!operator&&knownSenders.get(m.sender_group));
  const participant=automatic?'automation':m.type==='user'?'customer':sender?'operator:'+sender:operator&&pending?'operator:'+(ownSender||ownGroups[0]||'own'):m.sender_group?'operator:'+m.sender_group:null;
  const exception=m.type==='system'&&!automatic||!!m.episode_boundary;
  const formatted=persianDate(stamp,now);
  return {conversation:m.conversation_id||m._row?.conversation||conversation||null,m,stamp,day,outgoing,state,participant,exception,...formatted,first:true,last:true};
 });
 const together=(a,b)=>a&&b&&!a.exception&&!b.exception&&a.participant&&a.participant===b.participant&&a.conversation===b.conversation&&a.outgoing===b.outgoing&&a.day&&a.day===b.day&&b.stamp-a.stamp>=0&&(operator||b.stamp-a.stamp<=CUSTOMER_GROUP_GAP_MS);
 for(let i=0;i<rows.length;i++){rows[i].first=!together(rows[i-1],rows[i]);rows[i].last=!together(rows[i],rows[i+1]);rows[i].separator=rows[i].day&&rows[i].day!==rows[i-1]?.day&&(i>0||rows[i].day!==today);}
 return rows;
}
