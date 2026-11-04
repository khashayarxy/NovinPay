import {contextualReply} from './bplus.js?v=bplus1';
// Composer contains an ID only. Text previews never enter drafts, outbox or notifications.
export function replyControls({host,composer,input,engine,warn,reading=null,changed=()=>{}}){
 const destroyContext=contextualReply(host);
 const bar=document.createElement('div');bar.className='support-reply-draft';bar.hidden=true;
 const preview=document.createElement('span'),cancel=document.createElement('button');cancel.type='button';cancel.className='support-icon';cancel.textContent='close';cancel.setAttribute('aria-label','لغو پاسخ به پیام');bar.append(preview,cancel);composer.prepend(bar);let selected=null,busy=false;
 const refresh=()=>{bar.hidden=!selected;if(!selected)return;const m=engine()?.sync?.messages?.get(selected);preview.textContent=m?('پاسخ به '+(m.text||'تصویر پیوست')).slice(0,150):'پیام مرجع در دسترس نیست';};
 const set=id=>{selected=Number.isSafeInteger(id)&&id>0?id:null;refresh();changed();if(selected)input.focus({preventScroll:true});};
 const clear=()=>{selected=null;bar.hidden=true;preview.textContent='';};cancel.onclick=()=>{clear();changed();};
 const reply=event=>set(event.detail?.id);
 const reference=async event=>{const id=event.detail?.id;if(!Number.isSafeInteger(id)||busy)return;const e=engine(),scope=e.scope,conversation=e.sync.id;busy=true;reading?.pause();
  try{const deadline=Date.now()+15000;while(e.sync.busy&&Date.now()<deadline)await new Promise(r=>setTimeout(r,20));if(e.frozen||scope!==e.scope||conversation!==e.sync.id)return;if(e.sync.busy)throw Error('دریافت پیام طول کشید؛ دوباره تلاش کنید.');if(!await e.sync.focusMessage(id))return;const target=host.querySelector(`[data-message-id="${id}"]`);if(target){target.scrollIntoView({block:'center'});target.classList.add('support-reference-target');setTimeout(()=>target.classList.remove('support-reference-target'),1800);}}
  catch{warn('پیام مرجع در دسترس نیست یا منقضی شده است.');}finally{busy=false;refresh();}
 };
 host.addEventListener('support-reply',reply);host.addEventListener('support-reference',reference);
 return {get id(){return selected;},set,clear,refresh,destroy(){destroyContext();clear();bar.remove();host.removeEventListener('support-reply',reply);host.removeEventListener('support-reference',reference);}};
}
