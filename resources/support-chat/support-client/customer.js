import {composerSize} from './composer-size.js?v=ux1';
import {sendCooldown} from './send-cooldown.js';
import {customerShell} from './customer-shell.js?v=0.1.0-r14';
import {themeControls} from './theme.js?v=0.1.0-r14';
import {ChatEngine,controls,conversationView} from './engine.js?v=team1';
import {recoveryControls} from './recovery.js?v=0.1.0-r14';
import {phoneControls} from './phone.js?v=0.1.0-r14';
import {chatMenu} from './menu.js?v=compat1';
import {issuanceControls} from './issuance.js?v=0.1.0-r14';
import {attachmentComposer} from './attachments.js?v=refresh2';
import {typingIndicator} from './typing.js?v=0.1.0-r14';
import {visibleReads,DeviceAlerts,notificationSettings,messageViewport} from './attention.js?v=team1';
import {unreadJump} from './unread-jump.js?v=latest2';
import {replyControls} from './reply.js?v=bplus1';
import {satisfaction} from './satisfaction.js?v=flow1';
// Older embedded shells remain compatible; no cookie/storage migration is needed.
export function customerInitHost(win=document.getElementById('chatWindow')){
    let init=document.getElementById('supportInit');if(init)return init;
    init=document.createElement('section');init.id='supportInit';init.className='support-init-status';init.hidden=true;init.setAttribute('role','status');init.setAttribute('aria-live','polite');
    const text=document.createElement('span'),retry=document.createElement('button'),reference=document.createElement('small');text.dataset.initText='';retry.dataset.initRetry='';reference.dataset.initReference='';retry.type='button';retry.hidden=true;retry.textContent='تلاش مجدد';init.append(text,retry,reference);win.querySelector('.chat-header').after(init);return init;
}
export async function mountCustomer({shellFactory=customerShell,transcriptRenderer=undefined,presentation=null,onClose=null}={}){
    const d={win:document.getElementById('chatWindow'),messages:document.getElementById('messages'),input:document.getElementById('chatInput'),init:document.getElementById('initialForm'),area:document.getElementById('inputArea'),logout:document.getElementById('logoutBtn'),error:document.getElementById('errorMsg'),status:document.getElementById('adminStatus')};
    const stable=d.win.hasAttribute('data-support-stable-shell');
    const init=customerInitHost(d.win),initText=init.querySelector('[data-init-text]'),initRetry=init.querySelector('[data-init-retry]'),initRef=init.querySelector('[data-init-reference]');
    const diagnostics=globalThis.SupportInitDiagnostics||{release:'mobile-visual1',reference:crypto.getRandomValues(new Uint32Array(2)).join('-'),events:[]};globalThis.SupportInitDiagnostics=diagnostics;
    const initState=(state,stage,code='')=>{
        d.win.dataset.initState=state;init.hidden=state==='ready';
        if(stable){
            d.win.setAttribute('aria-busy',String(state==='initializing'));
            if(state==='ready')document.body.dataset.supportVisualReady='';
            initText.textContent=state==='initializing'?'در حال آماده‌سازی گفتگو...':stage==='history'?'پیام‌ها دریافت نشدند. دوباره تلاش کنید.':'آماده‌سازی گفتگو انجام نشد.';
        }else initText.textContent=state==='initializing'?'در حال آماده‌سازی چت…':state==='partial'?(stage==='storage'?'ذخیرهٔ محلی در دسترس نیست؛ ارسال در همین صفحه فعال است. پیش از بستن صفحه منتظر تأیید پیام بمانید.':'بخشی از امکانات بارگذاری نشد؛ دوباره تلاش کنید.'):'بارگذاری چت انجام نشد.';
        initRetry.hidden=state==='initializing'||state==='ready';initRef.textContent=state==='failed'?'کد بررسی: '+diagnostics.reference:'';
        diagnostics.events.push({stage,code,ms:Math.round(performance.now())});diagnostics.events=diagnostics.events.slice(-12);dispatchEvent(new Event('support-init-state'));
    };
    initState('initializing','mount');
    const phoneField=document.getElementById('userPhone');phoneField.dir='ltr';phoneField.style.textAlign='right';phoneField.placeholder='شماره موبایل (اختیاری)';
    const phoneLabel=document.createElement('label');phoneLabel.htmlFor=phoneField.id;phoneLabel.textContent='شماره موبایل (اختیاری)';phoneLabel.className='support-phone-label';phoneField.before(phoneLabel);
    const launcher=document.querySelector('.chat-btn'),badge=document.createElement('span');badge.className='support-unread-badge';badge.hidden=true;badge.setAttribute('aria-live','polite');launcher?.append(badge);
    let unreadCount=0,reads=null,alerts=null,notificationSection=null,navigation=null,shell=null;
    const shown=()=>d.win.classList.contains('active')&&!globalThis.SupportWidgetHidden&&menu.drawer.hidden&&shell?.chat===true;
    const setUnread=count=>{navigation?.unread(count,engine?.sync.attention?.first_customer_unread_sequence||0);shell?.unread(count);badge.hidden=count===0;badge.textContent=count>9?'9+':String(count);launcher?.setAttribute('aria-label',count?`پشتیبانی، ${count} پیام خوانده‌نشده`:'پشتیبانی');if(parent!==window)parent.postMessage({type:'support-unread',count},location.origin);};
    const ui=controls(d.win);const menu=chatMenu(d.win,ui);themeControls(menu.body);
    const region=d.win.querySelector('[data-support-transcript]')||document.createElement('section');
    if(!stable){region.className='support-conversation';region.style.display='none';d.messages.replaceWith(region);region.append(d.messages);}
    ui.queue.remove();
    const availability=document.createElement('small');availability.className='support-hours-notice';availability.hidden=true;d.area.before(availability);
    const retentionNotice=document.createElement('small');retentionNotice.hidden=true;retentionNotice.textContent='نگهداری پیام: متن گفتگوهای غیرفعال و خوانده‌شده پس از هفت روز ممکن است حذف شود. گفتگوهای فعال، خوانده‌نشده و قدیمیِ فاقد زمان معتبر مستثنا هستند. صف حل‌نشدهٔ دستگاه حذف نمی‌شود.';ui.panel.append(retentionNotice);
    d.win.setAttribute('aria-busy','true');
    const startButton=d.win.querySelector('.btn-start-chat');startButton.disabled=true;
    let engine,revision=0,lastQueuedRevision=-1,selection=0,initializing=null,hasConversation=false,preparing=false,composerLocked=false;
    const contact=document.createElement('details');contact.className='support-contact';const contactTitle=document.createElement('summary');contactTitle.textContent='اطلاعات اختیاری';contact.append(contactTitle,d.init);menu.body.prepend(contact);d.init.hidden=false;d.init.style.display='flex';
    document.getElementById('userName').required=false;
    const welcome=document.createElement('p');welcome.className='support-welcome';welcome.textContent=globalThis.SupportWidgetConfig?.welcome||'سلام! چطور می‌توانیم کمکتان کنیم؟';region.prepend(welcome);
    let welcomeEpisode=null,welcomeHasMessages=false;
    let transcript,reply=null,typing=null,rating=null,historyTimer=null;
    let openedInPage=!onClose||globalThis.SupportWidgetHidden===false;
    const deferDraftSync=()=>!openedInPage&&!hasConversation;
    const pageQueue=new Map();let recovery=null,phone=null,issuance=null,attachments=null;
    const cooldown=sendCooldown({host:d.area,button:d.win.querySelector('.chat-send'),current:()=>engine});
    // Only localized application messages reach the customer; raw exception
    // text stays in the console for diagnosis and never enters the header area.
    const warn=(message,kind='chat')=>{const localized=typeof message==='string'&&/[\u0600-\u06FF]/.test(message);if(!localized){diagnostics.events.push({stage:'operation',code:'unexpected',ms:Math.round(performance.now())});diagnostics.events=diagnostics.events.slice(-12);}d.error.dataset.kind=kind;d.error.textContent=localized?message:'این عملیات انجام نشد؛ پیش‌نویس شما حفظ شده است. دوباره تلاش کنید.';d.error.style.display='block';};
    function setComposerDisabled(disabled){
        composerLocked=!!disabled;d.win.classList.toggle('support-composer-locked',composerLocked);
        d.area.querySelectorAll('textarea,input').forEach(el=>{el.disabled=composerLocked;});
        for(const el of d.area.querySelectorAll('.support-attach-button,.v2-emoji-toggle'))el.disabled=composerLocked;
        const send=d.win.querySelector('.chat-send');if(send)send.disabled=composerLocked||(engine?.sendWait()||0)>0;
        if(composerLocked){typing?.stop();d.input.blur();}
    }
    const failInitialHistory=()=>{
        if(!stable||!hasConversation||d.win.dataset.initState!=='initializing'||d.win.dataset.historyState!=='loading')return false;
        clearTimeout(historyTimer);engine.poller.stop();engine.sync.controller?.abort();
        d.win.dataset.historyState='failed';setComposerDisabled(true);initState('failed','history','unavailable');
        return true;
    };
    const identity=(message,{keepMenu=false}={})=>{
        hasConversation=false;welcomeEpisode=null;welcomeHasMessages=false;welcome.hidden=false;typing?.reset?.();presentation?.reset?.();shell?.reset({keepMenu});rating?.clear();reply?.clear();navigation?.reset();transcript?.reset();d.messages.replaceChildren();region.style.display='flex';d.messages.style.display='flex';d.area.style.display='block';d.logout.style.display='none';
        d.input.value='';for(const id of ['userName','userEmail','userPhone'])document.getElementById(id).value='';warn(message);
        issuance?.clear();
        attachments?.clear();
    };
    const create=async(withSession=false)=>{
        reads?.destroy();alerts?.destroy();notificationSection?.permissionCleanup?.();notificationSection?.remove();engine?.destroy();navigation?.reset();setUnread(0);unreadCount=0;
        engine=new ChatEngine('customer',{
            queued:()=>navigation?.outgoing(),visible:()=>shown(),deferSync:deferDraftSync,pollError:error=>{
                const failed=failInitialHistory();
                return failed&&![401,403,426].includes(error.status);
            },cooldown:()=>cooldown.refresh(),
            gap:async()=>{let h=await engine.transport.request('customer-notifications.php?action=hint');if(h.attention?.scope!==engine.context.scope_id||h.attention?.epoch!==engine.context.session_epoch){engine.freeze();identity('نشست تغییر کرده است؛ صفحه را تازه کنید.');return;}h=engine.sync.mergeAttention(h);unreadCount=h.customer_unread??h.unread??0;setUnread(unreadCount);alerts?.update(h.sequence,h.store_epoch);},
            warning:(message,kind)=>{if(kind==='storage'&&!stable){initState('partial','storage','unavailable');return;}if(stable&&d.win.dataset.initState!=='ready')return;warn(message,kind);},recovered:()=>{if(d.error.dataset.kind==='connectivity'){d.error.textContent='';d.error.style.display='none';}},identity,mode:value=>{ui.opt.checked=value;},stranded:count=>{ui.clearOld.hidden=count===0;},connection:message=>{ui.connection.textContent=message;},
            presence:data=>{d.status.textContent=data.admin_online===true?'آنلاین':data.admin_online===false?'آفلاین':'وضعیت حضور نامشخص';d.status.title='وضعیت فعالیت اخیر اپراتورها؛ زمان پاسخ تضمین نمی‌شود.';d.status.classList.toggle('online',data.admin_online===true);presentation?.presence?.(data);},
            messages:(messages,history,page)=>{if(page){clearTimeout(historyTimer);if(!stable&&(d.win.dataset.initState==='initializing'||d.win.dataset.initState==='partial'&&!engine.storageDegraded)){initState('ready','history');d.win.setAttribute('aria-busy','false');}d.win.dataset.historyState='ready';rating?.page(page.satisfaction,page.lifecycle);availability.hidden=!page.availability?.outside;availability.textContent=page.availability?.text||'';unreadCount=page.customer_unread||0;setUnread(unreadCount);alerts?.observe(messages,page,history);if(!stable||d.win.dataset.initState==='ready')setComposerDisabled(!!page.lifecycle?.resolved);}reads?.messages(messages);typing?.received?.(messages);if(messages.length)hasConversation=true;if(page?.lifecycle){const episode=page.lifecycle.episode||1;if(welcomeEpisode!==episode){if(welcomeEpisode!==null)welcomeHasMessages=false;welcomeEpisode=episode;}if(messages.some(m=>['user','admin'].includes(m.type)&&(m.episode||1)===episode))welcomeHasMessages=true;}welcome.hidden=welcomeHasMessages;transcript.messages(messages,history);presentation?.messages?.(messages,page);reply?.refresh();if(stable&&page&&d.win.dataset.initState==='initializing'){setComposerDisabled(!!page.lifecycle?.resolved);initState('ready','history');}older.hidden=!engine.sync.hasOlder;older.disabled=loadingHistory||!engine.sync.hasOlder;navigation.tail(engine.sync.hasNewer);if(page?.history_removed_at){retentionNotice.hidden=false;menu.summary.textContent='اطلاعات گفتگو · بخشی از سابقه حذف شده';}},
            accepted:(row,ack)=>{if(row.conversation!==engine.sync.id)return;hasConversation=true;startButton.disabled=false;shell?.session();engine.draftConversation=null;transcript.accepted(row,ack);presentation?.accepted?.(row,ack);recovery?.refresh();phone?.refresh();issuance?.refresh();if(!engine.frozen&&row.scope===engine.scope&&row.conversation===engine.sync.id){ui.clear();d.error.style.display='none';}},
            unavailable:async error=>{
                try{
                    const s=await engine.transport.request('api.php?get_session=1');
                    if(!s.user_id||s.retired||s.user_id!==engine.sync.id){
                        const isRetired=error?.code==='conversation_retired'||!!s.retired;
                        const msg=isRetired?'این گفتگو طبق سیاست نگهداری پایان یافته است.':'گفتگوی قبلی در دسترس نیست یا منقضی شده است.';
                        let notice=d.win.querySelector('.v2-retired');
                        if(!notice){notice=document.createElement('section');notice.className='v2-retired';d.area.before(notice);}
                        notice.replaceChildren();
                        const p=document.createElement('p');p.textContent=msg;
                        const fresh=document.createElement('button');fresh.type='button';fresh.textContent='شروع گفتگوی تازه';
                        fresh.onclick=async()=>{
                            notice.remove();const draft=d.input.value;hasConversation=false;
                            engine.sync.messages.clear();engine.sync.cursor=0;engine.sync.epoch=null;
                            d.messages.replaceChildren();rating?.clear();reply?.clear();d.error.style.display='none';
                            setComposerDisabled(false);contactValues(s);activate(s.user_id||s.draft_user_id);
                            if(draft){d.input.value=draft;d.input.dispatchEvent(new Event('input',{bubbles:true}));}
                            welcome.hidden=false;d.input.focus();
                        };
                        notice.append(p,fresh);setComposerDisabled(true);
                    }
                }catch(e){warn(e.message);}
            },
        });
        engine.box.memory=pageQueue;
        transcript=conversationView(d.messages,engine,{scroll:navigation,renderer:transcriptRenderer});engine.hooks.queue=(...args)=>{transcript.queue(...args);if(args[0].some(r=>r.conversation===engine.sync.id&&r.state!=='accepted'))welcomeHasMessages=true;welcome.hidden=welcomeHasMessages;};initializing=engine.initialize({session:withSession});try{await initializing;retentionNotice.hidden=engine.context.retention_enabled!==true;}finally{initializing=null;}recovery?.refresh();phone?.refresh();issuance?.refresh();
        reads=visibleReads({engine,host:d.messages,visible:shown,updated:(ids,page)=>{if(Number.isSafeInteger(page.customer_unread)){unreadCount=page.customer_unread;setUnread(unreadCount);}else engine.poller.wake();}});
        alerts=new DeviceAlerts(engine,{check:async event=>{if(messageViewport(d.messages,event.sequence,{active:shown()}))return false;if(event.preview&&event.sender&&event.unread!==0)return event;try{const h=await engine.transport.request('customer-notifications.php?action=hint&preview='+(alerts.prefs.preview?'1':'0'));return h.sequence===event.sequence&&h.store_epoch===event.storeEpoch&&h.unread>0&&!messageViewport(d.messages,event.sequence,{active:shown()})?h:false;}catch{return false;}},open:()=>{if(parent!==window)parent.postMessage({type:'support-customer-open'},location.origin);if(!d.win.classList.contains('active'))window.toggleChat();},alert:()=>launcher?.classList.add('has-unread')});
        presentation?.alerts?.(alerts,engine);
        notificationSection=notificationSettings(menu.body,alerts,{deviceControls:!presentation?.deviceSettings,stopBackground:async()=>{const r=await navigator.serviceWorker?.getRegistration(new URL('support-client/',document.baseURI));const sub=await r?.pushManager.getSubscription();if(sub){await engine.transport.request('customer-notifications.php',{data:{action:'unsubscribe'}});await sub.unsubscribe();}}});
        const background=document.createElement('button');background.type='button';background.textContent='اعلان پس‌زمینهٔ این دستگاه';const backgroundStatus=document.createElement('small');notificationSection.append(background,backgroundStatus);background.hidden=true;engine.transport.request('customer-notifications.php?action=status').then(s=>{background.hidden=s.push_enabled!==true;}).catch(()=>{});
        background.onclick=async()=>{try{const s=await engine.transport.request('customer-notifications.php?action=status');if(!s.push_enabled){backgroundStatus.textContent='اعلان پس‌زمینه در این نصب خاموش است.';return;}if(!hasConversation||!('serviceWorker'in navigator)||!('PushManager'in window))throw Error('ابتدا گفتگو را شروع کنید؛ این دستگاه باید Push را پشتیبانی کند.');if(await alerts.permission()!=='granted')throw Error('اجازهٔ اعلان لازم است.');const key=await engine.transport.request('customer-notifications.php?action=key');const registration=await navigator.serviceWorker.register(new URL('support-client/customer-sw.js',document.baseURI),{scope:new URL('support-client/',document.baseURI).pathname,updateViaCache:'none'});await new Promise(resolve=>{if(registration.active)return resolve();const worker=registration.installing||registration.waiting;worker?.addEventListener('statechange',()=>{if(worker.state==='activated')resolve();});});const bytes=Uint8Array.from(atob(key.key.replace(/-/g,'+').replace(/_/g,'/')),c=>c.charCodeAt(0));const sub=await registration.pushManager.subscribe({userVisibleOnly:true,applicationServerKey:bytes});await engine.transport.request('customer-notifications.php',{data:{action:'subscribe',user_id:engine.sync.id,subscription:JSON.stringify(sub.toJSON())}});await alerts.save({background:true});backgroundStatus.textContent=s.external_delivery?'اشتراک ثبت شد؛ دریافت تضمین نمی‌شود.':'اشتراک ثبت شد؛ ارسال بیرونی خاموش است.';}catch(e){backgroundStatus.textContent=e.message;}};
        presentation?.bind?.(engine);cooldown.refresh();return engine;
    };
    ui.opt.addEventListener('change',()=>engine.setPersistence(ui.opt.checked).then(value=>{ui.opt.checked=value;}).catch(e=>{ui.opt.checked=engine.box.persistent;warn(e.message);}));
    ui.clearOld.addEventListener('click',()=>engine.clearStranded().catch(e=>warn(e.message)));
    const older=document.createElement('button');older.type='button';older.hidden=true;older.textContent='پیام‌های قدیمی‌تر';older.className='support-older';let loadingHistory=false;
    older.addEventListener('click',async()=>{
        if(loadingHistory)return;loadingHistory=true;older.disabled=true;const id=engine.sync.id,scope=engine.scope,deadline=Date.now()+15000;
        try{
            // Polling owns the same sync object; queue this deliberate user action
            // instead of silently losing the click while a poll is in flight.
            while(engine.sync.busy&&Date.now()<deadline)await new Promise(resolve=>setTimeout(resolve,20));
            if(engine.sync.id!==id||engine.scope!==scope||engine.frozen)return;
            if(engine.sync.busy)throw new Error('دریافت سابقه طول کشید؛ دوباره امتحان کنید.');
            await engine.sync.sync(true);
        }catch(e){warn(e.message);}finally{loadingHistory=false;older.disabled=!engine.sync.hasOlder;}
    });region.insertBefore(older,d.messages);

    const receiveLatest=async()=>{const scope=engine.scope,id=engine.sync.id,deadline=Date.now()+15000;while(engine.sync.busy&&Date.now()<deadline)await new Promise(resolve=>setTimeout(resolve,20));if(engine.frozen||scope!==engine.scope||id!==engine.sync.id)return;if(engine.sync.busy)throw Error('دریافت پیام‌ها طول کشید؛ دوباره تلاش کنید.');if(engine.sync.hasNewer)await engine.sync.latest();};
    navigation=unreadJump({host:d.messages,region,compact:document.documentElement.dataset.supportSurface==='customer-v2',restoreOlder:true,latest:()=>receiveLatest().catch(e=>{warn(e.message);throw e;})});

    const startHistory=id=>{
        clearTimeout(historyTimer);
        d.win.dataset.historyState='loading';
        historyTimer=setTimeout(()=>{
            if(engine?.sync.id!==id||d.win.dataset.historyState==='ready')return;
            if(stable)failInitialHistory();
            else{d.win.dataset.historyState='failed';initState('partial','history','timeout');}
        },10000);
    };
    const activate=id=>{
        engine?.composerEdit?.close();
        if(engine?.sync.id!==id)typing?.reset();
        engine.draftConversation=hasConversation?null:id;
        selection++;clearTimeout(historyTimer);
        if(deferDraftSync())d.win.dataset.historyState='deferred';else startHistory(id);
        region.style.display='flex';d.messages.style.display='flex';d.area.style.display='block';d.logout.style.display='flex';
        d.error.style.display='none';engine.select(id);cooldown.refresh();startButton.disabled=!hasConversation;shell?.session();
        recovery?.refresh();phone?.refresh();issuance?.refresh();
    };
    const contactValues=session=>{presentation?.session?.(session);document.getElementById('userName').value=session.contact_name==='مهمان'?'':session.contact_name||'';document.getElementById('userEmail').value=session.user_email||'';document.getElementById('userPhone').value=window.NovinPayMobile?.display(session.user_phone||'')??(session.user_phone||'');phone?.prefill(session.user_phone||'');};
d.win.querySelector('.chat-send').addEventListener('pointerdown',event=>{if(document.activeElement===d.input)event.preventDefault();});
    const send=async()=>{
        if(composerLocked)return;if(engine?.sendWait()){cooldown.refresh();return;}if(preparing||revision===lastQueuedRevision)return;preparing=true;
        const draft={text:d.input.value,revision,selection,reply:reply?.id||null};lastQueuedRevision=revision;
        try{const images=await attachments.upload();await engine.enqueue(draft.text,images,draft.reply);attachments.consume(images);if(draft.revision===revision&&draft.selection===selection&&d.input.value===draft.text){typing?.stop();reply?.clear();d.input.value='';presentation?.queued?.();growth.resize();revision++;lastQueuedRevision=-1;}welcome.hidden=true;d.error.style.display='none';}
        catch(e){lastQueuedRevision=-1;if(e.code==='send_cooldown')cooldown.refresh();else warn(e.message);}
        finally{preparing=false;}
    };
    window.sendMessage=send;
    window.startChat=async()=>{
        try{
            if(initializing)await initializing;
            if(!engine||engine.frozen||engine.paused)await create();else await engine.auth();
            if(!hasConversation)throw new Error('ابتدا پیام خود را بفرستید؛ اطلاعات تماس اختیاری است.');
            await engine.transport.request('api.php',{data:{update_contact:'1',user_id:engine.sync.id,name:document.getElementById('userName').value,email:document.getElementById('userEmail').value,phone:window.NovinPayMobile?.ascii(document.getElementById('userPhone').value)??document.getElementById('userPhone').value}});
            menu.close();
            phone?.prefill(document.getElementById('userPhone')?.value||'');
        }catch(e){warn(e.message);}
    };
    window.logout=async()=>{try{if(await engine.logout()){menu.close();await create();const s=await engine.transport.request('api.php?get_session=1');contactValues(s);hasConversation=!!s.user_id;activate(s.user_id||s.draft_user_id);d.input.focus();}}catch(e){warn(e.message);}};
    window.toggleChat=()=>{
        const open=!d.win.classList.contains('active');d.win.style.display=open?'flex':'none';d.win.classList.toggle('active',open);
        if(open){d.win.setAttribute('aria-hidden','false');shell?.open();if(shell?.chat)navigation?.open();rating?.refresh();if(engine?.sync.hasNewer)receiveLatest().catch(e=>warn(e.message));engine?.poller.wake();if(matchMedia('(pointer:fine)').matches){if(shell?.chat)d.input.focus({preventScroll:true});else shell?.start.focus({preventScroll:true});}}
        else{navigation?.close();rating?.refresh();menu.close();d.win.setAttribute('aria-hidden','true');document.querySelector('[aria-label="پشتیبانی"]')?.focus();}
    };
    let widgetWasVisible=false;window.addEventListener('support-widget-visibility',event=>{const visible=!!event.detail?.visible;if(visible===widgetWasVisible)return;widgetWasVisible=visible;if(visible){openedInPage=true;if(engine?.sync.id&&d.win.dataset.historyState==='deferred')startHistory(engine.sync.id);if(!stable||!engine?.sync.busy||d.win.dataset.initState==='ready')engine?.poller.wake();shell?.open();if(shell?.chat)navigation?.open();rating?.refresh();if(engine?.sync.hasNewer)receiveLatest().catch(e=>warn(e.message));}else navigation?.close();});
    window.addEventListener('message',event=>{if(parent!==window&&event.origin===location.origin&&event.source===parent&&event.data?.type==='support-widget-attention')engine?.poller.wake();});
    window.closeChat=()=>{if(onClose){onClose();return;}if(d.win.classList.contains('active'))window.toggleChat();};
    const growth=composerSize(d.input);d.input.addEventListener('input',()=>{revision++;});
    attachments=attachmentComposer({refocus:!!presentation,host:d.area,input:d.input,current:()=>({...engine?.context,csrf_token:engine?.transport.token}),conversation:()=>engine?.sync.id,changed:()=>{revision++;},warn});
    rating=satisfaction({host:d.area,stream:d.messages,engine:()=>engine,visible:shown,following:()=>navigation?.follow(),warn,hasDraft:()=>!!d.input.value||attachments.has(),lifecycle:state=>setComposerDisabled(!!state?.resolved),switchConversation:async id=>{reply?.clear();rating.clear();hasConversation=true;contactValues(await engine.transport.request('api.php?get_session=1'));activate(id);navigation.open();}});
    reply=replyControls({host:d.messages,composer:d.area,input:d.input,engine:()=>engine,warn,reading:navigation,changed:()=>revision++});
    typing=typingIndicator({enabled:globalThis.SupportWidgetConfig?.typing===true,dots:!!presentation,host:presentation?region:d.area,input:d.input,visible:shown,current:()=>({csrf_token:engine?.transport.token}),conversation:()=>hasConversation?engine?.sync.id:null});
    d.input.addEventListener('keydown',e=>{if(e.key==='Enter'&&!e.shiftKey&&!e.isComposing&&e.keyCode!==229&&(presentation||!matchMedia('(pointer: coarse)').matches)){e.preventDefault();send();}});
    d.win.addEventListener('keydown',e=>{if(e.key==='Escape')window.closeChat();});
    d.win.addEventListener('keydown',e=>{if(e.key==='Tab')d.win.classList.add('support-keyboard');});
    document.querySelector('.chat-btn')?.addEventListener('pointerdown',()=>d.win.classList.remove('support-keyboard'));
    d.win.addEventListener('pointerdown',()=>d.win.classList.remove('support-keyboard'));
    document.getElementById('userEmail').addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();window.startChat();}});

    const recoveryOptions={current:()=>engine?.context,conversation:()=>hasConversation?engine?.sync.id:null,warn:message=>warn(message,'recovery'),
        beforeSwitch:()=>{if((d.input.value||attachments?.has())&&!confirm('متن کادر هنوز ارسال نشده است. کنار گذاشته شود؟'))return false;engine.freeze();return true;},
        restart:async switched=>{const previousScope=engine.scope,draft=switched?'':(engine.composerEdit?.draft??d.input.value);selection++;if(switched){revision++;lastQueuedRevision=-1;}identity('در حال بررسی نشست امن',{keepMenu:true});await create();const session=await engine.transport.request('api.php?get_session=1');contactValues(session);hasConversation=!!session.user_id;activate(session.user_id||session.draft_user_id);if(!switched&&previousScope===engine.scope){d.input.value=draft;growth.resize();}recovery.refresh();phone.refresh();}};
    recoveryOptions.done=()=>menu.close();
    recovery=recoveryControls(menu.recovery,recoveryOptions);phone=phoneControls(menu.recovery,recoveryOptions);
    issuance=issuanceControls({region,menu,current:()=>engine?.context,conversation:()=>hasConversation?engine?.sync.id:null,email:()=>document.getElementById('userEmail').value,warn:message=>warn(message,'recovery'),clearWarning:()=>{if(d.error.dataset.kind==='recovery')d.error.style.display='none';},activatedCode:()=>recovery.refresh(true)});
    region.style.display='flex';d.messages.style.display='flex';d.area.style.display='block';setComposerDisabled(true);
    shell=shellFactory({win:d.win,region,composer:d.area,error:d.error,menu,current:()=>hasConversation,warn,onView:({to,resume})=>{if(to==='chat'){if(resume)navigation.resume();else navigation.open();reads?.wake();}else navigation.close();rating?.refresh();}});
    let bootJob=null;
    const boot=()=>{if(bootJob)return bootJob;initRetry.disabled=true;initState('initializing','session');d.win.setAttribute('aria-busy','true');setComposerDisabled(true);
        const job=(async()=>{
            for(const key of ['chat_user_id','chat_user_name','chat_user_email'])try{localStorage.removeItem(key);}catch{}
            if(!engine||engine.frozen||stable&&engine.paused)await create(true);
            else if(!engine.initialized||engine.storageDegraded){engine.box.retryStorage();await engine.initialize({session:true});}
            else await engine.auth();
            const session=engine.transport.takeBootstrapSession()??await engine.transport.request('api.php?get_session=1',{timeout:10000});contactValues(session);hasConversation=!!session.user_id;
            const id=session.user_id||session.draft_user_id;
            if(engine.sync.id!==id)activate(id);
            else if(stable&&hasConversation&&!engine.sync.lastPage){startHistory(id);engine.poller.start();}
            else engine.poller.wake();
            attachments.recover();
            if(stable&&hasConversation&&!engine.sync.lastPage)return;
            setComposerDisabled(!!engine.sync.lastPage?.lifecycle?.resolved);
            initState(!stable&&(engine.storageDegraded||d.win.dataset.historyState==='failed')?'partial':'ready',engine.storageDegraded?'storage':'session',engine.storageDegraded?'unavailable':'');
        })().catch(e=>{initState('failed','session',e.code==='storage_timeout'?'storage_timeout':e.status===401?'authentication':e.status===403?'csrf':e.status>=500?'server':e.status===0?'network':'initialization');setComposerDisabled(true);})
        .finally(()=>{if(!stable)d.win.setAttribute('aria-busy','false');startButton.disabled=!hasConversation;initRetry.disabled=false;shell?.ready();if(bootJob===job)bootJob=null;});bootJob=job;return job;
    };
    initRetry.onclick=boot;await boot();
    navigator.serviceWorker?.addEventListener('message',event=>{if(['support-customer-push','support-customer-open'].includes(event.data?.type))engine?.poller.wake();});
    return engine;
}

function fitCustomerViewport(){const viewport=window.visualViewport,win=document.getElementById('chatWindow');if(!viewport||!win||viewport.scale!==1)return;if(innerWidth<=768&&viewport.height<innerHeight){win.style.setProperty('--support-viewport-height',viewport.height+'px');win.style.bottom=Math.max(0,innerHeight-viewport.height-viewport.offsetTop)+'px';}else{win.style.removeProperty('--support-viewport-height');win.style.bottom='';}}
window.visualViewport?.addEventListener('resize',fitCustomerViewport);window.visualViewport?.addEventListener('scroll',fitCustomerViewport);window.addEventListener('resize',fitCustomerViewport);fitCustomerViewport();
