// The engine's scoped Retry-After deadline is the only cooldown state. This
// presentation timer never sends requests, changes outbox rows or clears drafts.
export function sendCooldown({host,button,current}){
 const notice=document.createElement('section');notice.className='support-send-cooldown';notice.hidden=true;notice.setAttribute('role','status');
 const title=document.createElement('strong');title.textContent='ارسال پیام موقتاً محدود شده است';const copy=document.createElement('small');copy.textContent='لطفاً پرسش‌هایتان را در یک پیام کامل بنویسید؛ ارسال به‌زودی فعال می‌شود.';notice.append(title,copy);host.before(notice);
 const count=document.createElement('span');count.className='support-cooldown-count';count.hidden=true;count.setAttribute('aria-hidden','true');button.append(count);let timer=null,locked=false;
 const originalLabel=button.getAttribute('aria-label'),originalTitle=button.title;
 const refresh=()=>{clearTimeout(timer);const wait=current()?.sendWait()||0,active=wait>0;notice.hidden=!active;count.hidden=!active;
  if(active){const seconds=Math.ceil(wait/1000);button.disabled=true;button.dataset.cooldown='';count.textContent=seconds.toLocaleString('fa-IR');button.title='ارسال دوباره تا '+count.textContent+' ثانیه دیگر';button.setAttribute('aria-label',button.title);locked=true;timer=setTimeout(refresh,Math.min(1000,wait));}
  else if(locked){delete button.dataset.cooldown;if(!host.hasAttribute('aria-busy')&&!document.getElementById('chatWindow')?.classList.contains('support-composer-locked'))button.disabled=false;button.title=originalTitle;if(originalLabel)button.setAttribute('aria-label',originalLabel);else button.removeAttribute('aria-label');locked=false;}
 };
 addEventListener('support-widget-visibility',refresh);addEventListener('online',refresh);document.addEventListener('visibilitychange',refresh);
 return {refresh,destroy(){clearTimeout(timer);removeEventListener('support-widget-visibility',refresh);removeEventListener('online',refresh);document.removeEventListener('visibilitychange',refresh);notice.remove();count.remove();}};
}
