// Account presentation over the existing operator authority. Never stores passwords.
export function profileSettings({api,current,updated,reauthenticate,warn,pending=()=>false}){
 const $=id=>document.getElementById(id);let profile=null,directoryEpoch=0,loadEpoch=0;
 const saved=panel=>panel.dispatchEvent(new CustomEvent('support-form-saved',{bubbles:true}));
 const report=e=>{const host=$('settings').open?($('selfProfile').closest('section').hidden?$('settingsFeedback'):$('profileFeedback')):null;if(host){host.textContent=e.message||'ذخیره انجام نشد.';host.setAttribute('role','alert');}else warn(e);};
 const field=(label,value,{type='text',max=120}={})=>{const wrap=document.createElement('label'),input=document.createElement('input');wrap.append(document.createTextNode(label),input);input.type=type;input.value=value;input.maxLength=max;return {wrap,input};};
 const button=(label,run)=>{const b=document.createElement('button');b.type='button';b.textContent=label;b.onclick=async()=>{b.disabled=true;try{await run();}catch(e){report(e);}finally{b.disabled=false;}};return b;};
 const avatar=(host,p)=>{host.replaceChildren();const meta=p.public_profile?.avatar;if(meta){const img=document.createElement('img');img.alt='تصویر '+p.name;img.src='operator-avatar.php?scope=operator&id='+meta.id;img.onerror=()=>host.replaceChildren(document.createTextNode(p.name.trim().slice(0,1)||'پ'));host.append(img);}else host.textContent=p.name.trim().slice(0,1)||'پ';};
 const upload=async(id,file)=>{const data=new FormData();data.set('id',id);data.set('upload_id',crypto.randomUUID());data.set('image',file);return api('avatar',data);};
 const remove=id=>{const data=new FormData();data.set('id',id);data.set('remove','1');return api('avatar',data);};
 const feedback=text=>{$('profileFeedback').setAttribute('role','status');$('profileFeedback').textContent=text;};
 function showSelf(p){if(p.id!==current()?.id)return;profile=p;$('menuName').textContent=p.name;avatar($('menuAvatar'),p);if(!pending($('selfProfile'))){$('selfName').value=p.name;$('selfPublic').checked=p.public_profile.enabled;}avatar($('selfAvatar'),p);$('removeSelfAvatar').disabled=!p.public_profile.avatar;}
 async function load(){const version=++loadEpoch,id=current()?.id,fields=['selfName','selfPublic','saveSelfProfile','selfAvatarFile','chooseSelfAvatar','removeSelfAvatar'];$('selfProfile').setAttribute('aria-busy','true');for(const field of fields)$(field).disabled=true;try{const p=(await api('profile')).profile;if(version===loadEpoch&&id===current()?.id)showSelf(p);}finally{if(version===loadEpoch&&id===current()?.id){$('selfProfile').setAttribute('aria-busy','false');for(const field of fields)$(field).disabled=profile?.id!==id;$('removeSelfAvatar').disabled=!profile?.public_profile.avatar;}}}
 async function directory(){
  if(pending($('accounts')))return;
  const version=++directoryEpoch;$('accounts').setAttribute('aria-busy','true');$('accounts').textContent='دریافت حساب‌ها…';let rows;try{rows=(await api('operator_accounts')).accounts;}catch(e){if(version===directoryEpoch){$('accounts').textContent='دریافت حساب‌ها انجام نشد.';$('accounts').setAttribute('aria-busy','false');}throw e;}if(version!==directoryEpoch||current()?.role!=='administrator')return;$('accounts').replaceChildren();
  for(const p of rows){
   const card=document.createElement('details');card.className='account-card';const heading=document.createElement('summary'),icon=document.createElement('span'),copy=document.createElement('span');icon.className='account-avatar';avatar(icon,p);copy.textContent=p.name+' · '+(p.role==='administrator'?'مدیر':'پشتیبان')+(p.active?'':' · غیرفعال');heading.append(icon,copy);card.append(heading);
   const identity=document.createElement('small');identity.textContent=p.username;identity.dir='ltr';card.append(identity);
   const name=field('نام نمایشی',p.name);name.input.required=true;
   const role=document.createElement('select');role.setAttribute('aria-label','نقش '+p.name);for(const [v,t] of [['support','پشتیبان'],['administrator','مدیر']])role.add(new Option(t,v));role.value=p.role;
   const active=field('حساب فعال','',{type:'checkbox'});active.input.checked=p.active;
   const publish=field('نمایش نام و تصویر برای مشتری','',{type:'checkbox'});publish.input.checked=p.public_profile.enabled;
   role.disabled=true;active.input.disabled=true;card.append(name.wrap,role,active.wrap,publish.wrap,button('ذخیرهٔ حساب',async()=>{const r=await api('account_update',{id:p.id,name:name.input.value,role:role.value,active:active.input.checked?'1':'0',public:publish.input.checked?'1':'0'});if(r.reauthenticate)return reauthenticate();saved(card);await directory();await updated();}));
   const picker=document.createElement('input');picker.type='file';picker.accept='image/jpeg,image/png,image/webp';picker.setAttribute('aria-label','تصویر '+p.name);picker.onchange=async()=>{if(!picker.files[0])return;picker.disabled=true;try{await upload(p.id,picker.files[0]);await directory();if(p.id===current().id)await load();}catch(e){report(e);}finally{picker.value='';picker.disabled=false;}};
   picker.hidden=true;card.append(button('تغییر تصویر',()=>picker.click()),picker,button('حذف تصویر',async()=>{await remove(p.id);await directory();if(p.id===current().id)await load();}));
   if(p.id!==current().id){const manage=document.createElement('a');manage.href='/admin/staff';manage.textContent='مدیریت دسترسی و گذرواژه';card.append(manage);}
   $('accounts').append(card);
  }
  $('accounts').setAttribute('aria-busy','false');
 }
 $('selfProfile').onsubmit=async event=>{event.preventDefault();const submit=$('saveSelfProfile');submit.disabled=true;try{const r=await api('profile_save',{name:$('selfName').value,public:$('selfPublic').checked?'1':'0'});showSelf(r.profile);await updated(r.profile);feedback('پروفایل ذخیره شد.');}catch(e){report(e);}finally{submit.disabled=false;}};
 $('chooseSelfAvatar').onclick=()=>$('selfAvatarFile').click();
 $('selfAvatarFile').onchange=async()=>{const picker=$('selfAvatarFile');if(!picker.files[0]||!profile)return;picker.disabled=true;try{showSelf((await upload(profile.id,picker.files[0])).profile);feedback('تصویر پروفایل تغییر کرد.');}catch(e){report(e);}finally{picker.value='';picker.disabled=false;}};
 $('removeSelfAvatar').onclick=async()=>{try{showSelf((await remove(profile.id)).profile);feedback('تصویر پروفایل حذف شد.');}catch(e){report(e);}};
 $('selfPassword').onsubmit=event=>{event.preventDefault();location.href='/admin/account/password';};
 $('changeSelfPassword').textContent='تغییر گذرواژه مدیریت';
 for(const id of ['currentSelfPassword','newSelfPassword']){const field=$(id);field.required=false;field.closest('label').hidden=true;}
 return {load,directory,clear(){directoryEpoch++;loadEpoch++;profile=null;$('selfName').value='';$('selfPublic').checked=false;$('selfAvatarFile').value='';$('accountPassword').value='';$('currentSelfPassword').value=$('newSelfPassword').value='';$('selfAvatar').replaceChildren();$('accounts').replaceChildren();}};
}
