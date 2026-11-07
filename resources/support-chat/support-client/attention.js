// Permission is browser authority, independent of device preferences or remote Push.
function permissionHost(){
 try{if(parent!==window&&parent.location.origin===location.origin)return parent;}catch{}
 return window;
}
export function notificationPermission(){
 const host=permissionHost(),api=host.Notification;
 const ios=/iPad|iPhone|iPod/.test(host.navigator.userAgent)||(host.navigator.platform==='MacIntel'&&host.navigator.maxTouchPoints>1);
 const installed=host.navigator.standalone===true||host.matchMedia('(display-mode:standalone)').matches;
 if(!host.isSecureContext)return {state:'unsupported',reason:'secure'};
 if(ios&&!installed)return {state:'unsupported',reason:'install'};
 if(!api||typeof api.requestPermission!=='function')return {state:'unsupported',reason:'browser'};
 return {state:['default','granted','denied'].includes(api.permission)?api.permission:'default',reason:null};
}
let pending=null;
export function requestNotificationPermission(){
 const info=notificationPermission();
 if(info.state!=='default')return Promise.resolve(info.state);
 if(pending)return pending;
 // No await, database read, registration or network request before the native call.
 try{const host=permissionHost(),result=host.Notification.requestPermission();
  pending=Promise.resolve(result).then(value=>['default','granted','denied'].includes(value)?value:notificationPermission().state).finally(()=>{pending=null;});return pending;
 }catch{return Promise.reject(Error('درخواست مجوز اعلان انجام نشد؛ دوباره تلاش کنید.'));}
}
export function watchNotificationPermission(changed){
 let dead=false,status=null;
 const refresh=()=>{if(!dead)changed();},visible=()=>{if(!document.hidden)refresh();},widget=e=>{if(e.detail?.visible)refresh();};
 addEventListener('focus',refresh);addEventListener('pageshow',refresh);document.addEventListener('visibilitychange',visible);addEventListener('support-widget-visibility',widget);
 try{permissionHost().navigator.permissions?.query({name:'notifications'}).then(value=>{if(dead)return;status=value;status.addEventListener?.('change',refresh);}).catch(()=>{});}catch{}
 return ()=>{dead=true;removeEventListener('focus',refresh);removeEventListener('pageshow',refresh);document.removeEventListener('visibilitychange',visible);removeEventListener('support-widget-visibility',widget);status?.removeEventListener?.('change',refresh);};
}
export function notificationPermissionView({host,current,changed=()=>{},close=null,requesting=()=>{},settled=()=>{},stopBackground=async()=>{}}){
 const make=(tag,text)=>Object.assign(document.createElement(tag),{textContent:text});
 const recheck=make('button','بررسی مجدد');recheck.type='button';recheck.onclick=async()=>{recheck.disabled=true;status.textContent='در حال بررسی…';try{await update();const state=notificationPermission().state;status.textContent=state==='denied'?'مجوز همچنان مسدود است؛ آن را از تنظیمات مرورگر تغییر دهید.':state==='granted'?'مجوز مرورگر فعال شد.':state==='default'?'اکنون می‌توانید درخواست مجوز را بزنید.':'اعلان در این مرورگر در دسترس نیست.';}finally{recheck.disabled=false;}};
 const title=make('h3','اعلان‌های چت'),copy=make('p',''),status=make('p',''),enable=make('button','فعال‌سازی'),disable=make('button','خاموش‌کردن'),help=make('button','راهنما'),later=make('button','بعداً');
 status.setAttribute('role','status');copy.className='bplus-confirm-copy';enable.className='bplus-notification-primary';
 for(const b of [enable,disable,help,later])b.type='button';later.hidden=!close;later.onclick=()=>close?.();
 host.append(title,copy,status,enable,disable,recheck,help,later);let busy=false,requested=false,version=0,dead=false,lastState=null;
 function paint(){const info=notificationPermission(),prefs=current()?.prefs||{};if(info.state!==lastState){status.textContent='';lastState=info.state;}
  title.textContent=info.state==='denied'?'اعلان‌ها مسدود است':info.state==='granted'?'مجوز مرورگر فعال است':info.state==='unsupported'?'اعلان پشتیبانی نمی‌شود':'مجوز اعلان فعال نیست';enable.textContent=info.state==='default'?(requested?'درخواست مجدد مجوز':'درخواست مجوز'):'فعال‌سازی';recheck.hidden=info.state!=='denied';recheck.className=info.state==='denied'?'bplus-notification-primary':'';
  copy.textContent=info.state==='denied'?'از تنظیمات مرورگر، اجازه اعلان‌های این سایت را فعال کنید.':info.state==='unsupported'?info.reason==='install'?'در iPhone و iPad، برنامه را از صفحهٔ اصلی باز کنید.':info.reason==='secure'?'اعلان‌ها به اتصال امن HTTPS نیاز دارند.':'اعلان‌ها در این مرورگر پشتیبانی نمی‌شوند.':info.state==='granted'?(prefs.browser?'اعلان داخل برنامه روشن است؛ ارسال Push تأیید نشده است.':'اعلان داخل برنامه خاموش است؛ مجوز مرورگر به‌تنهایی تضمین ارسال نیست.'):'برای اطلاع از پاسخ‌های جدید، اعلان‌ها را فعال کنید.';
  enable.hidden=['unsupported','denied'].includes(info.state)||info.state==='granted'&&prefs.browser;
  disable.hidden=info.state!=='granted'||!prefs.browser;help.hidden=info.state!=='denied';enable.disabled=busy||!current();disable.disabled=busy;
  changed({...info,enabled:info.state==='granted'&&!!prefs.browser});return info;
 }
 async function update(){const ticket=++version,alerts=current();paint();try{await alerts?.load();}catch{}if(!dead&&ticket===version&&alerts===current())paint();}
 enable.onclick=async()=>{if(busy||!current())return;busy=true;requested=true;status.textContent='';enable.disabled=true;
  try{const result=current().permission();requesting();const value=await result;await update();status.textContent=value==='default'?'مجوز داده نشد؛ هر زمان خواستید دوباره تلاش کنید.':'';}
  catch{paint();status.textContent=notificationPermission().state==='granted'?'مجوز داده شد؛ ذخیرهٔ تنظیم انجام نشد. دوباره تلاش کنید.':'درخواست مجوز انجام نشد؛ دوباره تلاش کنید.';}
  finally{busy=false;paint();settled();}
 };
 disable.onclick=async()=>{if(busy||!current())return;busy=true;try{await current().save({browser:false,background:false});await stopBackground();status.textContent='';}catch{status.textContent='تنظیم ذخیره نشد؛ دوباره تلاش کنید.';}finally{busy=false;paint();}};
 help.onclick=()=>{status.textContent=/SamsungBrowser/.test(navigator.userAgent)?'Samsung Internet: منو ← تنظیمات ← سایت‌ها و بارگیری‌ها ← اعلان‌ها.':/; wv\)|Telegram/.test(navigator.userAgent)?'در مرورگر داخلی، سایت را در Chrome باز کنید.':'Chrome: کنار آدرس ← مجوزها ← اعلان‌ها؛ اجازه را فعال یا مجوز سایت را Reset permissions کنید، سپس برگردید و درخواست مجوز را بزنید. اگر مجوز سایت granted است ولی اعلان ندارید، تنظیمات Android ← برنامه‌ها ← Chrome ← اعلان‌ها و دستهٔ سایت را بررسی کنید.';};
 const unwatch=watchNotificationPermission(()=>{if(host.isConnected&&!busy)update();});paint();
 return {status,update,destroy(){dead=true;version++;unwatch();}};
}

// One geometry policy for reads and banner suppression. DOM presence is not visibility.
export function messageViewport(host,id,{active=true,operator=false}={}){
    if(!active||document.hidden||!document.hasFocus()||host.closest('[inert]')||document.querySelector('dialog[open]'))return null;
    const el=host.querySelector(`[data-message-id="${Number(id)}"]`),scroller=operator?host.parentElement:host;
    if(!el?.getClientRects().length||!scroller?.getClientRects().length)return null;
    const r=(operator?el.querySelector('.v2-bubble')||el:el).getBoundingClientRect(),s=scroller.getBoundingClientRect();
    const height=Math.min(r.bottom,s.bottom,innerHeight)-Math.max(r.top,s.top,0),width=Math.min(r.right,s.right,innerWidth)-Math.max(r.left,s.left,0);
    return height>=Math.min(80,r.height/2)&&width>=Math.min(80,r.width/2)?r:null;
}
// Read state follows stable visible bubbles, never a successful poll or inbox selection.
export function visibleReads({engine,host,visible=()=>true,updated=()=>{}}){
    let timer=null,busy=false,version=0,messages=[],seen=new Set(),visibleSince=new Map();
    const resetVisibility=()=>visibleSince.clear(),scroller=engine.role==='operator'?host.parentElement:host;
    scroller.addEventListener('scroll',resetVisibility,{passive:true});addEventListener('blur',resetVisibility);document.addEventListener('visibilitychange',resetVisibility);addEventListener('resize',resetVisibility);
    const tick=async()=>{
        if(busy||engine.frozen||document.hidden||!document.hasFocus()||!visible()||host.closest('[inert]')||document.querySelector('dialog[open]')){visibleSince.clear();return;}
        const id=engine.sync.id,scope=engine.scope,hostRect=host.getBoundingClientRect();if(!hostRect.width||!hostRect.height){visibleSince.clear();return;}
        const incoming=engine.role==='customer'?'admin':'user',field=engine.role==='customer'?'customer_read':'read';
        const now=Date.now(),eligible=new Set();
        for(const m of messages.filter(m=>m.type===incoming&&m[field]===false&&!seen.has(m.id))){
            const r=messageViewport(host,m.id,{operator:engine.role==='operator'});if(!r)continue;eligible.add(m.id);
            const old=visibleSince.get(m.id);if(!old||Math.abs(old.top-r.top)>8||Math.abs(old.bottom-r.bottom)>8||Math.abs(old.left-r.left)>8||Math.abs(old.right-r.right)>8)visibleSince.set(m.id,{at:now,top:r.top,bottom:r.bottom,left:r.left,right:r.right});
        }
        for(const n of visibleSince.keys())if(!eligible.has(n))visibleSince.delete(n);
        const ids=[...eligible].filter(n=>now-visibleSince.get(n).at>=500).slice(0,100);
        if(!ids.length)return;busy=true;const current=version;
        try{
            const response=await engine.transport.request('api.php',{data:{scope:engine.role,read_visible:'1',user_id:id,message_ids:JSON.stringify(ids)}});
            if(response?.store_epoch&&engine.sync.epoch&&response.store_epoch!==engine.sync.epoch)return;
            if(current===version&&scope===engine.scope&&id===engine.sync.id){for(const n of (response?.read_message_ids||[]))seen.add(n);const page=engine.sync.acknowledgeRead?.(response)||response;updated(ids,page);engine.box.channel?.postMessage({type:'read',scope,tab:engine.box.tab});}
        }catch(e){engine.handleError(e);}finally{busy=false;}
    };
    timer=setInterval(tick,250);
    return {messages(value){if(engine.sync.id!==this.id){this.id=engine.sync.id;version++;seen=new Set();visibleSince.clear();}messages=value;},wake:tick,destroy(){clearInterval(timer);version++;scroller.removeEventListener('scroll',resetVisibility);removeEventListener('blur',resetVisibility);document.removeEventListener('visibilitychange',resetVisibility);removeEventListener('resize',resetVisibility);}};
}

// Metadata only: preferences and sequence cursors; no message text or credentials.
export class DeviceAlerts {
    constructor(engine,{check=async()=>true,eligible=async()=>true,quiet=()=>false,open=()=>{},alert=()=>{}}={}){
        Object.assign(this,{engine,check,eligible,quiet,open,alert});this.prefs={browser:false,sound:false,volume:'low',preview:engine.role==='operator'};this.audio=null;this.dead=false;this.observed=null;this.lastSound=0;this.listeners=new Set();this.audioResult='needs_gesture';this.pending=null;
        this.gesture=event=>{if(event.isTrusted&&this.prefs.sound)this.prepareAudio().catch(()=>{});};
        document.addEventListener('pointerdown',this.gesture,true);document.addEventListener('keydown',this.gesture,true);this.load().catch(()=>{});
    }
    key(name){return `attention:${this.engine.scope}:${this.engine.context.session_epoch}:${name}`;}
    async state(name,change){
        const db=await this.engine.box.open();return new Promise((resolve,reject)=>{
            const tx=db.transaction('meta','readwrite'),store=tx.objectStore('meta'),key=this.key(name),q=store.get(key);let result;
            q.onsuccess=()=>{const next=change(q.result?.value);result=next.result;if(next.value!==undefined)store.put({key,value:next.value});};
            tx.oncomplete=()=>resolve(result);tx.onerror=tx.onabort=()=>reject(Error('ذخیرهٔ تنظیم اعلان ممکن نشد.'));
        });
    }
    async load(){this.prefs=await this.state('preferences',p=>({result:{browser:false,sound:false,volume:'low',preview:this.engine.role==='operator',...p}}));return this.prefs;}
    async save(values){this.prefs=await this.state('preferences',p=>{const value={browser:false,sound:false,volume:'low',preview:this.engine.role==='operator',...p,...values};return {value,result:value};});this.changed();return this.prefs;}
    changed(){for(const listener of this.listeners)listener(this.soundState());}
    soundState(){return !this.prefs.sound?'muted':this.audio?.state==='running'?'running':['blocked','unsupported'].includes(this.audioResult)?this.audioResult:'needs_gesture';}
    onstatus(listener){this.listeners.add(listener);listener(this.soundState());return ()=>this.listeners.delete(listener);}
    async prepareAudio(){
        if(this.dead)return;const Audio=window.AudioContext||window.webkitAudioContext;
        if(!Audio){this.audioResult='unsupported';this.changed();return;}
        try{if(!this.audio){this.audio=new Audio();this.audio.addEventListener?.('statechange',()=>this.changed());}if(this.audio.state!=='running')await this.audio.resume();this.audioResult=this.audio.state==='running'?'running':'needs_gesture';}
        catch{this.audioResult='blocked';}this.changed();
    }
    async permission(){
        const permission=await requestNotificationPermission();
        if(permission!=='unsupported')await this.save({browser:permission==='granted'});return permission;
    }
    async sound(value){if(value)await this.prepareAudio();await this.save({sound:value});return this.soundState();}
    observe(messages,page,older=false){
        if(!page?.store_epoch)return;const conversation=this.engine.sync.id,initial=this.observed!==conversation;this.observed=conversation;
        if(initial&&this.pending){if(this.pending.timer)clearTimeout(this.pending.timer);this.pending=null;}
        const incoming=this.engine.role==='customer'?'admin':'user',incomingList=messages.filter(m=>m.type===incoming&&Number.isSafeInteger(m.id)),sequence=Math.max(0,...incomingList.map(m=>m.id));
        const incomingIds=this.engine.role==='operator'&&page.operator_unread===0?[]:incomingList.filter(m=>this.engine.role==='customer'?m.customer_read===false:m.read===false).map(m=>m.id);const latestMsg=incomingList.find(m=>m.id===sequence),preview=latestMsg?(latestMsg.text||(latestMsg.attachments?.length?'تصویر پیوست':'')):'',sender=latestMsg?(typeof latestMsg.sender==='object'?latestMsg.sender?.name:(latestMsg.sender||'پشتیبانی')):'پشتیبانی';
        this.update(sequence,page.store_epoch,{silent:initial||older,conversation,incomingIds,preview,sender,unread:incomingIds.length}).catch(()=>{});
    }
    async testSound(){await this.prepareAudio();return this.play(true)?'started':this.audioResult==='blocked'?'blocked':'unavailable';}
    play(test=false){
        if((!test&&!this.prefs.sound)||this.audio?.state!=='running'||Date.now()-this.lastSound<2000)return false;
        // Original gentle tone; a longer envelope and practical low/medium amplitude.
        const osc=this.audio.createOscillator(),gain=this.audio.createGain(),t=this.audio.currentTime,level=this.prefs.volume==='medium'?.16:.085;osc.type='sine';osc.frequency.value=660;osc.frequency.linearRampToValueAtTime?.(880,t+.16);gain.gain.value=level;gain.gain.setValueAtTime?.(.0001,t);gain.gain.exponentialRampToValueAtTime?.(level,t+.025);gain.gain.exponentialRampToValueAtTime?.(.0001,t+.32);osc.connect(gain);gain.connect(this.audio.destination);osc.onended=()=>{osc.disconnect?.();gain.disconnect?.();};osc.start(t);osc.stop(t+.34);this.lastSound=Date.now();return true;
    }
    async update(sequence,epoch,context={}){
        if(this.dead||this.engine.frozen||!Number.isSafeInteger(sequence)||!epoch)return;
        try{await this.load();}catch{return;}
        let fresh=false,added=0;try{fresh=await this.state('cursor',old=>{const base=old?.epoch===epoch?old.sequence:0;added=Array.isArray(context.incomingIds)?context.incomingIds.filter(id=>id>base).length:Math.max(1,Number(context.count)||1);const value={epoch,sequence:Math.max(base,sequence)};return {value,result:!!old&&old.epoch===epoch&&sequence>old.sequence};});}catch{return;}
        if(!fresh||!sequence||context.silent)return;
        if(Array.isArray(context.incomingIds)&&added===0)return;
        this.enqueue({...context,sequence,storeEpoch:epoch,count:Math.max(1,added)});
    }
    enqueue(event){
        if(this.dead||this.engine.frozen)return;
        if(!this.pending)this.pending={event,count:0,timer:null};
        this.pending.count+=event.count;this.pending.event={...event,count:this.pending.count};
        this.alert(this.pending.event);
        if(!this.pending.timer)this.pending.timer=setTimeout(()=>this.flush(),1000);
    }
    async flush(){
        const pending=this.pending;this.pending=null;if(!pending||this.dead||this.engine.frozen)return;
        const event=pending.event;
        try{
            if(!await this.eligible(event)||this.dead||this.engine.frozen)return;
            await this.load();if(this.quiet())return;
            // Sound is independent of desktop-notification visibility suppression.
            this.play();
            if(!this.prefs.browser||notificationPermission().state!=='granted')return;
            if(this.dead||this.engine.frozen)return;
            const permitted=await this.check(event);if(!permitted||this.dead||this.engine.frozen||this.quiet())return;
            await this.load();if(!this.prefs.browser||notificationPermission().state!=='granted')return;
            const target=typeof permitted==='object'?{...event,...permitted}:event;
            const clean=value=>String(value||'').replace(/<[^>]*>/g,'').replace(/[\u0000-\u001f\u007f\u202a-\u202e\u2066-\u2069]/g,'').slice(0,180);
            const count=Math.max(1,pending.count),isOp=this.engine.role==='operator';
            const title=count>1?(isOp?'پیام‌های تازهٔ کاربران':'پیام تازهٔ پشتیبانی'):(this.prefs.preview&&target.sender?clean(target.sender):(isOp?'پیام تازهٔ کاربر':'پیام تازهٔ پشتیبانی'));
            const body=count>1?(isOp?`${count.toLocaleString('fa-IR')} پیام جدید از کاربران`:`${count.toLocaleString('fa-IR')} پیام جدید از پشتیبانی`):(this.prefs.preview&&target.preview?clean(target.preview):'برای دیدن پیام، گفتگو را باز کنید.');
            const options={body,dir:'rtl',tag:`support-${this.engine.role}-${this.engine.scope.slice(0,16)}`,silent:!this.prefs.sound||!document.hidden};
            try{const notification=new Notification(title,options);notification.onclick=()=>{notification.close();window.focus();if(!this.dead&&!this.engine.frozen)this.open(target);};setTimeout(()=>notification.close(),15000);}
            catch(error){
                // Android may grant permission while rejecting the page constructor.
                // Reuse only an existing, correctly scoped worker; never create Push here.
                const scope=this.engine.role==='operator'?'operator/':'support-client/';
                const registration=await navigator.serviceWorker?.getRegistration(new URL(scope,document.baseURI));
                if(registration?.active&&typeof registration.showNotification==='function'&&new URL(registration.scope).pathname===new URL(scope,document.baseURI).pathname&&!this.dead&&!this.engine.frozen)
                    await registration.showNotification(title,{...options,data:this.engine.role==='operator'?{conversation:target.conversation}:{customer:true}});
            }

        }catch{/* Browser policy/permission failures never interrupt messaging. */}
    }
    destroy(){this.dead=true;if(this.pending?.timer)clearTimeout(this.pending.timer);this.pending=null;document.removeEventListener('pointerdown',this.gesture,true);document.removeEventListener('keydown',this.gesture,true);this.listeners.clear();this.audio?.close().catch(()=>{});}
}

export function soundControls(host,alerts){
    const group=document.createElement('div');group.className='support-sound-controls';
    const label=document.createElement('label'),volume=document.createElement('select');label.textContent='صدای پیام تازه';volume.setAttribute('aria-label','صدای پیام تازه');
    for(const [value,text]of [['off','خاموش'],['low','کم'],['medium','متوسط']])volume.add(new Option(text,value));label.append(volume);
    const test=document.createElement('button');test.type='button';test.textContent='آزمایش صدای اعلان';test.dataset.testSound='';
    const status=document.createElement('small');status.setAttribute('role','status');status.dataset.soundStatus='';
    alerts.onstatus(value=>{volume.value=alerts.prefs.sound?alerts.prefs.volume||'low':'off';status.textContent={muted:'صدای پیام خاموش است.',running:'پخش مرورگر آماده است؛ شنیده‌شدن را با آزمایش بررسی کنید.',needs_gesture:'برای آماده‌شدن صدا، دکمهٔ آزمایش را بزنید.',blocked:'مرورگر پخش صدا را مسدود کرده است.',unsupported:'مرورگر پخش صدا را پشتیبانی نمی‌کند.'}[value];});
    volume.onchange=async()=>{try{const value=volume.value;if(value!=='off')await alerts.prepareAudio();await alerts.save({sound:value!=='off',...(value==='off'?{}:{volume:value})});}catch{status.textContent='ترجیح ذخیره نشد؛ دوباره انتخاب کنید.';}};
    test.onclick=async()=>{test.disabled=true;try{const result=await alerts.testSound();status.textContent=result==='started'?'پخش آغاز شد. اگر نمی‌شنوید، صدای تب و خروجی دستگاه را بررسی کنید.':result==='blocked'?'مرورگر پخش را مسدود کرد؛ دوباره آزمایش کنید.':'پخش آغاز نشد؛ دو ثانیه صبر کنید و دوباره آزمایش کنید.';}catch{status.textContent='خطا در پخش؛ دوباره آزمایش کنید.';}finally{test.disabled=false;}};
    const privacy=document.createElement('label'),preview=document.createElement('input');preview.type='checkbox';preview.setAttribute('role','switch');privacy.append(preview,document.createTextNode('نمایش متن پیام در اعلان‌ها'));
    preview.onchange=async()=>{try{await alerts.save({preview:preview.checked});}catch{preview.checked=!preview.checked;status.textContent='ترجیح حریم خصوصی ذخیره نشد.';}};
    alerts.load().then(p=>{volume.value=p.sound?p.volume:'off';preview.checked=p.preview;}).catch(()=>{});
    const hint=document.createElement('small');hint.textContent='حریم خصوصی صفحهٔ قفل را تنظیمات سیستم‌عامل تعیین می‌کند.';
    group.append(label,test,status,privacy,hint);host.append(group);return group;
}

export function notificationSettings(host,alerts,{push=null,deviceControls=true,stopBackground=async()=>{}}={}){
    const section=document.createElement('section');section.className='support-notification-settings';
    const permission=notificationPermissionView({host:section,current:()=>alerts,stopBackground});section.permissionCleanup=permission.destroy;
    if(deviceControls)soundControls(section,alerts);if(push)section.append(push);host.append(section);permission.update();return section;
}
