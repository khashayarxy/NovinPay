// System Health & Security module for NovinPay Operator Admin Panel V3
export function healthView({host,api,currentOperator,warn}){
 let loading=false;
 const number=(n,digits=0)=>new Intl.NumberFormat('fa-IR',{maximumFractionDigits:digits}).format(n);
 function readable(host,value){
  const text=String(value??'').replaceAll('authority','وضعیت ثبت‌شده').replaceAll('metadata','اطلاعات ثبت‌شده').replaceAll('deletion ledger','دفتر حذف').replaceAll('store','مخزن داده').replaceAll('intent','درخواست').replaceAll('worker','پردازشگر').replaceAll('claim/result','نتیجهٔ پردازش');
  for(const part of text.split(/([A-Za-z0-9][A-Za-z0-9_.:/-]*(?: [A-Za-z0-9_.:/-]+)*)/g)){
   if(/^[A-Za-z0-9]/.test(part)){const bdi=document.createElement('bdi');bdi.dir='ltr';bdi.textContent=part;host.append(bdi);}else host.append(document.createTextNode(part));
  }
 }

 function buildUI(){
  host.replaceChildren();

  const wrap=document.createElement('div');
  wrap.className='health-container';

  // Header
  const header=document.createElement('div');
  header.className='health-header';
  const headingBox=document.createElement('div');
  const h2=document.createElement('h2');
  h2.textContent='سلامت، امنیت و پایداری سیستم';
  const p=document.createElement('p');
  p.textContent='پایش سلامت ذخیره‌سازی، ظرفیت‌ها، آمادگی ارسال نوتیفیکیشن و پارامترهای امنیتی.';
  headingBox.append(h2,p);

  const checkBtn=document.createElement('button');
  checkBtn.type='button';
  checkBtn.className='primary';
  checkBtn.innerHTML='<span class="support-icon">health_and_safety</span><span>بررسی سلامت سیستم</span>';
  checkBtn.onclick=()=>runDiagnostics();
  header.append(headingBox,checkBtn);
  wrap.append(header);

  // Results Grid
  const resultsGrid=document.createElement('div');
  resultsGrid.className='health-results-grid';
  resultsGrid.id='healthResultsGrid';
  wrap.append(resultsGrid);

  host.append(wrap);
 }

 async function runDiagnostics(){
  if(loading||currentOperator()?.role!=='administrator')return;
  loading=true;
  const grid=document.getElementById('healthResultsGrid');
  if(grid)grid.innerHTML='<div class="health-loading"><span class="support-icon spinning">sync</span><p>در حال سنجش مولفه‌ها و بررسی فایل‌های ذخیره‌سازی…</p></div>';

  try{
   const [healthRes,readinessRes]=await Promise.all([
    api('health').catch(e=>({error:e.message})),
    api('readiness').catch(e=>({error:e.message}))
   ]);

   if(!grid)return;
   grid.replaceChildren();

   // Card 1: Storage Health
   const storageCard=document.createElement('div');
   storageCard.className='admin-card health-card';
   storageCard.innerHTML=`
    <div class="health-card-header">
     <div class="health-card-title">
      <span class="support-icon">inventory_2</span>
      <h3>سلامت ذخیره‌سازی و تراکنش‌ها</h3>
     </div>
     <span class="badge badge-success">بررسی‌شده</span>
    </div>
   `;

   const metrics=healthRes.metrics||[];
   const metricList=document.createElement('div');
   metricList.className='health-metrics-list';

   if(healthRes.error){
    const pErr=document.createElement('p');
    pErr.className='health-error-msg';
    pErr.textContent='خطا در دریافت سلامت: '+healthRes.error;
    metricList.replaceChildren(pErr);
   }else{
    for(const m of metrics){
     const row=document.createElement('div');
     const healthState=({healthy:'ok',ok:'ok',warning:'warning',error:'error',unknown:'unknown'})[String(m.state||'unknown').toLowerCase()]||'unknown';row.className='health-metric-row state-'+healthState;
     const stateIcon=healthState==='ok'?'check_circle':healthState==='warning'?'warning':healthState==='error'?'error':'help_outline';

     const mInfo=document.createElement('div');
     mInfo.className='metric-info';
     const sIcon=document.createElement('span');
     sIcon.className='support-icon';
     sIcon.textContent=stateIcon;
     const strong=document.createElement('strong');
     readable(strong,m.label);
     const small=document.createElement('small');
     readable(small,m.note);
     mInfo.append(sIcon,strong,small);

     const mValues=document.createElement('div');
     mValues.className='metric-values';
     if(Number.isFinite(m.bytes)){
      const codeBytes=document.createElement('code');
      codeBytes.textContent=Number.isFinite(m.limit)?`${number(m.bytes/1048576,2)} از ${number(m.limit/1048576,2)} مگابایت`:`${number(m.bytes/1048576,2)} مگابایت`;
      mValues.append(codeBytes);
     }
     if(Number.isFinite(m.files)){
      const codeFiles=document.createElement('code');
      codeFiles.textContent=`${number(m.files)} فایل`;
      mValues.append(codeFiles);
     }
     for(const [field,label]of [['pending','در انتظار'],['failed','ناموفق']])if(Number.isFinite(m[field])){const value=document.createElement('span');value.textContent=`${label}: ${number(m[field])}`;mValues.append(value);}
     const badge=document.createElement('span');
     badge.className='badge badge-'+(healthState==='ok'?'success':healthState==='error'?'urgent':healthState==='warning'?'warning':'neutral');
     badge.textContent=({ok:'سالم',warning:'هشدار',error:'خطا',unknown:'نامشخص'})[healthState];
     mValues.append(badge);

     row.append(mInfo,mValues);
     metricList.append(row);
    }
   }
   storageCard.append(metricList);
   grid.append(storageCard);

   // Card 2: Push & Notification Readiness
   const pushCard=document.createElement('div');
   pushCard.className='admin-card health-card';
   pushCard.innerHTML=`
    <div class="health-card-header">
     <div class="health-card-title">
      <span class="support-icon">notifications_active</span>
      <h3>آمادگی اعلان‌های پس‌زمینه (Push & SMS)</h3>
     </div>
     <span class="badge badge-${readinessRes.vapid?.ready?'success':'normal'}">${readinessRes.vapid?.ready?'آماده':'نیازمند پیکربندی'}</span>
    </div>
   `;

   const pushList=document.createElement('div');
   pushList.className='health-metrics-list';

   if(readinessRes.error){
    const pErr=document.createElement('p');
    pErr.className='health-error-msg';
    pErr.textContent='خطا در دریافت آمادگی: '+readinessRes.error;
    pushList.replaceChildren(pErr);
   }else{
    const rows=[
     {label:'مرورگر و اتصال امن',value:`${isSecureContext?'مرورگر در محیط امن':'مرورگر در محیط ناامن'} · ${readinessRes.https?'اتصال امن سرور (HTTPS)':'اتصال سرور بدون HTTPS'}`,state:isSecureContext&&readinessRes.https?'ok':'warning'},
     {label:'کلیدهای VAPID',value:readinessRes.vapid?.ready?`کلیدها آماده · اثر انگشت: ${readinessRes.vapid?.public_fingerprint}`:'کلید خصوصی روی سرور فعال نیست',state:readinessRes.vapid?.ready?'ok':'muted'},
     {label:'سیاست‌های اعلان و سکوت',value:`بازهٔ سکوت: ${number(readinessRes.policy?.quiet_start??0)} تا ${number(readinessRes.policy?.quiet_end??0)} · سقف روزانهٔ پیامک: ${number(readinessRes.policy?.sms_daily_budget??0)}`,state:'ok'},
     {label:'نشست امن اپراتور',value:`مهلت فعالیت: ${number(readinessRes.policy?.interactive_session_seconds??1800)} ثانیه · حداکثر نشست: ${number(readinessRes.policy?.absolute_session_seconds??28800)} ثانیه`,state:'ok'}
    ];

    for(const r of rows){
     const row=document.createElement('div');
     row.className='health-metric-row state-'+r.state;
     const mInfo=document.createElement('div');
     mInfo.className='metric-info';
     const sIcon=document.createElement('span');
     sIcon.className='support-icon';
     sIcon.textContent=r.state==='ok'?'check_circle':'info';
     const strong=document.createElement('strong');
     strong.textContent=r.label;
     const small=document.createElement('small');
     readable(small,r.value);
     mInfo.append(sIcon,strong,small);

     const badge=document.createElement('span');
     badge.className='badge badge-'+(r.state==='ok'?'success':r.state==='warning'?'warning':'neutral');
     badge.textContent=({ok:'سالم',warning:'هشدار',muted:'آماده نیست'})[r.state]||'نامشخص';

     row.append(mInfo,badge);
     pushList.append(row);
    }
   }
   pushCard.append(pushList);
   grid.append(pushCard);

  }catch(e){
   warn(e);
  }finally{
   loading=false;
  }
 }

 return {
  mount(){
   buildUI();
   runDiagnostics();
  },
  refresh(){
   runDiagnostics();
  },
  destroy(){
   host.replaceChildren();
  }
 };
}
