// Display-only deadlines use elapsed time, never the device's wall clock.
// The server remains authoritative for every send and attachment submission.
export class ReplyOwnership {
 constructor({now=()=>performance.now(),changed=()=>{},timer=setTimeout,cancel=clearTimeout}={}){
  Object.assign(this,{now,changed,timer,cancel});this.states=new Map();this.current=null;
 }
 update(id,snapshot){
  if(!id||!snapshot||!Number.isSafeInteger(snapshot.checked_at)||!Number.isSafeInteger(snapshot.server_now))return false;
  if(snapshot.operator_id!==null&&(!/^[a-f0-9]{32}$/.test(snapshot.operator_id)||typeof snapshot.name!=='string'||snapshot.name.length>120||!Number.isSafeInteger(snapshot.expires_at)||typeof snapshot.is_owner!=='boolean'))return false;
  const previous=this.states.get(id);if(previous&&snapshot.checked_at<=previous.checked_at)return false;
  const remaining=snapshot.operator_id===null?0:Math.max(0,Math.min(300,snapshot.expires_at-snapshot.server_now))*1000;
  this.states.set(id,{...snapshot,deadline:this.now()+remaining});this.schedule();this.changed();return true;
 }
 blocked(id=this.current){const s=this.states.get(id);return !!s&&s.operator_id!==null&&!s.is_owner&&this.now()<s.deadline;}
 notice(id=this.current){return this.blocked(id)?this.states.get(id).name+' در حال پاسخگویی به این گفتگو است.':'';}
 select(id){this.current=id;this.schedule();this.changed();}
 schedule(){this.cancel(this.expiration);if(this.blocked())this.expiration=this.timer(()=>this.changed(),Math.max(1,this.states.get(this.current).deadline-this.now()));}
 clear(){this.cancel(this.expiration);this.states.clear();this.current=null;this.changed();}
}
