// Dashboard view module for NovinPay Operator Admin Panel V3
import {persianDate,messageDate} from '../../support-client/persian-date.js';

export function dashboardView({host,api,currentOperator,navigate,openConversation,warn}){
 let container=null,timer=null,loading=false,refreshBtn=null;

 function formatNumber(n){
  if(n===null||n===undefined||n==='')return '–';
  const num=Number(n);
  if(Number.isNaN(num))return String(n);
  return num.toLocaleString('fa-IR');
 }

 function formatDuration(seconds){
  if(seconds===null||seconds===undefined)return 'داده کافی نیست';
  const sec=Number(seconds);
  if(Number.isNaN(sec))return 'داده کافی نیست';
  if(sec<60)return Math.round(sec).toLocaleString('fa-IR')+' ثانیه';
  const mins=Math.round(sec/60);
  return mins.toLocaleString('fa-IR')+' دقیقه';
 }

 function formatTime(val){
  if(!val)return '';
  try{
   return persianDate(messageDate({time:val})).label;
  }catch{
   return String(val);
  }
 }

 function buildUI(){
  host.replaceChildren();
  container=document.createElement('div');
  container.className='dashboard-container';

  // Header
  const header=document.createElement('div');
  header.className='dashboard-header';

  const greetingBox=document.createElement('div');
  const h2=document.createElement('h2');
  h2.textContent='داشبورد پشتیبانی';
  const p=document.createElement('p');
  const op=currentOperator();
  const todayStr=new Date().toLocaleDateString('fa-IR-u-ca-persian-nu-arabext',{timeZone:'Asia/Tehran',weekday:'long',day:'numeric',month:'long',year:'numeric'});
  p.textContent=`خوش آمدید، ${op?.name||'همکار'} · امروز ${todayStr}`;
  greetingBox.append(h2,p);

  refreshBtn=document.createElement('button');
  refreshBtn.type='button';
  refreshBtn.id='dashboardRefreshBtn';
  refreshBtn.className='secondary dashboard-refresh-btn';
  refreshBtn.setAttribute('aria-label','به‌روزرسانی اطلاعات داشبورد');
  refreshBtn.innerHTML='<span class="support-icon" aria-hidden="true">refresh</span><span>به‌روزرسانی</span>';
  refreshBtn.onclick=()=>loadData(true);

  header.append(greetingBox,refreshBtn);
  container.append(header);

  // KPI Cards Grid
  const kpiGrid=document.createElement('div');
  kpiGrid.className='dashboard-kpi-grid';
  kpiGrid.id='dashboardKpiGrid';
  container.append(kpiGrid);

  // Actionable Shortcuts
  const actionsSection=document.createElement('div');
  actionsSection.className='dashboard-section';
  const actionsTitle=document.createElement('h3');
  actionsTitle.textContent='دسترسی سریع به صف‌ها و ابزارها';
  const actionsGrid=document.createElement('div');
  actionsGrid.className='dashboard-actions-grid';

  const shortcuts=[
   {title:'نیازمند پاسخ',desc:'مشاهده گفتگوهایی که منتظر پاسخ انسانی اپراتور هستند',icon:'mark_chat_unread',route:'conversations',params:{tab:'needs_reply'}},
   {title:'اولویت فوری',desc:'مشاهده موارد با برچسب یا اولویت اضطراری',icon:'priority_high',route:'conversations',params:{filter:'urgent'}},
   {title:'پیام‌های خوانده‌نشده',desc:'صندوق پیام‌های تازه دریافت‌شده',icon:'inbox',route:'conversations',params:{tab:'unread'}},
   {title:'پرسش و پاسخ',desc:'مدیریت پرسش‌ها و پاسخ‌های متداول',icon:'quiz',route:'knowledge',params:{}},
   {title:'مخاطبین',desc:'مشاهده و جستجوی کاربران و سوابق',icon:'people',route:'contacts',params:{}},
  ];

  if(currentOperator()?.role==='administrator'){
   shortcuts.push(
    {title:'آمار و گزارش‌ها',desc:'بررسی عملکرد تیم و ساعات اوج پیام‌ها',icon:'bar_chart',route:'analytics',params:{}},
    {title:'سلامت سیستم',desc:'بررسی اتصالات ذخیره‌سازی و صف‌ها',icon:'health_and_safety',route:'health',params:{}}
   );
  }

  for(const sc of shortcuts){
   const card=document.createElement('button');
   card.type='button';
   card.className='dashboard-action-card';

   const iconWrap=document.createElement('div');
   iconWrap.className='action-icon-wrap';
   const sIcon=document.createElement('span');
   sIcon.className='support-icon';
   sIcon.textContent=sc.icon;
   iconWrap.append(sIcon);

   const copyWrap=document.createElement('div');
   copyWrap.className='action-copy';
   const strongTitle=document.createElement('strong');
   strongTitle.textContent=sc.title;
   const smallDesc=document.createElement('small');
   smallDesc.textContent=sc.desc;
   copyWrap.append(strongTitle,smallDesc);

   card.append(iconWrap,copyWrap);
   card.onclick=()=>navigate(sc.route,sc.params);
   actionsGrid.append(card);
  }
  actionsSection.append(actionsTitle,actionsGrid);
  container.append(actionsSection);

  // Live Queue Snapshot
  const queueSection=document.createElement('div');
  queueSection.className='dashboard-section';
  const queueTitle=document.createElement('h3');
  queueTitle.textContent='گفتگوهای نیازمند توجه در صف';
  const queueList=document.createElement('div');
  queueList.className='dashboard-queue-list';
  queueList.id='dashboardQueueList';
  queueSection.append(queueTitle,queueList);
  container.append(queueSection);

  host.append(container);
 }

 async function loadData(manual=false){
  if(loading)return;
  loading=true;
  if(refreshBtn){
   refreshBtn.disabled=true;
   refreshBtn.classList.add('is-loading');
  }
  try{
   const statsRes=await api('dashboard_stats').catch(e=>{if(manual)throw e;return null;});
   const stats=statsRes?.stats||{};

   const kpiGrid=document.getElementById('dashboardKpiGrid');
   if(kpiGrid){
    kpiGrid.replaceChildren();

    const items=[
     {title:'گفتگوهای باز',value:formatNumber(stats.open_count),sub:'در حال بررسی',icon:'forum',route:'conversations',params:{filter:'new'}},
     {title:'نیازمند پاسخ',value:formatNumber(stats.unanswered_count),sub:'در انتظار پاسخ انسانی اپراتور',icon:'mark_chat_unread',route:'conversations',params:{tab:'needs_reply'},alert:(stats.unanswered_count||0)>0},
     {title:'پیام‌های خوانده‌نشده',value:formatNumber(stats.unread_count),sub:'پیام تازه مشتری',icon:'notifications_active',route:'conversations',params:{tab:'unread'},alert:(stats.unread_count||0)>0},
     {title:'اولویت فوری',value:formatNumber(stats.urgent_count),sub:'نیازمند پیگیری سریع',icon:'error_outline',route:'conversations',params:{filter:'urgent'},urgent:(stats.urgent_count||0)>0},
     {title:'گفتگوهای امروز',value:formatNumber(stats.today_new),sub:formatNumber(stats.today_messages)+' پیام دریافت‌شده',icon:'today',route:'conversations',params:{}},
    ];

    if(currentOperator()?.role==='administrator'){
     items.push(
      {title:'میانهٔ زمان اولین پاسخ',value:formatDuration(stats.median_first_response),sub:'امروز',icon:'timer',route:'analytics',params:{}},
      {title:'رضایت مشتریان',value:stats.csat_percent!==null&&stats.csat_percent!==undefined?formatNumber(stats.csat_percent)+'٪':'–',sub:formatNumber(stats.csat_positive||0)+' مثبت / '+formatNumber(stats.csat_negative||0)+' منفی',icon:'thumb_up',route:'analytics',params:{}}
     );
    }

    for(const item of items){
     const card=document.createElement('div');
     card.className='dashboard-kpi-card'+(item.alert?' is-alert':'')+(item.urgent?' is-urgent':'');

     const iconBox=document.createElement('div');
     iconBox.className='kpi-icon-box';
     const sIcon=document.createElement('span');
     sIcon.className='support-icon';
     sIcon.textContent=item.icon;
     iconBox.append(sIcon);

     const bodyBox=document.createElement('div');
     bodyBox.className='kpi-body';
     const titleSp=document.createElement('span');
     titleSp.className='kpi-title';
     titleSp.textContent=item.title;
     const valSt=document.createElement('strong');
     valSt.className='kpi-value';
     valSt.textContent=item.value;
     const subSm=document.createElement('small');
     subSm.className='kpi-sub';
     subSm.textContent=item.sub;
     bodyBox.append(titleSp,valSt,subSm);

     card.append(iconBox,bodyBox);
     if(item.route){
      card.style.cursor='pointer';
      card.onclick=()=>navigate(item.route,item.params);
     }
     kpiGrid.append(card);
    }
   }

   // Load snapshot from inbox
   const inboxRes=await api('inbox',null,{tab:'needs_reply'}).catch(e=>{if(manual)throw e;return null;});
   const queueList=document.getElementById('dashboardQueueList');
   if(queueList){
    queueList.replaceChildren();
    const rows=inboxRes?.conversations||[];
    if(rows.length===0){
     const empty=document.createElement('div');
     empty.className='dashboard-queue-empty';
     const emptyIcon=document.createElement('span');
     emptyIcon.className='support-icon';
     emptyIcon.textContent='check_circle';
     const emptyP=document.createElement('p');
     emptyP.textContent='در حال حاضر هیچ گفتگویی در صف نیازمند پاسخ قرار ندارد.';
     empty.append(emptyIcon,emptyP);
     queueList.append(empty);
    }else{
     const table=document.createElement('div');
     table.className='report-table';
     const tbl=document.createElement('table');
     const thead=document.createElement('thead');
     const headerRow=document.createElement('tr');
     for(const hText of ['مشتری','آخرین پیام','زمان','اولویت','اقدام']){
      const th=document.createElement('th');
      th.textContent=hText;
      headerRow.append(th);
     }
     thead.append(headerRow);
     tbl.append(thead);

     const tbody=document.createElement('tbody');
     for(const r of rows.slice(0,8)){
      const tr=document.createElement('tr');
      const timeStr=formatTime(r.time);
      const name=r.name||'مهمان';
      const snippet=r.last||'(بدون متن)';

      const tdName=document.createElement('td');
      const strongName=document.createElement('strong');
      strongName.textContent=name;
      tdName.append(strongName);

      const tdSnippet=document.createElement('td');
      const spanSnippet=document.createElement('span');
      spanSnippet.className='table-snippet';
      spanSnippet.textContent=snippet;
      tdSnippet.append(spanSnippet);

      const tdTime=document.createElement('td');
      const smallTime=document.createElement('small');
      smallTime.dir='ltr';smallTime.textContent=timeStr;
      tdTime.append(smallTime);

      const tdPriority=document.createElement('td');
      const badgePriority=document.createElement('span');
      badgePriority.className='badge '+(r.priority==='urgent'?'badge-urgent':'badge-normal');
      badgePriority.textContent=r.priority==='urgent'?'فوری':'عادی';
      tdPriority.append(badgePriority);

      const tdAction=document.createElement('td');
      const btn=document.createElement('button');
      btn.type='button';
      btn.className='compact-btn primary';
      btn.textContent='پاسخ';
      btn.onclick=()=>{
       navigate('conversations',{id:r.id});
       openConversation?.(r.id);
      };
      tdAction.append(btn);

      tr.append(tdName,tdSnippet,tdTime,tdPriority,tdAction);
      tbody.append(tr);
     }
     tbl.append(tbody);
     table.append(tbl);
     queueList.append(table);
    }
   }
  }catch(e){
   if(manual){
    const msg=e?.status===429
     ?'درخواست‌های زیادی ارسال شده است؛ لطفاً کمی صبر کنید.'
     :(e?.status===401
       ?'نشست شما منقضی شده است؛ لطفاً دوباره وارد شوید.'
       :(e?.message||'به‌روزرسانی اطلاعات داشبورد با خطا مواجه شد.'));
    warn(Error(msg));
   }
  }finally{
   loading=false;
   if(refreshBtn){
    refreshBtn.disabled=false;
    refreshBtn.classList.remove('is-loading');
   }
  }
 }

 return {
  mount(){
   buildUI();
   loadData();
  },
  refresh(){
   loadData();
  },
  destroy(){
   if(timer)clearInterval(timer);
   refreshBtn=null;
   host.replaceChildren();
  }
 };
}
