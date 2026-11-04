// Per-document API evidence. Optional availability never defines messaging health.
export const apiHealth={sequence:0,success:0,at:0,failures:0,state:'unknown',
 begin(){return ++this.sequence;},
 finish(id,error=null){if(id<this.success)return;if(!error||error.status>=400&&error.status<500&&![401,403,426].includes(error.status)){this.success=id;this.at=Date.now();this.failures=0;this.state=error?.status===429?'limited':'reachable';}else if([401,403,426].includes(error.status)){this.state='authentication';}else{this.failures++;this.state=this.failures>=3?'unavailable':'degraded';}globalThis.dispatchEvent?.(new Event('support-api-health'));}
};
import {validUUID, validReceipt, backoff} from './outbox.js?v=team1';
export class RequestError extends Error {
    constructor(message,status=0,code='',retryAfter=0){super(message);Object.assign(this,{status,code,retryAfter});}
}
export const retryAfter = (value, now=Date.now()) => {
    if(!value)return 0;
    if(/^\d+$/.test(value))return Math.min(86400000,Number(value)*1000);
    const time=Date.parse(value);return Number.isFinite(time)?Math.min(86400000,Math.max(0,time-now)):0;
};
export function mergeUserPage(previous,page,append=false) {
    if(!Array.isArray(page.users)||page.users.length>50||typeof page.has_more!=='boolean'||!Number.isSafeInteger(page.next_offset)||page.next_offset<0)throw new RequestError('فهرست گفتگوها معتبر نیست.');
    const fresh=new Map(page.users.map(user=>[user.user_id,user]));
    if(append)return [...previous.filter(user=>!fresh.has(user.user_id)),...fresh.values()];
    if(!page.has_more)return [...fresh.values()];
    return [...fresh.values(),...previous.filter(user=>!fresh.has(user.user_id))].slice(0,Math.max(previous.length,page.users.length));
}
export function validateAck(ack, row) {
    if(!validReceipt(ack,row)) throw new RequestError('تأیید ثبت معتبر دریافت نشد؛ نتیجه نامعلوم است.');
    return ack;
}
export class Transport {
    constructor(role,{fetch:fetcher=globalThis.fetch.bind(globalThis),now=Date.now}={}){this.role=role;this.fetcher=fetcher;this.now=now;this.token=null;this.context=null;}
    async request(path,{data=null,signal=null,timeout=data?30000:20000}={}) {
        const controller=new AbortController();let timer,abort;const observed=!/^(?:get_activity|update_activity)\.php/.test(path)&&!data?.operator_activity,ticket=observed?apiHealth.begin():0;
        if(signal){abort=()=>controller.abort();if(signal.aborted)abort();else signal.addEventListener('abort',abort,{once:true});}
        timer=setTimeout(()=>controller.abort(),timeout);
        try {
            const options={credentials:'same-origin',cache:'no-store',signal:controller.signal,headers:{}};
            if(data){
                if(!this.token)throw new RequestError('نشست معتبر نیست؛ متن حفظ شده است.',401,'session_expired');
                options.method='POST';options.body=new FormData();
                for(const [key,value]of Object.entries(data))options.body.append(key,value);
                options.body.append('csrf_token',this.token);options.headers['X-CSRF-Token']=this.token;
            }
            const response=await this.fetcher(path,options);
            // Same-origin server clock for provisional presentation only; never security/expiry.
            const received=this.now(),server=Date.parse(response.headers.get('Date')||'');
            if(Number.isFinite(server)&&(!this.serverClock||server>=this.presentationTime(received)))this.serverClock={server,local:received};
            let body;try{body=await response.json();}catch{throw new RequestError('پاسخ سرور کامل نبود؛ نتیجه نامعلوم است.',response.ok?0:response.status,'invalid_response',retryAfter(response.headers.get('Retry-After'),this.now()));}
            if(body.code==='customer_send_limited')body.message='ارسال زیاد است؛ '+Math.ceil(retryAfter(response.headers.get('Retry-After'),this.now())/1000)+' ثانیه صبر کنید. لطفاً پرسش‌هایتان را در یک پیام کامل بنویسید.';
            if(!response.ok || body.status==='error')throw new RequestError(typeof body.message==='string'?body.message.slice(0,400):'درخواست انجام نشد؛ متن حفظ شده است.',response.status,body.code||'',retryAfter(response.headers.get('Retry-After'),this.now()));
            if(observed)apiHealth.finish(ticket);return body;
        }catch(error){const failure=error instanceof RequestError?error:new RequestError('ارتباط قطع یا انتظار تمام شد؛ نتیجه هنوز مشخص نیست.');if(observed&&!signal?.aborted)apiHealth.finish(ticket,failure);throw failure;}
        finally{clearTimeout(timer);if(signal&&abort)signal.removeEventListener('abort',abort);}
    }
    presentationTime(local){return this.serverClock?local+this.serverClock.server-this.serverClock.local:local;}
    async refresh(expectedScope=null,options={}){
        const body=await this.request(`api.php?get_csrf=1&scope=${this.role}`,options),c=body.context;
        if(!c || c.role!==this.role || c.protocol_version!==2 || !/^[a-f0-9]{64}$/.test(c.scope_id) || !/^[a-f0-9]{32}$/.test(c.session_epoch) || !/^[a-f0-9]{64}$/.test(body.token))throw new RequestError('پاسخ هویت معتبر نیست.');
        if(expectedScope && c.scope_id!==expectedScope)throw new RequestError('هویت تغییر کرده؛ صف قبلی ارسال نمی‌شود.',401,'identity_changed');
        this.token=body.token;this.context=c;return c;
    }
    async send(row){
        const data={protocol_version:'2',scope:this.role,user_id:row.conversation,client_message_id:row.uuid};
        if(this.role==='customer'){data.send_message='1';data.message=row.text;}else{data.action='send_reply';data.reply=row.text;}
        if(this.role==='customer'&&row.sourcePath)data.source_path=row.sourcePath;
        if(this.role==='customer'&&row.faqArticle)data.faq_article=row.faqArticle;
        if(this.role==='operator'&&row.teamThrough!==undefined)data.team_through=String(row.teamThrough);
        if(row.replyTo)data.reply_to=String(row.replyTo);
        if(row.attachments?.length)data.attachments=JSON.stringify(row.attachments);
        return validateAck(await this.request('api.php',{data}),row);
    }
    async receipt(row){
        const query=new URLSearchParams({receipts:'1',scope:this.role,user_id:row.conversation});query.append('client_message_ids[]',row.uuid);
        const body=await this.request(`api.php?${query}`);
        if(body.protocol_version!==2 || body.conversation_id!==row.conversation || !Array.isArray(body.receipts))throw new RequestError('پاسخ بررسی ثبت معتبر نیست.');
        return body.receipts.length?validateAck(body.receipts[0],row):null;
    }
}
export class Poller {
    constructor(work,{visible=()=>!document.hidden,now=Date.now,random=Math.random,setTimer=(fn,delay)=>setTimeout(fn,delay),clearTimer=id=>clearTimeout(id),active=4000,idle=5000,hidden=9000,onerror=()=>{}}={}){
        Object.assign(this,{work,visible,now,random,setTimer,clearTimer,active,idle,hidden,onerror});
        this.timer=null;this.running=false;this.stopped=true;this.failures=0;this.lastChange=now();this.again=false;this.notBefore=0;
    }
    start(){this.stopped=false;this.wake();}
    stop(){this.stopped=true;this.clearTimer(this.timer);}
    wake(){if(this.stopped)return;if(this.running){this.again=true;return;}this.clearTimer(this.timer);this.timer=this.setTimer(()=>this.tick(),Math.max(0,this.notBefore-this.now()));}
    async tick(){
        if(this.running||this.stopped)return;
        if(this.now()<this.notBefore){this.clearTimer(this.timer);this.timer=this.setTimer(()=>this.tick(),this.notBefore-this.now());return;}
        this.running=true;let delay=this.active;
        try{const changed=await this.work();this.failures=0;this.notBefore=0;if(changed)this.lastChange=this.now();delay=!this.visible()?(this.now()-this.lastChange>180000?Math.min(this.hidden*3,30000):this.hidden):this.now()-this.lastChange>60000?this.idle:this.active;}
        catch(error){this.failures++;delay=Math.max(backoff(this.failures,this.random),error.retryAfter||0);this.notBefore=this.now()+delay;this.onerror(error);if([401,403,426].includes(error.status))this.stopped=true;}
        finally{this.running=false;if(!this.stopped){if(this.again){this.again=false;delay=Math.max(0,this.failures?delay:0);}this.timer=this.setTimer(()=>this.tick(),delay);}}
    }
}
export class ConversationSync {
    constructor(transport,onchange=()=>{}){this.transport=transport;this.onchange=onchange;this.generation=0;this.id=null;this.messages=new Map();this.cursor=0;this.epoch=null;this.hasOlder=false;this.hasNewer=false;this.controller=null;this.busy=false;}
    select(id){this.controller?.abort();this.generation++;this.id=id;this.messages.clear();this.attention=null;this.lastPage=null;this.editCursor=0;this.cursor=0;this.epoch=null;this.hasOlder=false;this.hasNewer=false;this.onchange([],true);}
    async latest(){if(this.busy)return false;this.select(this.id);return this.sync();}
    values(){return [...this.messages.values()].sort((a,b)=>a.id-b.id);}
    validate(page){
        if(!page || page.protocol_version!==2 || page.conversation_id!==this.id || !Array.isArray(page.messages) || page.messages.length>100
            || !/^[a-f0-9]{32}$/.test(page.store_epoch) || !Number.isSafeInteger(page.next_after) || !Number.isSafeInteger(page.high_watermark) || typeof page.has_more!=='boolean')throw new RequestError('داده همگام‌سازی معتبر نیست.');
        // IDs belong to a store epoch: restore requests must resync before
        // comparing them against immutable payloads from the previous store.
        if(this.epoch && page.store_epoch!==this.epoch)throw new RequestError('نسخه داده عوض شده است.',409,'cursor_reset_required');
        let last=0;
        for(const m of page.messages){
            if(!Number.isSafeInteger(m.id)||m.id<=last||m.sequence!==m.id||m.id>page.high_watermark||!['user','admin','system'].includes(m.type)||typeof m.text!=='string'||(m.client_message_id!==null&&!validUUID(m.client_message_id)))throw new RequestError('ترتیب پیام‌ها معتبر نیست.');
            last=m.id;const old=this.messages.get(m.id);
            if(old && m.id>(page.history_removed_through||0) && (old.type!==m.type||old.client_message_id!==m.client_message_id||old.text!==m.text&&(old.revision||0)===(m.revision||0)))throw new RequestError('محتوای پیام پذیرفته‌شده ناسازگار است.',409,'message_integrity');
            if(m.revision!==undefined&&(!Number.isSafeInteger(m.revision)||m.revision<0||m.revision>20||m.revision>0&&!/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/.test(m.edited_at||'')))throw new RequestError('نسخه ویرایش معتبر نیست.');
        }
        if(page.messages.length&&page.next_after!==last)throw new RequestError('نشانگر همگام‌سازی معتبر نیست.');
    }
    mergeAttention(page){
        if(!Number.isSafeInteger(page.attention_revision)||page.attention_revision<0||page.conversation_id!==this.id)return page;
        if(this.attention&&page.store_epoch===this.attention.store_epoch&&page.attention_revision<this.attention.attention_revision)return {...page,...this.attention};
        this.attention=Object.fromEntries(['conversation_id','store_epoch','attention_revision','customer_unread','operator_unread','needs_reply','operator_read_watermark','first_customer_unread_sequence'].filter(k=>Object.hasOwn(page,k)).map(k=>[k,page[k]]));return page;
    }
    acknowledgeRead(page){
        if(page.conversation_id!==this.id||page.store_epoch!==this.epoch)return;
        const field=this.transport.role==='customer'?'customer_read':'read',incoming=this.transport.role==='customer'?'admin':'user';
        for(const id of page.read_message_ids||[]){const m=this.messages.get(id);if(m?.type===incoming){m[field]=true;m.read_receipt=true;}}
        const merged=this.mergeAttention(page);if(this.lastPage)this.onchange(this.values(),false,{...this.lastPage,...merged});
        return merged;
    }
    mergeEdited(message){
        if(!Number.isSafeInteger(message?.id)||message.sequence!==message.id||!Number.isSafeInteger(message.revision)||message.revision<1||message.revision>20||typeof message.text!=='string'||!/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/.test(message.edited_at||''))throw new RequestError('نسخه ویرایش معتبر نیست.');
        const old=this.messages.get(message.id);if(!old)return false;
        if(old.type!==message.type||old.client_message_id!==message.client_message_id||old.accepted_at!==message.accepted_at||JSON.stringify(old.attachments||[])!==JSON.stringify(message.attachments||[]))throw new RequestError('هویت پیام ویرایش‌شده ناسازگار است.',409,'message_integrity');
        if(message.revision<(old.revision||0))return false;
        if(message.revision===(old.revision||0)&&old.text!==message.text)throw new RequestError('متن ویرایش‌شده ناسازگار است.',409,'message_integrity');
        this.messages.set(message.id,{...old,...message,read:old.read===true||message.read===true,customer_read:old.customer_read===true||message.customer_read===true,read_receipt:old.read_receipt||message.read_receipt});return true;
    }
    edited(result){
        if(result.conversation_id!==this.id||result.store_epoch!==this.epoch)return false;
        if(this.mergeEdited(result.message))this.onchange(this.values(),false,this.lastPage);return true;
    }
    merge(page,older=false){
        this.validate(page);if(page.updates!==undefined&&(!Array.isArray(page.updates)||page.updates.length>50||page.updates.some(m=>!Number.isSafeInteger(m.id)||!Number.isSafeInteger(m.revision)||m.revision<1||typeof m.text!=='string'))||page.reference_updates!==undefined&&(!Array.isArray(page.reference_updates)||page.reference_updates.length>500))throw new RequestError('داده ویرایش معتبر نیست.');page=this.mergeAttention(page);this.lastPage=page;this.userName=typeof page.user_name==='string'?page.user_name:'';
        if(Array.isArray(page.public_agents)&&page.public_agents.length<=100&&page.public_agents.every(p=>p&&/^[a-f0-9]{32}$/.test(p.id)&&typeof p.name==='string'&&p.name.length<=120&&(p.avatar===null||/^(?:support-avatar|operator-avatar)\.php\?id=[a-f0-9]{32}$/.test(p.avatar)))){const profiles=new Map(page.public_agents.map(p=>[p.id,p]));for(const m of this.messages.values())if(m.sender)m.sender=profiles.get(m.sender.id)||{id:m.sender.id,name:'',avatar:null};}
        this.epoch=page.store_epoch;
        const removed=page.history_removed_through||0;for(const [id,m]of this.messages){if(id<=removed)this.messages.delete(id);else if(m.reference?.id<=removed)m.reference={id:m.reference.id,available:false};}
        const read=new Set(page.read_message_ids||[]);for(const m of this.messages.values())if(read.has(m.id))m.read_receipt=true;
        for(let m of page.messages){const old=this.messages.get(m.id);if((old?.revision||0)>(m.revision||0))m={...m,text:old.text,revision:old.revision,edited_at:old.edited_at,can_edit:old.can_edit,edit_until:old.edit_until};if((old?.reference?.revision||0)>(m.reference?.revision||0))m.reference=old.reference;if(old?.read===true)m.read=true;if(old?.customer_read===true)m.customer_read=true;if(old?.read_receipt)m.read_receipt=true;this.messages.set(m.id,m);}
        for(const m of page.updates||[])this.mergeEdited(m);
        for(const update of page.reference_updates||[]){const m=this.messages.get(update.id);if(m&&update.reference?.id===m.reply_to&&(update.reference.revision||0)>=(m.reference?.revision||0))m.reference=update.reference;}
        if(Number.isSafeInteger(page.edit_next))this.editCursor=Math.max(this.editCursor||0,page.edit_next);
        if(!older)this.cursor=Math.max(this.cursor,page.next_after);
        if(older||this.messages.size===page.messages.length)this.hasOlder=page.has_more;
        let values=this.values();
        if(values.length>500){const remove=older?values.slice(500):values.slice(0,values.length-500);for(const m of remove)this.messages.delete(m.id);if(!older)this.hasOlder=true;else this.hasNewer=true;}
        if(page.focus_sequence){this.hasOlder=page.has_older;this.hasNewer=page.has_newer;this.cursor=page.head_sequence;}
        this.onchange(this.values(),older,page);
    }
    async focusMessage(message){
        if(!Number.isSafeInteger(message)||message<1||!this.id||this.busy)return false;const id=this.id,generation=this.generation;
        const page=await this.transport.request('api.php?'+new URLSearchParams({get_conversation:'1',scope:this.transport.role,user_id:id,around_sequence:String(message),limit:'50'}));
        if(generation!==this.generation||id!==this.id)return false;this.controller?.abort();this.generation++;this.messages.clear();this.epoch=null;this.cursor=0;this.merge(page,true);return true;
    }
    async sync(older=false){
        // A bounded history window must not hide a gap behind the live cursor.
        // Return to a fresh latest page explicitly rather than appending across it.
        if(!this.id||this.busy||(!older&&this.hasNewer))return false;this.busy=true;
        const generation=this.generation,id=this.id,controller=new AbortController();this.controller=controller;
        let changed=false;
        try{
            let through=null;
            for(let pages=0;pages<10;pages++){
                const query=new URLSearchParams({get_conversation:'1',protocol_version:'2',scope:this.transport.role,user_id:id,limit:'50'});
                if(older){const first=this.values()[0];if(!first)return false;query.set('before_sequence',String(first.id));}
                else if(this.epoch){query.set('after_sequence',String(this.cursor));if(through!==null)query.set('through_sequence',String(through));}
                if(this.epoch){query.set('store_epoch',this.epoch);const ids=[...this.messages.keys()];if(ids.length){query.set('edit_after',String(this.editCursor||0));query.set('known_from',String(Math.min(...ids)));query.set('known_through',String(Math.max(...ids)));}}

                const page=await this.transport.request(`api.php?${query}`,{signal:controller.signal});
                if(generation!==this.generation)return false;
                const before=this.cursor;this.merge(page,older);changed ||= this.cursor!==before;
                if(older||!page.edit_has_more&&(!page.has_more||before===0||page.next_after===before))break;
                through=page.high_watermark;await new Promise(resolve=>setTimeout(resolve,0));
            }
            return changed;
        }catch(error){
            if(generation!==this.generation)return false;
            if(error.code==='cursor_reset_required'){this.messages.clear();this.cursor=0;this.epoch=null;this.editCursor=0;this.attention=null;this.onchange([],true);}
            throw error;
        }finally{this.busy=false;}
    }
}
