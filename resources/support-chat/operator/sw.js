importScripts('/support-chat/support-client/attention-store.js?v=0.1.0-r14');
const ASSET_SCOPE=new URL('/support-chat/operator/',self.location.origin),PANEL=new URL('/support',self.location.origin);
// Public, non-personal assets only. HTML, JS/CSS, API, uploads and POST stay network-only.
const PREFIX='support-operator:'+new URL(self.registration.scope).pathname+':',CACHE=PREFIX+'mobile2';
const STATIC=['../assets/fonts/Vazirmatn.woff2','../assets/fonts/material-symbols.woff2','icons/icon-192.png','icons/icon-512.png','icons/maskable-512.png','icons/apple-touch-icon.png'].map(p=>new URL(p,ASSET_SCOPE).href);
self.addEventListener('install',event=>event.waitUntil(caches.open(CACHE).then(c=>c.addAll(STATIC))));
// No skipWaiting/reload: the waiting worker activates after existing operator windows close.
self.addEventListener('activate',event=>event.waitUntil(caches.keys().then(keys=>Promise.all(keys.filter(k=>k.startsWith(PREFIX)&&k!==CACHE).map(k=>caches.delete(k))))));
self.addEventListener('fetch',event=>{
 const r=event.request;if(r.method!=='GET')return;
 if(r.mode==='navigate'&&new URL(r.url).pathname===PANEL.pathname){
  event.respondWith(fetch(r,{cache:'no-store'}).catch(()=>new Response('<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>اتصال برقرار نیست</title><h1>اتصال برقرار نیست</h1><p>برای ورود و دریافت یا تأیید پیام‌ها به اینترنت متصل شوید.</p><p><a href="/support">تلاش دوباره</a></p></html>',{status:503,headers:{'Content-Type':'text/html; charset=utf-8','Cache-Control':'no-store','Content-Security-Policy':"default-src 'none'; base-uri 'none'; frame-ancestors 'none'"}})));return;
 }
 if(!STATIC.includes(r.url))return;
 event.respondWith(fetch(r,{cache:'no-cache'}).then(response=>{if(response.ok&&response.type!=='opaqueredirect'){const copy=response.clone();event.waitUntil(caches.open(CACHE).then(c=>c.put(r,copy)));}return response;}).catch(()=>caches.open(CACHE).then(c=>c.match(r)).then(c=>c||Response.error())));
});
self.addEventListener('push',event=>event.waitUntil((async()=>{
 // Push contains no customer data. Resolve a hint only through the current
 // same-origin operator session; never store API responses in CacheStorage.
 let hint=null;
 try{const r=await fetch(new URL('../operator-api.php?action=notification_hint',ASSET_SCOPE),{credentials:'same-origin',cache:'no-store'});if(r.ok)hint=await r.json();}catch{}
 if(!hint||!/^user_[a-f0-9]{32}_[0-9]+$/.test(hint.conversation))return;
 const windows=(await self.clients.matchAll({type:'window'})).filter(client=>new URL(client.url).pathname===PANEL.pathname),foreground=windows.some(client=>client.focused&&client.visibilityState==='visible');
 if(!foreground){try{if(await SupportAttentionStore.claim(hint))await self.registration.showNotification('پیام تازهٔ پشتیبانی',{body:'برای بررسی گفتگوها برنامه را باز کنید.',tag:'support-awaiting',icon:new URL('icons/icon-192.png',ASSET_SCOPE).href,data:{conversation:hint.conversation}});}catch{}}
 for(const client of windows)client.postMessage({type:'support-push'});
})()));
self.addEventListener('notificationclick',event=>{event.notification.close();event.waitUntil((async()=>{const target=new URL(PANEL),id=event.notification.data?.conversation;if(typeof id==='string'&&/^user_[a-f0-9]{32}_[0-9]+$/.test(id))target.hash='conversation='+id;const windows=(await self.clients.matchAll({type:'window'})).filter(client=>new URL(client.url).pathname===PANEL.pathname);const client=windows.find(c=>new URL(c.url).pathname===PANEL.pathname);if(client){await client.focus();client.postMessage({type:'support-open',conversation:id});}else await self.clients.openWindow(target.href);})());});
