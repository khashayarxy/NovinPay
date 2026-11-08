import {quickReplyEditor} from './quick-reply-editor.js?v=team1';
import {repliesView} from './modules/replies.js?v=navneutral1';
import {composerSize} from '../support-client/composer-size.js?v=ux1';
import {apiHealth} from '../support-client/network.js?v=team1';
import {authLifecycle} from './auth-lifecycle.js?v=release1';
import {mobileWorkspace} from './mobile.js?v=ux1';
import {operatorPwa} from './pwa.js?v=sidebar1';
import {analyticsView} from './analytics.js?v=release1';
import {persianDate,messageDate} from '../support-client/persian-date.js';
import {widgetSettings} from './widget-settings.js?v=navneutral1';
import {unreadJump} from '../support-client/unread-jump.js?v=latest2';
import {overlayControls} from './overlays.js?v=operatoraudit1';
import {profileSettings} from './profile.js?v=0.1.0-ui4';
import {renderCustomerV2} from '../support-client/customer-v2-renderer.js?v=team1';
import {themeControls,quickThemeToggle} from '../support-client/theme.js?v=0.1.0-r14';
import {notificationReadiness} from './readiness.js?v=0.1.0-r14';
import {healthView} from './health.js?v=0.1.0-r14';
import {ConversationDrafts} from './drafts.js?v=refresh1';
import {productivity,insertQuickText} from './productivity.js?v=team1';
import {replyControls} from '../support-client/reply.js?v=bplus1';
import {visibleReads,DeviceAlerts,notificationSettings,messageViewport} from '../support-client/attention.js?v=team1';
import {createRouter} from './modules/router.js?v=navneutral1';
import {dashboardView} from './modules/dashboard.js?v=navneutral1';
import {knowledgeView} from './modules/knowledge.js?v=navneutral1';
import {contactsView} from './modules/contacts.js?v=polish1';
import {analyticsView as v3AnalyticsView} from './modules/analytics.js?v=operatoraudit1';
import {teamView} from './modules/team.js';
import {settingsView} from './modules/settings.js?v=navneutral1';
import {healthView as v3HealthView} from './modules/health.js?v=mobile2';
import {operatorEmojiPicker} from './emoji.js';

const $=id=>document.getElementById(id);
const toastHost=$('toastContainer');
const recentToasts=new Map();
function showToast(message,severity='info',duration=null){
 if(!message)return null;const text=String(message).trim();if(!text)return null;
 const now=Date.now();if(recentToasts.has(text)&&now-recentToasts.get(text)<4000)return null;recentToasts.set(text,now);
 if(!toastHost)return null;
 const toast=document.createElement('div');toast.className='toast';toast.dataset.severity=severity;
 toast.setAttribute('role',severity==='error'||severity==='warning'?'alert':'status');
 const icon=document.createElement('span');icon.className='toast-icon support-icon';icon.setAttribute('aria-hidden','true');
 icon.textContent=severity==='success'?'check_circle':(severity==='warning'?'warning':(severity==='error'?'error':'info'));
 const body=document.createElement('div');body.className='toast-body';body.textContent=text;
 const closeBtn=document.createElement('button');closeBtn.type='button';closeBtn.className='toast-close';closeBtn.setAttribute('aria-label','بستن اعلان');closeBtn.textContent='×';
 const dismiss=()=>{if(toast.classList.contains('toast-hide'))return;toast.classList.add('toast-hide');setTimeout(()=>toast.remove(),200);};
 closeBtn.onclick=dismiss;toast.append(icon,body,closeBtn);toastHost.prepend(toast);
 const autoDismissMs=duration??(severity==='error'?0:(severity==='warning'?6000:4500));
 if(autoDismissMs>0)setTimeout(dismiss,autoDismissMs);
 return toast;
}
try{window.showToast=showToast;}catch{}
function sanitizeErrorMessage(e){
 if(!e)return 'درخواست انجام نشد.';const raw=e.message||String(e);
 if(/Cannot read properties|is not a function|reading '|of undefined|of null|Unexpected token/i.test(raw)){
  return 'خطای غیرمنتظره در برنامه رخ داد. لطفاً صفحه را تازه کنید.';
 }
 return raw;
}
const fail=e=>{if(e?.name==='AbortError'||e?.code==='stale_auth_response')return;const msg=sanitizeErrorMessage(e);const errEl=$('error');if(errEl)errEl.textContent=msg;showToast(msg,'error');};
let router=null,dashboardModule=null,knowledgeModule=null,contactsModule=null;
let analyticsModule=null,teamModule=null,settingsModule=null,repliesModule=null,healthModule=null;

let lastInboxFetch=0,lastSyncWake=0;function softInbox(append=false,interactive=false){return loadInbox(append,interactive).catch(e=>{if(e.status===429||e.status===503){connection();return false;}fail(e);});}
let token=null,operator=null,engine=null,selected=null,rows=[],replies=[],accounts=[],cursor=0,more=false,generation=0,poller=null,attachments=null,preferences={},lastSound=0,registration=null,revision=0,lastQueued=-1;
let conversationClosed=null,closing=false,operatorSending=false;let selectedUnread=0,lastUnreadTotal=0;let reads=null,alerts=null,notificationSection=null,composing=false,replyReference=null;
let transcript=null;let bootstrapPromise=null,bootstrapping=false,inboxRequest=0,lastAppliedInboxRequest=0;let drafts=null,macroUI=null,workView='inbox',inboxTab='all';let audio=null,typing=null,editingReply=null;
const authStatus=document.createElement('p'),retryAuth=document.createElement('button');authStatus.id='authStatus';authStatus.setAttribute('role','status');retryAuth.id='retryAuth';retryAuth.type='button';retryAuth.textContent='تلاش دوباره';retryAuth.hidden=true;$('bootSplash').append(authStatus,retryAuth);
const authGate=authLifecycle({paint:paintAuth,clear:clearAuthenticated});
function paintAuth(state,message=''){
 document.documentElement.dataset.operatorAuth=state;
 const authenticated=state==='authenticated';$('adminShell').hidden=!authenticated;$('adminShell').inert=!authenticated;$('login').hidden=state!=='unauthenticated';$('bootSplash').hidden=state!=='checking';
 for(const id of ['operatorIdentity','exit','breadcrumbHost','mobileMenuBtn'])$(id).hidden=!authenticated;
 authStatus.textContent=message||'در حال بررسی نشست امن…';retryAuth.hidden=!message;
 if(state==='unauthenticated'){$('loginError').textContent=message;$('loginError').hidden=!message;}
}
function clearAuthenticated(){
 quickEditor?.reset();
 generation++;preferenceVersion++;bootstrapping=false;bootstrapPromise=null;clearTimeout($('reply').draftTimer);clearTimeout(searchTimer);clearTimeout($('messageNotice').timer);inboxWanted=null;inboxJob=null;inboxNext=0;inboxRequest++;preferenceChanges.clear();preferenceJob=null;
 poller?.stop();poller=null;reads?.destroy();reads=null;alerts?.destroy();alerts=null;typing?.destroy();typing=null;replyReference?.destroy();replyReference=null;macroUI?.destroy();macroUI=null;attachments?.destroy();attachments=null;notificationSection?.permissionCleanup?.();notificationSection?.remove();notificationSection=null;emojiUI.close();engine?.destroy();engine=null;
 router?.destroy();router=null;for(const m of [dashboardModule,knowledgeModule,contactsModule,analyticsModule,teamModule,settingsModule,repliesModule,healthModule])m?.destroy();dashboardModule=knowledgeModule=contactsModule=analyticsModule=teamModule=settingsModule=repliesModule=healthModule=null;
 reports.clear();accountUI.clear();widgetUI.clear();health?.clear();readiness?.clear();drafts?.clear();drafts=null;
 operator=null;token=null;selected=null;rows=[];replies=[];accounts=[];preferences={};selectedUnread=lastUnreadTotal=0;conversationClosed=null;operatorSending=closing=false;editingReply=null;revision++;lastQueued=-1;
 for(const dialog of document.querySelectorAll('dialog[open]'))dialog.close();for(const details of document.querySelectorAll('details[open]'))details.open=false;
 for(const id of ['conversations','chatMessages','outbox','notes','sourcePage','selfAvatar','sidebarAvatar','menuAvatar'])$(id).replaceChildren();
 for(const id of ['menuName','accountFullName','profile','accountIdentity','sidebarUserName','sidebarUserRole','conversationTitle','settingsFeedback','profileFeedback','widgetConfiguration','followUpStatus','error','messageNotice'])$(id).textContent='';
 for(const id of ['search','reply','note','labels','quickTitle','quickText','macroLabels','accountUsername','accountName','accountPassword','password','snoozeUntil'])$(id).value='';
 for(const id of ['assignment','quick','savedReplies'])$(id).replaceChildren(new Option('انتخاب کنید',''));
 $('filter').value='';$('assignedOnly').checked=false;$('includeTests').checked=false;$('operatorAccount').querySelector('summary').setAttribute('aria-label','حساب اپراتور');$('adminSidebar').classList.remove('mobile-open');$('adminSidebar').inert=innerWidth<900;$('mobileMenuBtn').setAttribute('aria-expanded','false');$('sidebarBackdrop').hidden=true;$('adminStage').inert=false;
 document.querySelector('.app-header').querySelectorAll(':scope > *').forEach(x=>x.inert=false);document.body.classList.remove('detail-open');$('toastContainer').replaceChildren();$('workspace').hidden=true;$('detailHeader').hidden=true;$('messageStream').hidden=true;$('replyArea').hidden=true;$('emptyConversation').hidden=false;navigation.reset();document.title='ورود به پشتیبانی';try{navigator.clearAppBadge?.().catch(()=>{});}catch{}
}
retryAuth.onclick=()=>bootstrap().catch(fail);
themeControls($('appearanceSettings'));quickThemeToggle(document.querySelector('.app-header'));
$('conversationStatusHost').append($('status').closest('label'));$('status').hidden=true;const statusText=document.createElement('span');statusText.id='conversationState';$('status').before(statusText);$('conversationAssignmentHost').append($('assignment').closest('label'));
const quickChooser=document.createElement('button');quickChooser.type='button';quickChooser.className='icon-button';quickChooser.setAttribute('aria-label','پاسخ آماده');const quickBolt=document.createElement('span');quickBolt.className='support-icon';quickBolt.setAttribute('aria-hidden','true');quickBolt.textContent='bolt';quickChooser.append(quickBolt);$('replyArea').querySelector('.chat-input-row').insertBefore(quickChooser,$('emojiToggle'));quickChooser.onclick=()=>{if(!engine?.composerEdit)macroUI?.open();};
const emojiUI=operatorEmojiPicker({host:$('replyArea'),input:$('reply'),toggle:$('emojiToggle'),changed:()=>{revision++;resizeReply();captureDraft();}});
const replyGrowth=composerSize($('reply'),{operator:true});
const navigation=unreadJump({host:$('messageStream'),region:$('detail'),compact:true,latest:async()=>{if(engine?.sync.hasNewer)await engine.sync.latest();}});
const overlays=overlayControls();
const widgetUI=widgetSettings({host:$('widgetEditor'),token:()=>token,current:()=>operator,config:()=>globalThis.SupportWidgetConfig});
const reports=analyticsView({api,current:()=>operator,openConversation:id=>{router?.navigate('conversations',{id});},warn:fail});

const endConversation=document.createElement('button');endConversation.id='endConversation';endConversation.className='compact-close';endConversation.type='button';endConversation.textContent='پایان گفتگو';$('conversationStatusHost').after(endConversation);
const closeDialog=$('closeConversationConfirm'),cancelClose=$('cancelClose'),confirmClose=$('confirmClose');
cancelClose.onclick=()=>overlays.close(closeDialog);endConversation.onclick=()=>{if(selected&&conversationClosed===false&&!closing)overlays.open(closeDialog);};
closeDialog.addEventListener('close',()=>{if(conversationClosed){$('conversationTitle').tabIndex=-1;$('conversationTitle').focus({preventScroll:true});}});
closeDialog.addEventListener('keydown',event=>{if(event.key!=='Tab')return;const controls=[...closeDialog.querySelectorAll('button:not([disabled])')];const first=controls[0],last=controls.at(-1);if(!first){event.preventDefault();return;}if(event.shiftKey&&document.activeElement===first){event.preventDefault();last.focus();}else if(!event.shiftKey&&document.activeElement===last){event.preventDefault();first.focus();}});
confirmClose.onclick=async()=>{if(closing||conversationClosed!==false||!selected)return;closing=true;confirmClose.disabled=cancelClose.disabled=true;closeDialog.setAttribute('aria-busy','true');try{await change('status','resolved');closeDialog.removeAttribute('aria-busy');overlays.close(closeDialog);}catch(e){fail(e);}finally{closing=false;closeDialog.removeAttribute('aria-busy');confirmClose.disabled=cancelClose.disabled=false;applyComposerState();}};
const closedNote=document.createElement('small');closedNote.id='closedConversationNote';closedNote.textContent='گفتگو پایان یافته است.';closedNote.hidden=true;$('replyArea').before(closedNote);
function applyComposerState(){for(const el of $('replyArea').querySelectorAll('textarea,input,button,select'))el.disabled=conversationClosed!==false||(el.id==='send'&&operatorSending);endConversation.disabled=conversationClosed!==false||closing;closedNote.hidden=!selected||conversationClosed!==true;statusText.hidden=true;endConversation.textContent=conversationClosed?'پایان یافته':'پایان گفتگو';}
const statusNames={new:'جدید',awaiting_reply:'در انتظار پاسخ',resolved:'حل‌شده'};
async function api(action,data=null,query={}){
 if(!['bootstrap','login'].includes(action)&&!operator)throw authGate.stale();
 const healthTicket=apiHealth.begin();const ticket=authGate.request(),options={credentials:'same-origin',cache:'no-store',signal:ticket.signal};let url='operator-api.php?'+new URLSearchParams({action,...query});
 if(data instanceof FormData){url='operator-avatar.php';options.method='POST';options.headers={'X-CSRF-Token':token};options.body=data;}else if(data){options.method='POST';options.headers={'X-CSRF-Token':token};options.body=new URLSearchParams({action,...data});}
 try{const r=await fetch(url,options);let body;try{body=await r.json();}catch{throw Error('ارتباط تأیید نشد.');}authGate.assert(ticket.epoch);
  if(!r.ok){if(action==='bootstrap'&&body.code==='operator_unavailable')throw Object.assign(Error('پنل چنداپراتوره در تنظیمات این نصب فعال نشده است.'),{unavailable:true});if(r.status===401&&action!=='login')authGate.unauthenticated('نشست شما پایان یافته است. دوباره وارد شوید.');
   const accountErrors={current_password_required:'رمز فعلی درست نیست.',last_administrator:'حداقل یک مدیر فعال باید باقی بماند.',operator_exists:'این نام کاربری قبلاً ثبت شده است.',invalid_operator:'نام نمایشی، نقش و طول رمز را بررسی کنید.',invalid_brand_image:'تصویر JPEG، PNG یا WebP تا ۲ مگابایت انتخاب کنید.',use_self_password_flow:'رمز خود را از بخش پروفایل من تغییر دهید.',avatar_capacity:'ظرفیت تصویر پروفایل پر شده است.'};
   throw Object.assign(Error(accountErrors[body.code]||body.message||'درخواست مجاز نیست.'),{status:r.status,retryAfter:Math.min(86400,Math.max(0,Number(r.headers.get('Retry-After'))||0))*1000});
  }if(options.method==='POST'){const targets={profile_save:'selfProfile',profile_password:'selfPassword',account_add:'accountCreation',reply_save:'quickReplyForm'};if(targets[action])overlays.saved($(targets[action]));}apiHealth.finish(healthTicket);return body;
 }catch(e){authGate.assert(ticket.epoch);apiHealth.finish(healthTicket,e);if(e instanceof TypeError)throw Error('ارتباط با سرویس چت تأیید نشد؛ دوباره تلاش کنید.');throw e;}finally{ticket.done();}
}
function bootstrap(){if(!bootstrapPromise){const job=initializeOperator().finally(()=>{if(bootstrapPromise===job)bootstrapPromise=null;});bootstrapPromise=job;}return bootstrapPromise;}
async function initializeOperator(){
 const epoch=authGate.checking();poller?.stop();engine?.freeze();bootstrapping=true;$('workspace').setAttribute('aria-busy','true');for(const id of ['exit','send'])$(id).disabled=true;
 // Auth-checking state: until the session probe resolves, neither the login
 // form nor the privileged workspace may paint; the splash shell covers both.
 if($('login').hidden&&$('workspace').hidden)$('bootSplash').hidden=false;
 try{
 const b=await api('bootstrap');token=b.token;globalThis.SupportWidgetConfig=b.config;document.title='پشتیبانی '+b.config.name;$('siteTitle').textContent=document.title;
 if(!b.authenticated){authGate.unauthenticated();token=b.token;return;}
 if(operator?.id!==b.operator.id){preferenceVersion++;preferenceChanges.clear();preferences={};health?.clear();readiness?.clear();drafts?.clear();selected=null;$('reply').value='';replyReference?.clear();$('detailHeader').hidden=true;$('replyArea').hidden=true;$('messageStream').hidden=true;$('emptyConversation').hidden=false;document.body.classList.remove('detail-open');}operator=b.operator;reports.update();$('profile').textContent=operator.name;$('accountIdentity').textContent=operator.name+' · '+(operator.role==='administrator'?'مدیر':'پشتیبان');if($('preferences'))$('preferences').hidden=false;$('advancedTab').hidden=operator.role!=='administrator';$('developmentControl').hidden=operator.role!=='administrator'||b.config.development!==true;$('login').hidden=true;$('workspace').hidden=false;$('adminAccounts').hidden=operator.role!=='administrator';$('operatorsTab').hidden=operator.role!=='administrator';$('widgetTab').hidden=operator.role!=='administrator';$('menuName').textContent=operator.name;$('accountFullName').textContent=operator.name;$('operatorAccount').querySelector('summary').setAttribute('aria-label','حساب '+operator.name);$('menuAvatar').textContent=operator.name.trim().slice(0,1);$('widgetConfiguration').textContent='پشتیبانی '+b.config.name+' · '+(b.config.attachments?'پیوست تصویر فعال':'پیوست تصویر غیرفعال');await accountUI.load();authGate.assert(epoch);$('sidebarUserName').textContent=operator.name;$('sidebarUserName').title=operator.name;$('sidebarUserRole').textContent=operator.role==='administrator'?'مدیر سیستم':'پشتیبان';$('sidebarAvatar').textContent=operator.name.trim().slice(0,1);for(const el of document.querySelectorAll('.admin-nav-item'))el.hidden=operator.role!=='administrator';
 const {ChatEngine,controls,conversationView}=await import('../support-client/engine.js?v=team1');
 const {Poller}=await import('../support-client/network.js?v=team1');const {attachmentComposer}=await import('../support-client/attachments.js?v=refresh2');
 authGate.assert(epoch);reads?.destroy();alerts?.destroy();notificationSection?.permissionCleanup?.();notificationSection?.remove();engine?.destroy();engine=new ChatEngine('operator',authGate.hooks({sessionExpired:()=>authGate.unauthenticated('نشست شما پایان یافته است. دوباره وارد شوید.'),canSend:id=>id!==selected||conversationClosed===false,visible:()=>!!selected&&!$('workspace').hidden&&(innerWidth>=900||document.body.classList.contains('detail-open')),warning:message=>{if(message)showToast(message,'warning');},identity:message=>authGate.unauthenticated(message),
  queued:row=>{if(row.conversation===selected)navigation.outgoing();},
  messages:(messages,older,page)=>{if(page?.workflow_status){$('status').value=page.workflow_status;conversationClosed=page.workflow_status==='resolved';applyComposerState();}if(Number.isSafeInteger(page?.operator_unread)){applyReadSnapshot(page);selectedUnread=page.operator_unread;navigation.unread(selectedUnread);}const stream=$('messageStream'),anchor=navigation.before(older),height=stream.scrollHeight;transcript?.messages(messages,older);navigation.tail(engine.sync.hasNewer);reads?.messages(messages);typing?.received?.(messages);alerts?.observe(messages,page,older);replyReference?.refresh();if(older)stream.scrollTop+=stream.scrollHeight-height;navigation.after(anchor,older);softInbox();},accepted:(row,ack)=>{if(ack)applyReadSnapshot(engine.sync.mergeAttention(ack));transcript?.accepted(row);if(row.scope===drafts?.scope)drafts.accepted(row).catch(fail);softInbox();},connection:()=>connection()}));
 $('privacy').replaceChildren();const privacy=controls($('privacy'));$('outbox').replaceChildren();transcript=conversationView($('chatMessages'),engine,{renderer:(host,messages,{older=false,animate=false}={})=>renderCustomerV2(host,messages,{older,animate,operator:true,ownGroup:operator?.sender_groups||operator?.sender_group,engine,follow:false})});engine.hooks.queue=authGate.hooks({queue:(...args)=>{const anchor=navigation.before();transcript.queue(...args);navigation.after(anchor);}}).queue;engine.hooks.mode=authGate.hooks({mode:value=>privacy.opt.checked=value}).mode;privacy.opt.onchange=()=>engine.setPersistence(privacy.opt.checked).then(()=>saveDrafts()).catch(fail);
 const {typingIndicator}=await import('../support-client/typing.js?v=0.1.0-r14');typing?.destroy();typing=typingIndicator({host:$('replyArea'),input:$('reply'),role:'operator',visible:()=>!!selected&&!$('workspace').hidden&&(innerWidth>=900||document.body.classList.contains('detail-open')),current:()=>({csrf_token:engine.transport.token}),conversation:()=>selected});await engine.initialize();authGate.assert(epoch);const previousDrafts=drafts;drafts=new ConversationDrafts(engine);drafts.adopt(previousDrafts);await restoreDrafts();authGate.assert(epoch);attachments?.destroy();attachments=attachmentComposer({refocus:true,host:$('replyArea'),input:$('reply'),role:'operator',current:()=>({...engine.context,csrf_token:engine.transport.token}),conversation:()=>selected,changed:()=>{revision++;},warn:authGate.hooks({warn:message=>fail(Error(message))}).warn,unauthenticated:()=>authGate.unauthenticated('نشست شما پایان یافته است. دوباره وارد شوید.')});
 $('assignment').replaceChildren(new Option('بدون مسئول',''));accounts=(await api('accounts')).accounts;for(const a of accounts.filter(a=>a.active))$('assignment').add(new Option(a.name,a.id));
 reads=visibleReads({engine,host:$('chatMessages'),visible:()=>!!selected&&(innerWidth>=900||document.body.classList.contains('detail-open'))&&!$('settings').open&&!$('conversationDetails').open,updated:(ids,page)=>{applyReadSnapshot(page);selectedUnread=Number.isSafeInteger(page.operator_unread)?page.operator_unread:selectedUnread;navigation.unread(selectedUnread);softInbox();}});
 const activeConversation=id=>id===selected&&!document.hidden&&document.hasFocus()&&(innerWidth>=900||document.body.classList.contains('detail-open'))&&!$('workspace').hidden&&!document.querySelector('dialog[open]');
 alerts=new DeviceAlerts(engine,{eligible:()=>loadPreferences(),quiet:()=>preferenceChanges.get('quiet')==='1'||preferences.quiet_now===true,check:async event=>{if(event.conversation&&activeConversation(event.conversation)&&messageViewport($('chatMessages'),event.sequence,{active:true,operator:true}))return false;if(event.preview&&event.conversation)return event;try{const hint=await api('notification_hint',null,{preview:alerts.prefs.preview?'1':'0'});return hint.sequence===event.sequence&&hint.store_epoch===event.storeEpoch&&hint.conversation&&!messageViewport($('chatMessages'),event.sequence,{active:activeConversation(hint.conversation),operator:true})?hint:false;}catch{return false;}},open:target=>{if(target?.conversation)return select(target.conversation).catch(fail);},alert:context=>{$('unread').classList.add('new-message');if(!document.hidden&&!preferences.quiet_now&&!activeConversation(context.conversation)){showToast('پیام تازه‌ای دریافت شد.','info');}}});
 notificationSection=notificationSettings($('deviceNotifications'),alerts,{stopBackground:async()=>{const sub=await registration?.pushManager.getSubscription();if(sub){await api('push_unsubscribe',{endpoint:sub.endpoint});await sub.unsubscribe();}}});
 replyReference?.destroy();replyReference=replyControls({host:$('chatMessages'),composer:$('replyArea'),input:$('reply'),engine:()=>engine,warn:message=>fail(Error(message)),changed:()=>{revision++;captureDraft();}});
 macroUI?.destroy();macroUI=productivity({input:$('reply'),current:()=>selected,replies:()=>replies,api,drafts:()=>drafts,applyDraft:(id,text)=>{if(selected===id){if(engine?.composerEdit)return;$('reply').value=text;revision++;captureDraft();resizeReply();}},workflow:(id,w)=>{if(selected===id)renderWorkflow(w);softInbox();},editReply:q=>quickEditor.open(q),canEditReply:q=>quickEditor.canEdit(q),warn:authGate.hooks({warn:message=>fail(Error(message))}).warn});
 await loadReplies();await loadPreferences();authGate.assert(epoch);const activeRow=rows.find(row=>row.id===selected);if(activeRow){selectedUnread=activeRow.unread;navigation.unread(selectedUnread);}
 $('inboxEmpty').hidden=false;$('inboxEmpty').textContent='دریافت گفتگوها…';poller?.stop();poller=new Poller(()=>loadInbox(),{active:4000,idle:5000,hidden:9000,onerror:e=>{if(!e.status||e.status===429||e.status>=500)connection();else fail(e);}});poller.start();
 // Route selection must wait for the authenticated engine and scoped drafts.
 setupV3Modules();authGate.authenticated(epoch);router.init();
 connection();
 }catch(error){
  if(epoch!==authGate.version||error?.name==='AbortError')return;
  authGate.retry(error.unavailable?error.message:'ارتباط با سرور تأیید نشد. برای بررسی دوباره تلاش کنید.');throw error;
 }finally{if(epoch===authGate.version){bootstrapping=false;$('workspace').setAttribute('aria-busy','false');const ready=authGate.state==='authenticated'&&!!engine?.context&&!engine.frozen;for(const id of ['exit','send'])$(id).disabled=!ready;applyComposerState();}}
}
function connection(){
 const stale=Date.now()-apiHealth.at>30000,unavailable=apiHealth.state==='unavailable',offline=!navigator.onLine&&stale;
 $('operatorOffline').hidden=!(unavailable||offline);
 const text=unavailable?'ارتباط با سرویس چت تأیید نشد؛ پیام‌های در انتظار محفوظ‌اند.':offline?'اتصال دستگاه در دسترس نیست؛ پیام‌های در انتظار محفوظ‌اند.':'';
 $('operatorOffline').textContent=text;$('connection').textContent=text;
 const dot=$('connectionDot');if(dot){dot.classList.remove('online','offline','connecting');dot.classList.add(unavailable||offline?'offline':apiHealth.state==='reachable'?'online':'connecting');dot.title=text||(apiHealth.state==='reachable'?'سرویس چت در دسترس است':'در حال بررسی ارتباط');}
}
addEventListener('support-api-health',connection);
function displayName(row){if(row.name&&row.name!=='مهمان')return row.name;let h=2166136261;for(const c of row.id)h=Math.imul(h^c.charCodeAt(0),16777619);return 'مهمان '+((h>>>0)%9000+1000).toLocaleString('fa-IR',{useGrouping:false});}
function messageTime(value){return persianDate(messageDate({time:value})).label;}
// Read receipts, ACK and polling share one refresh budget (server: 20/minute).
// Coalesce bursts, read current filters at execution, and honor Retry-After.
let inboxJob=null,inboxWanted=null,inboxNext=0,inboxReadVersion=0,inboxAttentionStamp='';
function loadInbox(append=false,interactive=false){
 if(!operator||authGate.state!=='authenticated')return Promise.resolve(false);if(interactive)inboxNext=0;const epoch=authGate.version;
 inboxWanted={append:append&&(inboxWanted?.append??true),request:++inboxRequest};
 if(!inboxJob){const job=(async()=>{let result=false;while(inboxWanted&&operator&&epoch===authGate.version){await new Promise(resolve=>setTimeout(resolve,Math.max(0,inboxNext-Date.now())));authGate.assert(epoch);if(!operator)return false;const next=inboxWanted;inboxWanted=null;inboxNext=Date.now()+2500;try{result=await fetchInbox(next.append,next.request);lastInboxFetch=Date.now();}catch(e){inboxNext=Math.max(inboxNext,Date.now()+(e.retryAfter||0));throw e;}}return result;})().finally(()=>{if(inboxJob===job)inboxJob=null;});inboxJob=job;}
 return inboxJob;
}
async function fetchInbox(append,request){
 if(!operator)return false;const readVersion=inboxReadVersion;const r=await api('inbox',null,{q:$('search').value,filter:$('filter').value,offset:append?cursor:0,include_tests:$('includeTests').checked?'1':'0',view:workView,tab:inboxTab});if(readVersion!==inboxReadVersion){inboxWanted??={append:false,request:++inboxRequest};inboxNext=0;return false;}if(request<lastAppliedInboxRequest)return false;lastAppliedInboxRequest=request;
 const previous=new Set(rows.filter(x=>x.unread>0).map(x=>x.id));rows=append?[...rows,...r.conversations.filter(x=>!rows.some(y=>y.id===x.id))]:r.conversations;cursor=r.next_offset;more=r.has_more;$('more').hidden=!more;
 const list=$('conversations'),ids=new Set(rows.map(r=>r.id));for(const el of [...list.children])if(!ids.has(el.dataset.conversation))el.remove();let position=0;
 for(const row of rows){let button=[...list.children].find(el=>el.dataset.conversation===row.id);if(!button){button=document.createElement('button');button.dataset.conversation=row.id;button.onclick=()=>router?.navigate('conversations',{id:row.id});}
  const signature=JSON.stringify([row.name,row.last,row.last_origin,row.time,row.unread,row.needs_reply,row.development_test,row.priority,row.follow_up_due,row.waiting?.state,row.waiting?.since]);if(button.dataset.content!==signature){button.dataset.content=signature;const avatar=document.createElement('span'),copy=document.createElement('span'),name=document.createElement('strong'),preview=document.createElement('small'),meta=document.createElement('span'),time=document.createElement('time');avatar.className='conversation-avatar';avatar.setAttribute('aria-hidden','true');avatar.textContent=displayName(row).slice(0,1);copy.className='conversation-copy';name.textContent=displayName(row);preview.textContent=row.development_test?'گفتگوی آزمایشی':(row.last_origin==='faq_auto'?'پاسخ خودکار: ':'')+(row.last||'تصویر پیوست');copy.append(name,preview);meta.className='conversation-meta';time.textContent=messageTime(row.time);time.dateTime=String(row.time).replace(' ','T');meta.append(time);if(row.waiting?.state==='support'&&row.waiting?.since&&Date.now()/1000-row.waiting.since>=row.delay_threshold){const delay=document.createElement('small');delay.className='support-delay';delay.textContent='انتظار طولانی';meta.append(delay);}if(row.priority==='urgent'||row.follow_up_due){const flag=document.createElement('small');flag.className='support-work-flag';flag.textContent=row.follow_up_due?'پیگیری': 'فوری';meta.append(flag);}appendAttention(meta,row);button.replaceChildren(avatar,copy,meta);}
  button.setAttribute('aria-current',String(row.id===selected));if(list.children[position]!==button)list.insertBefore(button,list.children[position]||null);position++;
 }
 const activeRow=rows.find(row=>row.id===selected);if(activeRow){selectedUnread=activeRow.unread;navigation.unread(selectedUnread);}
 $('inboxEmpty').hidden=rows.length>0;$('inboxEmpty').textContent=$('search').value?'گفتگویی با این عبارت پیدا نشد.':(inboxTab==='unread'?'پیام خوانده‌نشده‌ای ندارید.':inboxTab==='needs_reply'?'همهٔ پیام‌ها پاسخ گرفته‌اند.':'هنوز گفتگویی اینجا نیست.');const unread=Number.isSafeInteger(r.unread_total)?r.unread_total:rows.reduce((n,r)=>n+r.unread,0);const unreadDelta=Math.max(1,unread-lastUnreadTotal);lastUnreadTotal=unread;paintUnreadTotal(unread);
 if(alerts){const unreadConv=rows.find(x=>x.unread>0);await alerts.update(r.unread_sequence,r.store_epoch,{count:unreadDelta,conversation:unreadConv?.id||null,preview:unreadConv?.last||'',sender:unreadConv?displayName(unreadConv):'کاربر'});}
 await engine?.presence();connection();return true;
}
// A server-confirmed visible-read ACK updates only this account/conversation.
// Reject inbox snapshots requested before that ACK; never clear unseen messages.
function appendAttention(meta,row){if(!row.unread&&!row.needs_reply)return;const dot=document.createElement('span');dot.className=row.unread?'unread-dot':'needs-reply-dot';const label=(row.unread?row.unread.toLocaleString('fa-IR')+' پیام خوانده‌نشده توسط تیم پشتیبانی':'')+(row.needs_reply?(row.unread?'؛ ':'')+'نیازمند پاسخ':'');dot.setAttribute('aria-label',label);dot.title=label;meta.append(dot);}
function applyReadSnapshot(page){
 if(page?.conversation_id!==selected||page.store_epoch!==engine?.sync.epoch||!Number.isSafeInteger(page.operator_unread)||page.operator_unread<0)return;
 const stamp=[page.conversation_id,page.store_epoch,page.attention_revision].join('|'),revisionChanged=stamp!==inboxAttentionStamp;if(revisionChanged){inboxAttentionStamp=stamp;inboxReadVersion++;}const row=rows.find(r=>r.id===page.conversation_id);if(!row)return;if(row.unread===page.operator_unread&&row.needs_reply===page.needs_reply)return;if(!revisionChanged)inboxReadVersion++;
 const previous=row.unread;row.unread=page.operator_unread;if(typeof page.needs_reply==='boolean')row.needs_reply=page.needs_reply;const button=[...$('conversations').children].find(b=>b.dataset.conversation===row.id);
 if(button){delete button.dataset.content;button.querySelectorAll('.unread-dot,.needs-reply-dot').forEach(x=>x.remove());appendAttention(button.querySelector('.conversation-meta'),row);}
 paintUnreadTotal(Math.max(0,lastUnreadTotal-previous+row.unread));
}
function paintUnreadTotal(unread){lastUnreadTotal=unread;$('unread').textContent=unread?unread.toLocaleString('fa-IR'):'';$('unread').hidden=unread===0;const navBadge=$('navUnreadBadge');if(navBadge){navBadge.textContent=unread?unread.toLocaleString('fa-IR'):'';navBadge.hidden=unread===0;}if(!unread)$('unread').classList.remove('new-message');document.title=(unread?'('+unread+') ':'')+'پشتیبانی '+globalThis.SupportWidgetConfig.name;try{if(unread)navigator.setAppBadge?.(unread).catch(()=>{});else navigator.clearAppBadge?.().catch(()=>{});}catch{}}
async function select(id){
 if(engine?.composerEdit&&!engine.composerEdit.cancel())return;
 if(authGate.state!=='authenticated')return;const epoch=authGate.version;
 const version=++generation,same=selected===id&&engine?.sync.id===id;if(selected){drafts.set(selected,engine?.composerEdit?.draft??$('reply').value,replyReference?.id||null);await saveDrafts();authGate.assert(epoch);}if(!same&&attachments?.has()&&!confirm('تصویر انتخاب‌شده هنوز در صف نیست. تغییر گفتگو؟'))return;
 if(!same){attachments?.clear();replyReference?.clear();}for(const field of ['detailsToggle','status','assignment'])$(field).disabled=true;selected=id;if(!same)conversationClosed=null;applyComposerState();$('messageNotice').hidden=true;clearTimeout($('messageNotice').timer);for(const button of $('conversations').children)button.setAttribute('aria-current',String(button.dataset.conversation===id));revision++;document.body.classList.add('detail-open');$('conversationTitle').textContent=rows.find(x=>x.id===id)?displayName(rows.find(x=>x.id===id)):'گفتگو';$('detailHeader').hidden=false;$('emptyConversation').hidden=true;$('messageStream').hidden=false;$('conversationDetails').close();$('replyArea').hidden=false;$('workflow').hidden=false;$('replyTools').hidden=false;$('reply').value=drafts.get(id);if(drafts.reply(id))replyReference?.set(drafts.reply(id));else replyReference?.clear();resizeReply();attachments?.recover();if(!same){selectedUnread=0;navigation.reset();navigation.open();engine.select(id);}else{navigation.open();engine.poller.wake();}
 try{const detail=await api('detail',null,{user_id:id});if(version!==generation)return;renderWorkflow(detail.workflow);$('sourcePage').replaceChildren();$('sourcePage').hidden=!detail.source_page;if(detail.source_page){const title=document.createElement('strong'),path=document.createElement('small');title.textContent=detail.source_page.title;path.textContent=detail.source_page.path;path.dir='ltr';$('sourcePage').append(title,path);}reads?.wake();}finally{if(version===generation)for(const field of ['detailsToggle','status','assignment'])$(field).disabled=false;}
}
function renderWorkflow(w){conversationClosed=w.status==='resolved';$('priority').value=w.priority||'normal';$('snoozeCancel').hidden=!w.snoozed_until;$('followUpStatus').textContent=w.snoozed_until?(w.snoozed_until*1000<=Date.now()?'زمان پیگیری رسیده است':'پیگیری: '+new Date(w.snoozed_until*1000).toLocaleString('fa-IR')):'';$('status').value=w.status;applyComposerState();$('assignment').value=w.assigned||'';if(!overlays.pending($('labels')))$('labels').value=w.labels.join('، ');$('notes').replaceChildren();for(const n of w.notes){const p=document.createElement('p');p.textContent=n.text+' · '+new Date(n.at*1000).toLocaleString('fa-IR');$('notes').append(p);}}
async function change(operation,value){if(!selected)return;const id=selected,r=await api('workflow',{user_id:id,operation,value});if(selected===id){const field={status:'status',assign:'assignment',labels:'labels',note:'note',priority:'priority',snooze:'snoozeUntil'}[operation];if(field)overlays.saved($(field));renderWorkflow(r.workflow);}if(!['note','delete_note'].includes(operation))await softInbox();}
async function loadReplies(){const selectedReply=$('savedReplies').value;replies=(await api('replies')).replies;$('quick').replaceChildren(new Option('انتخاب پاسخ آماده',''));$('savedReplies').replaceChildren(new Option('پاسخ تازه',''));for(const q of replies){$('quick').add(new Option(q.title,q.id));$('savedReplies').add(new Option(q.title,q.id));}if(replies.some(q=>q.id===selectedReply))$('savedReplies').value=selectedReply;}

async function saveDrafts(){await drafts?.save();}
async function restoreDrafts(){await drafts?.restore();}
function captureDraft(){if(!selected||!drafts)return;try{drafts.set(selected,engine?.composerEdit?.draft??$('reply').value,replyReference?.id||null);clearTimeout($('reply').draftTimer);$('reply').draftTimer=setTimeout(()=>saveDrafts().catch(fail),500);}catch(e){fail(e);}}
function macroFields(q){$('macroLabels').value=(q?.actions?.labels||[]).join('، ');$('macroPriority').value=q?.actions?.priority||'';$('macroStatus').value=q?.actions?.status||'';}
const accountUI=profileSettings({api,pending:overlays.pending,current:()=>operator,updated:async p=>{if(p){operator.name=p.name;$('profile').textContent=p.name;$('menuName').textContent=p.name;$('accountFullName').textContent=p.name;$('operatorAccount').querySelector('summary').setAttribute('aria-label','حساب '+p.name);$('accountIdentity').textContent=p.name+' · '+(operator.role==='administrator'?'مدیر':'پشتیبان');}const assigned=$('assignment').value;accounts=(await api('accounts')).accounts;$('assignment').replaceChildren(new Option('بدون مسئول',''));for(const a of accounts.filter(a=>a.active||a.id===assigned)){const option=new Option(a.name+(a.active?'':' (غیرفعال)'),a.id);option.disabled=!a.active;$('assignment').add(option);}$('assignment').value=assigned;},reauthenticate:async()=>{engine?.freeze();poller?.stop();accountUI.clear();widgetUI.clear();await bootstrap();},warn:fail});
let preferenceJob=null;const preferenceChanges=new Map();let preferenceVersion=0;
for(const id of ['quietStart','quietEnd'])for(let n=0;n<24;n++)$(id).add(new Option(String(n).padStart(2,'0')+':00',String(n)));
function showPreferences(){
 for(const [id,key]of [['sms','sms'],['quiet','quiet']])if(!preferenceChanges.has(key))$(id).checked=preferences[key]===true;
 for(const [id,key]of [['quietStart','quiet_start'],['quietEnd','quiet_end']])if(!preferenceChanges.has(key))$(id).value=String(preferences[key]);
 $('quietStatus').textContent=(preferences.quiet_now?'اکنون در حالت سکوت · ':'صدای پیام تابع ترجیح دستگاه · ')+preferences.quiet_start+':00 تا '+preferences.quiet_end+':00 · '+preferences.timezone+' (ساعت‌های برابر: بدون بازهٔ سکوت)';
 $('push').disabled=!preferences.push_available;$('pushStatus').textContent=preferences.push_available?'اعلان فقط با رضایت شما فعال می‌شود.':'ارسال واقعی Push خاموش است.';
}
async function loadPreferences(){const version=preferenceVersion;try{const p=(await api('notification_settings')).preferences||{};if(version!==preferenceVersion)return false;preferences=p;showPreferences();return true;}catch{$('push').disabled=true;return false;}}
function savePreferences(){if(preferenceJob)return preferenceJob;const owner=operator?.id,epoch=authGate.version;preferenceJob=(async()=>{while(preferenceChanges.size){if(epoch!==authGate.version)return;if(!owner||owner!==operator?.id){preferenceChanges.clear();return;}const [field,value]=preferenceChanges.entries().next().value;$('settingsFeedback').textContent='در حال ذخیره…';try{await api('notification_preferences',{field,value:String(value)});if(preferenceChanges.get(field)===value){preferenceChanges.delete(field);overlays.saved($({sms:'sms',quiet:'quiet',quiet_start:'quietStart',quiet_end:'quietEnd'}[field]));}$('retryPreferences').hidden=true;}catch(e){if(epoch!==authGate.version)return;$('settingsFeedback').textContent='ذخیره نشد؛ تغییرات در انتظار تلاش دوباره است.';$('retryPreferences').hidden=false;return;}}if(owner!==operator?.id)return;await loadPreferences();if(!preferenceChanges.size)$('settingsFeedback').textContent='ذخیره شد.';})().finally(()=>{if(epoch!==authGate.version)return;preferenceJob=null;if(preferenceChanges.size&&$('retryPreferences').hidden)savePreferences();});return preferenceJob;}
for(const [id,field]of [['sms','sms'],['quiet','quiet'],['quietStart','quiet_start'],['quietEnd','quiet_end']])$(id).onchange=()=>{preferenceVersion++;preferenceChanges.set(field,['sms','quiet'].includes(field)?($(id).checked?'1':'0'):$(id).value);savePreferences();};
$('retryPreferences').onclick=savePreferences;
let loginBusy=false;
$('login').onsubmit=async event=>{event.preventDefault();if(loginBusy)return;loginBusy=true;const button=$('login').querySelector('button');button.disabled=true;const err=$('loginError');err.hidden=true;err.textContent='';try{const probe=await api('bootstrap');token=probe.token;if(!probe.authenticated)await api('login',{username:$('username').value,password:$('password').value});$('password').value='';await bootstrap();await engine?.interact();}catch(e){if(e.name!=='AbortError'&&authGate.state==='unauthenticated'){err.textContent=sanitizeErrorMessage(e);err.hidden=false;}}finally{loginBusy=false;button.disabled=false;}};

let logoutBusy=false;
$('exit').onclick=async()=>{if(logoutBusy)return;logoutBusy=true;$('exit').focus({preventScroll:true});try{if(bootstrapping||!engine?.context)return;const unfinished=preferenceChanges.size>0||!!preferenceJob||$('reply').value.trim()||attachments?.has()||[...(drafts?.records.keys()||[])].some(id=>drafts.get(id)||drafts.reply(id))||($('settings')&&overlays.pending($('settings'))||overlays.pending($('viewSettings')));if(unfinished&&!confirm('پیش‌نویس یا تغییر ذخیره‌نشده دارید. خروج ممکن است آن را از این دستگاه پاک کند. خارج می‌شوید؟'))return;const exiting=engine;if(!await exiting.logout())return;if(authGate.state!=='unauthenticated')authGate.unauthenticated('خروج انجام شد.');}catch(e){fail(e);}finally{logoutBusy=false;}};
$('send').addEventListener('pointerdown',event=>{if(document.activeElement===$('reply'))event.preventDefault();});
$('send').onclick=async()=>{
 if(!selected||conversationClosed||$('send').disabled||lastQueued===revision)return;const target=selected,text=$('reply').value,version=revision,replyTo=replyReference?.id||null,replyThrough=Math.max(0,...engine.sync.values().filter(m=>m.type==='user'&&Number.isSafeInteger(m.id)).map(m=>m.id));let queued=false;lastQueued=version;operatorSending=true;$('send').disabled=true;
 try{drafts.set(target,text,replyTo);const draftVersion=drafts.version(target);await saveDrafts();const images=await attachments.upload();if(target!==selected)throw Error('گفتگو تغییر کرده است.');const row=await engine.enqueue(text,images,replyTo,null,replyThrough);queued=true;typing?.stop();attachments.consume(images);if(revision===version){$('reply').value='';replyReference?.clear();resizeReply();}try{await drafts.queued(target,row.uuid,draftVersion);}catch(e){fail(e);}}
 catch(e){if(!queued&&lastQueued===version)lastQueued=-1;fail(e);}finally{operatorSending=false;applyComposerState();}
};
$('reply').addEventListener('compositionstart',()=>composing=true);$('reply').addEventListener('compositionend',()=>composing=false);
$('reply').addEventListener('keydown',event=>{if(event.key==='Enter'&&!event.shiftKey&&!event.isComposing&&event.keyCode!==229&&!composing&&!event.repeat&&!matchMedia('(pointer: coarse)').matches){event.preventDefault();if(!$('send').disabled&&($('reply').value.trim()||attachments?.has()))$('send').click();}});
$('reply').oninput=()=>{revision++;resizeReply();captureDraft();};
$('back').onclick=()=>router?.navigate('conversations');$('assignment').onchange=()=>change('assign',$('assignment').value).catch(fail);$('saveLabels').onclick=()=>change('labels',JSON.stringify($('labels').value.split(/[,،]/).map(x=>x.trim()).filter(Boolean))).catch(fail);$('addNote').onclick=()=>change('note',$('note').value).then(()=>$('note').value='').catch(fail);
$('quick').onchange=()=>{if(engine?.composerEdit)return;const q=replies.find(x=>x.id===$('quick').value);if(q&&Object.keys(q.actions||{}).length){$('conversationDetails').close();macroUI.open(q);return;}if(q){macroFields(q);editingReply=q.id;$('quickTitle').value=q.title;$('quickText').value=q.text;$('reply').value=insertQuickText($('reply').value,q.text,$('reply').selectionEnd);$('reply').dispatchEvent(new Event('input'));$('conversationDetails').close();$('reply').focus();resizeReply();}};
const quickEditor=quickReplyEditor({form:$('quickReplyForm'),current:()=>operator,replies:()=>replies,api,reload:loadReplies,input:$('reply'),changed:()=>macroUI?.refresh(),warn:fail});
$('includeTests').onchange=()=>softInbox(false,true);
$('addAccount').onclick=()=>api('account_add',{username:$('accountUsername').value,name:$('accountName').value,password:$('accountPassword').value,role:$('accountRole').value}).then(async()=>{$('accountPassword').value='';$('settingsFeedback').textContent='حساب ساخته شد.';await accountUI.directory();}).catch(fail);
let searchTimer;$('search').oninput=()=>{clearTimeout(searchTimer);searchTimer=setTimeout(()=>softInbox(false,true),400);};$('filter').onchange=()=>softInbox(false,true);$('more').onclick=()=>softInbox(true,true);
const readiness=notificationReadiness({host:$('readinessView'),load:()=>api('readiness'),warn:fail});
const health=healthView({host:$('healthView'),load:()=>api('health'),warn:fail});
$('detailsToggle').onclick=()=>overlays.open($('conversationDetails'));$('closeDetails').onclick=()=>overlays.close($('conversationDetails'));
function settingsPanel(name){if(name==='widget')widgetUI.load().catch(fail);if(name==='account'&&!overlays.pending($('selfProfile')))accountUI.load().catch(fail);if(['advanced','operators','widget'].includes(name)&&operator?.role!=='administrator')return;if(name==='operators'&&!overlays.pending(document.querySelector('[data-settings-panel=operators]'))&&document.querySelector('[data-settings-panel=operators]').hidden)accountUI.directory().catch(fail);for(const el of document.querySelectorAll('[data-settings-panel]'))el.hidden=el.dataset.settingsPanel!==name&&!(name==='notifications'&&el.dataset.settingsPanel==='replies');for(const el of document.querySelectorAll('[data-settings]'))el.setAttribute('aria-pressed',String(el.dataset.settings===name));}
for(const button of document.querySelectorAll('[data-settings]'))button.onclick=()=>settingsPanel(button.dataset.settings);
if($('preferences'))$('preferences').onclick=()=>{$('preferences').focus({preventScroll:true});settingsPanel('notifications');$('settingsFeedback').textContent='';overlays.open($('settings'));};if($('closeSettings'))$('closeSettings').onclick=()=>overlays.close($('settings'));

$('push').onclick=async()=>{try{const r=await api('push_key');if(!registration||!('PushManager'in window)||!r.key)throw Error('Push در این مرورگر آماده نیست.');if(await Notification.requestPermission()!=='granted')throw Error('مجوز اعلان داده نشد.');await alerts.save({browser:true});const key=Uint8Array.from(atob(r.key.replace(/-/g,'+').replace(/_/g,'/')),c=>c.charCodeAt(0));const sub=await registration.pushManager.subscribe({userVisibleOnly:true,applicationServerKey:key});await api('push_subscribe',{subscription:JSON.stringify(sub.toJSON())});await alerts.save({background:true});$('pushStatus').textContent='اشتراک این دستگاه ثبت شد؛ دریافت تضمین‌شده نیست.';}catch(e){fail(e);}};
$('removePush').onclick=async()=>{try{const sub=await registration?.pushManager.getSubscription();if(sub){await api('push_unsubscribe',{endpoint:sub.endpoint});await sub.unsubscribe();}await alerts.save({background:false});$('pushStatus').textContent='اشتراک این دستگاه حذف شد.';}catch(e){fail(e);}};
operatorPwa({button:$('install'),dialog:$('pwaDialog'),registrationChanged:r=>registration=r,warn:fail});
$('pwaUpdateEntry').onclick=()=>$('install').click();
$('accountSettings').onclick=()=>{$('operatorAccount').open=false;router?.navigate('settings',{tab:'account'});};
addEventListener('online',()=>{connection();if(operator){engine?.poller.wake();softInbox();}else bootstrap().catch(fail);});addEventListener('offline',()=>{connection();});document.addEventListener('visibilitychange',()=>{if(!document.hidden&&operator){if(Date.now()-lastSyncWake>2500){lastSyncWake=Date.now();engine?.poller.wake();}if(Date.now()-lastInboxFetch>3000)softInbox();}});addEventListener('focus',()=>{if(operator){if(Date.now()-lastSyncWake>2500){lastSyncWake=Date.now();engine?.poller.wake();}if(Date.now()-lastInboxFetch>3000)softInbox();}});
if('serviceWorker'in navigator){navigator.serviceWorker.addEventListener('message',event=>{if(event.data?.type==='support-push'){lastSound=Date.now();softInbox();}if(event.data?.type==='support-open'&&operator&&/^user_[a-f0-9]{32}_[0-9]+$/.test(event.data.conversation))router?.navigate('conversations',{id:event.data.conversation});});}
bootstrap().catch(e=>{connection();if(e.unavailable)$('connection').textContent=e.message;fail(e);});

for(const event of ['pointerdown','keydown','wheel'])document.addEventListener(event,e=>{if(e.isTrusted&&!document.hidden&&document.hasFocus())engine?.interact();});

function resizeReply(){replyGrowth.resize();}


$('priority').onchange=()=>change('priority',$('priority').value).catch(fail);$('snoozeSave').onclick=()=>{const t=new Date($('snoozeUntil').value);if(Number.isNaN(t.getTime()))return fail(Error('زمان پیگیری را انتخاب کنید.'));change('snooze',t.toISOString().replace('.000Z','Z')).catch(fail);};$('snoozeCancel').onclick=()=>change('snooze','0').catch(fail);for(const button of document.querySelectorAll('[data-inbox-tab]'))button.onclick=()=>{inboxTab=button.dataset.inboxTab;for(const el of document.querySelectorAll('[data-inbox-tab]'))el.setAttribute('aria-pressed',String(el===button));softInbox(false,true);};
$('assignedOnly').onchange=()=>{workView=$('assignedOnly').checked?'mine':'inbox';softInbox(false,true);};

$('navInbox').onclick=()=>router?.navigate('conversations');

$('editQuickReplies').onclick=()=>{overlays.close($('conversationDetails'));router?.navigate('replies');};


$('statusFilterHost').append($('filter').closest('label'));


$('sidebarToggle').onclick=()=>{
 const sb=$('adminSidebar');
 const col=sb.classList.toggle('collapsed');
 localStorage.setItem('support_sidebar_collapsed',col?'1':'0');
};
if(localStorage.getItem('support_sidebar_collapsed')==='1'){
 $('adminSidebar')?.classList.add('collapsed');
}
const openMobileDrawer=()=>{
 $('adminSidebar')?.classList.add('mobile-open');
 $('adminSidebar').inert=false;$('mobileMenuBtn').setAttribute('aria-expanded','true');
 $('sidebarBackdrop').hidden=false;
};
const closeMobileDrawer=()=>{
 $('adminSidebar')?.classList.remove('mobile-open');
 $('adminSidebar').inert=innerWidth<900;$('mobileMenuBtn').setAttribute('aria-expanded','false');
 $('sidebarBackdrop').hidden=true;
};
// Existing account actions move to the authenticated drawer footer.
const identity=$('sidebarAvatar').parentElement;identity.tabIndex=0;identity.setAttribute('role','button');identity.setAttribute('aria-label','تنظیمات حساب');
identity.onclick=()=>{closeMobileDrawer();router?.navigate('settings',{tab:'account'});};identity.onkeydown=e=>{if(e.key==='Enter'||e.key===' '){e.preventDefault();identity.click();}};
const footer=identity.parentElement;for(const [id,label,icon]of [['exit','خروج از حساب','logout']]){const b=$(id);b.className='icon-button';b.setAttribute('aria-label',label);b.title=label;b.replaceChildren(Object.assign(document.createElement('span'),{className:'support-icon',textContent:icon}));footer.append(b);}
const installation=document.createElement('section');installation.id='installationSettings';const installTitle=document.createElement('h3');installTitle.textContent='نصب برنامه';const installStatus=document.createElement('p');installStatus.id='pwaInstallStatus';installStatus.setAttribute('role','status');const installButton=$('install');installButton.className='secondary';installation.append(installTitle,installStatus,installButton,$('pwaUpdateEntry'));document.querySelector('[data-settings-panel=account]').append(installation);
$('operatorAccount').hidden=true;
mobileWorkspace({account:$('operatorAccount'),drawer:$('adminSidebar'),closeDrawer:closeMobileDrawer,menuButton:$('mobileMenuBtn'),header:document.querySelector('.app-header'),stage:$('adminStage'),stream:$('messageStream')});
$('mobileMenuBtn').onclick=()=>{$('adminSidebar').classList.contains('mobile-open')?closeMobileDrawer():openMobileDrawer();};
const drawerMedia=matchMedia('(max-width:899px)');const syncDrawer=()=>{if(!drawerMedia.matches)closeMobileDrawer();else $('adminSidebar').inert=!$('adminSidebar').classList.contains('mobile-open');};drawerMedia.addEventListener('change',syncDrawer);syncDrawer();
document.addEventListener('keydown',event=>{if(event.key==='Escape'&&$('adminSidebar').classList.contains('mobile-open')){closeMobileDrawer();$('mobileMenuBtn').focus();}});
$('sidebarBackdrop').onclick=closeMobileDrawer;
for(const item of document.querySelectorAll('.admin-nav .nav-item')){
 item.addEventListener('click',()=>closeMobileDrawer());
}
function setupV3Modules(){
 if(!dashboardModule){
  dashboardModule=dashboardView({
   host:$('viewDashboard'),
   api,
   currentOperator:()=>operator,
   navigate:(route,params)=>router?.navigate(route,params),
   openConversation:id=>{router?.navigate('conversations',{id});},
   warn:fail
  });
 }
 if(!knowledgeModule){
  knowledgeModule=knowledgeView({
   host:$('viewKnowledge'),
   csrfToken:()=>token,
   warn:fail
  });
 }
 if(!contactsModule){
  contactsModule=contactsView({
   host:$('viewContacts'),
   api,
   navigate:(route,params)=>router?.navigate(route,params),
   openConversation:id=>{router?.navigate('conversations',{id});},
   warn:fail
  });
 }
 if(!analyticsModule){
  analyticsModule=v3AnalyticsView({
   host:$('viewAnalytics'),
   api,
   currentOperator:()=>operator,
   navigate:(route,params)=>router?.navigate(route,params),
   openConversation:id=>{router?.navigate('conversations',{id});},
   warn:fail
  });
 }
 if(!teamModule){
  teamModule=teamView({
   host:$('viewTeam'),
   api,
   currentOperator:()=>operator,
   warn:fail
  });
 }
 if(!repliesModule)repliesModule=repliesView({host:$('viewReplies'),load:loadReplies,warn:fail});
 if(!settingsModule){
  settingsModule=settingsView({
   host:$('viewSettings'),
   api,
   currentOperator:()=>operator,
   csrfToken:()=>token,
   widgetUI,
   accountUI,
   preferences,
   savePreferences,
   replies:()=>replies,
   loadReplies,
   warn:fail
  });
 }
 if(!healthModule){
  healthModule=v3HealthView({
   host:$('viewHealth'),
   api,
   currentOperator:()=>operator,
   warn:fail
  });
 }
 if(!router){
  router=createRouter({
   views:{
    dashboard:$('viewDashboard'),
    conversations:$('viewConversations'),
    knowledge:$('viewKnowledge'),
    replies:$('viewReplies'),
    contacts:$('viewContacts'),
    analytics:$('viewAnalytics'),
    team:$('viewTeam'),
    settings:$('viewSettings'),
    health:$('viewHealth')
   },
   navLinks:document.querySelectorAll('.admin-nav .nav-item'),
   titleHost:$('pageTitle'),
   isAdmin:()=>operator?.role==='administrator',
   onNavigate:(route,params)=>{
    captureDraft();
    closeMobileDrawer();
    $('operatorAccount').open=false;
    if(route!=='conversations')document.body.classList.remove('detail-open');
    if(route==='dashboard')dashboardModule?.mount();
    else if(route==='conversations'){
     const targetTab=params.tab||inboxTab;
     const targetFilter=params.filter??$('filter').value;
     inboxTab=targetTab;
     for(const el of document.querySelectorAll('[data-inbox-tab]'))el.setAttribute('aria-pressed',String(el.dataset.inboxTab===inboxTab));
     $('filter').value=targetFilter;
     if(params.id)select(params.id).catch(fail);else{document.body.classList.remove('detail-open');navigation.close();}
     softInbox(false,true);
    }
    else if(route==='knowledge')knowledgeModule?.mount();
    else if(route==='replies')repliesModule?.mount();
    else if(route==='contacts')contactsModule?.mount();
    else if(route==='analytics')analyticsModule?.mount();
    else if(route==='team')teamModule?.mount();
    else if(route==='settings')settingsModule?.mount(params.tab||'appearance');
    else if(route==='health')healthModule?.mount();
   }
  });
 }
}

addEventListener('pagehide',()=>{if(authGate.state==='authenticated'){authGate.checking();poller?.stop();engine?.freeze();}});
addEventListener('pageshow',event=>{if(event.persisted)bootstrap().catch(fail);});
