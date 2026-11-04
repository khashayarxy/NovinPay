// The explicit production flag and server-verified local preview both select V2.
// Without either flag the existing secure fallback remains available.
// Clicks on the static entry button during the async initialization window
// queue an open intent instead of hitting an undefined global handler.
window.toggleChat=()=>{window.__supportOpenQueued=true;};
async function boot(){
if(window.SupportCompatibilityDiagnostics?.capabilities.modules===false)return;
const controller=new AbortController(),timer=setTimeout(()=>controller.abort(),20000);
const config=await fetch('api.php?widget_config=1',{cache:'no-store',signal:controller.signal}).finally(()=>clearTimeout(timer)).then(r=>{if(!r.ok)throw Error('Widget configuration unavailable');return r.json();});
if((config.customer_v2===true||config.local_v2===true)&&!window.__supportCompat&&new URL(location.href).searchParams.get('support_legacy')!=='1'){
 document.querySelector('#chatWindow')?.remove();document.querySelector('.chat-btn')?.remove();
 const script=document.createElement('script');script.src='support-widget.js?v='+(/^[a-f0-9]{64}$/.test(config.local_asset_revision||'')?config.local_asset_revision:'ux1');document.body.append(script);
}else{
 const legacy=document.querySelector('.chat-btn');legacy?.classList.remove('support-entry-pending');
 const {mountCustomer}=await import('./customer.js?v=team1');await mountCustomer();window.__supportEntryReady=true;
 if(window.__supportOpenQueued&&!document.getElementById('chatWindow').classList.contains('active'))window.toggleChat();
}

}
function retryBoot(){boot().catch(function(){var shell=document.getElementById('chatWindow');if(!shell)return;var error=document.getElementById('errorMsg');shell.style.display='flex';shell.classList.add('active');if(error){error.replaceChildren(document.createTextNode('بارگذاری چت انجام نشد. '));error.style.display='block';var retry=document.createElement('button');retry.type='button';retry.textContent='تلاش مجدد';retry.onclick=function(){retry.disabled=true;retryBoot();};error.append(retry);}window.toggleChat=function(){shell.style.display='flex';shell.classList.add('active');};});}
retryBoot();
