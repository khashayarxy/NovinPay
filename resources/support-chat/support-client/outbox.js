// Only submitted text, never cookies, CSRF, owner IDs or credentials, belongs here.
export const DB_NAME = globalThis.SupportWidgetConfig?.installation && globalThis.SupportWidgetConfig.installation!=='legacy-zizolearn' ? `support-outbox-${globalThis.SupportWidgetConfig.installation}-v1` : 'zizolearn-support-outbox-v1';
export const LIMIT_COUNT = 50, LIMIT_BYTES = 500 * 1024, DAY = 86400000;
export const uuid = () => crypto.randomUUID ? crypto.randomUUID() : '10000000-1000-4000-8000-100000000000'.replace(/[018]/g,c => (Number(c) ^ crypto.getRandomValues(new Uint8Array(1))[0] & 15 >> Number(c) / 4).toString(16));
export const validUUID = id => typeof id === 'string' && /^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/.test(id);
export function validReceipt(ack, row) {
    return !!ack && ack.protocol_version===2 && ack.status==='accepted' && ack.client_message_id===row.uuid
        && ack.conversation_id===row.conversation && Number.isSafeInteger(ack.message_id) && ack.message_id>0
        && ack.sequence===ack.message_id && typeof ack.replayed==='boolean' && typeof ack.accepted_at==='string'
        && /^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/.test(ack.accepted_at)
        && Number.isFinite(Date.parse(ack.accepted_at)) && new Date(ack.accepted_at).toISOString().replace('.000Z','Z')===ack.accepted_at;
}
const bytes = x => new TextEncoder().encode(JSON.stringify(x)).length;
const validScope = x => typeof x === 'string' && /^[a-f0-9]{64}$/.test(x);
const validConversation = x => typeof x === 'string' && /^user_[a-f0-9]{32}_[0-9]{1,20}$/.test(x);
const states = new Set(['pending','sending','unknown','needs_action','cancelled','accepted']);
export function validateText(value) {
    if (typeof value !== 'string' || !value.trim()) throw new Error('متن پیام لازم است.');
    const text = value.trim();
    for(const char of text){const point=char.codePointAt(0);if(point>=0xd800&&point<=0xdfff)throw new Error('متن دارای کاراکتر نامعتبر است.');}
    const encoded = text.replace(/[&<>"']/g,c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
    if (new TextEncoder().encode(encoded).length > 2000) throw new Error('متن پیام از محدودیت ۲۰۰۰ بایت بیشتر است.');
    return text;
}
export function validateRecord(r) {
    const fields=new Set(['version','scope','role','epoch','conversation','uuid','key','text','state','created','ordinal','attempts','firstAttempt','manualUntil','nextAttempt','backoffUntil','leaseUntil','leaseOwner','error','persistent','ack','acceptedLocal','attachments','replyTo','sourcePath','everAttempted','leaseFirstSubmission','faqArticle','teamThrough']);
    if(r&&Object.keys(r).some(key=>!fields.has(key)))throw new Error('نسخه یا ساختار صف محلی شناخته‌شده نیست.');
    if(r&&['everAttempted','leaseFirstSubmission'].some(key=>key in r&&typeof r[key]!=='boolean'))throw new Error('سابقهٔ تلاش صف معتبر نیست.');
    if (!r || r.version !== 1 || !validScope(r.scope) || !validConversation(r.conversation) || !validUUID(r.uuid)
        || !['customer','operator'].includes(r.role) || !states.has(r.state) || typeof r.epoch !== 'string' || !/^[a-f0-9]{32}$/.test(r.epoch)
        || !Number.isSafeInteger(r.ordinal) || !Number.isFinite(r.created) || r.created < 0
        || !Number.isInteger(r.attempts) || r.attempts < 0 || r.attempts > 8) throw new Error('دادهٔ صف محلی معتبر نیست؛ خودکار حذف یا ارسال نشد.');
    if('sourcePath'in r&&(typeof r.sourcePath!=='string'||r.sourcePath.length>512||!/^\/(?!\/)[a-zA-Z0-9_./-]*$/.test(r.sourcePath)||r.sourcePath.includes('..')))throw new Error('مسیر عمومی معتبر نیست.');
    if('faqArticle'in r&&(r.role!=='customer'||typeof r.faqArticle!=='string'||!/^[a-f0-9]{32}$/.test(r.faqArticle)))throw new Error('پرسش معتبر نیست.');
    if('teamThrough'in r&&(r.role!=='operator'||!Number.isSafeInteger(r.teamThrough)||r.teamThrough<0))throw new Error('مرز پاسخ معتبر نیست.');
    if('replyTo'in r&&(!Number.isSafeInteger(r.replyTo)||r.replyTo<1))throw new Error('مرجع پاسخ معتبر نیست.');
    if('attachments'in r&&(!Array.isArray(r.attachments)||r.attachments.length>3||r.attachments.some(x=>typeof x!=='string'||!/^[a-f0-9]{32}$/.test(x))||new Set(r.attachments).size!==r.attachments.length))throw new Error('پیوست معتبر نیست.');
    if (r.state !== 'accepted') {if(!r.attachments?.length||r.text)validateText(r.text);else if(r.text!=='')throw new Error('متن معتبر نیست.');}
    else if('text'in r||!validReceipt(r.ack,r)||!Number.isFinite(r.acceptedLocal))throw new Error('رسید محلی معتبر نیست.');
    if(typeof r.error!=='string'||r.error.length>400||typeof r.persistent!=='boolean'||typeof r.leaseOwner!=='string'||(r.leaseOwner!==''&&!validUUID(r.leaseOwner))) throw new Error('metadata صف محلی معتبر نیست.');
    if (r.key !== `${r.scope}|${r.conversation}|${r.uuid}`) throw new Error('شناسهٔ صف محلی ناسازگار است.');
    for (const field of ['nextAttempt','leaseUntil','firstAttempt','manualUntil','backoffUntil'])
        if (!Number.isFinite(r[field]) || r[field] < 0) throw new Error('زمان صف محلی معتبر نیست.');
    return r;
}
export function blockedReason(r, now) {
    if (now < r.created - 300000) return 'ساعت دستگاه ناسازگار است؛ بررسی لازم است.';
    if (r.attempts >= 8 || (r.firstAttempt && now - r.firstAttempt >= 900000)) return 'بودجهٔ تلاش تمام شد؛ تلاش دستی لازم است.';
    if (now - r.created >= DAY) return 'مهلت ارسال ۲۴ساعته تمام شد؛ متن حفظ شده است. برای پیام تازه متن را بررسی و دوباره وارد کنید.';
    return '';
}
export function backoff(attempt, random = Math.random) {
    const cap = Math.min(60000, 2000 * 2 ** Math.max(0, attempt - 1));
    return Math.round(cap / 2 + random() * cap / 2);
}
export class Outbox {
    constructor({now = Date.now, indexedDB: factory = globalThis.indexedDB, storageTimeout=4000} = {}) {
        this.now = now; this.factory = factory; this.memory = new Map();this.temporaryScopes=new Set(); this.db = null;
        this.tab = uuid(); this.persistent = false; this.openPromise = null;this.storageTimeout=storageTimeout;this.storageBlocked=false;this.closed=false;this.cancelOpen=null;
        this.channel = typeof BroadcastChannel !== 'undefined' ? new BroadcastChannel(DB_NAME) : null;
        this.onchange = () => {};
        if (this.channel) this.channel.onmessage = e => this.onchange(e.data);
    }
    signal(type = 'changed', scope = '') { this.remember(type,scope); this.channel?.postMessage({type, scope}); this.onchange({type, scope}); }
    temporaryKey(scope){return 'support-tab-outbox:'+scope;}
    remember(type,scope){try{if(type==='logout'){sessionStorage.removeItem(this.temporaryKey(scope));this.temporaryScopes.delete(scope);for(const [key,row]of this.memory)if(row.scope===scope)this.memory.delete(key);return;}const groups=new Map();for(const row of this.memory.values()){if(!groups.has(row.scope))groups.set(row.scope,[]);groups.get(row.scope).push(validateRecord(row));}for(const key of groups.keys())this.temporaryScopes.add(key);for(const key of this.temporaryScopes){const rows=this.pruneAccepted(groups.get(key)||[],{delete(){}});const text=JSON.stringify({at:this.now(),rows});if(text.length<=2097152)sessionStorage.setItem(this.temporaryKey(key),text);}}catch{this.temporaryUnavailable=true;/* A denied tab store never changes durable replay authority. */}}
    restoreTemporary(scope){this.temporaryScopes.add(scope);try{const raw=sessionStorage.getItem(this.temporaryKey(scope));if(!raw||raw.length>2097152)return;const value=JSON.parse(raw);if(!Number.isFinite(value.at)||this.now()-value.at>DAY||value.at>this.now()+60000||!Array.isArray(value.rows)||value.rows.length>512)return;const rows=value.rows.map(validateRecord);if(rows.some(r=>r.scope!==scope||r.persistent))return;for(const row of rows)if(!this.memory.has(row.key))this.memory.set(row.key,row);}catch{/* Untrusted browser storage never grants server ownership. */}}
    async open() {
        if(this.closed||this.storageBlocked)throw this.storageError();
        if (this.db) return this.db;
        if (this.openPromise) return this.openPromise;
        this.openPromise = new Promise((resolve,reject) => {
            let settled=false;const timer=setTimeout(()=>{settled=true;this.storageBlocked=true;reject(this.storageError());},this.storageTimeout);
            const fail=error=>{if(settled)return;settled=true;clearTimeout(timer);this.cancelOpen=null;reject(error);};this.cancelOpen=()=>fail(this.storageError());
            if (!this.factory) return fail(new Error('ذخیرهٔ محلی در این مرورگر در دسترس نیست.'));
            let request;try{request=this.factory.open(DB_NAME,1);}catch{fail(new Error('ذخیرهٔ محلی در دسترس نیست.'));return;}
            request.onupgradeneeded = () => {
                if(settled){request.transaction?.abort();return;}
                request.result.createObjectStore('items', {keyPath:'key'});
                request.result.createObjectStore('meta', {keyPath:'key'});
            };
            request.onerror = () => fail(new Error('ذخیرهٔ محلی ممکن نشد؛ متن را نگه دارید.'));
            request.onblocked = () => {this.storageBlocked=true;fail(this.storageError());};
            request.onsuccess = () => {
                if(settled||this.closed){request.result.close();return;}settled=true;clearTimeout(timer);this.cancelOpen=null;
                const db=request.result;this.db=db;
                db.onversionchange=()=>{db.close();if(this.db===db){this.db=null;this.openPromise=null;}};
                resolve(this.db);
            };
        }).catch(error => {this.openPromise = null;throw error;});
        return this.openPromise;
    }
    storageError(){return Object.assign(new Error('ذخیرهٔ محلی پاسخ نداد؛ صف قبلی حفظ شده است.'),{code:'storage_timeout'});}
    retryStorage(){if(this.closed)throw this.storageError();this.storageBlocked=false;this.openPromise=null;this.db?.close();this.db=null;}
    async read(storeName,query){
        const db=await this.open();return new Promise((resolve,reject)=>{
            const tx=db.transaction(storeName,'readonly');let settled=false;
            const timer=setTimeout(()=>{if(settled)return;settled=true;this.storageBlocked=true;try{tx.abort();}catch{}reject(this.storageError());},this.storageTimeout);
            let request;try{request=query(tx.objectStore(storeName));}catch{clearTimeout(timer);settled=true;try{tx.abort();}catch{}reject(this.storageError());return;}
            request.onsuccess=()=>{if(settled)return;settled=true;clearTimeout(timer);resolve(request.result);};
            tx.onabort=tx.onerror=()=>{if(settled)return;settled=true;clearTimeout(timer);reject(this.storageError());};
        });
    }
    async snapshot(){
        const rows=this.pruneAccepted(await this.read('items',store=>store.getAll()),{delete(){}});
        const merged=new Map(rows.map(r=>[r.key,r]));for(const r of this.memory.values())merged.set(r.key,r);return [...merged.values()];
    }
    async transaction(mutator) {
        const db = await this.open();
        return new Promise((resolve,reject) => {
            const tx = db.transaction(['items','meta'],'readwrite');
            let result, failure;
            const items = tx.objectStore('items'), meta = tx.objectStore('meta');
            const request = items.getAll();
            request.onsuccess = () => {
                const sequence = meta.get('ordinal');
                sequence.onsuccess = () => {
                    try {result = mutator(request.result,items,meta,sequence.result?.value || 0);}
                    catch (e) {failure = e;tx.abort();}
                };
            };
            tx.oncomplete = () => resolve(result); // request success alone never means saved locally
            tx.onabort = tx.onerror = () => reject(failure || new Error('نوشتن صف محلی انجام نشد؛ متن پاک نشده است.'));
        });
    }
    async consent(scope) {
        if (!validScope(scope)) throw new Error('نشست معتبر نیست.');
        return (await this.read('meta',store=>store.get(`consent:${scope}`)))?.value===true;
    }
    async setConsent(scope, value) {
        await this.transaction((rows,items,meta) => meta.put({key:`consent:${scope}`,value:value === true}));
        this.persistent = value === true;
    }
    pruneAccepted(rows,items){
        rows.forEach(validateRecord);const now=this.now(),accepted=rows.filter(r=>r.state==='accepted').sort((a,b)=>b.acceptedLocal-a.acceptedLocal);
        const expired=new Set(accepted.filter((r,i)=>i>=256||(Number.isFinite(r.acceptedLocal)&&now-r.acceptedLocal>DAY)).map(r=>r.key));
        for(const key of expired)items.delete(key);return rows.filter(r=>!expired.has(r.key));
    }
    async all() {
        const persisted=await this.transaction((rows,items)=>this.pruneAccepted(rows,items));
        const merged = new Map(persisted.map(r=>[r.key,r]));
        for (const r of this.memory.values()) merged.set(r.key,r);
        return [...merged.values()];
    }
    async list(scope, conversation = null) {
        let rows;
        try {rows=await this.snapshot();} catch(error) {if(this.persistent)throw error;rows=[...this.memory.values()];}
        return rows.filter(r=>r.scope===scope && (!conversation || r.conversation===conversation)).map(validateRecord).sort((a,b)=>a.ordinal-b.ordinal);
    }
    async enqueue(context, conversation, input, {persistent = this.persistent,attachments=[],replyTo=null,sourcePath=null,faqArticle=null,teamThrough=null} = {}) {
        if (!validScope(context.scope_id) || !validConversation(conversation)) throw new Error('هویت یا گفتگو معتبر نیست.');
        const text = attachments.length&&!input.trim()?'':validateText(input), id = uuid(), now = this.now();
        const record = {version:1,scope:context.scope_id,role:context.role,epoch:context.session_epoch,conversation,uuid:id,
            key:`${context.scope_id}|${conversation}|${id}`,text,state:'pending',created:now,ordinal:0,attempts:0,firstAttempt:0,
            manualUntil:0,nextAttempt:0,backoffUntil:0,leaseUntil:0,leaseOwner:'',error:'',persistent,everAttempted:false};
        if(teamThrough!==null)record.teamThrough=teamThrough;
        if(faqArticle!==null)record.faqArticle=faqArticle;
        if(replyTo!==null)record.replyTo=replyTo;
        if(context.role==='customer'&&typeof sourcePath==='string'&&context.source_paths?.includes(sourcePath))record.sourcePath=sourcePath;
        if(attachments.length)record.attachments=[...attachments];
        const check = rows => {
            const pending = rows.filter(r=>r.state!=='accepted');pending.forEach(validateRecord);
            if (pending.length >= LIMIT_COUNT || bytes([...pending,record]) > LIMIT_BYTES) throw new Error('صف پر است؛ متن جدید پاک نشده است. سقف ۵۰ پیام یا ۵۰۰ کیبی‌بایت است.');
        };
        if (persistent) {
            await this.transaction((rows,items,meta,ordinal) => {
                rows=this.pruneAccepted(rows,items);check([...rows,...this.memory.values()]);record.ordinal=ordinal+1;
                meta.put({key:'ordinal',value:record.ordinal});items.put(validateRecord(record));
            });
        } else {
            let rows;
            try {rows=await this.snapshot();} catch {rows=[...this.memory.values()];}
            record.ordinal=Math.max(0,...rows.map(r=>Number.isSafeInteger(r.ordinal)?r.ordinal:0))+1;
            check(rows);this.memory.set(record.key,validateRecord(record));
        }
        this.signal(); return record;
    }
    async update(key, change) {
        if (this.memory.has(key)) {
            const row = this.memory.get(key), result = change(row);
            if (result === null) this.memory.delete(key); else this.memory.set(key,validateRecord(result));
            this.signal();return result;
        }
        const result = await this.transaction((rows,items) => {
            const old = rows.find(r=>r.key===key);if (!old) return null;
            validateRecord(old);const next = change(old);
            if (next === null) items.delete(key); else items.put(validateRecord(next));return next;
        });
        this.signal(); return result;
    }
    async reserve(key, context) {
        return this.update(key, row => {
            const now=this.now();
            if (row.scope!==context.scope_id || row.role!==context.role || row.state==='accepted' || row.state==='cancelled') return row;
            if (row.epoch!==context.session_epoch) {row.state='needs_action';row.error='نشست عوض شده؛ ادامه نیازمند تأیید صریح است.';return row;}
            if (row.leaseUntil>now && row.leaseOwner!==this.tab) return row;
            const reason=blockedReason(row,now);
            if (reason) {row.state='needs_action';row.error=reason;return row;}
            if (row.state==='needs_action' || row.nextAttempt>now) return row;
            // Capture fresh-send proof under the same transaction as the lease.
            row.leaseFirstSubmission=row.everAttempted===false&&row.attempts===0;
            row.state='sending';row.leaseOwner=this.tab;row.leaseUntil=now+45000;
            row.attempts++;row.everAttempted=true;row.firstAttempt ||= now;row.error='';return row;
        });
    }
    async accepted(key, ack) {
        return this.update(key,row => {
            if(!validReceipt(ack,row))throw new Error('رسید ثبت معتبر نیست؛ متن حفظ شد.');
            if(row.state==='accepted' && (row.ack.message_id!==ack.message_id || row.ack.accepted_at!==ack.accepted_at))throw new Error('رسید تکراری ناسازگار است.');
            // Tombstone has no message content and cannot authorize any server operation.
            delete row.text;delete row.sourcePath;delete row.faqArticle;row.state='accepted';row.ack=ack;row.acceptedLocal=this.now();
            row.leaseUntil=0;row.leaseOwner='';row.error='';return row;
        });
    }
    async fail(key, {status=0,message='',retryAfter=0}, random=Math.random) {
        return this.update(key,row => {
            const now=this.now(); row.leaseUntil=0;row.leaseOwner='';
            if(row.state==='cancelled'||row.state==='accepted') return row;
            const transient=status===0 || status===408 || status===429 || status>=500;
            row.state=transient?'unknown':'needs_action';row.error=String(message||'نتیجه ارسال مشخص نیست؛ متن حفظ شده است.').slice(0,400);
            row.backoffUntil=Math.max(row.backoffUntil,now+Math.max(0,retryAfter));
            row.nextAttempt=Math.max(row.backoffUntil,now+backoff(row.attempts,random));
            const reason=blockedReason(row,now);if(reason){row.state='needs_action';row.error=reason;}
            return row;
        });
    }
    async retry(key, context) {
        return this.update(key,row => {
            if(row.scope!==context.scope_id || row.role!==context.role) throw new Error('ارسال تحت هویت دیگری ممنوع است.');
            if(row.state==='accepted') return row;
            if(this.now()-row.created>=DAY)throw new Error('مهلت ارسال این پیام گذشته است؛ متن حذف نشد. برای پیام تازه، متن را بررسی و دوباره وارد کنید.');
            if(row.leaseUntil>this.now()) throw new Error('درخواست فعال است؛ نتیجه هنوز مشخص نیست.');
            row.epoch=context.session_epoch;row.state='pending';row.attempts=0;row.firstAttempt=0;
            row.manualUntil=0;row.nextAttempt=Math.max(this.now(),row.backoffUntil);
            row.leaseUntil=0;row.leaseOwner='';row.error='';return row;
        });
    }
    async cancel(key) {
        return this.update(key,row => {
            if(!row.attempts) return null;
            row.state='cancelled';row.error='تلاش بعدی متوقف شد؛ درخواست قبلی ممکن است در سرور ثبت شده باشد.';return row;
        });
    }
    async deleteScope(scope) {
        for(const [key,row] of this.memory) if(row.scope===scope) this.memory.delete(key);
        await this.transaction((rows,items,meta) => {for(const row of rows)if(row.scope===scope)items.delete(row.key);meta.delete(`consent:${scope}`);meta.delete(`drafts:${scope}`);const request=meta.openCursor();request.onsuccess=()=>{const cursor=request.result;if(cursor){if(String(cursor.key).startsWith(`attention:${scope}:`))cursor.delete();cursor.continue();}};});
        this.signal('logout',scope);
    }
    close() {this.closed=true;this.cancelOpen?.();this.channel?.close();this.db?.close();}
}
