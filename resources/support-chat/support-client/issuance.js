import {recoveryRequest} from './recovery.js?v=0.1.0-r14';

// Bearer code lives only in this closure/readonly field, never ChatEngine or storage.
export function issuanceControls({region,menu,current,conversation,email,warn,clearWarning=()=>{},activatedCode=()=>{}}){
    const action=document.createElement('button');action.type='button';action.textContent='کد بازیابی گفتگو';action.hidden=true;menu.body.prepend(action);
    const card=document.createElement('section');card.className='support-secret-card';card.hidden=true;card.setAttribute('aria-label','کد بازیابی گفتگوی شما');card.setAttribute('role','note');
    const title=document.createElement('strong');title.textContent='کد بازیابی گفتگوی شما';
    const help=document.createElement('p');help.textContent='برای دسترسی به این گفتگو در آینده، کد را در جای امنی نگه دارید.';
    const status=document.createElement('p');status.setAttribute('role','status');
    const field=document.createElement('input');field.type='password';field.readOnly=true;field.autocomplete='off';field.spellcheck=false;field.setAttribute('aria-label','کد آمادهٔ بازیابی');field.dir='ltr';
    const show=document.createElement('button');show.type='button';show.textContent='نمایش کد';
    const copy=document.createElement('button');copy.type='button';copy.textContent='کپی کد';
    const savedLabel=document.createElement('label'),saved=document.createElement('input');saved.type='checkbox';savedLabel.append(saved,document.createTextNode('کد را در جای امن ذخیره کردم'));
    const confirm=document.createElement('button');confirm.type='button';confirm.textContent='فعال کردن کد';confirm.disabled=true;
    const dismiss=document.createElement('button');dismiss.type='button';dismiss.textContent='بعداً ادامه می‌دهم';
    const emailButton=document.createElement('button');emailButton.type='button';emailButton.textContent='ارسال نسخه به ایمیل';emailButton.hidden=true;
    const mail=document.createElement('fieldset');mail.hidden=true;const legend=document.createElement('legend');legend.textContent='نسخهٔ خصوصی در ایمیل';
    const mailHelp=document.createElement('small');mailHelp.textContent='ابتدا ایمیل را تأیید کنید. نسخهٔ ایمیلی هم دسترسی به گفتگو می‌دهد؛ فقط صندوق خصوصی خودتان را انتخاب کنید.';
    const address=document.createElement('input');address.type='email';address.autocomplete='email';address.maxLength=254;address.setAttribute('aria-label','ایمیل دریافت نسخهٔ بازیابی');
    const sendVerification=document.createElement('button');sendVerification.type='button';sendVerification.textContent='ارسال کد ایمیل';
    const mailCode=document.createElement('input');mailCode.inputMode='numeric';mailCode.autocomplete='one-time-code';mailCode.maxLength=6;mailCode.setAttribute('aria-label','کد تأیید ایمیل');mailCode.hidden=true;
    const verify=document.createElement('button');verify.type='button';verify.textContent='تأیید ایمیل';verify.hidden=true;
    const backup=document.createElement('button');backup.type='button';backup.textContent='ارسال کد بازیابی به ایمیل';backup.hidden=true;
    mail.append(legend,mailHelp,address,sendVerification,mailCode,verify,backup);card.append(title,help,status,field,show,copy,savedLabel,confirm,emailButton,mail,dismiss);region.append(card);
    let available=false,mailAvailable=false,busy=false,token='',operation=null,id=null,active=false,mailOperation=null,challenge=null,backupOperation=null,mailSentAt=0,generation=0,boundScope=null;
    const stale=()=>Object.assign(new Error('گفتگو تغییر کرده است.'),{code:'identity_changed'});
    const request=async(kind,data={})=>{
        const version=generation,target=id,scope=boundScope;
        if(!target||target!==conversation()||scope!==current()?.scope_id)throw stale();
        const result=await recoveryRequest({recovery_code_action:kind,user_id:target,...data});
        if(version!==generation||target!==conversation()||scope!==current()?.scope_id)throw stale();return result;
    };
    const clear=()=>{generation++;boundScope=null;token='';field.value='';field.type='password';show.textContent='نمایش کد';operation=null;active=false;saved.checked=false;mailCode.value='';address.value='';mail.hidden=true;mailCode.hidden=verify.hidden=backup.hidden=true;challenge=mailOperation=backupOperation=null;card.hidden=true;};
    const repaint=()=>{field.hidden=show.hidden=copy.hidden=!token;savedLabel.hidden=confirm.hidden=active||!token;confirm.disabled=busy||!saved.checked;emailButton.hidden=!active||!token||!mailAvailable;dismiss.textContent=!token?'بستن':active?'متوجه شدم':'بعداً ادامه می‌دهم';};
    const run=async fn=>{if(busy)return;busy=true;for(const b of card.querySelectorAll('button'))b.disabled=true;try{await fn();clearWarning();}catch(e){if(e.code!=='identity_changed')warn(e.message);}finally{busy=false;for(const b of card.querySelectorAll('button'))b.disabled=false;repaint();}};
    const activated=()=>{active=true;status.textContent='کد فعال است. پس از بستن این کارت، نمایش دوبارهٔ کد ممکن نیست.';repaint();activatedCode();};
    action.onclick=()=>run(async()=>{
        const selected=conversation();if(id!==selected||boundScope!==current()?.scope_id){clear();id=selected;boundScope=current()?.scope_id;}
        const r=await request('status');
        if(r.state==='active'&&token&&r.operation===operation){activated();card.hidden=false;menu.close();return;}
        if(['active','revoked','existing'].includes(r.state)||!r.may_prepare){clear();id=selected;card.hidden=false;status.textContent=r.state==='revoked'?'کد این گفتگو باطل شده است.':'کد قبلی از این صفحه قابل بازیابی نیست؛ از نسخهٔ ذخیره‌شده یا شمارهٔ تأییدشده استفاده کنید.';menu.close();repaint();return;}
        operation=r.operation||operation||crypto.randomUUID();
        const prepared=await request('prepare',{operation_id:operation});if(token!==prepared.recovery_secret)saved.checked=false;token=prepared.recovery_secret;field.value=token;active=false;status.textContent='کد آماده است و هنوز فعال نیست. آن را ذخیره و سپس فعال کنید.';card.hidden=false;menu.close();repaint();
    });
    saved.onchange=repaint;
    show.onclick=()=>{field.type=field.type==='password'?'text':'password';show.textContent=field.type==='password'?'نمایش کد':'پنهان کردن کد';};
    copy.onclick=()=>run(async()=>{try{await navigator.clipboard.writeText(token);status.textContent=active?'کد فعال کپی شد.':'کد کپی شد؛ هنوز باید آن را فعال کنید.';}catch{field.focus();field.select();status.textContent='کد را انتخاب و کپی کنید؛ آن را خصوصی نگه دارید.';}});
    confirm.onclick=()=>run(async()=>{
        if(!saved.checked||!token)return;
        try{await request('confirm',{operation_id:operation,recovery_secret:token,acknowledged:'1'});activated();}
        catch(e){const r=await request('status').catch(()=>null);if(r?.state==='active'&&r.operation===operation)activated();else {status.textContent='فعال‌سازی تأیید نشد؛ کد را فعال ندانید و دوباره بررسی کنید.';throw e;}}
    });
    dismiss.onclick=()=>clear();emailButton.onclick=()=>{mail.hidden=!mail.hidden;if(!mail.hidden){if(!address.value)address.value=email()||'';address.focus();}};
    address.oninput=()=>{mailOperation=challenge=backupOperation=null;mailCode.value='';mailCode.hidden=verify.hidden=backup.hidden=true;};
    sendVerification.onclick=()=>run(async()=>{if(challenge){if(Date.now()-mailSentAt<60000)throw new Error('برای ارسال دوبارهٔ ایمیل یک دقیقه صبر کنید.');mailOperation=null;}mailOperation??=crypto.randomUUID();const r=await request('email_begin',{operation_id:mailOperation,email:address.value});challenge=r.challenge;mailSentAt=Date.now();mailCode.hidden=verify.hidden=false;mailCode.focus();status.textContent='درخواست کد ایمیل ثبت شد؛ در صورت دریافت، آن را تا پنج دقیقه وارد کنید.';});
    verify.onclick=()=>run(async()=>{try{await request('email_verify',{challenge,code:mailCode.value});}catch(e){const r=await request('email_status',{challenge}).catch(()=>null);if(r?.verified!==true)throw e;}mailCode.value='';mailCode.hidden=verify.hidden=true;backup.hidden=false;status.textContent='ایمیل تأیید شد. اکنون می‌توانید نسخهٔ همین کد فعال را درخواست کنید.';});
    backup.onclick=()=>run(async()=>{backupOperation??=crypto.randomUUID();await request('email_backup',{challenge,operation_id:backupOperation,recovery_secret:token});status.textContent='درخواست نسخهٔ پشتیبان ثبت شد؛ این پیام تضمین دریافت ایمیل نیست.';});
    function refresh(){
        if(id&&id!==conversation()){clear();id=null;}
        action.hidden=!available||!conversation()||current()?.can_issue_recovery_code===false;
    }
    fetch('api.php?recovery_availability=1',{credentials:'same-origin',cache:'no-store'}).then(r=>r.json()).then(r=>{available=r.code_issuance_enabled===true;mailAvailable=r.email_available===true;refresh();}).catch(()=>{});
    return {refresh,clear};
}
