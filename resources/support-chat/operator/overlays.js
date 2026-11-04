// Native dialogs keep focus trapping/inertness. Drafts remain outside these panels.
export function overlayControls(){
 const dirty=new Set(),origins=new WeakMap(),baseline=new WeakMap(),positions=new WeakMap();
 const value=el=>el.type==='checkbox'||el.type==='radio'?el.checked:el.value;
 const pending=panel=>[...dirty].some(el=>el.isConnected&&panel.contains(el));
 const saved=panel=>{for(const el of dirty)if(!el.isConnected||panel.contains(el)&&(!panel.dataset.settingsPanel||el.closest('[data-settings-panel]')===panel)){dirty.delete(el);baseline.delete(el);}};
 function close(dialog,{accidental=false}={}){
  if(dialog.getAttribute('aria-busy')==='true')return false;
  if(pending(dialog)){
   if(accidental){dialog.querySelector('[role=status]')?.replaceChildren(document.createTextNode('تغییرات ذخیره نشده است؛ ذخیره کنید یا از دکمهٔ بستن استفاده کنید.'));return false;}
   if(!confirm('تغییرات این پنجره ذخیره نشده است. با حفظ متن در فرم، پنجره بسته شود؟'))return false;
  }
  dialog.close();return true;
 }
 function open(dialog){
  for(const other of document.querySelectorAll('dialog[open]'))if(other!==dialog&&!close(other))return false;
  for(const menu of document.querySelectorAll('.operator-account-menu[open]'))menu.open=false;
  if(dialog.open)return true;origins.set(dialog,document.activeElement);positions.set(dialog,[...document.querySelectorAll('#messageStream,#conversations')].map(el=>({el,top:el.scrollTop,left:el.scrollLeft,tail:el.scrollHeight-el.clientHeight-el.scrollTop<32})));if(matchMedia('(pointer:coarse)').matches){dialog.tabIndex=-1;const focus=dialog.querySelector('button:not([disabled]):not([hidden])');focus?.setAttribute('autofocus','');dialog.showModal();focus?.removeAttribute('autofocus');dialog.focus({preventScroll:true});}else dialog.showModal();return true;
 }
 for(const dialog of document.querySelectorAll('dialog')){
  dialog.addEventListener('support-form-saved',event=>saved(event.target));
  dialog.addEventListener('focusin',event=>{const el=event.target;if(el.matches('input,textarea,select')&&!baseline.has(el))baseline.set(el,value(el));});
  const changed=event=>{const el=event.target;if(!el.matches('input,textarea,select')||el.closest('#appearanceSettings,#deviceNotifications,#privacy,#developmentControl'))return;if(baseline.has(el)&&baseline.get(el)===value(el))dirty.delete(el);else dirty.add(el);};
  dialog.addEventListener('input',changed);dialog.addEventListener('change',changed);
  dialog.addEventListener('cancel',event=>{event.preventDefault();close(dialog);});
  let backdrop=false;dialog.addEventListener('pointerdown',event=>{const r=dialog.getBoundingClientRect();backdrop=event.target===dialog&&(event.clientX<r.left||event.clientX>r.right||event.clientY<r.top||event.clientY>r.bottom);});
  dialog.addEventListener('click',event=>{if(backdrop&&event.target===dialog)close(dialog,{accidental:true});backdrop=false;});
  dialog.addEventListener('close',()=>{document.body.classList.toggle('overlay-open',!!document.querySelector('dialog[open]'));for(const {el,top,left,tail}of positions.get(dialog)||[]){el.scrollTop=tail?el.scrollHeight:top;el.scrollLeft=left;}const origin=origins.get(dialog);if(origin?.isConnected&&origin.getClientRects().length)origin.focus({preventScroll:true});});
 }
 document.addEventListener('pointerdown',event=>{for(const menu of document.querySelectorAll('.operator-account-menu[open]'))if(!menu.contains(event.target))menu.open=false;});
 document.addEventListener('keydown',event=>{if(event.key!=='Escape'||document.querySelector('dialog[open]'))return;const menu=document.querySelector('.operator-account-menu[open]');if(menu){menu.open=false;menu.querySelector('summary').focus();event.preventDefault();}});
 return {open(dialog){const ok=open(dialog);document.body.classList.toggle('overlay-open',!!document.querySelector('dialog[open]'));return ok;},close,pending,saved};
}
