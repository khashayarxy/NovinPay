import {Outbox,blockedReason} from './outbox.js?v=team1';
import {Transport,ConversationSync,Poller,RequestError,apiHealth} from './network.js?v=team1';
const presenceBudget=new Map();
export class ChatEngine {
    constructor(role,hooks={},dependencies={}) {
        this.role=role;this.hooks=hooks;this.transport=new Transport(role,dependencies);this.box=new Outbox(dependencies);
        this.ackSamples=[];this.context=null;this.scope=null;this.initialized=false;this.paused=false;this.frozen=false;this.sending=false;this.timer=null;
        this.knownAcks=new Map();this.lastPresence=0;this.queueBusy=false;
        this.sendCooldowns=new Map();
        this.sync=new ConversationSync(this.transport,(...args)=>this.hooks.messages?.(...args));
        this.poller=new Poller(async()=>{
            const changed=await this.sync.sync();
            await this.presence();
            if(this.sync.hasNewer)await this.hooks.gap?.();
            this.hooks.recovered?.();this.hooks.connection?.('متصل؛ ثبت در سرور فقط با تأیید هر پیام');return changed;
        },{visible:()=>!document.hidden&&this.hooks.visible?.()!==false,onerror:e=>this.handleError(e)});
        this.box.onchange=event=>{
            if(event.type==='read'&&event.scope===this.scope&&event.tab!==this.box.tab)this.poller.wake();
            if(event.type==='logout'&&event.scope===this.scope){this.freeze();this.hooks.identity?.('از این هویت در تب دیگر خارج شده‌اید.');}
            this.renderQueue().catch(e=>this.hooks.warning?.(e.message));
        };
        this.wake=()=>{this.poller.wake();this.scheduleSender(0);};
        this.queueTicker=setInterval(()=>this.renderQueue().catch(()=>{}),2000);
        window.addEventListener('online',this.wake);document.addEventListener('visibilitychange',this.wake);
    }
    async initialize() {
        const bootstrapStarted=Date.now();this.context=await this.transport.refresh(this.scope,{timeout:10000});this.startupRtt=Date.now()-bootstrapStarted;this.scope=this.context.scope_id;
        this.box.restoreTemporary(this.scope);this.restoreSendCooldowns();
        const [consent,snapshot]=await Promise.allSettled([this.box.consent(this.scope),this.box.snapshot()]);
        this.box.persistent=consent.status==='fulfilled'&&consent.value;
        this.storageDegraded=consent.status==='rejected'||snapshot.status==='rejected';
        if(this.storageDegraded){this.box.persistent=false;this.hooks.warning?.('ذخیرهٔ محلی در دسترس نیست؛ صف قبلی حفظ شده است. ارسال جدید فعلاً در حافظهٔ همین صفحه است.','storage');}
        const rows=snapshot.status==='fulfilled'?snapshot.value:[...this.box.memory.values()];
        this.hooks.mode?.(this.box.persistent);this.initialized=true;
        this.hooks.queue?.(rows.filter(r=>r.scope===this.scope),this.sync.id,this.knownAcks);
        const other=rows.filter(r=>r.role===this.role&&r.scope!==this.scope&&r.state!=='accepted');this.hooks.stranded?.(other.length);
        if(other.length)this.hooks.warning?.('صف نشست قبلی روی این دستگاه حفظ شده و با این هویت ارسال نمی‌شود.');
        this.scheduleSender(0);return this.context;
    }
    async presence() {
        if(this.role==='operator'||this.frozen||this.paused||!this.context)return;
        const scope=this.scope,key='support-presence:'+scope+':'+this.context.session_epoch;
        const run=async()=>{
            const now=Date.now();let stored=0;try{stored=Number(localStorage.getItem(key))||0;}catch{}
            const until=Math.max(presenceBudget.get(key)||0,stored>now&&stored<=now+86400000?stored:0);
            if(now<until||this.frozen||this.paused||scope!==this.scope)return;
            const save=next=>{presenceBudget.set(key,next);try{localStorage.setItem(key,String(next));}catch{}};
            save(now+45000);this.lastPresence=now;
            try{
                const data={scope:this.role,user_id:this.sync.id};
                if(this.sync.id&&this.draftConversation!==this.sync.id)await this.transport.request('update_activity.php',{data});
                if(!this.frozen&&!this.paused&&scope===this.scope){const hint=await this.transport.request('get_activity.php');if(!this.frozen&&!this.paused&&scope===this.scope)this.hooks.presence?.(hint);}
            }catch(e){save(Math.max(now+45000,Date.now()+(e.retryAfter||0)));if(e.status===401||e.status===403)throw e;this.hooks.presence?.({admin_online:null});}
        };
        // Timestamp only, scoped by installation/principal/session. Locks coalesce tabs;
        // storage failures fall back to memory and the unchanged server rate limit.
        if(globalThis.navigator?.locks)await navigator.locks.request(key,{ifAvailable:true},lock=>lock?run():undefined);else await run();
    }
    async interact() {
        if(this.role!=='operator'||this.frozen||document.hidden||!document.hasFocus()||Date.now()-(this.lastInteraction||0)<60000)return;
        this.lastInteraction=Date.now();
        try{await this.transport.request('api.php',{data:{operator_activity:'1'}});}catch(e){if(e.status===401||e.status===403)this.handleError(e);else this.lastInteraction=Date.now()+Math.max(0,(e.retryAfter||0)-60000);}
    }
    async auth() {
        if(this.authJob)return this.authJob;
        const job=this.transport.refresh(this.scope).then(context=>{this.context=context;return context;}).catch(e=>{this.handleError(e);throw e;}).finally(()=>{if(this.authJob===job)this.authJob=null;});this.authJob=job;return job;
    }
    sendWait(conversation=this.sync.id){return this.role==='customer'?Math.max(0,(this.sendCooldowns.get(conversation)||0)-this.transport.now()):0;}
    cooldownKey(){return 'support-send-cooldown:'+this.scope+':'+this.context?.session_epoch;}
    restoreSendCooldowns(){
        if(this.role!=='customer')return;
        try{const records=JSON.parse(sessionStorage.getItem(this.cooldownKey())||'[]');if(!Array.isArray(records)||records.length>32)return;const now=this.transport.now();for(const [id,until]of records)if(typeof id==='string'&&Number.isFinite(until)&&until>now&&until<=now+86400000)this.sendCooldowns.set(id,until);}catch{}
    }
    restrictSend(conversation,error){
        if(this.role!=='customer'||!conversation||error.status!==429||!(error.retryAfter>0))return false;
        const now=this.transport.now();this.sendCooldowns.set(conversation,Math.max(this.sendCooldowns.get(conversation)||0,now+error.retryAfter));
        try{sessionStorage.setItem(this.cooldownKey(),JSON.stringify([...this.sendCooldowns].filter(([,until])=>until>now).slice(-32)));}catch{}
        this.hooks.cooldown?.(conversation);return true;
    }
    assertSendAllowed(conversation=this.sync.id){const wait=this.sendWait(conversation);if(wait)throw new RequestError('ارسال پیام موقتاً محدود شده است؛ پیش‌نویس حفظ شده است.',429,'send_cooldown',wait);}
    handleError(error,conversation=null) {
        const restricted=this.restrictSend(conversation,error);
        if(error.retryAfter>0&&!restricted&&conversation)this.senderNotBefore=Math.max(this.senderNotBefore||0,Date.now()+error.retryAfter);
        this.hooks.connection?.(error.status===429?'در انتظار سهمیهٔ سرور':'ارتباط تأیید نشده؛ متن‌های صف حفظ شده‌اند');
        if(!restricted&&(error.status&&error.status<500||apiHealth.state==='unavailable'))this.hooks.warning?.(error.message,!error.status||error.status>=500?'connectivity':'chat');
        if([401,403,426].includes(error.status)){
            closeImagePreviews();
            this.paused=true;this.poller.stop();
            if(error.code==='identity_changed'){this.freeze();this.hooks.identity?.(error.message);}
        }
        if(this.role==='operator'&&error.status===401)this.hooks.sessionExpired?.(error);
        if(this.role==='customer'&&(error.status===404&&error.code==='conversation_unavailable'||error.status===409&&error.code==='conversation_retired')){
            this.paused=true;this.poller.stop();
            this.hooks.unavailable?.(error);
        }
    }
    async setPersistence(value) {
        if(value){
            if(!confirm('متن پیام‌های ارسال‌شده تا دریافت تأیید، روی همین مرورگر ذخیره می‌شود. روی دستگاه مشترک ممکن است دیگران به آن دسترسی داشته باشند. مهلت ارسال هر پیام ۲۴ ساعت است؛ متن بدون تصمیم شما حذف نمی‌شود. فعال شود؟'))return false;
            await this.box.setConsent(this.scope,true);
        }else{
            // Existing persistent pending text stays until ACK or explicit deletion.
            await this.box.setConsent(this.scope,false);this.box.persistent=false;
        }
        this.hooks.mode?.(this.box.persistent);return this.box.persistent;
    }
    select(id) {
        if(this.sync.id!==id)closeImagePreviews();
        this.sync.select(id);
        this.poller.start();this.renderQueue().catch(e=>this.hooks.warning?.(e.message));this.scheduleSender(0);
    }
    async enqueue(text,attachments=[],replyTo=null,faqArticle=null,teamThrough=null) {
        if(this.frozen||this.paused||!this.sync.id)throw new Error('ابتدا هویت و گفتگوی معتبر را برقرار کنید؛ متن حفظ شده است.');
        this.assertSendAllowed();
        if(this.hooks.canSend?.(this.sync.id)===false)throw new Error('این گفتگو پایان یافته است؛ پیش‌نویس حفظ شد.');
        const row=await this.box.enqueue(this.context,this.sync.id,text,{attachments,replyTo,faqArticle,teamThrough:this.role==='operator'?(teamThrough??Math.max(0,...this.sync.values().filter(m=>m.type==='user'&&Number.isSafeInteger(m.id)).map(m=>m.id))):null,sourcePath:this.role==='customer'?(Object.hasOwn(globalThis,'SupportSourcePath')?globalThis.SupportSourcePath:location.pathname):null});
        if(this.box.temporaryUnavailable&&!this.box.persistent)this.hooks.warning?.('بازیابی صف موقت در این مرورگر در دسترس نیست؛ پیش از تازه‌سازی منتظر تأیید پیام بمانید.','storage');this.hooks.queued?.(row);await this.renderQueue();this.scheduleSender(0);return row;
    }
    async renderQueue() {
        if(!this.initialized||this.queueBusy||!this.scope)return;this.queueBusy=true;
        try{
            const rows=await this.box.list(this.scope);
            this.hooks.queue?.(this.frozen?[]:rows,this.sync.id,this.knownAcks);
        }finally{this.queueBusy=false;}
    }
    scheduleSender(delay=2000) {
        clearTimeout(this.timer);
        if(this.frozen||this.paused)return;
        this.timer=setTimeout(()=>this.drain(),Math.max(delay,(this.senderNotBefore||0)-Date.now()));
    }
    pendingGrace(){const configured=globalThis.SupportWidgetConfig?.pending_indicator_grace_ms;if(Number.isFinite(configured))return Math.max(0,Math.min(2000,configured));const samples=[...this.ackSamples].sort((a,b)=>a-b);const base=samples.length?samples[Math.ceil(samples.length*.75)-1]:Math.max(globalThis.navigator?.connection?.rtt||0,(this.startupRtt||100)*2);return Math.min(1200,Math.max(150,base*1.5));}
    async accept(row,ack) {
        if(row.attempts===1&&Date.now()-row.created<10000){this.ackSamples.push(Date.now()-row.created);this.ackSamples=this.ackSamples.slice(-20);globalThis.SupportDeliveryMetrics={ack_ms:[...this.ackSamples],grace_ms:this.pendingGrace()};}
        const submitted={...row,attachments:[...(row.attachments||[])]};
        this.knownAcks.set(row.key,ack);
        try{await this.box.accepted(row.key,ack);}catch(e){this.hooks.warning?.('پیام در سرور ثبت شد؛ پاک‌سازی نسخه محلی انجام نشد. متن تا بررسی بعدی حفظ می‌شود.');}
        this.hooks.accepted?.(submitted,ack);this.poller.wake();
    }
    async drain() {
        if(this.sending||this.frozen)return;
        this.sending=true;let progressed=false;
        try{
            if(this.paused||document.hidden||Date.now()<(this.senderNotBefore||0))return;
            const rows=(await this.box.list(this.scope)).filter(r=>r.state!=='accepted');
            const heads=new Map();for(const row of rows)if(!heads.has(row.conversation))heads.set(row.conversation,row);
            for(const row of heads.values()){
                if(this.knownAcks.has(row.key)){await this.box.accepted(row.key,this.knownAcks.get(row.key));continue;}
                // A read-only receipt can resolve a lost ACK even while an old tab's
                // lease is alive. It never grants permission to start a second send.
                if(row.attempts>0&&row.epoch===this.context.session_epoch&&Date.now()-(this.lastReconcile||0)>=10000){
                    this.lastReconcile=Date.now();await this.auth();
                    const receipt=await this.transport.receipt(row);
                    if(receipt){await this.accept(row,receipt);progressed=true;continue;}
                }
                if(this.sendWait(row.conversation))continue;
                const reason=blockedReason(row,this.box.now());
                if(reason && row.state!=='needs_action' && row.state!=='cancelled'){await this.box.update(row.key,r=>({...r,state:'needs_action',error:reason}));continue;}
                if(['needs_action','cancelled'].includes(row.state)||row.nextAttempt>this.box.now()||row.leaseUntil>this.box.now())continue;
                // Recheck the authenticated identity before replay, never a localStorage ID.
                await this.auth();
                if(this.paused||this.frozen)return;
                if(row.epoch!==this.context.session_epoch){await this.box.update(row.key,r=>({...r,state:'needs_action',error:'ورود دوباره انجام شده؛ ادامهٔ این پیام نیازمند تأیید شماست.'}));continue;}
                const reserved=await this.box.reserve(row.key,this.context);
                if(!reserved||reserved.state!=='sending'||reserved.leaseOwner!==this.box.tab)continue;
                await this.renderQueue();
                try{
                    // Recovered or previously attempted messages reconcile before resend.
                    const ack=reserved.attempts>1?await this.transport.receipt(reserved):null;
                    await this.accept(reserved,ack||await this.transport.send(reserved));progressed=true;
                }catch(error){
                    this.handleError(error,reserved.conversation);
                    // Keep rejected/unknown text and its UUID; the bounded outbox
                    // retry and message-specific cooldown decide when it may resume.
                    await this.box.fail(reserved.key,error);
                }
                break;
            }
        }catch(error){this.handleError(error);}
        finally{this.sending=false;await this.renderQueue().catch(e=>this.hooks.warning?.(e.message));this.scheduleSender(document.hidden?45000:progressed?0:2000);}
    }
    async retry(row) {
        if(this.frozen)throw new Error('هویت قبلی بسته شده است.');
        await this.auth();
        if(row.scope!==this.context.scope_id)throw new Error('صف به هویت دیگری تعلق دارد.');
        if(row.epoch!==this.context.session_epoch && !confirm('ورود دوباره انجام شده است. پس از بررسی مجوز گفتگو، همین پیام با شناسهٔ قبلی ادامه یابد؟'))return;
        const receipt=await this.transport.receipt(row);
        if(receipt){await this.accept(row,receipt);return;}
        this.assertSendAllowed(row.conversation);
        this.paused=false;await this.box.retry(row.key,this.context);this.poller.start();this.scheduleSender(0);
    }
    async cancel(row) {
        if(!confirm(row.attempts?'فقط تلاش‌های بعدی متوقف می‌شود؛ درخواست قبلی ممکن است ثبت شده باشد. ادامه؟':'پیام هنوز ارسال نشده است. متن محلی این پیام حذف شود؟'))return;
        await this.box.cancel(row.key);await this.renderQueue();
    }
    async deleteLocal(row) {
        if(!confirm('متن این پیام فقط از صف همین دستگاه حذف شود؟ این کار پیام ثبت‌شده در سرور را حذف نمی‌کند.'))return;
        await this.box.update(row.key,()=>null);await this.renderQueue();
    }
    async clearStranded() {
        const rows=(await this.box.all()).filter(r=>r.role===this.role&&r.scope!==this.scope&&r.state!=='accepted');
        if(!rows.length)return;
        if(!confirm('متن صف نشست‌های قبلیِ همین نقش فقط از این دستگاه حذف شود؟ این متن‌ها تحت هویت فعلی ارسال نمی‌شوند. پیام ثبت‌شده در سرور حذف نمی‌شود.'))return;
        const scopes=new Set(rows.map(r=>r.scope));for(const scope of scopes){if(!/^[a-f0-9]{64}$/.test(scope))throw new Error('صف قدیمی خراب است؛ پاک‌سازی خودکار انجام نشد.');await this.box.deleteScope(scope);}
        this.hooks.stranded?.(0);
    }
    freeze(){closeImagePreviews();this.frozen=true;this.paused=true;clearTimeout(this.timer);this.poller.stop();this.sync.select(null);}
    async logout() {
        this.paused=true;clearTimeout(this.timer);this.poller.stop();
        const rows=(await this.box.list(this.scope)).filter(r=>r.state!=='accepted');
        const exitInfo=this.role==='customer'?'خروج، هویت این مرورگر را از گفتگو جدا می‌کند؛ سابقهٔ گفتگو در سرور حذف نمی‌شود. برای بازگشت به سابقه به روش بازیابی فعال نیاز دارید. ':'';
        const question=exitInfo+(rows.length?`${rows.length} پیام حل‌نشده روی این دستگاه دارید. خروج و حذف متن محلی همین هویت؟ درخواست فعال ممکن است در سرور ثبت شود. برای ماندن و بررسی، انصراف را بزنید.`:'از گفتگوی پشتیبانی خارج می‌شوید؟');
        if(!confirm(question)){this.paused=false;this.poller.start();this.scheduleSender(0);return false;}
        try{
            await this.transport.request(this.role==='customer'?'api.php':'logout.php',{data:this.role==='customer'?{logout_customer:'1'}:{},timeout:20000});
        }catch(e){
            // Operator logout is a normal 303 redirect to login HTML; handled separately below.
            if(this.role!=='operator'){this.paused=false;this.handleError(e);if(!this.paused){this.poller.start();this.scheduleSender(0);}throw e;}
            // No assumption that a malformed reply proves logout: independently check auth.
            try{await this.transport.refresh(this.scope);this.paused=false;this.poller.start();this.scheduleSender(0);throw new Error('خروج تأیید نشد؛ متن محفوظ است.');}
            catch(check){if(check.status!==401){this.handleError(check);throw check;}}
        }
        this.freeze();
        try{await this.box.deleteScope(this.scope);}catch(e){this.box.signal('logout',this.scope);this.hooks.warning?.('از سرور خارج شدید، اما حذف صف محلی ممکن نشد. دادهٔ این هویت خودکار نمایش یا ارسال نمی‌شود.');}
        this.hooks.identity?.('خروج انجام شد.');return true;
    }
    destroy(){this.freeze();clearInterval(this.queueTicker);window.removeEventListener('online',this.wake);document.removeEventListener('visibilitychange',this.wake);this.box.close();}
}
export function queueView(host,engine,{compact=false}={}) {
    let signature='';
    return (rows,conversation,knownAcks)=>{
        const next=JSON.stringify([rows,conversation,[...knownAcks.keys()],compact?Math.floor(Date.now()/10000):0]);if(next===signature)return;signature=next;
        const expanded=compact?new Set([...host.querySelectorAll('.support-pending details[open]')].map(el=>el.parentElement.dataset.uuid)):new Set();
        const focused=compact&&host.contains(document.activeElement)?document.activeElement:null,focusId=focused?.closest('.support-pending')?.dataset.uuid;
        host.replaceChildren();
        const pending=rows.filter(r=>r.state!=='accepted'&&r.conversation===conversation);
        for(const row of pending){
            const item=document.createElement('article');item.className='support-pending'+(compact?' support-pending-compact':'');item.dataset.uuid=row.uuid;
            const text=document.createElement('p');text.textContent=row.text;item.append(text);
            const state=document.createElement('small');
            state.textContent=knownAcks.has(row.key)?'در سرور ثبت شد؛ پاک‌سازی محلی در انتظار است.':row.error||({pending:row.persistent?'روی این دستگاه ذخیره شد؛ در صف ارسال':'در حافظهٔ همین صفحه؛ هنوز در سرور ثبت نشده',sending:'در حال ارسال؛ ثبت هنوز تأیید نشده',unknown:'نتیجه هنوز مشخص نیست؛ تلاش مجدد با همان شناسه',needs_action:'نیازمند اقدام شما',cancelled:'تلاش بعدی متوقف است'}[row.state]);
            let actions=item;
            if(compact){const details=document.createElement('details'),summary=document.createElement('summary');summary.className='support-icon';summary.textContent='more_horiz';summary.setAttribute('aria-label','گزینه‌های پیام ارسال‌نشده');summary.title='گزینه‌های پیام';details.hidden=Date.now()-row.created<10000&&!['needs_action','cancelled'].includes(row.state);details.open=expanded.has(row.uuid);details.append(summary);if(row.error){const error=document.createElement('small');error.textContent=row.error;details.append(error);}item.append(details);actions=details;
                state.textContent=knownAcks.has(row.key)?'ثبت شد؛ بررسی دستگاه باقی است':(Date.now()-row.created<10000&&!['needs_action','cancelled'].includes(row.state)?'…':({needs_action:'نیازمند بررسی شما',cancelled:'تلاش متوقف شد'}[row.state]||'ارسال تأیید نشد'));}
            item.append(state);
            if(compact)item.append(actions);
            for(const [name,action]of [['تلاش مجدد',()=>engine.retry(row)],['توقف تلاش',()=>engine.cancel(row)],['کنار گذاشتن پیش‌نویس',()=>engine.deleteLocal(row)]]){
                const button=document.createElement('button');button.type='button';button.textContent=name;
                button.disabled=row.state==='sending'&&row.leaseUntil>engine.box.now()&&name!=='توقف تلاش';
                button.addEventListener('click',()=>action().catch(e=>engine.hooks.warning?.(e.message)));actions.append(button);
            }
            host.append(item);
            if(focused&&focusId===row.uuid){const target=focused.tagName==='SUMMARY'?item.querySelector('summary'):[...item.querySelectorAll('button')].find(b=>b.textContent===focused.textContent);target?.focus();}
        }
    };
}
export function controls(parent) {
    const panel=document.createElement('section');panel.className='support-controls';
    const connection=document.createElement('p');connection.className='support-connection';connection.setAttribute('role','status');connection.textContent='در حال بررسی ارتباط';
    const label=document.createElement('label'), opt=document.createElement('input');opt.type='checkbox';opt.dataset.supportPersistence='1';
    label.append(opt,document.createTextNode(' ذخیرهٔ موقت پیام‌های ارسال‌شده روی این دستگاه'));
    const privacy=document.createElement('small');privacy.textContent='مهلت ارسال هر پیام ۲۴ ساعت است؛ متن حل‌نشده حذف نمی‌شود. در حالت بدون ذخیره، refresh ممکن است متن را از بین ببرد.';
    const errors=document.createElement('p');errors.setAttribute('role','alert');errors.hidden=true;
    const queue=document.createElement('div');queue.className='support-outbox';queue.setAttribute('aria-live','polite');
    const clearOld=document.createElement('button');clearOld.type='button';clearOld.hidden=true;clearOld.textContent='حذف محلی صف نشست‌های قبلی';
    panel.append(connection,label,privacy,clearOld,errors,queue);parent.append(panel);
    return {panel,opt,queue,connection,clearOld,warn:message=>{errors.textContent=message;errors.hidden=false;},clear:()=>{errors.textContent='';errors.hidden=true;}};
}
// Optimistic text is scoped to this mounted session. ACK bridges polling without
// losing a bubble; only server messages replace it. Outbox remains authoritative.
export function conversationView(host,engine,{scroll=null,renderer=renderTranscript}={}){
    let messages=[],conversation=null,historyLoaded=false;const pending=new Map(),presentationTimes=new Map();
    const render=(older=false,animate=false)=>{
        const seen=new Set(messages.filter(m=>m.client_message_id).map(m=>m.type+':'+m.client_message_id));
        for(const [key,row] of pending)if(seen.has((row.role==='operator'?'admin':'user')+':'+row.uuid)){pending.delete(key);presentationTimes.delete(key);}
        const optimistic=[...pending.values()].sort((a,b)=>a.ordinal-b.ordinal).map(row=>{const ack=engine.knownAcks.get(row.key);if(!presentationTimes.has(row.key))presentationTimes.set(row.key,engine.transport?.presentationTime?.(row.created)??row.created);return {_presentation_at:presentationTimes.get(row.key),id:ack?.message_id||'pending-'+row.uuid,accepted_at:ack?.accepted_at,client_message_id:row.uuid,type:engine.role==='operator'?'admin':'user',text:row.text,attachments:row.attachments,reply_to:row.replyTo,_row:row,_ack:!!ack};});
        const anchor=scroll?.before(older);
        const ordered=[...messages,...optimistic];const confirmed=ordered.filter(m=>Number.isSafeInteger(m.id)).sort((a,b)=>a.id-b.id),unconfirmed=ordered.filter(m=>!Number.isSafeInteger(m.id));renderer(host,[...confirmed,...unconfirmed],{compact:true,older,animate,engine,follow:scroll?.follow()??true});
        if(anchor)scroll.after(anchor,older);
    };
    return {
        reset(){messages=[];pending.clear();presentationTimes.clear();historyLoaded=false;conversation=null;host.replaceChildren();},
        messages(value,older){const animate=historyLoaded&&!older;historyLoaded=true;messages=value;render(older,animate);},
        accepted(row){if(!engine.frozen&&row.scope===engine.scope&&row.conversation===engine.sync.id){pending.set(row.key,row);render();}},
        queue(rows,id){if(conversation!==id){messages=[];pending.clear();presentationTimes.clear();historyLoaded=false;conversation=id;}
            const active=new Set(rows.filter(r=>r.state!=='accepted'&&r.conversation===id).map(r=>r.key));
            for(const [key] of pending)if(!active.has(key)&&!engine.knownAcks.has(key)){pending.delete(key);presentationTimes.delete(key);}
            for(const row of rows)if(row.state!=='accepted'&&row.conversation===id)pending.set(row.key,{...row});
            render(false,historyLoaded);
        }
    };
}
export function closeImagePreviews(){for(const dialog of document.querySelectorAll('.support-image-viewer')){dialog.close();dialog.remove();}}
export function openImagePreview(url,origin){
    closeImagePreviews();const dialog=document.createElement('dialog');dialog.className='support-image-viewer';dialog.setAttribute('aria-label','نمایش تصویر گفتگو');
    const close=document.createElement('button');close.type='button';close.className='support-image-close support-icon';close.textContent='close';close.setAttribute('aria-label','بستن تصویر');
    const image=document.createElement('img');image.alt='تصویر کامل گفتگو';const status=document.createElement('p');status.setAttribute('role','status');const retry=document.createElement('button');retry.type='button';retry.textContent='تلاش مجدد';retry.hidden=true;
    const load=()=>{status.textContent='در حال دریافت تصویر…';image.hidden=true;retry.hidden=true;image.src=url;};image.onload=()=>{image.hidden=false;status.textContent='';};image.onerror=()=>{image.hidden=true;image.removeAttribute('src');status.textContent='تصویر در دسترس نیست. اتصال و نشست خود را بررسی کنید.';retry.hidden=false;};
    retry.onclick=load;close.onclick=()=>dialog.close();dialog.addEventListener('close',()=>{image.removeAttribute('src');dialog.remove();if(origin.isConnected)origin.focus();},{once:true});dialog.addEventListener('click',event=>{if(event.target===dialog){const r=dialog.getBoundingClientRect();if(event.clientX<r.left||event.clientX>r.right||event.clientY<r.top||event.clientY>r.bottom)dialog.close();}});
    dialog.append(close,image,status,retry);document.body.append(dialog);dialog.showModal();load();
}
export function renderTranscript(host,messages,{operator=false,older=false,compact=false,engine=null,follow=true}={}) {
    const bottom=host.scrollHeight-host.scrollTop-host.clientHeight<80,oldHeight=host.scrollHeight;
    const messageKey=m=>!operator&&m.type==='user'&&m.client_message_id?'user:'+m.client_message_id:'message:'+m.id;
    const rows=[];let previousDay=null;const now=new Date(),today=now.toDateString();for(const m of messages){const stamp=m.accepted_at?new Date(m.accepted_at):m._row?new Date(m._row.created):new Date(String(m.time).replace(' ','T'));if(!Number.isNaN(stamp.getTime())){const day=stamp.toDateString();if(day!==previousDay){rows.push({_day:day,_label:day===today?'امروز':stamp.toLocaleDateString('fa-IR',{month:'long',day:'numeric'}),_key:'day:'+day+':'+messageKey(m)});previousDay=day;}}rows.push(m);}messages=rows;
    const key=m=>m._day?m._key:messageKey(m),keys=new Set(messages.map(key));let position=0;
    for(const el of [...host.children])if(!keys.has(el.dataset.messageKey))el.remove();
    for(const m of messages){
        let el=[...host.children].find(e=>e.dataset.messageKey===key(m));
        if(!el){el=document.createElement('div');el.dataset.messageKey=key(m);}
        if(m._day){el.className='support-day-marker';el.textContent=m._label;el.setAttribute('role','separator');el.setAttribute('aria-label',m._label);if(host.children[position]!==el)host.insertBefore(el,host.children[position]||null);position++;continue;}
        el.dataset.messageId=String(m.id);el.dataset.incoming=String(operator?m.type==='user':m.type==='admin');
        const signature=JSON.stringify([m.text,m.attachments,m.accepted_at,m.time,m.reference,m.reply_to,m.sender,m.read_receipt,m._row?.state,m._row?.error,m._ack,m._row?Date.now()-m._row.created>=10000:false]);
        if(el.dataset.content!==signature){
            const open=el.querySelector('details')?.open,focused=el.contains(document.activeElement)?document.activeElement.textContent:null;
            el.replaceChildren();el.dataset.content=signature;
            el.className=operator?(m.type==='user'?'message-user':'message-admin'):`chat-msg ${m.type==='user'?'user':'admin'}`;
            if((operator&&m.type==='user')||(!operator&&m.type==='admin')){const avatar=document.createElement('span');avatar.className='support-sender-avatar';avatar.setAttribute('aria-label',operator?'مشتری':'پشتیبانی');if(!operator&&m.sender?.avatar&&/^support-avatar\.php\?id=[a-f0-9]{32}$/.test(m.sender.avatar)){const img=document.createElement('img');img.src=m.sender.avatar;img.alt=m.sender.name||'پشتیبانی';img.onerror=()=>{img.remove();avatar.textContent='پ';};avatar.append(img);}else if(operator)avatar.textContent=(engine?.sync?.userName||'مهمان').trim().slice(0,1);else{const icon=document.createElement('span');icon.className='support-icon';icon.textContent='support_agent';avatar.append(icon);}el.append(avatar);if(!operator&&m.sender?.name){const name=document.createElement('small');name.className='support-sender-name';name.textContent=m.sender.name;el.append(name);}}
            const text=document.createElement('span');text.className='support-message-text';text.textContent=m.text;
            const time=document.createElement('small');time.className=operator?'message-time':'chat-time';
            const stamp=m.accepted_at?new Date(m.accepted_at):new Date(String(m.time).replace(' ','T'));
            time.textContent=(Number.isNaN(stamp.getTime())?'زمان قدیمی':stamp.toLocaleTimeString('fa-IR',{hour:'2-digit',minute:'2-digit'}))+(compact?'':m.accepted_at?' · در سرور ثبت شد':' · سابقهٔ قدیمی');time.setAttribute('aria-label',m.accepted_at?'ثبت‌شده در '+time.textContent:time.textContent);
            if(m.reply_to){const quote=document.createElement('button');quote.type='button';quote.className='support-reply-reference';quote.textContent=m.reference?.available?(m.reference.text||'تصویر پیوست'):'پیام مرجع در دسترس نیست';quote.disabled=m.reference?.available!==true;quote.setAttribute('aria-label','رفتن به پیام مرجع');quote.onclick=()=>host.dispatchEvent(new CustomEvent('support-reference',{bubbles:true,detail:{id:m.reply_to}}));el.append(quote);}
            el.append(text);
            if(m._row&&!m._ack){
                el.classList.add('support-pending');el.dataset.uuid=m._row.uuid;el.setAttribute('aria-label','پیام در انتظار تأیید');
                const row=m._row,late=Date.now()-row.created>=10000||['needs_action','cancelled'].includes(row.state);
                time.textContent=late?'ارسال تأیید نشد · ':'…';el.append(time);
                if(m.attachments?.length){const hint=document.createElement('small');hint.textContent=`${m.attachments.length} تصویر پیوست`;el.append(hint);}
                if(late&&engine){
                    const retry=document.createElement('button');retry.type='button';retry.textContent='تلاش مجدد';retry.onclick=()=>engine.retry(row).catch(e=>engine.hooks.warning?.(e.message));el.append(retry);
                    const details=document.createElement('details'),summary=document.createElement('summary');summary.className='support-icon';summary.textContent='more_horiz';summary.setAttribute('aria-label','گزینه‌های پیام ارسال‌نشده');summary.title='گزینه‌های پیام';details.open=!!open;details.append(summary);
                    if(row.error){const error=document.createElement('small');error.textContent=row.error;details.append(error);}
                    for(const [name,action]of [['توقف تلاش',()=>engine.cancel(row)],['کنار گذاشتن پیش‌نویس',()=>engine.deleteLocal(row)]]){const button=document.createElement('button');button.type='button';button.textContent=name;button.onclick=()=>action().catch(e=>engine.hooks.warning?.(e.message));details.append(button);}el.append(details);
                }
            }else{el.removeAttribute('aria-label');delete el.dataset.uuid;if(m._ack)time.textContent='ثبت شد';el.append(time);
            if(m.attachments?.length){const images=document.createElement('div');images.className='support-message-images';for(const id of m.attachments){if(!/^[a-f0-9]{32}$/.test(id))continue;const link=document.createElement('a');link.href=`support-attachment.php?scope=${operator?'operator':'customer'}&id=${id}`;link.target='_blank';link.rel='noopener noreferrer';link.setAttribute('aria-label','باز کردن تصویر');const img=document.createElement('img');img.src=link.href;img.alt='تصویر پیوست گفتگو';img.loading='lazy';img.decoding='async';img.addEventListener('error',()=>{const failure=document.createElement('small');failure.className='support-image-fallback';failure.textContent='تصویر در دسترس نیست';link.replaceChildren(failure);},{once:true});link.addEventListener('click',event=>{if(event.button!==0||event.ctrlKey||event.metaKey||event.shiftKey||event.altKey)return;event.preventDefault();openImagePreview(link.href,link);});link.append(img);images.append(link);}el.append(images);}}
            if(Number.isSafeInteger(m.id)&&['user','admin'].includes(m.type)){const reply=document.createElement('button');reply.type='button';reply.className='support-message-reply support-icon';reply.textContent='reply';reply.setAttribute('aria-label','پاسخ به پیام');reply.onclick=()=>host.dispatchEvent(new CustomEvent('support-reply',{bubbles:true,detail:{id:m.id}}));el.append(reply);}
            if((operator&&m.type==='admin')||(!operator&&m.type==='user')){const receipt=document.createElement('span');receipt.className='support-read-state';receipt.textContent=m.read_receipt?'خوانده شد':m._row&&!m._ack?'در انتظار تأیید':m.accepted_at||m._ack?'ارسال شد':'سابقه';receipt.setAttribute('aria-label',receipt.textContent);el.append(receipt);}
            if(focused)[...el.querySelectorAll('button,summary')].find(e=>e.textContent===focused)?.focus();
        }
        if(host.children[position]!==el)host.insertBefore(el,host.children[position]||null);position++;
    }
    if(older)host.scrollTop+=host.scrollHeight-oldHeight;else if(bottom&&follow)host.scrollTop=host.scrollHeight;
}
