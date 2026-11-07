export function notificationReadiness({host,load,warn}){
 const button=document.createElement('button');button.type='button';button.textContent='بررسی آمادگی اعلان‌ها';const result=document.createElement('div');result.className='support-health';result.setAttribute('aria-live','polite');host.append(button,result);let busy=false;
 const row=(label,value)=>{const section=document.createElement('section'),title=document.createElement('strong'),text=document.createElement('small');title.textContent=label;text.textContent=value;section.append(title,text);result.append(section);};
 button.onclick=async()=>{if(busy)return;busy=true;button.disabled=true;result.replaceChildren();try{
  const server=await load(),expected=new URL('./',import.meta.url),registration=await navigator.serviceWorker?.getRegistration(expected.href),subscription=await registration?.pushManager?.getSubscription();let provider='اشتراکی روی این مرورگر نیست.';
  if(subscription){const endpoint=new URL(subscription.endpoint);provider=['fcm.googleapis.com','updates.push.services.mozilla.com','web.push.apple.com'].includes(endpoint.hostname)?endpoint.hostname:'Unknown provider';}
  row('مرورگر و HTTPS',`${isSecureContext?'Secure context':'Not secure'} · ${server.https?'HTTPS سرور':'HTTP سرور'} · ${'PushManager'in window?'Push API موجود':'Push API ناموجود'}`);
  row('Service worker',registration?.scope===expected.href?'scope صحیح: '+expected.pathname:'Unknown؛ scope مورد انتظار: '+expected.pathname);
  row('مجوز و اشتراک',`${'Notification'in window?Notification.permission:'unsupported'} · ${provider} · ${server.account.active_subscriptions} اشتراک معتبر حساب${server.account.next_expiry?' · انقضا: '+new Date(server.account.next_expiry*1000).toLocaleString('fa-IR'):''}`);
  if(subscription?.options?.applicationServerKey){const key=new Uint8Array(subscription.options.applicationServerKey),encoded=btoa(String.fromCharCode(...key)).replaceAll('+','-').replaceAll('/','_').replace(/=+$/,'');const digest=await crypto.subtle.digest('SHA-256',new TextEncoder().encode(encoded));const fingerprint=[...new Uint8Array(digest)].map(v=>v.toString(16).padStart(2,'0')).join('').slice(0,16);row('تطبیق کلید اشتراک',server.vapid.public_fingerprint===fingerprint?'کلید مرورگر و سرور منطبق است.':'Unknown / mismatch؛ پیش از ارسال واقعی بررسی شود.');}
  row('VAPID',server.vapid.ready?'کلید موجود · اثر انگشت عمومی: '+server.vapid.public_fingerprint:'Unknown؛ کلید خصوصی آماده تأیید نشده است.');
  row('ارسال بیرونی',Object.entries(server.flags).map(([key,value])=>key.replace('SUPPORT_','')+': '+(value?'ON':'OFF')).join(' | '));
  row('آمادگی پیکربندی',Object.entries(server.configuration).map(([key,value])=>key+': '+(value?'موجود':'آماده تأیید نشده')).join(' | '));
  row('سیاست اعلان',`تأخیر SMS: ${server.policy.sms_delay_seconds}s · سقف روزانهٔ نصب: ${server.policy.sms_daily_budget} · هر اپراتور: ${server.policy.per_operator_sms_daily} · quiet: ${server.policy.quiet_start}–${server.policy.quiet_end} · بی‌صدا اکنون: ${server.account.quiet_now?'بله':'خیر'}`);
  row('TTL و محدودیت پس‌زمینه',`TTL فعلی: ${server.policy.push_ttl_seconds}s · urgency: ${server.policy.push_urgency} · JWT: ${server.policy.vapid_jwt_seconds}s؛ سازگاری cadence اپل و دریافت روی گوشی واقعی NOT VERIFIED است.`);
  row('نشست و لغو',`پنجرهٔ فعالیت: ${server.policy.interactive_session_seconds}s · سقف نشست: ${server.policy.absolute_session_seconds}s؛ logout/لغو حساب/انقضا دوباره بررسی می‌شود.`);
  row('شبکه و گوشی قفل‌شده','NOT VERIFIED؛ دسترسی سرور و دستگاه به سرویس Push و دسترسی سرور به SMS.ir لازم است. شبکهٔ ملی، نمایش اعلان را تضمین نمی‌کند. این بررسی permission یا ارسال ایجاد نمی‌کند.');
 }catch(e){warn(e);}finally{busy=false;button.disabled=false;}};
 return {clear(){result.replaceChildren();}};
}
