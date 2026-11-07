// Reuse the existing private content authority and optimistic revision check.
export function widgetSettings({host,token,current,config}){
 let loaded=false,state=null,busy=false;
 async function request(data){const r=await fetch('support-content-admin.php',{cache:'no-store',credentials:'same-origin',...(data?{method:'POST',headers:{'X-CSRF-Token':token()},body:new URLSearchParams(data)}:{})});const b=await r.json();if(!r.ok)throw Error(r.status===409?'تنظیمات هم‌زمان تغییر کرده است؛ پنجره را دوباره باز کنید.':'دریافت یا ذخیرهٔ تنظیمات مجاز نیست.');return b;}
 return {async load(){
  if(current()?.role!=='administrator'||loaded||busy)return;busy=true;
  const status=document.createElement('p');status.setAttribute('role','status');host.replaceChildren(status);status.textContent='دریافت تنظیمات…';
  try{state=await request();const form=document.createElement('form');form.id='widgetPresentation';const fields={site_title:['نام پشتیبانی',120],welcome:['پیام خوشامد',1000],availability:['توضیح دسترس‌پذیری',120],color:['رنگ نشان (فقط ویجت قدیمی)',7]};const inputs={};
   for(const [key,[text,max]]of Object.entries(fields)){const label=document.createElement('label'),input=document.createElement(key==='welcome'?'textarea':'input');input.name=key;input.maxLength=max;input.required=true;input.value=state.settings[key];if(key==='color'){input.pattern='#[0-9a-fA-F]{6}';input.dir='ltr';}label.append(text,input);form.append(label);inputs[key]=input;}
   const readonly=document.createElement('small');readonly.textContent='رنگ V2 آبی ثابت است. اطلاعات تماس مشتری اختیاری است.';const save=document.createElement('button');save.type='submit';save.textContent='ذخیرهٔ تنظیمات ویجت';save.className='primary';form.append(readonly,save);
   host.append(form);loaded=true;status.textContent='';
   form.onsubmit=async event=>{event.preventDefault();save.disabled=true;try{const values={...state.settings};for(const [key,input]of Object.entries(inputs))values[key]=input.value;const result=await request({...values,knowledge_visible:values.knowledge_visible?'1':'0',action:'presentation_save',revision:String(state.revision)});state.settings=values;state.revision=result.revision;form.dispatchEvent(new CustomEvent('support-form-saved',{bubbles:true}));status.textContent='تنظیمات ذخیره شد؛ صفحهٔ تازه تنظیمات جدید را می‌گیرد.';}catch(e){status.textContent=e.message;}finally{save.disabled=false;}};
  }catch(e){status.textContent=e.message;}finally{busy=false;}
 },clear(){loaded=false;state=null;host.replaceChildren();}};
}
