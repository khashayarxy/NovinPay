(()=>{
// Same-origin iframe adapter: no remote widget service and no cross-origin API.
const source=new URL(document.currentScript.src),root=new URL('./',source);
if(root.origin!==location.origin)throw new Error('Support widget requires a same-origin installation');
if(!document.querySelector('[data-support-launcher]')){
const style=document.createElement('link');style.rel='stylesheet';style.href=new URL('support-client/launcher.css?v='+encodeURIComponent(source.searchParams.get('v')||'0.1.0-ui4'),root);document.head.append(style);
const button=document.createElement('button');button.type='button';button.dataset.supportLauncher='';button.setAttribute('aria-label','پشتیبانی');button.setAttribute('aria-expanded','false');
const svg=document.createElementNS('http://www.w3.org/2000/svg','svg'),path=document.createElementNS('http://www.w3.org/2000/svg','path');svg.setAttribute('viewBox','0 0 24 24');svg.setAttribute('aria-hidden','true');path.setAttribute('d','M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z');svg.append(path);button.append(svg);
const frame=document.createElement('iframe');frame.title='پشتیبانی';frame.src=new URL('support-widget.php',root);frame.hidden=true;frame.setAttribute('allow','clipboard-write');
frame.id='supportWidgetFrame';frame.dataset.supportFrame='';frame.dataset.state='closed';frame.inert=true;button.setAttribute('aria-controls',frame.id);
const badge=document.createElement('span');badge.hidden=true;badge.className='support-launcher-badge';button.append(badge);
const openKey='support-widget-open:'+root.pathname;const rememberOpen=value=>{try{sessionStorage.setItem(openKey,value?'1':'0');}catch{}};let sourcePaths=[],open=false,transition=0,closingTimer=null;const visibility=()=>frame.contentWindow.postMessage({type:'support-widget-visibility',visible:open,source_path:sourcePaths.includes(location.pathname)?location.pathname:null},location.origin);
const show=()=>{const version=++transition;clearTimeout(closingTimer);open=true;rememberOpen(true);frame.hidden=false;fit();frame.inert=false;button.setAttribute('aria-expanded','true');requestAnimationFrame(()=>requestAnimationFrame(()=>{if(version!==transition||!open)return;frame.dataset.state='open';visibility();}));};
const close=()=>{++transition;open=false;rememberOpen(false);frame.inert=true;frame.dataset.state='closing';button.hidden=false;button.setAttribute('aria-expanded','false');visibility();button.focus({preventScroll:true});clearTimeout(closingTimer);closingTimer=setTimeout(()=>{if(!open){frame.hidden=true;frame.dataset.state='closed';}},matchMedia('(prefers-reduced-motion:reduce)').matches?0:220);};
let fitFrame=0;const fit=()=>{if(fitFrame)return;fitFrame=requestAnimationFrame(()=>{fitFrame=0;applyFit();});};const applyFit=()=>{const v=window.visualViewport;if(innerWidth>599||!v||Math.abs(v.scale-1)>.01){frame.style.removeProperty('top');frame.style.removeProperty('height');frame.style.removeProperty('bottom');return;}frame.style.top=(v.offsetTop+4)+'px';frame.style.bottom='auto';frame.style.height=Math.max(1,v.height-8)+'px';};
window.visualViewport?.addEventListener('resize',fit);window.visualViewport?.addEventListener('scroll',fit);addEventListener('resize',fit);fit();addEventListener('focus',fit);addEventListener('pageshow',fit);frame.addEventListener('load',()=>{try{frame.contentDocument.addEventListener('focusin',fit);frame.contentDocument.addEventListener('focusout',fit);}catch{}});
button.onclick=()=>open?close():show();
// The iframe remains the theme authority; accept only its same-origin resolution.
addEventListener('message',event=>{if(event.origin!==location.origin||event.source!==frame.contentWindow||event.data?.type!=='support-widget-theme'||!['light','dark'].includes(event.data.resolved))return;frame.dataset.theme=event.data.resolved;});
frame.addEventListener('load',visibility);
addEventListener('message',event=>{if(event.origin!==location.origin||event.source!==frame.contentWindow)return;if(event.data?.type==='support-customer-open')show();if(event.data?.type==='support-widget-ready')visibility();if(event.data?.type==='support-unread'&&Number.isSafeInteger(event.data.count)&&event.data.count>=0){badge.hidden=event.data.count===0;badge.textContent=event.data.count>999?'۹۹۹+':event.data.count.toLocaleString('fa-IR');button.setAttribute('aria-label',event.data.count?`پشتیبانی، ${event.data.count} پیام خوانده‌نشده`:'پشتیبانی');}});
addEventListener('message',event=>{if(event.origin===location.origin&&event.source===frame.contentWindow&&event.data==='support-widget-close')close();});
document.body.append(button,frame);
const accentVisibility=()=>button.dataset.accentPaused=String(document.hidden);document.addEventListener('visibilitychange',accentVisibility);accentVisibility();
fetch(new URL('api.php?widget_config=1',root),{credentials:'same-origin',cache:'no-store'}).then(r=>r.json()).then(c=>{sourcePaths=Array.isArray(c.source_paths)?c.source_paths:[];visibility();if(c.customer_v2===true||c.local_v2===true){button.style.background='#0866ff';button.dataset.supportV2Launcher='1';frame.dataset.supportV2Frame='1';}else if(/^#[a-f0-9]{6}$/i.test(c.color))button.style.background=c.color;button.title='پشتیبانی '+c.name;}).catch(()=>{});

let restoreOpen=false;try{restoreOpen=sessionStorage.getItem(openKey)==='1';}catch{}if(new URL(location.href).searchParams.get('support_chat')==='1'||restoreOpen)show();
navigator.serviceWorker?.addEventListener('message',event=>{if(event.data?.type==='support-customer-open')show();});
}

})();
