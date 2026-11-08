import {messageEdit} from './message-edit.js?v=team1';
import {messageText} from './bplus.js?v=bplus1';
import {messageGroups,operatorSenderName,supportSide} from './message-groups.js?v=ux1';
import {openImagePreview} from './engine.js?v=team1';
const node=(tag,cls,text)=>{const e=document.createElement(tag);e.className=cls;if(text!==undefined)e.textContent=text;return e;};
const action=(label,run,cls='')=>{const b=node('button',cls,label);b.type='button';b.onclick=run;return b;};
const senderLabels=new WeakMap(),latestViews=new WeakMap(),pendingTimers=new WeakMap(),motionHeads=new WeakMap();
// Normalized engine data in, keyed presentation out. No listeners, polling or state store.
export function renderCustomerV2(host,messages,{engine,follow=true,operator=false,ownGroup=null,operatorName=null,older=false,animate=false}={}){
 latestViews.set(host,[messages,{engine,follow,operator,ownGroup,operatorName}]);clearTimeout(pendingTimers.get(host));let nextPending=Infinity;
 const scrollHost=operator?host.closest('#messageStream')||host:host;const atBottom=scrollHost.scrollHeight-scrollHost.scrollTop-scrollHost.clientHeight<80;
 const multiple=new Set(messages.filter(m=>m.type==='admin').map(m=>m.sender_group||m.sender?.name).filter(Boolean)).size>1;
 const labels=senderLabels.get(host)||new Map();senderLabels.set(host,labels);for(const m of messages)if(m.sender_group&&!labels.has(m.sender_group))labels.set(m.sender_group,labels.size+1);
 const prior=motionHeads.get(host),conversation=engine?.sync?.id??null,head=host.childElementCount&&prior?.conversation===conversation?prior.head:0;motionHeads.set(host,{conversation,head:Math.max(head,...messages.map(m=>Number.isSafeInteger(m.id)?m.id:0))});
 const keys=new Set();let position=0,unreadMarked=false;
 for(const group of messageGroups(messages,{operator,ownGroup,conversation:engine?.sync?.id})){
  const {m,outgoing}=group;
  if(m.episode_boundary){const key='episode:'+m.id;keys.add(key);let marker=[...host.children].find(e=>e.dataset.messageKey===key);if(!marker){marker=node('div','support-episode-marker','گفتگوی جدید');marker.dataset.messageKey=key;marker.setAttribute('role','separator');}if(host.children[position]!==marker)host.insertBefore(marker,host.children[position]||null);position++;continue;}
  if(group.separator){const key='day:'+group.day+':'+m.id;keys.add(key);let marker=[...host.children].find(e=>e.dataset.messageKey===key);if(!marker){marker=node('div','support-day-marker',group.date);marker.dataset.messageKey=key;marker.setAttribute('role','separator');}marker.textContent=group.date;if(host.children[position]!==marker)host.insertBefore(marker,host.children[position]||null);position++;}
  if(!unreadMarked&&m.type===(operator?'user':'admin')&&m[operator?'read':'customer_read']===false){const key='unread:'+m.id;keys.add(key);let marker=[...host.children].find(e=>e.dataset.messageKey===key);if(!marker){marker=node('div','support-new-separator','پیام‌های جدید');marker.dataset.messageKey=key;marker.setAttribute('role','separator');}if(host.children[position]!==marker)host.insertBefore(marker,host.children[position]||null);position++;unreadMarked=true;}
  const key=m.client_message_id?m.type+':'+m.client_message_id:'message:'+m.id;keys.add(key);
  let row=[...host.children].find(e=>e.dataset.messageKey===key);
  const inserted=!row;if(!row){row=node('article','');row.dataset.messageKey=key;}
  const pending=!!m._row&&!m._ack,late=pending&&(Date.now()-m._row.created>=10000||['needs_action','cancelled'].includes(m._row.state));
  const grace=pending&&!late&&['pending','sending'].includes(m._row.state)&&globalThis.navigator?.onLine!==false&&Date.now()-m._row.created<(engine?.pendingGrace?.()??300);if(grace)nextPending=Math.min(nextPending,m._row.created+(engine?.pendingGrace?.()??300)-Date.now());
  const grouped=!group.first;
  const automatic=m.type==='system'&&m.origin==='faq_auto';
  const signature=JSON.stringify([automatic,multiple,operator,operatorName,outgoing,grouped,group.last,group.label,m.text,m.revision,m.edited_at,m.attachments,m.reference,m.reply_to,m.sender,m.time,m.accepted_at,m.read_receipt,m._ack,m._row?.state,m._row?.error,grace,late,late&&!!engine?.sendWait?.(m._row.conversation)]);
  if(row.dataset.content!==signature){
   const editForm=row.querySelector('.support-message-editor'),editFocus=editForm?.contains(document.activeElement)?document.activeElement:null,editSelection=editFocus?[editFocus.selectionStart,editFocus.selectionEnd]:null;const focused=row.contains(document.activeElement)?document.activeElement.getAttribute('aria-label'):null;
   const oldBubble=row.querySelector('.v2-bubble'),bubbleSignature=JSON.stringify([m.text,m.attachments||[],m.reply_to||null,m.reference||null,!!m.attachments?.length&&pending]);const keepBubble=oldBubble&&row.dataset.bubbleContent===bubbleSignature;row.dataset.bubbleContent=bubbleSignature;
   const expanded=row.querySelector('details')?.open;row.replaceChildren();row.dataset.content=signature;
   row.className='chat-msg '+(m.type==='user'?'user':'admin')+(operator?(m.type==='admin'||automatic?' message-admin':' message-user'):'')+(outgoing?' outgoing':' incoming')+(pending?' support-pending':'')+(grouped?' v2-grouped':'');
   if(automatic)row.dataset.origin='faq_auto';else delete row.dataset.origin;
   row.dataset.messageId=String(m.id);row.dataset.incoming=String(operator?m.type==='user':m.type==='admin');row.dataset.groupStart=String(group.first);row.dataset.groupEnd=String(group.last);row.setAttribute('aria-label',group.full+(group.state?' · '+({sent:'ارسال شد',read:'خوانده شد',pending:'در انتظار تأیید',failed:'ارسال تأیید نشد',legacy:'سابقهٔ قدیمی'}[group.state]):''));if(pending)row.dataset.uuid=m._row.uuid;else delete row.dataset.uuid;
   if(operator&&supportSide(m)||!operator&&!outgoing&&!grouped&&multiple&&m.type==='admin'){
    const identity=node('div','v2-identity'),avatar=node('span','support-sender-avatar'),name=node('span','v2-sender-name',operator?operatorSenderName(m,operatorName):m.type==='user'?(engine?.sync?.userName||'مهمان'):(m.type==='system'?'پاسخ خودکار':m.sender?.name||(multiple&&m.sender_group?'پشتیبان '+labels.get(m.sender_group).toLocaleString('fa-IR'):'پشتیبانی '+(globalThis.SupportWidgetConfig?.name||'نوین‌پی'))));avatar.setAttribute('aria-hidden','true');
    if(/^(?:support-avatar|operator-avatar)\.php\?id=[a-f0-9]{32}$/.test(m.sender?.avatar||'')){const img=node('img','');img.src=m.sender.avatar;img.alt='';img.onerror=()=>avatar.replaceChildren(node('span','support-icon','support_agent'));avatar.append(img);}else if(m.type==='user')avatar.textContent=name.textContent.trim().slice(0,1);else avatar.append(node('span','support-icon',m.type==='system'?'auto_awesome':'support_agent'));
    identity.append(avatar,name);row.append(identity);
   }
   if(automatic)row.append(node('small','support-auto-label','پاسخ خودکار'));
   const wrap=node('div','v2-bubble-wrap'),bubble=node('div','v2-bubble');bubble.title=row.getAttribute('aria-label');wrap.append(bubble);
   if(m.reply_to){const ref=m.reference||engine?.sync?.messages?.get(m.reply_to);const available=m.reference?ref.available===true:!!ref;const quote=action(available?(ref.text||'تصویر پیوست'):'پیام مرجع در دسترس نیست',()=>host.dispatchEvent(new CustomEvent('support-reference',{bubbles:true,detail:{id:m.reply_to}})),'support-reply-reference');quote.disabled=!available;quote.setAttribute('aria-label','رفتن به پیام مرجع');bubble.append(quote);}
   if(m.text){const text=node('span','support-message-text');messageText(text,m.text);bubble.append(text);}
   const images=node('div','support-message-images');
   if(!pending)for(const id of m.attachments||[]){if(!/^[a-f0-9]{32}$/.test(id))continue;const link=node('a',''),img=node('img','');link.href='support-attachment.php?scope='+(operator?'operator':'customer')+'&id='+id;link.target='_blank';link.rel='noopener noreferrer';link.setAttribute('aria-label','باز کردن تصویر');img.src=link.href;img.alt='تصویر پیوست گفتگو';img.loading='lazy';img.decoding='async';img.width=160;img.height=120;img.onerror=()=>link.replaceChildren(node('small','','تصویر در دسترس نیست'));link.onclick=e=>{if(e.button||e.ctrlKey||e.metaKey||e.shiftKey||e.altKey)return;e.preventDefault();openImagePreview(link.href,link);};link.append(img);images.append(link);}
   if(pending)for(const id of m.attachments||[]){if(/^[a-f0-9]{32}$/.test(id))images.append(node('span','support-image-pending','در انتظار تأیید تصویر…'));}
   if(images.childElementCount){bubble.classList.add('v2-media');bubble.append(images);}
   if(keepBubble)bubble.replaceWith(oldBubble);row.append(wrap);
   const stamp=group.stamp;
   const meta=node('div','v2-meta'),time=node('time','chat-time',group.label);if(!Number.isNaN(stamp.getTime())){time.dateTime=stamp.toISOString();time.title=group.full;time.setAttribute('aria-label',group.full);time.dir='auto';}meta.classList.toggle('v2-meta-collapsed',!group.last);meta.append(time);
   if(outgoing&&!automatic){const label=pending?(late?'ارسال تأیید نشد':'در انتظار تأیید'):m.read_receipt?'خوانده شد':'ارسال شد';const status=node('span','support-read-state support-icon',pending?(late?'error_outline':'schedule'):m.read_receipt?'done_all':'done');status.style.visibility=grace?'hidden':'';status.dataset.state=pending?(late?'failed':'pending'):m.read_receipt?'read':'sent';status.title=label;status.setAttribute('aria-label',label);meta.append(status);}
   if(m.edited_at){const edited=node('small','support-edited','ویرایش‌شده');edited.title=m.edited_at;if(group.last)meta.append(edited);else row.append(edited);}
   row.append(meta);if(editForm){row.append(editForm);if(editFocus){editFocus.focus({preventScroll:true});if(editSelection&&editFocus.setSelectionRange)editFocus.setSelectionRange(...editSelection);}}
   if(late&&engine&&operator){const actions=node('div','v2-message-actions'),retry=action('تلاش مجدد',()=>engine.retry(m._row).catch(e=>engine.hooks.warning?.(e.message)),'v2-retry');retry.disabled=!!engine.sendWait?.(m._row.conversation);retry.setAttribute('aria-label','تلاش مجدد');const details=node('details','v2-retry-options');details.open=!!expanded;const summary=node('summary');summary.setAttribute('aria-label','تلاش مجدد و گزینه‌های پیام ارسال‌نشده');summary.title='گزینه‌های پیام';summary.append(node('span','support-icon','more_horiz'));details.append(summary);const menu=node('div','v2-message-action-menu');menu.append(retry);if(m._row.error&&!engine.sendWait?.(m._row.conversation))menu.append(node('small','',m._row.error));for(const [label,run]of [['توقف تلاش',()=>engine.cancel(m._row)],['کنار گذاشتن پیش‌نویس',()=>engine.deleteLocal(m._row)]])menu.append(action(label,()=>run().catch(e=>engine.hooks.warning?.(e.message))));details.append(menu);actions.append(details);wrap.append(actions);}
   if(late&&engine&&!operator){const retry=action('بررسی و ادامه ارسال',()=>engine.retry(m._row).catch(e=>engine.hooks.warning?.(e.message)),'v2-recovery');retry.disabled=!!engine.sendWait?.(m._row.conversation);row.append(retry);}
   if(m.type!=='system'&&Number.isSafeInteger(m.id)){const reply=action('reply',()=>host.dispatchEvent(new CustomEvent('support-reply',{bubbles:true,detail:{id:m.id}})),'support-message-reply support-icon');reply.setAttribute('aria-label','پاسخ به پیام');wrap.append(reply);}
   if(focused)[...row.querySelectorAll('[aria-label]')].find(e=>e.getAttribute('aria-label')===focused)?.focus({preventScroll:true});
  }
  messageEdit(row,m,host,engine);if(host.children[position]!==row)host.insertBefore(row,host.children[position]||null);position++;if(inserted&&animate&&!older&&(!Number.isSafeInteger(m.id)||m.id>head)&&atBottom&&!document.hidden&&!matchMedia('(prefers-reduced-motion:reduce)').matches){const bubble=row.querySelector('.v2-bubble');bubble?.animate?.([{opacity:.65,transform:'translateY(7px)'},{opacity:1,transform:'translateY(0)'}],{duration:240,easing:'cubic-bezier(.2,.8,.2,1)'});}const contact=host.querySelector('.v2-contact-card');if(contact?.dataset.anchor===key){if(host.children[position]!==contact)host.insertBefore(contact,host.children[position]||null);position++;}
 }
 for(const row of [...host.children])if(!row.classList.contains('v2-contact-card')&&!keys.has(row.dataset.messageKey))row.remove();
 if(Number.isFinite(nextPending))pendingTimers.set(host,setTimeout(()=>{if(host.isConnected){const [items,options]=latestViews.get(host);if(options.engine?.frozen||options.engine?.paused)return;renderCustomerV2(host,items,{...options,follow:false});}},Math.max(1,nextPending)));
 if(atBottom&&follow)host.scrollTop=host.scrollHeight;
}
