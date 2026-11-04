import {recoveryRequest} from './recovery.js?v=0.1.0-r14';
export function phoneControls(parent,{current,conversation,beforeSwitch,restart,warn,done=()=>{}}){
    const panel=document.createElement('details');panel.className='support-phone-recovery';panel.hidden=true;
    const title=document.createElement('summary');title.textContent='بازیابی با شماره همراه';
    const help=document.createElement('p');help.textContent='فقط گفتگوهای متصل به شمارهٔ تأییدشده بازیابی می‌شوند.';
    const policy=document.createElement('details'),policyTitle=document.createElement('summary'),policyText=document.createElement('small');policyTitle.textContent='دربارهٔ شماره همراه';policyText.textContent='دارندگان شمارهٔ مشترک به گفتگوهای متصل دسترسی دارند. پیش از واگذاری سیم‌کارت، ارتباط را حذف کنید. تأیید شماره پس از ۳۰ روز نیاز به تمدید دارد.';policy.append(policyTitle,policyText);
    const number=document.createElement('input');number.type='tel';number.inputMode='tel';number.autocomplete='tel';number.maxLength=30;number.placeholder='شماره موبایل';number.setAttribute('aria-label','شماره موبایل برای بازیابی');
    const link=document.createElement('button');link.type='button';link.textContent='تأیید شماره همراه';
    const recover=document.createElement('button');recover.type='button';recover.textContent='بازیابی گفتگوهای قبلی';
    const code=document.createElement('input');code.type='text';code.inputMode='numeric';code.autocomplete='one-time-code';code.maxLength=6;code.placeholder='کد پیامک‌شده را وارد کنید';code.setAttribute('aria-label','کد پیامک‌شده');code.hidden=true;
    const verify=document.createElement('button');verify.type='button';verify.textContent='تأیید کد';verify.hidden=true;
    const unlink=document.createElement('button');unlink.type='button';unlink.textContent='حذف ارتباط شماره همین گفتگو';
    const listButton=document.createElement('button');listButton.type='button';listButton.textContent='نمایش گفتگوهای بازیابی‌شده';listButton.hidden=true;
    const list=document.createElement('div');list.setAttribute('aria-label','گفتگوهای قابل بازیابی');
    const status=document.createElement('p');status.setAttribute('role','status');
    panel.append(title,help,number,link,recover,code,verify,unlink,listButton,list,status,policy);parent.prepend(panel);
    let busy=false,available=false,enabled=false,challenge=null,operation=null,lastConversation=null;
    const run=async fn=>{if(busy)return;busy=true;refresh();try{await fn();}catch(e){warn(e.message);}finally{busy=false;refresh();}};
    const resetChallenge=()=>{challenge=operation=null;code.value='';code.hidden=verify.hidden=true;};
    number.addEventListener('input',resetChallenge);
    async function begin(purpose){
        if(!number.value.trim())throw new Error('شماره موبایل را وارد کنید.');
        const id=purpose==='link'?conversation():'';if(purpose==='link'&&!id)return;
        if(challenge&&challenge.purpose===purpose){if(Date.now()-challenge.at<60000)throw new Error('برای ارسال دوباره یک دقیقه صبر کنید.');operation=null;}
        if(purpose==='link'&&!confirm('این شماره برای بازیابی همین گفتگو استفاده می‌شود. اگر شماره قبلی متصل باشد، جایگزین خواهد شد. ادامه؟'))return;
        if(!operation||operation.purpose!==purpose||operation.id!==id)operation={purpose,id,key:crypto.randomUUID()};
        const r=await recoveryRequest({phone_action:'begin',purpose,phone:number.value,user_id:id,operation_id:operation.key});
        challenge={id:r.challenge,purpose,at:Date.now()};code.hidden=verify.hidden=false;code.focus();status.textContent='درخواست ارسال کد ثبت شد. در صورت دریافت، کد را تا پنج دقیقه وارد کنید. برای ارسال دوباره حداقل یک دقیقه صبر کنید.';
    }
    link.onclick=()=>run(()=>begin('link'));recover.onclick=()=>run(()=>begin('recover'));
    async function showList(){
        const r=await recoveryRequest({phone_action:'list'});list.replaceChildren();
        for(const c of r.conversations){const button=document.createElement('button');button.type='button';button.textContent=`ادامه گفتگو: ${c.name} — ${c.created_at}`;
            button.onclick=()=>run(async()=>{if(!beforeSwitch())return;try{await recoveryRequest({phone_action:'select',user_id:c.id});await restart(true);status.textContent='گفتگو بازیابی شد.';done();}catch(e){await restart(false);throw e;}});list.append(button);}
        if(!r.conversations.length)status.textContent='شماره تأیید شد؛ گفتگوی متصل و معتبری برای بازیابی وجود ندارد.';
    }
    verify.onclick=()=>run(async()=>{
        if(!challenge)return;const switching=challenge.purpose==='recover';
        if(switching&&(!confirm('نشست فعلی عوض می‌شود و صف ارسال آن منتقل نمی‌شود. ادامه؟')||!beforeSwitch()))return;
        try{await recoveryRequest({phone_action:'verify',purpose:challenge.purpose,challenge:challenge.id,code:code.value});resetChallenge();
            if(switching){await restart(true);await showList();}else status.textContent='شماره برای بازیابی همین گفتگو تأیید شد.';
        }catch(e){if(switching)await restart(false);throw e;}
    });
    unlink.onclick=()=>run(async()=>{if(!conversation()||!confirm('ارتباط شماره و دسترسی‌های پیامکی همین گفتگو حذف شوند؟'))return;
        await recoveryRequest({phone_action:'unlink',user_id:conversation()});resetChallenge();list.replaceChildren();await restart(false);status.textContent='ارتباط شماره حذف شد.';});
    listButton.onclick=()=>run(showList);
    function refresh(){
        panel.hidden=!enabled;number.hidden=recover.hidden=!available;link.hidden=unlink.hidden=!available||!conversation();
        if(enabled&&!available)status.textContent='بازیابی پیامکی فعلاً فعال نیست؛ می‌توانید گفتگو را عادی شروع کنید.';
        link.disabled=unlink.disabled=busy||!conversation();recover.disabled=verify.disabled=listButton.disabled=busy;number.disabled=busy;code.disabled=busy;
        listButton.hidden=!available||current()?.phone_recovery_session!==true;
        if(conversation()!==lastConversation){lastConversation=conversation();list.replaceChildren();resetChallenge();}
    }
    fetch('api.php?recovery_availability=1',{credentials:'same-origin',cache:'no-store'}).then(r=>r.json()).then(r=>{enabled=r.phone_enabled===true;available=r.phone_available===true;refresh();}).catch(()=>{});
    refresh();return {refresh,prefill:value=>{if(!number.value)number.value=value;}};
}
