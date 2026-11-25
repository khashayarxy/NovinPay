import('./icons.js?v=permissions1').catch(()=>{try{document.fonts?.load('22px "Support Icons"').then(fonts=>{if(fonts.length)document.documentElement.classList.add('support-icons-ready');}).catch(()=>{});}catch{}});
import {mountCustomer,customerInitHost} from './customer.js?v=team1';

import {quickThemeToggle} from './theme.js?v=0.1.0-r14';
import {customerTools} from './customer-v2-tools.js?v=ux1';
import {renderCustomerV2} from './customer-v2-renderer.js?v=team1';
import {CustomerReconnect,transientFailure} from './reconnect.js';
import {retryAfter} from './network.js?v=team1';
const publishTheme=()=>{if(parent!==window)parent.postMessage({type:'support-widget-theme',resolved:document.documentElement.dataset.supportTheme},location.origin);};
addEventListener('support-theme-change',publishTheme);

function shell({win,region,composer,menu,current,onView}){
 if(win.hasAttribute('data-support-stable-shell')){
  tools.bell.disabled=false;quickThemeToggle(win.querySelector('.chat-header-actions'));
 }else{
  const heading=win.querySelector('.chat-heading h4');heading.textContent='پشتیبانی '+(globalThis.SupportWidgetConfig?.name||'نوین‌پی');
  const logo=document.createElement('span');logo.className='v2-brand support-icon';logo.setAttribute('aria-hidden','true');logo.textContent='support_agent';heading.parentElement.prepend(logo);
  menu.toggle.replaceWith(tools.bell);tools.bell.after(quickThemeToggle(win.querySelector('.chat-header-actions')));
  const caption=document.createElement('span');caption.className='v2-header-copy';heading.before(caption);caption.append(heading,win.querySelector('#adminStatus'));
 }
 menu.body.querySelector('.support-theme-control')?.remove();

 win.querySelector('#chatInput').dir='auto';win.querySelector('#chatInput').style.textAlign='start';win.querySelector('#chatInput').placeholder='پیام خود را اینجا بنویسید…';
 const sendIcon=win.querySelector('.chat-send svg');sendIcon?.replaceWith(Object.assign(document.createElement('span'),{className:'support-icon',textContent:'arrow_upward'}));win.querySelector('.chat-send .support-icon').setAttribute('aria-hidden','true');
 const logout=win.querySelector('#logoutBtn');logout.textContent='پایان دسترسی این مرورگر';menu.body.append(logout);
 const start=document.createElement('button');start.hidden=true;win.append(start);
 const show=()=>{region.hidden=false;composer.hidden=false;onView?.({to:'chat',resume:false});};
 win.classList.add('active');win.setAttribute('aria-hidden','false');win.querySelector('.chat-send').disabled=true;show();return {get chat(){return true;},open:show,session(){logout.hidden=!current();},unread(){},ready(){},reset(){show();},start};
}
// One boot job: module completion never waits indefinitely for a user retry.
window.SupportBootStarted=true;
if(parent!==window)globalThis.SupportWidgetHidden=true;
const startup=document.getElementById('chatWindow'),loading=customerInitHost(startup);
const diagnostics={release:'mobile-visual1',reference:crypto.getRandomValues(new Uint32Array(2)).join('-'),browser:/SamsungBrowser/.test(navigator.userAgent)?'Samsung Internet':/Edg/.test(navigator.userAgent)?'Edge':/Firefox/.test(navigator.userAgent)?'Firefox':/Chrome/.test(navigator.userAgent)?'Chrome':'other',capabilities:{indexedDB:!!globalThis.indexedDB,visualViewport:!!window.visualViewport,secure:isSecureContext},events:[]};globalThis.SupportInitDiagnostics=diagnostics;
const closeFrame=()=>{globalThis.SupportWidgetHidden=true;dispatchEvent(new CustomEvent('support-widget-visibility',{detail:{visible:false}}));parent.postMessage('support-widget-close',location.origin);};
window.closeChat=()=>{if(parent!==window)closeFrame();else startup.classList.remove('active');};
// Embedded geometry belongs to the parent adapter; standalone preview uses its own viewport.
if(parent===window){let queued=false;const fit=()=>{if(queued)return;queued=true;requestAnimationFrame(()=>{queued=false;const v=window.visualViewport;if(innerWidth>599||!v||Math.abs(v.scale-1)>.01){for(const key of ['height','top','bottom'])startup.style.removeProperty(key);return;}startup.style.height=Math.max(1,Math.min(v.height,innerHeight))+'px';startup.style.top=Math.max(0,v.offsetTop)+'px';startup.style.bottom='auto';});};window.visualViewport?.addEventListener('resize',fit);window.visualViewport?.addEventListener('scroll',fit);addEventListener('resize',fit);addEventListener('pageshow',fit);startup.addEventListener('focusin',fit);startup.addEventListener('focusout',fit);fit();}
startup.classList.add('active');startup.setAttribute('aria-busy','true');startup.querySelector('.chat-send').disabled=true;loading.hidden=false;startup.dataset.initState='initializing';
let config=null,bootJob=null,mounted=false,tools=null;
function initFaqAndChannels(win,cfg){
 if(!win||win.dataset.faqBound==='1')return;
 win.dataset.faqBound='1';
 const tabChat=win.querySelector('[data-support-tab="chat"]');
 const tabFaq=win.querySelector('[data-support-tab="faq"]');
 const chatPane=win.querySelector('#supportChatPane');
 const faqPane=win.querySelector('#supportFaqPane');
 const inputArea=win.querySelector('#inputArea');
 const searchInput=win.querySelector('#supportFaqSearch');
 const faqList=win.querySelector('#supportFaqList');
 const faqEmpty=win.querySelector('#supportFaqEmpty');
 const toChatBtn=win.querySelector('#supportFaqToChat');
 if(!tabChat||!tabFaq||!faqPane||!chatPane)return;

 const setTab=tab=>{
  const isFaq=tab==='faq';
  tabChat.classList.toggle('is-active',!isFaq);
  tabFaq.classList.toggle('is-active',isFaq);
  tabChat.setAttribute('aria-selected',String(!isFaq));
  tabFaq.setAttribute('aria-selected',String(isFaq));
  chatPane.hidden=isFaq;
  if(inputArea)inputArea.hidden=isFaq;
  faqPane.hidden=!isFaq;
  if(isFaq)searchInput?.focus({preventScroll:true});
  else win.querySelector('#chatInput')?.focus({preventScroll:true});
 };
 tabChat.addEventListener('click',()=>setTab('chat'));
 tabFaq.addEventListener('click',()=>setTab('faq'));
 toChatBtn?.addEventListener('click',()=>setTab('chat'));

 const appendLatinText=(el,text)=>{
  const str=String(text||'');
  const re=/\b(?:[0-9]+[A-Za-z][A-Za-z0-9._-]*|[A-Za-z][A-Za-z0-9._-]*)\b/g;
  let last=0,m;
  while((m=re.exec(str))!==null){
   if(m.index>last)el.append(document.createTextNode(str.slice(last,m.index)));
   const bdi=document.createElement('bdi');
   bdi.className='np-latin';
   bdi.dir='ltr';
   bdi.textContent=m[0];
   el.append(bdi);
   last=m.index+m[0].length;
  }
  if(last<str.length)el.append(document.createTextNode(str.slice(last)));
 };
 const items=Array.isArray(cfg?.faq_items)?cfg.faq_items:[];
 const renderFaq=(query='')=>{
  if(!faqList)return;
  const q=query.trim().toLowerCase();
  faqList.innerHTML='';
  let shown=0;
  for(const item of items){
   const hay=`${item.q||''} ${item.a||''}`.toLowerCase();
   if(q&&!hay.includes(q))continue;
   const details=document.createElement('details');
   details.className='v2-faq-item';
   const summary=document.createElement('summary');
   appendLatinText(summary,item.q||'');
   const body=document.createElement('div');
   body.className='v2-faq-item__body';
   const p=document.createElement('p');
   appendLatinText(p,item.a||'');
   body.append(p);
   if(item.link&&typeof item.link==='string'&&item.link.startsWith('/')){
    const a=document.createElement('a');
    a.className='v2-faq-item__link';
    a.href=item.link;
    a.target='_top';
    a.textContent=(item.link_label||'مشاهده بیشتر')+' ←';
    body.append(a);
   }
   details.append(summary,body);
   faqList.append(details);
   shown++;
  }
  if(faqEmpty)faqEmpty.hidden=shown>0;
 };
 renderFaq();
 searchInput?.addEventListener('input',()=>renderFaq(searchInput.value));
}
const allowed=path=>config?.source_paths?.includes(path)?path:null;
addEventListener('message',event=>{if(parent===window||event.origin!==location.origin||event.source!==parent||event.data?.type!=='support-widget-visibility')return;globalThis.SupportSourcePath=allowed(event.data.source_path);globalThis.SupportWidgetHidden=!event.data.visible;dispatchEvent(new CustomEvent('support-widget-visibility',{detail:{visible:!!event.data.visible}}));if(event.data.visible&&matchMedia('(pointer:fine)').matches)document.getElementById('chatInput')?.focus({preventScroll:true});});
const launch=()=>{if(bootJob)return bootJob;const retry=loading.querySelector('[data-init-retry]'),text=loading.querySelector('[data-init-text]');retry.disabled=true;retry.hidden=true;loading.hidden=false;text.textContent='در حال آماده‌سازی گفتگو...';startup.dataset.initState='initializing';
 const job=(async()=>{
  const embedded=document.getElementById('supportWidgetConfig');
  if(embedded)config=JSON.parse(embedded.textContent);
  else {
   const controller=new AbortController(),timer=setTimeout(()=>controller.abort(),10000);
   try{let r;try{r=await fetch('api.php?widget_config=1',{cache:'no-store',signal:controller.signal});}catch(e){throw Object.assign(new Error(),{status:0,code:e.name==='AbortError'?'timeout':'network'});}if(!r.ok)throw Object.assign(new Error(),{status:r.status,retryAfter:retryAfter(r.headers.get('Retry-After'))});try{config=await r.json();}catch{throw Object.assign(new Error(),{status:0,code:'invalid_response'});}}
   finally{clearTimeout(timer);}
  }
  if(typeof config?.name!=='string')throw Error();
  globalThis.SupportWidgetConfig=config;initFaqAndChannels(startup,config);window.SupportTheme?.configure(config);const requested=new URL(location.href).searchParams.get('source_path');let referring=null;try{const ref=new URL(document.referrer);if(ref.origin===location.origin)referring=ref.pathname;}catch{}globalThis.SupportSourcePath=allowed(requested)||allowed(referring);
  tools=customerTools();mounted=true;await mountCustomer({shellFactory:shell,transcriptRenderer:renderCustomerV2,presentation:tools,onClose:parent!==window?closeFrame:null});
  if(!startup.classList.contains('active'))window.toggleChat();if(parent!==window){parent.postMessage({type:'support-widget-ready'},location.origin);publishTheme();}
 })().catch(error=>{startup.dataset.initState='failed';startup.setAttribute('aria-busy','false');loading.hidden=false;text.textContent='گفتگو در دسترس نیست. دوباره بررسی کنید.';retry.hidden=false;retry.onclick=mounted?()=>location.reload():()=>configurationRecovery.start(true);loading.querySelector('[data-init-reference]').textContent='';diagnostics.events.push({stage:mounted?'mount':'configuration',code:transientFailure(error)?'transient':'terminal',ms:Math.round(performance.now())});diagnostics.events=diagnostics.events.slice(-12);dispatchEvent(new Event('support-init-state'));throw error;})
 .finally(()=>{retry.disabled=false;if(bootJob===job)bootJob=null;});bootJob=job;return job;
};
const configurationRecovery=new CustomerReconnect(launch,{state:state=>{if(['waiting','offline'].includes(state)){startup.dataset.initState='initializing';startup.setAttribute('aria-busy','true');loading.querySelector('[data-init-text]').textContent='در حال اتصال دوباره…';loading.querySelector('[data-init-retry]').hidden=true;}}});
addEventListener('online',()=>{if(!mounted)configurationRecovery.onlineAgain();});configurationRecovery.start();
