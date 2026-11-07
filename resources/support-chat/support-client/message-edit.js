import {uuid} from './outbox.js?v=team1';
const active=new WeakMap();
const make=(tag,cls,text)=>Object.assign(document.createElement(tag),{className:cls||'',...(text===undefined?{}:{textContent:text})});
export function messageEdit(row,m,host,engine){
 const current=active.get(host);
 if(current&&(current.scope!==engine?.scope||current.conversation!==engine?.sync.id))current.close(false);
 if(current?.id===m.id)row.classList.add('support-edit-target');
 const allowed=()=>engine&&!engine.frozen&&Number.isSafeInteger(m.id)&&!m._row&&m.can_edit===true&&Date.parse(m.edit_until)>=engine.transport.presentationTime(Date.now());

 if(!allowed()){row.querySelector('.support-edit-options')?.remove();row.querySelector('.v2-bubble-wrap')?.classList.remove('has-edit');row.oncontextmenu=row.onkeydown=null;return;}if(row.querySelector('.support-edit-options'))return;
 const edit=make('button','support-edit-options support-edit-pencil');edit.type='button';edit.setAttribute('aria-label','ویرایش پیام');edit.title='ویرایش پیام';edit.append(make('span','support-icon','edit'));row.querySelector('.v2-bubble-wrap').append(edit);row.querySelector('.v2-bubble-wrap').classList.add('has-edit');
 const expire=()=>{if(!edit.isConnected)return;const remaining=Date.parse(m.edit_until)-engine.transport.presentationTime(Date.now());if(remaining>0){setTimeout(expire,Math.min(remaining,900000)+20);return;}edit.remove();row.querySelector('.v2-bubble-wrap')?.classList.remove('has-edit');};setTimeout(expire,Math.max(0,Date.parse(m.edit_until)-engine.transport.presentationTime(Date.now()))+20);
 row.tabIndex=0;row.setAttribute('aria-keyshortcuts','Shift+F10');
 row.oncontextmenu=e=>{if(e.target.closest('a,input,textarea')||String(getSelection()).length)return;e.preventDefault();row.classList.add('reply-active');edit.focus({preventScroll:true});};
 row.onkeydown=e=>{if(e.target!==row)return;if(e.key==='ContextMenu'||e.shiftKey&&e.key==='F10'){e.preventDefault();row.classList.add('reply-active');edit.focus({preventScroll:true});}if(e.key==='Escape')row.classList.remove('reply-active');};
 edit.onclick=()=>{
  if(!allowed())return;
  const previous=active.get(host);if(previous){if(previous.id===m.id){previous.input.focus({preventScroll:true});return;}if(!previous.cancel())return;}
  const input=document.getElementById(engine.role==='operator'?'reply':'chatInput'),composer=document.getElementById(engine.role==='operator'?'replyArea':'inputArea'),send=document.querySelector(engine.role==='operator'?'#send':'.chat-send');if(!input||!composer||!send)return;
  const draft=input.value,selection=[input.selectionStart,input.selectionEnd],initial=m.text.replace(/\r\n/g,'\n'),state={id:m.id,scope:engine.scope,conversation:engine.sync.id,input,draft,request:null,text:null};
  const header=make('div','support-composer-edit'),label=make('span','','ویرایش پیام'),cancel=make('button','support-icon','close'),error=make('small','support-edit-error');cancel.type='button';cancel.setAttribute('aria-label','انصراف از ویرایش');error.setAttribute('role','status');const heading=make('span','support-edit-heading');heading.append(make('span','support-icon','edit'),label);header.append(heading,cancel,error);composer.prepend(header);
  const oldLabel=send.getAttribute('aria-label'),oldTitle=send.title,icon=send.querySelector('.support-icon'),oldIcon=icon?.textContent,oldChildren=[...send.childNodes],oldHeight=input.style.height;
  let busy=false,composing=false;const controls=[...composer.querySelectorAll('button,input,select')].filter(x=>x!==send&&x!==cancel);const disabled=controls.map(x=>x.disabled);controls.forEach(x=>x.disabled=true);composer.classList.add('support-editing');row.classList.add('support-edit-target');input.value=initial;send.setAttribute('aria-label','ذخیره ویرایش');send.title='ذخیره ویرایش';send.replaceChildren(make('span','support-icon','check'));
  const resize=()=>{input.style.height='auto';input.style.height=Math.min(input.scrollHeight,160)+'px';};resize();
  const abort=new AbortController(),options={capture:true,signal:abort.signal};
  state.close=(restore=true)=>{abort.abort();header.remove();composer.classList.remove('support-editing');host.querySelectorAll('.support-edit-target').forEach(x=>x.classList.remove('support-edit-target'));controls.forEach((x,i)=>x.disabled=disabled[i]);send.setAttribute('aria-label',oldLabel||'ارسال');send.title=oldTitle;send.replaceChildren(...oldChildren);input.readOnly=false;send.disabled=false;active.delete(host);if(engine.composerEdit===state)engine.composerEdit=null;if(restore){input.value=draft;input.style.height=oldHeight;input.setSelectionRange(...selection);}};
  state.cancel=()=>{if(busy)return false;if(input.value!==initial&&!confirm('تغییرات ذخیره‌نشده کنار گذاشته شود؟'))return false;state.close();return true;};cancel.onclick=state.cancel;
  const save=async()=>{if(busy)return;if(!allowed()){error.textContent='مهلت ویرایش پیام پایان یافته است.';return;}const text=input.value.trim();if(!text||new TextEncoder().encode(text).length>2000){input.setAttribute('aria-invalid','true');error.textContent=text?'متن پیام طولانی است؛ آن را کوتاه‌تر کنید.':'متن پیام را وارد کنید.';return;}if(input.value===initial){state.close();return;}busy=true;input.readOnly=true;send.disabled=cancel.disabled=true;error.textContent='در حال ذخیره…';if(state.text!==input.value){state.request=uuid();state.text=input.value;}
   try{const response=await engine.transport.request('api.php',{data:{edit_message:'1',protocol_version:'2',scope:engine.role,user_id:state.conversation,message_id:String(m.id),revision:String(m.revision||0),edit_request_id:state.request,text:state.text}});if(state.scope!==engine.scope||state.conversation!==engine.sync.id)return;state.close();engine.sync.edited(response);engine.poller.wake();}catch(e){error.textContent=e.message||'ویرایش ذخیره نشد؛ دوباره تلاش کنید.';if(e.code==='message_edit_conflict')engine.poller.wake();}finally{busy=false;input.readOnly=false;send.disabled=cancel.disabled=false;}
  };
  composer.addEventListener('click',e=>{const button=e.target.closest('button');if(button&&button!==send&&button!==cancel){e.preventDefault();e.stopImmediatePropagation();}},options);
  host.addEventListener('click',e=>{if(e.target.closest('.support-message-reply')){e.preventDefault();e.stopImmediatePropagation();}},options);
  input.addEventListener('paste',e=>{e.stopImmediatePropagation();if(e.clipboardData?.files.length)e.preventDefault();},options);
  composer.addEventListener('drop',e=>{e.preventDefault();e.stopImmediatePropagation();},options);
  send.addEventListener('click',e=>{e.preventDefault();e.stopImmediatePropagation();save();},options);
  input.addEventListener('input',e=>{e.stopImmediatePropagation();error.textContent='';resize();},options);
  input.addEventListener('compositionstart',()=>composing=true,options);input.addEventListener('compositionend',()=>composing=false,options);
  input.addEventListener('keydown',e=>{if(e.key==='Escape'&&!e.isComposing){e.preventDefault();e.stopImmediatePropagation();state.cancel();}if(e.key==='Enter'&&!e.shiftKey&&!e.isComposing&&!composing&&e.keyCode!==229&&!matchMedia('(pointer:coarse)').matches){e.preventDefault();e.stopImmediatePropagation();if(!e.repeat)save();}},options);
  window.addEventListener('beforeunload',e=>{if(input.value!==initial){e.preventDefault();e.returnValue='';}},options);
  active.set(host,state);engine.composerEdit=state;input.focus({preventScroll:true});
 };
}
