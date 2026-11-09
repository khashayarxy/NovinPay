importScripts('./attention-store.js?v=0.1.0-r14');
// Customer-only scope and session; no cached private messages or operator access.
self.addEventListener('push',event=>event.waitUntil((async()=>{
    try{
        const r=await fetch(new URL('../customer-notifications.php?action=hint',self.registration.scope),{credentials:'same-origin',cache:'no-store'});if(!r.ok)return;
        const hint=await r.json();if(!Number.isSafeInteger(hint.unread)||hint.unread<1)return;
        if(!await SupportAttentionStore.claim(hint))return;
        await self.registration.showNotification('پاسخ تازهٔ پشتیبانی',{body:'برای دیدن پاسخ، سایت را باز کنید.',tag:'support-customer-unread',data:{customer:true}});
    }catch{/* No unauthenticated fallback notification. */}
})()));
self.addEventListener('notificationclick',event=>{event.notification.close();event.waitUntil((async()=>{
    const target=new URL('/',self.registration.scope),windows=await self.clients.matchAll({type:'window',includeUncontrolled:true});
    const client=windows.find(c=>{const url=new URL(c.url);return c.frameType!=='nested'&&url.origin===target.origin&&!/^\/(?:admin|support|support-chat)(?:\/|$)/.test(url.pathname);});
    if(client){await client.focus();client.postMessage({type:'support-customer-open'});}else await self.clients.openWindow(target.href);
})());});
