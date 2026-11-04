// Durable draft text is opt-in; a bounded tab-only copy supports refresh; quoted content is never copied, only its reply ID. Scope is installation + operator.
export class ConversationDrafts {
 constructor(engine){this.engine=engine;this.scope=engine.scope;this.records=new Map();this.clock=0;this.dirty=new Map();
  try{const raw=sessionStorage.getItem(this.temporaryKey());if(!raw||raw.length>131072)return;const cached=JSON.parse(raw);if(!Number.isFinite(cached.at)||Date.now()-cached.at>=86400000||!Array.isArray(cached.values))return;
   for(const entry of cached.values.slice(0,20)){if(!Array.isArray(entry)||entry.length!==2)continue;const [id,r]=entry;if(!this.valid(id)||!r||typeof r.text!=='string'||r.text.length>2000||!Number.isSafeInteger(r.version)||!Number.isFinite(r.at)||Date.now()-r.at>=86400000||r.replyTo!==null&&(!Number.isSafeInteger(r.replyTo)||r.replyTo<1))continue;this.records.set(id,{...r});this.clock=Math.max(this.clock,r.version);}
  }catch{}}
 temporaryKey(){return 'support-tab-drafts:'+this.scope+':'+this.engine.context.session_epoch;}
 stash(){try{sessionStorage.setItem(this.temporaryKey(),JSON.stringify({at:Date.now(),values:[...this.records]}));}catch{}}
 valid(id){return /^user_[a-f0-9]{32}_[0-9]+$/.test(id);}
 adopt(previous){if(previous?.scope===this.scope){this.records=new Map([...previous.records].map(([id,r])=>[id,{...r}]));this.clock=previous.clock;this.dirty=new Map(previous.dirty);}}
 get(id){const r=this.records.get(id);return r?.queued&&r.version===r.pendingVersion?'':r?.text||'';}
 reply(id){const r=this.records.get(id);return r?.queued&&r.version===r.pendingVersion?null:r?.replyTo||null;}
 version(id){return this.records.get(id)?.version||0;}
 set(id,text,replyTo=null){
  if(!this.valid(id)||typeof text!=='string'||text.length>2000||replyTo!==null&&(!Number.isSafeInteger(replyTo)||replyTo<1))throw Error('پیش‌نویس معتبر نیست.');
  const old=this.records.get(id);if(old&&text===this.get(id)&&replyTo===this.reply(id))return;if(!text&&!replyTo&&!old?.queued){this.records.delete(id);this.dirty.set(id,{mode:'delete',at:Date.now()});this.stash();return;}
  if(!old&&this.records.size>=20)throw Error('سقف ۲۰ پیش‌نویس این دستگاه پر است؛ یکی را ارسال یا پاک کنید.');
  this.records.set(id,{text,replyTo,version:++this.clock,at:Date.now(),queued:old?.queued||null,pendingVersion:old?.pendingVersion||0,edit:crypto.randomUUID()});this.dirty.set(id,{mode:'set',at:Date.now()});this.stash();
 }
 queued(id,uuid,version){const r=this.records.get(id);if(r&&r.version===version){r.queued=uuid;r.pendingVersion=version;r.at=Date.now();this.dirty.set(id,{mode:'queued',edit:r.edit,at:r.at});}return this.save();}
 accepted(row){const r=this.records.get(row.conversation);if(r?.queued!==row.uuid)return Promise.resolve();const original=r.version===r.pendingVersion;r.queued=null;r.pendingVersion=0;this.dirty.set(row.conversation,{mode:'accepted',uuid:row.uuid,at:Date.now()});if(original||!r.text&&!r.replyTo)this.records.delete(row.conversation);return this.save();}
 async save(){
  this.stash();
  if(this.scope!==this.engine.scope||this.engine.frozen)return;if(!this.engine.box.persistent)return;
  const changes=[...this.dirty].map(([id,operation])=>[id,operation,this.records.get(id)?{...this.records.get(id)}:null]);if(!changes.length)return;
  const db=await this.engine.box.open();await new Promise((resolve,reject)=>{const tx=db.transaction('meta','readwrite'),meta=tx.objectStore('meta'),key='drafts:'+this.scope,q=meta.get(key);let failure=null;
   q.onsuccess=()=>{try{const old=q.result;const entries=new Map((Array.isArray(old?.values)?old.values:[]).map(([id,r])=>[id,typeof r==='string'?{text:r,replyTo:null,version:1,at:old.at,queued:null,pendingVersion:0,edit:crypto.randomUUID()}:r]).filter(([id,r])=>this.valid(id)&&r&&typeof r.text==='string'&&r.text.length<=2000&&Number.isFinite(r.at)&&Date.now()-r.at<86400000));
    for(const [id,op,r]of changes){const stored=entries.get(id);if(op.mode==='accepted'){if(stored?.queued===op.uuid){const original=stored.version===stored.pendingVersion;stored.queued=null;stored.pendingVersion=0;if(original||!stored.text&&!stored.replyTo)entries.delete(id);}}
     else if(op.mode==='queued'){if(!stored||stored.edit===op.edit)entries.set(id,r);}
     else if(!stored||stored.at<=op.at){if(r)entries.set(id,r);else entries.delete(id);}}
    const value={key,version:2,values:[...entries],at:Date.now()};if(entries.size>20||new TextEncoder().encode(JSON.stringify(value)).length>131072)throw Error('ظرفیت پیش‌نویس‌ها پر است؛ متن فعلی حفظ شده است.');meta.put(value);
   }catch(e){failure=e;tx.abort();}};tx.oncomplete=resolve;tx.onerror=tx.onabort=()=>reject(failure||Error('ذخیرهٔ پیش‌نویس ممکن نشد.'));});
  for(const [id,op]of changes)if(this.dirty.get(id)===op)this.dirty.delete(id);
 }
 async restore(){
  if(!this.engine.box.persistent)return;const db=await this.engine.box.open(),r=await new Promise((resolve,reject)=>{const tx=db.transaction('meta','readonly'),q=tx.objectStore('meta').get('drafts:'+this.scope);q.onsuccess=()=>resolve(q.result);q.onerror=()=>reject(Error('خواندن پیش‌نویس ممکن نشد.'));});
  if(!r||Date.now()-r.at>=86400000||!Array.isArray(r.values))return;
  for(const [id,stored]of r.values.slice(0,20)){const v=typeof stored==='string'?{text:stored,replyTo:null,version:1,at:r.at,queued:null}:stored;if(!this.valid(id)||!v||typeof v.text!=='string'||v.text.length>2000||!Number.isSafeInteger(v.version)||!Number.isFinite(v.at)||Date.now()-v.at>=86400000||v.replyTo!==null&&(!Number.isSafeInteger(v.replyTo)||v.replyTo<1))continue;if((this.records.get(id)?.at||0)>v.at)continue;this.records.set(id,{text:v.text,replyTo:v.replyTo,version:v.version,at:v.at,queued:typeof v.queued==='string'?v.queued:null,pendingVersion:Number.isSafeInteger(v.pendingVersion)?v.pendingVersion:0,edit:typeof v.edit==='string'?v.edit:crypto.randomUUID()});this.clock=Math.max(this.clock,v.version);}
 }
 clear(){try{sessionStorage.removeItem(this.temporaryKey());}catch{}this.records.clear();this.dirty.clear();}
}
