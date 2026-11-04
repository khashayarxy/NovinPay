// Shared presentation only: no transport, identity, polling or message store.
export function contextualReply(host){
 host.dataset.contextualReply='';let down=null;
 const clear=()=>host.querySelectorAll('.reply-active').forEach(e=>e.classList.remove('reply-active'));
 const start=e=>{if(e.pointerType!=='touch'&&e.pointerType!=='pen'){host.classList.remove('touch-reply');return;}host.classList.add('touch-reply');down={x:e.clientX,y:e.clientY,id:e.pointerId,target:e.target};};
 const finish=e=>{const d=down;down=null;if(!d||d.id!==e.pointerId||Math.hypot(e.clientX-d.x,e.clientY-d.y)>8||String(getSelection()).length)return;
  if(e.target.closest('button,a,input,textarea,select,summary,[contenteditable]'))return;
  const row=e.target.closest('.chat-msg');clear();if(row&&host.contains(row)&&row.querySelector('.support-message-reply'))row.classList.add('reply-active');
 };
 const cancel=()=>{down=null;clear();},move=e=>{if(e.pointerType==='mouse')host.classList.remove('touch-reply');},key=e=>{if(e.key==='Escape')clear();};
 document.addEventListener('pointerdown',start);document.addEventListener('pointerup',finish);document.addEventListener('pointermove',move);document.addEventListener('pointercancel',cancel);host.addEventListener('scroll',cancel,true);host.addEventListener('keydown',key);
 return ()=>{document.removeEventListener('pointerdown',start);document.removeEventListener('pointerup',finish);document.removeEventListener('pointermove',move);document.removeEventListener('pointercancel',cancel);host.removeEventListener('scroll',cancel,true);host.removeEventListener('keydown',key);delete host.dataset.contextualReply;clear();};
}
export function messageText(host,text){
 host.dir='auto';
 const pattern=/https?:\/\/[^\s<>]+/gu;let at=0;for(const match of text.matchAll(pattern)){host.append(document.createTextNode(text.slice(at,match.index)));const bdi=document.createElement('bdi');bdi.className='bplus-url';bdi.dir='ltr';bdi.textContent=match[0];host.append(bdi);at=match.index+match[0].length;}host.append(document.createTextNode(text.slice(at)));
}
