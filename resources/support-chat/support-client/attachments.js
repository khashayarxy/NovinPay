import {uuid} from './outbox.js?v=team1';
import {selectionToast} from './selection-toast.js?v=release1';
// Selected binaries stay in memory; only accepted opaque IDs enter the scoped outbox.
export function attachmentComposer({host,input,current,conversation,role='customer',changed=()=>{},refocus=false,canSubmit=()=>true,ownershipConflict=()=>{},warn,unauthenticated=()=>{}}){
 const box=document.createElement('section');box.className='support-attachment-draft';box.setAttribute('aria-label','تصاویر آماده ارسال');
 const errorEl=document.createElement('div');errorEl.className='support-attachment-error';errorEl.setAttribute('role','alert');errorEl.hidden=true;
 const picker=document.createElement('input');picker.type='file';picker.accept='image/jpeg,image/png,image/webp';picker.multiple=true;picker.hidden=true;
 const button=document.createElement('button');button.type='button';button.className='support-attach-button';button.setAttribute('aria-label','افزودن تصویر');button.title='پیوست تصویر';button.hidden=true;const icon=document.createElement('span');icon.className='support-icon';icon.setAttribute('aria-hidden','true');icon.textContent='attach_file';button.append(icon);
 const progress=document.createElement('progress');progress.max=100;progress.hidden=true;progress.setAttribute('aria-label','پیشرفت بارگذاری');
 const cancel=document.createElement('button');cancel.type='button';cancel.textContent=role==='customer'?'×':'لغو بارگذاری';cancel.setAttribute('aria-label','لغو بارگذاری تصویر');cancel.className='support-upload-cancel';cancel.hidden=true;
 const stage=document.createElement('small');stage.className='support-image-stage';stage.setAttribute('role','status');stage.hidden=true;
 const card=role==='customer'?document.createElement('section'):null;if(card){card.className='support-upload-card';card.hidden=true;const details=document.createElement('div');details.className='support-upload-details';details.append(stage,progress);card.append(box,details,cancel);host.prepend(card);card.after(errorEl);host.append(picker);}else{host.prepend(box);box.after(errorEl,stage);host.append(picker,progress,cancel);}input.parentElement.prepend(button);
 const recoveryKey=()=>current()?.scope_id&&conversation()?'support-upload-interrupted:'+role+':'+current().scope_id+':'+conversation():null;
 const mark=value=>{try{const key=recoveryKey();if(key){if(value)sessionStorage.setItem(key,'1');else sessionStorage.removeItem(key);}}catch{}};
 let items=[],enabled=false,active=null,generation=0,limit=3;
 const validation=selectionToast(host);
 const showError=(msg,temporary=false)=>{if(temporary){errorEl.textContent='';errorEl.hidden=true;validation.show(msg);return;}errorEl.textContent=msg;errorEl.hidden=false;if(typeof warn==='function')warn(msg);};
 const clearError=()=>{errorEl.textContent='';errorEl.hidden=true;validation.clear();};
 const render=()=>{if(card)card.hidden=!items.length;const nodes=new Set(items.map(x=>x.node).filter(Boolean));for(const child of [...box.children])if(!nodes.has(child))child.remove();
  for(const item of items){if(!item.node){const wrap=document.createElement('span'),img=document.createElement('img'),remove=document.createElement('button');wrap.className='support-image-slot';wrap.dataset.state='preparing';img.decoding='async';img.width=54;img.height=54;img.alt='پیش‌نمایش تصویر انتخاب‌شده';img.hidden=true;img.onload=()=>{img.hidden=false;wrap.dataset.state='ready';if(item.state==='preparing')item.state='selected';render();};img.onerror=()=>{img.hidden=true;wrap.dataset.state='unavailable';if(item.state==='preparing')item.state='selected';render();};if(item.url)img.src=item.url;else{item.state='selected';wrap.dataset.state='unavailable';wrap.title='پیش‌نمایش در دسترس نیست';}remove.type='button';remove.setAttribute('aria-label','حذف تصویر');remove.title='حذف تصویر';remove.className='support-remove-image support-icon';remove.textContent='close';remove.onclick=()=>{if(active)return;clearError();if(item.url)URL.revokeObjectURL(item.url);items=items.filter(x=>x!==item);render();changed();};wrap.append(img,remove);item.node=wrap;}if(item.node.parentElement!==box)box.append(item.node);}
  stage.hidden=!items.length;stage.textContent=items.some(x=>x.state==='failed')?'بارگذاری تصویر انجام نشد؛ دوباره ارسال کنید.':items.some(x=>x.state==='uploading')?'در حال بارگذاری تصویر…':items.some(x=>x.state==='preparing')?'در حال آماده‌سازی تصویر…':items.every(x=>x.id)?'در انتظار تأیید ارسال…':'تصویر آمادهٔ ارسال است.';
 };

 const add=files=>{if(!enabled||active||!canSubmit())return;clearError();for(const file of files){if(items.length>=limit){showError('حداکثر سه تصویر در هر پیام مجاز است.',true);break;}if(!['image/jpeg','image/png','image/webp'].includes(file.type)||file.size>5*1024*1024){showError('تصویر JPEG، PNG یا WebP تا پنج مگابایت انتخاب کنید.',true);continue;}let url=null;try{url=URL.createObjectURL(file);}catch{}items.push({file,operation:uuid(),id:null,url,state:'preparing',node:null});}if(items.length)mark(true);render();changed();};
 // No capture override: mobile browsers may offer their native camera/gallery chooser.
 button.onclick=()=>{if(canSubmit())picker.click();};picker.onchange=()=>{add(picker.files);picker.value='';if(refocus)input.focus();};
 const pasted=event=>{const files=[...(event.clipboardData?.items||[])].filter(x=>x.kind==='file').map(x=>x.getAsFile()).filter(Boolean);if(files.length&&enabled){event.preventDefault();add(files);}};
 const dragged=event=>{if(enabled&&[...(event.dataTransfer?.types||[])].includes('Files'))event.preventDefault();};
 const dropped=event=>{if(enabled&&event.dataTransfer?.files.length){event.preventDefault();add(event.dataTransfer.files);}};
 input.addEventListener('paste',pasted);host.addEventListener('dragover',dragged);host.addEventListener('drop',dropped);
 const clear=()=>{mark(false);generation++;active?.abort();active=null;clearError();for(const item of items)if(item.url)URL.revokeObjectURL(item.url);items=[];render();progress.hidden=cancel.hidden=true;};cancel.onclick=()=>active?.abort();
 // Queue persistence may finish after the user selects a new draft image.
 // Consume only the uploaded selection that this particular message owns.
 const consume=ids=>{const used=new Set(ids);for(const item of items)if(used.has(item.id))if(item.url)URL.revokeObjectURL(item.url);items=items.filter(item=>!used.has(item.id));if(!items.length)mark(false);clearError();render();};
 async function upload(){
  if(active){showError('بارگذاری قبلی هنوز تمام نشده است؛ پیش‌نویس حفظ شده است.');throw Error('بارگذاری قبلی هنوز تمام نشده است؛ پیش‌نویس حفظ شده است.');}
  if(!canSubmit())throw Error('ارسال در این گفتگو اکنون ممکن نیست؛ متن و تصویر حفظ شدند.');
  const version=generation,target=conversation(),scope=current()?.scope_id;
  for(const item of items){if(!canSubmit())throw Error('ارسال در این گفتگو اکنون ممکن نیست؛ متن و تصویر حفظ شدند.');if(item.id)continue;if(!target||!scope){showError('نشست معتبر نیست؛ پیش‌نویس حفظ شده است.');throw Error('نشست معتبر نیست؛ پیش‌نویس حفظ شده است.');}
   item.state='uploading';render();const data=new FormData();data.append('scope',role);data.append('user_id',target);data.append('upload_id',item.operation);data.append('image',item.file);data.append('csrf_token',current().csrf_token||'');
   try{
    await new Promise((resolve,reject)=>{const xhr=new XMLHttpRequest();active=xhr;xhr.open('POST','support-attachment.php');xhr.withCredentials=true;xhr.timeout=30000;xhr.setRequestHeader('X-CSRF-Token',current().csrf_token||'');progress.hidden=true;progress.removeAttribute('value');cancel.hidden=false;
     xhr.upload.onprogress=e=>{if(e.lengthComputable&&e.total>0){progress.hidden=false;progress.value=e.loaded/e.total*100;}};xhr.onerror=xhr.ontimeout=xhr.onabort=()=>reject(Error('بارگذاری کامل نشد؛ تصویر و متن حفظ شده‌اند.'));
     xhr.onload=()=>{if(xhr.status===401&&role==='operator'){unauthenticated();return reject(Error('نشست شما پایان یافته است. دوباره وارد شوید.'));}if(version!==generation||target!==conversation()||scope!==current()?.scope_id)return reject(Error('هویت گفتگو تغییر کرده است.'));try{const r=JSON.parse(xhr.responseText);if(role==='operator'&&r.code==='conversation_owned'){ownershipConflict(target,r.reply_ownership);reject(Error(r.message||'این گفتگو در اختیار پشتیبان دیگری است.'));return;}if(xhr.status!==200||!/^([a-f0-9]{32})$/.test(r.id))throw Error();item.id=r.id;item.state='uploaded';render();resolve();}catch{reject(Error('تصویر پذیرفته نشد؛ محدودیت یا دسترسی را بررسی و دوباره تلاش کنید.'));}};xhr.send(data);
    }).finally(()=>{active=null;progress.hidden=cancel.hidden=true;});
   }catch(err){
    item.state='failed';render();
    showError(err?.message||'بارگذاری تصویر ناموفق بود.');
    throw err;
   }
  }return items.map(x=>x.id);
 }
 const available=globalThis.SupportWidgetConfig;const configuration=available&&typeof available.attachments==='boolean'&&Number.isInteger(available.max_images)?Promise.resolve(available):fetch('api.php?widget_config=1',{cache:'no-store'}).then(r=>r.json());configuration.then(c=>{enabled=c.attachments===true;limit=c.max_images;button.hidden=!enabled;}).catch(()=>{});
 return {recover(){try{if(!items.length&&sessionStorage.getItem(recoveryKey())==='1'){errorEl.textContent='انتخاب تصویر با تازه‌سازی متوقف شد؛ تصویر را دوباره انتخاب کنید. متن شما حفظ شده است.';errorEl.hidden=false;}}catch{}},upload,clear,consume,has:()=>items.length>0,destroy:()=>{clear();validation.destroy();enabled=false;input.removeEventListener('paste',pasted);host.removeEventListener('dragover',dragged);host.removeEventListener('drop',dropped);for(const el of [box,errorEl,stage,picker,button,progress,cancel,card].filter(Boolean))el.remove();}};
}
