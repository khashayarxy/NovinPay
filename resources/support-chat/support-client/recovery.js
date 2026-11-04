// Existing bearer credentials stay in memory and never enter URLs or outbox.
export async function recoveryRequest(data){
    const auth=await fetch('api.php?get_recovery_context=1',{credentials:'same-origin',cache:'no-store'});const a=await auth.json();
    if(!auth.ok||typeof a.token!=='string')throw new Error('نشست بازیابی در دسترس نیست.');
    const body=new FormData();for(const [key,value]of Object.entries(data))body.append(key,value);body.append('csrf_token',a.token);
    const response=await fetch('api.php',{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'X-CSRF-Token':a.token},body});const result=await response.json();
    if(!response.ok||result.status!=='success'){
        const messages={phone_invalid:'شماره موبایل معتبر ایرانی وارد کنید.',otp_invalid:'کد معتبر نیست یا مهلت آن تمام شده است.',phone_rate_limited:'برای ارسال دوباره کمی صبر کنید؛ تعداد تلاش‌ها محدود است.',sms_unavailable:'پیامک فعلاً در دسترس نیست؛ گفتگو همچنان کار می‌کند.',phone_link_changed:'ارتباط شماره تغییر کرده است؛ دوباره اقدام کنید.',original_session_required:'برای تغییر شماره از مرورگر اصلی یا کلید خصوصی همان گفتگو استفاده کنید.'};
        Object.assign(messages,{code_already_issued:'کد معتبر قبلی حفظ شده است؛ تعویض کد در دسترس نیست.',code_prepare_conflict:'یک کد در حال آماده‌سازی است؛ همان مرحله را ادامه دهید.',code_prepare_expired:'مهلت آماده‌سازی تمام شده است؛ دوباره درخواست کنید.',email_unavailable:'ارسال ایمیل فعلاً در دسترس نیست؛ گفتگو و کد شما حفظ شده‌اند.',email_invalid:'ایمیل معتبر وارد کنید.',email_verification_invalid:'کد ایمیل معتبر نیست یا مهلت آن تمام شده است.'});
        const error=new Error(messages[result.code]||(response.status===429?'تلاش زیاد است؛ بعداً امتحان کنید.':'بازیابی تأیید نشد؛ کد یا دسترسی معتبر نیست.'));error.code=result.code;error.status=response.status;throw error;
    }return result;
}
export function recoveryControls(parent,{current,conversation,beforeSwitch,restart,warn,done=()=>{}}) {
    const panel=document.createElement('details');panel.className='support-secret-recovery';panel.hidden=true;
    const title=document.createElement('summary');title.textContent='بازیابی با کد ذخیره‌شده';
    const help=document.createElement('p');help.textContent='کد ذخیره‌شدهٔ همین گفتگو را وارد کنید.';
    const input=document.createElement('input');input.type='password';input.autocomplete='off';input.maxLength=80;input.spellcheck=false;input.setAttribute('aria-label','کد بازیابی گفتگو');input.placeholder='کد بازیابی گفتگو';
    const recover=document.createElement('button');recover.type='button';recover.textContent='بازیابی با کد';
    const revoke=document.createElement('button');revoke.type='button';revoke.textContent='باطل کردن کلید و نشست‌های بازیابی';revoke.hidden=true;
    const status=document.createElement('p');status.setAttribute('role','status');
    panel.append(title,help,input,recover,revoke,status);parent.append(panel);let busy=false,available=false,credential=false,checked=null;
    const run=async fn=>{if(busy)return;busy=true;refresh();try{await fn();}catch(e){warn(e.message);}finally{busy=false;refresh();}};
    recover.onclick=()=>run(async()=>{
        if(!input.value.trim())throw new Error('کلید خصوصی لازم است.');
        if(!confirm('نشست فعلی عوض می‌شود و صف ارسال آن منتقل نمی‌شود. ادامه؟')||!beforeSwitch())return;
        try{await recoveryRequest({secret_action:'recover',recovery_secret:input.value.trim()});input.value='';status.textContent='گفتگو بازیابی شد.';await restart(true);done();}catch(e){await restart(false);throw e;}
    });
    revoke.onclick=()=>run(async()=>{
        if(!conversation()||!confirm('کلید و نشست‌های بازیابی با کلید همین گفتگو باطل شوند؟'))return;
        const id=conversation();if(!beforeSwitch())return;
        try{await recoveryRequest({secret_action:'revoke',user_id:id});await restart(false);status.textContent='کد و دسترسی‌های آن باطل شدند.';done();}catch(e){await restart(false);throw e;}
    });
    function refresh(force=false){panel.hidden=current()?current().secret_recovery_enabled!==true:!available;revoke.hidden=!credential||!conversation();revoke.disabled=busy||!conversation();recover.disabled=busy;
        const key=conversation()+'|'+current()?.scope_id;if(!panel.hidden&&conversation()&&(force||key!==checked)){checked=key;credential=false;revoke.hidden=true;
            recoveryRequest({secret_action:'status',user_id:conversation()}).then(r=>{if(checked===key){credential=r.active===true;revoke.hidden=!credential;}}).catch(()=>{});}
    }
    fetch('api.php?recovery_availability=1',{credentials:'same-origin',cache:'no-store'}).then(r=>r.json()).then(r=>{available=r.enabled===true;refresh();}).catch(()=>{});
    refresh();return {refresh};
}
