// Dedicated full-width Analytics and Reports module for NovinPay Operator Admin Panel V3
export function analyticsView({host,api,currentOperator,navigate,openConversation,warn}){
 let container=null,generation=0,settings=null,loading=false;

 const format=n=>n===null||n===undefined?'داده کافی نیست':Number(n).toLocaleString('fa-IR',{maximumFractionDigits:1});
 const duration=n=>n===null||n===undefined?'داده کافی نیست':format(n)+' ثانیه';
 const percent=n=>n===null||n===undefined?'داده کافی نیست':format(n)+'٪';
 const stats=(title,s,unit='پاسخ')=>`${title}: میانگین ${duration(s?.average)} · میانه ${duration(s?.median)} · ۹۰٪ نمونه‌ها تا ${duration(s?.p90)} (${format(s?.count)} ${unit})`;

 function buildUI(){
  host.replaceChildren();

  container=document.createElement('div');
  container.className='analytics-container';

  // Header & Filter bar
  const header=document.createElement('div');
  header.className='analytics-header';

  const headingBox=document.createElement('div');
  const h2=document.createElement('h2');
  h2.textContent='آمار و گزارش‌های پشتیبانی';
  const p=document.createElement('p');
  p.textContent='سنجش پاسخ‌گویی، رضایت مشتریان، عملکرد اپراتورها و ساعات اوج مراجعات.';
  headingBox.append(h2,p);

  const filterForm=document.createElement('form');
  filterForm.className='report-filters';

  const range=document.createElement('select');
  range.setAttribute('aria-label','بازه گزارش');
  for(const [v,t]of [['today','امروز'],['7','هفت روز گذشته'],['30','سی روز گذشته'],['custom','بازه دلخواه']]){
   range.add(new Option(t,v));
  }
  range.value='7';

  const from=document.createElement('input');
  const to=document.createElement('input');
  from.type=to.type='date';
  from.setAttribute('aria-label','از تاریخ میلادی');
  to.setAttribute('aria-label','تا تاریخ میلادی');
  from.hidden=to.hidden=true;
  range.onchange=()=>from.hidden=to.hidden=range.value!=='custom';

  const refreshBtn=document.createElement('button');
  refreshBtn.type='submit';
  refreshBtn.className='primary';
  refreshBtn.innerHTML='<span class="support-icon" aria-hidden="true">refresh</span><span>نمایش</span>';

  const csvLink=document.createElement('a');
  csvLink.className='compact-btn secondary';
  csvLink.textContent='دریافت فایل CSV';
  csvLink.href='javascript:void(0)';
  csvLink.target='_blank';

  filterForm.append(range,from,to,refreshBtn,csvLink);
  header.append(headingBox,filterForm);
  container.append(header);

  // Tabs
  const tabsNav=document.createElement('nav');
  tabsNav.className='reports-tabs';
  const panels={};

  for(const [key,label]of [['summary','خلاصهٔ آمار'],['operators','عملکرد اپراتورها'],['operations','گفتگوهای در انتظار'],['hours','تنظیمات ساعت کاری']]){
   const btn=document.createElement('button');
   btn.type='button';
   btn.textContent=label;
   btn.setAttribute('aria-pressed',String(key==='summary'));
   btn.onclick=()=>{
    for(const [k,panel]of Object.entries(panels))panel.hidden=k!==key;
    for(const b of tabsNav.children)b.setAttribute('aria-pressed',String(b===btn));
   };
   tabsNav.append(btn);

   const panel=document.createElement('section');
   panel.className='admin-card analytics-panel';
   panel.hidden=key!=='summary';
   panels[key]=panel;
  }

  container.append(tabsNav,...Object.values(panels));
  host.append(container);

  filterForm.onsubmit=e=>{
   e.preventDefault();
   loadAll();
  };

  function getQuery(){
   return {
    range:range.value,
    ...(range.value==='custom'?{from:from.value,to:to.value}:{})
   };
  }

  async function loadAll(){
   if(currentOperator()?.role!=='administrator')return;
   const q=getQuery();
   csvLink.href='operator-api.php?'+new URLSearchParams({action:'analytics_csv',...q});
   await Promise.all([loadReports(q),loadHours()]).catch(warn);
  }

  async function loadReports(q){
   const currentGen=++generation;
   refreshBtn.disabled=true;
   try{
    const r=await api('analytics',null,q);
    if(currentGen!==generation)return;

    // Render Summary
    const p=panels.summary;
    p.replaceChildren();

    const topNote=document.createElement('div');
    topNote.className='analytics-info-bar';
    topNote.textContent=`ثبت آمار از: ${r.coverage_since?new Date(r.coverage_since*1000).toLocaleString('fa-IR',{timeZone:'Asia/Tehran'}):'داده کافی نیست'} · منطقه زمانی: ${r.timezone}`;
    p.append(topNote);

    const cards=document.createElement('div');
    cards.className='report-cards';
    const cardDefs=[
     ['new','گفتگوهای جدید'],['incoming','پیام‌های دریافتی'],['outgoing','پاسخ‌های اپراتورها'],
     ['resolved','دوره‌های پایان‌یافتهٔ گفتگو'],['closures','تعداد پایان گفتگو'],['reopened','باز شدن دوبارهٔ گفتگو']
    ];
    for(const [k,label]of cardDefs){
     const card=document.createElement('article');
     const sm=document.createElement('small');
     sm.textContent=label;
     const hints={resolved:'هر بار پایان یک دورهٔ گفتگو شمرده می‌شود؛ یک مشتری ممکن است چند دوره داشته باشد.',closures:'تعداد تغییر وضعیت واقعی به پایان‌یافته؛ کلیک تکراری شمرده نمی‌شود.',incoming:'پیام‌های مشتری؛ پاسخ‌های خودکار و پیام اپراتور در این عدد نیست.',outgoing:'پیام‌های انسانی اپراتورها؛ پاسخ خودکار شمرده نمی‌شود.',new:'گفتگوهایی که در بازهٔ انتخاب‌شده ایجاد شده‌اند.',reopened:'تعداد باز شدن دوبارهٔ گفتگوهای پایان‌یافته در بازهٔ گزارش.'};sm.title=hints[k];
     const st=document.createElement('strong');
     st.textContent=format(r.totals[k]);
     card.append(sm,st);
     cards.append(card);
    }
    p.append(cards);
    const definitions=document.createElement('details');definitions.className='analytics-definitions';const summary=document.createElement('summary');summary.textContent='این آمار چه معنایی دارد؟';const explanation=document.createElement('p');explanation.textContent='میانه: نیمی از زمان‌ها کمتر از این مقدارند. ۹۰٪ نمونه‌ها: ۹ مورد از هر ۱۰ نمونه تا این زمان ثبت شده‌اند. زمان اولین پاسخ از اولین پیام مشتری تا اولین پاسخ انسانی است؛ زمان پایان از شروع دوره تا پایان آن محاسبه می‌شود. رضایت، درصد پاسخ‌های مثبتِ ثبت‌شده است و در شمارش فعلی به بازهٔ گزارش محدود نمی‌شود. خوانده‌شدن پیام با نیاز به پاسخ یا پایان گفتگو متفاوت است.';definitions.append(summary,explanation);p.append(definitions);


    // Stats Section
    const statsBox=document.createElement('div');
    statsBox.className='analytics-stats-grid';
    statsBox.innerHTML=`
     <div class="stat-pill"><span class="support-icon" aria-hidden="true">timer</span><p>${stats('زمان اولین پاسخ',r.stats.first)}</p></div>
     <div class="stat-pill"><span class="support-icon" aria-hidden="true">schedule</span><p>${stats('زمان اولین پاسخ در ساعت کاری',r.stats.business_first)}</p></div>
     <div class="stat-pill"><span class="support-icon" aria-hidden="true">check_circle</span><p>${stats('زمان تا پایان گفتگو',r.stats.resolution,'پایان گفتگو')}</p></div>
     <div class="stat-pill"><span class="support-icon" aria-hidden="true">hourglass_empty</span><p>${stats('زمان انتظار مشتری',r.stats.wait)}</p></div>
     <div class="stat-pill"><span class="support-icon" aria-hidden="true">thumb_up</span><p>میزان رضایت مشتریان: ${percent(r.csat.percent)} (${format(r.csat.positive)} مثبت / ${format(r.csat.negative)} منفی)</p></div>
     <div class="stat-pill"><span class="support-icon" aria-hidden="true">warning_amber</span><p>انتظار بیش از زمان تعیین‌شده: ${format(r.above_threshold)} · باز شدن دوبارهٔ گفتگو: ${r.totals.closures?percent(100*r.totals.reopened/r.totals.closures):'داده کافی نیست'}</p></div>
    `;
    p.append(statsBox);

    // Trend table
    const trendTitle=document.createElement('h3');
    trendTitle.textContent='روند روزانه';
    p.append(trendTitle);

    const trendWrap=document.createElement('div');
    trendWrap.className='report-table';
    const trendTable=document.createElement('table');
    trendTable.innerHTML=`
     <thead>
      <tr>
       <th>روز</th>
       <th>گفتگوهای جدید</th>
       <th>پیام‌های دریافتی</th>
       <th>میانه زمان اولین پاسخ</th>
       <th>رضایت (مثبت / منفی)</th>
      </tr>
     </thead>
     <tbody></tbody>
    `;
    const trendBody=trendTable.querySelector('tbody');
    for(const d of r.trend||[]){
     const tr=document.createElement('tr');
     const tdDay=document.createElement('td');
     const strongDay=document.createElement('strong');
     strongDay.textContent=/^\d{4}-\d{2}-\d{2}$/.test(d.day||'')?new Intl.DateTimeFormat('fa-IR-u-ca-persian',{timeZone:'Asia/Tehran',year:'numeric',month:'2-digit',day:'2-digit'}).format(new Date(d.day+'T12:00:00+03:30')):(d.day||'');
     tdDay.append(strongDay);
     const tdNew=document.createElement('td');
     tdNew.textContent=format(d.new);
     const tdIn=document.createElement('td');
     tdIn.textContent=format(d.incoming);
     const tdFirst=document.createElement('td');
     tdFirst.textContent=duration(d.first?.median);
     const tdSat=document.createElement('td');
     tdSat.textContent=`${format(d.positive||0)} مثبت / ${format(d.negative||0)} منفی`;
     tr.append(tdDay,tdNew,tdIn,tdFirst,tdSat);
     trendBody.append(tr);
    }
    trendWrap.append(trendTable);
    p.append(trendWrap);

    // Hourly Histogram
    const hoursTitle=document.createElement('h3');
    hoursTitle.textContent='حجم پیام‌های ورودی بر اساس ساعات شبانه‌روز (تهران)';
    p.append(hoursTitle);

    const bars=document.createElement('div');
    bars.className='report-bars';
    bars.setAttribute('aria-label','حجم پیام‌های دریافتی در ساعت‌های تهران');
    const maxVal=Math.max(1,...(r.hours||[1]));
    for(let h=0;h<24;h++){
     const val=r.hours?.[h]||0;
     const col=document.createElement('div');
     col.title=`ساعت ${Number(h).toLocaleString('fa-IR')}:۰۰ · ${format(val)} پیام`;col.tabIndex=0;col.setAttribute('role','img');col.setAttribute('aria-label',col.title);
     const bar=document.createElement('span');
     bar.style.height=(val/maxVal*70+4)+'px';
     const lbl=document.createElement('small');
     lbl.textContent=Number(h).toLocaleString('fa-IR');
     col.append(bar,lbl);
     bars.append(col);
    }
    p.append(bars);

    // Render Operators Panel
    const perf=panels.operators;
    perf.replaceChildren();
    const perfNote=document.createElement('p');
    perfNote.className='analytics-info-bar';
    perfNote.textContent='پاسخ‌ها به فرستنده واقعی و رضایت به اپراتور پایان‌دهنده گفتگو نسبت داده می‌شود.';
    perf.append(perfNote);

    const perfWrap=document.createElement('div');
    perfWrap.className='report-table';
    const perfTable=document.createElement('table');
    perfTable.innerHTML=`
     <thead>
      <tr>
       <th>نام اپراتور</th>
       <th>گفتگوهای دارای پاسخ</th>
       <th>تعداد پاسخ انسانی</th>
       <th>میانه زمان اولین پاسخ</th>
       <th>تعداد پایان گفتگو</th>
       <th>آرای رضایت</th>
       <th>درصد رضایت</th>
      </tr>
     </thead>
     <tbody></tbody>
    `;
    const perfBody=perfTable.querySelector('tbody');
    for(const o of r.operators||[]){
     const tr=document.createElement('tr');
     const tdName=document.createElement('td');
     const strongName=document.createElement('strong');
     strongName.textContent=o.name||'–';
     tdName.append(strongName);
     const tdHandled=document.createElement('td');
     tdHandled.textContent=format(o.handled);
     const tdResp=document.createElement('td');
     tdResp.textContent=format(o.responses);
     const tdFirst=document.createElement('td');
     tdFirst.textContent=duration(o.first?.median);
     const tdClose=document.createElement('td');
     tdClose.textContent=format(o.closures);
     const tdSat=document.createElement('td');
     tdSat.textContent=`${format(o.positive)} مثبت / ${format(o.negative)} منفی`;
     const tdCsat=document.createElement('td');
     const strongCsat=document.createElement('strong');
     strongCsat.textContent=`${percent(o.csat)}`;
     tdCsat.append(strongCsat,document.createTextNode(` (${format(o.survey_count)} نظر)`));
     tr.append(tdName,tdHandled,tdResp,tdFirst,tdClose,tdSat,tdCsat);
     perfBody.append(tr);
    }
    perfWrap.append(perfTable);
    perf.append(perfWrap);

    // Render Operations Panel
    const ops=panels.operations;
    ops.replaceChildren();
    const opsNote=document.createElement('div');
    opsNote.className='analytics-info-bar';
    opsNote.textContent=`وضعیت جاری: ${format(r.snapshot.open)} باز · ${format(r.snapshot.waiting_support)} در انتظار پشتیبان · ${format(r.snapshot.waiting_customer)} در انتظار مشتری · ${format(r.snapshot.needs_reply)} نیازمند پاسخ · ${format(r.snapshot.unread)} خوانده‌نشده`;
    ops.append(opsNote);

    const opsListDefs=[
     ['waiting','در انتظار پشتیبان'],['delayed','انتظار بیش از زمان تعیین‌شده'],
     ['unanswered','بدون پاسخ انسانی'],['reopened','بازگشایی در ۲۴ ساعت گذشته'],
     ['negative','بازخوردهای منفی']
    ];
    for(const [key,label]of opsListDefs){
     const list=r.operations?.[key]||[];
     const details=document.createElement('details');
     details.className='admin-details-card';
     const summary=document.createElement('summary');
     const st=document.createElement('strong');
     st.textContent=label;
     const sp=document.createElement('span');
     sp.className='badge badge-normal';
     sp.textContent=`${format(list.length)} گفتگو`;
     summary.append(st,document.createTextNode(' '),sp);
     details.append(summary);

     if(list.length===0){
      const emptyItem=document.createElement('p');
      emptyItem.className='details-empty';
      emptyItem.textContent='موردی در این دسته وجود ندارد.';
      details.append(emptyItem);
     }else{
      const dWrap=document.createElement('div');
      dWrap.className='details-list';
      for(const row of list){
       const rowBox=document.createElement('div');
       rowBox.className='details-row';
       const info=document.createElement('div');
       const strongId=document.createElement('strong');
       strongId.textContent='شناسه گفتگو: '+(row.id||row.conversation);
       info.append(strongId);
       if(row.waiting_seconds){
        info.append(document.createTextNode(' · انتظار: '+duration(row.waiting_seconds)));
       }
       if(row.comment){
        const fbP=document.createElement('p');
        fbP.className='feedback-comment';
        fbP.textContent=row.comment;
        info.append(fbP);
       }

       const btn=document.createElement('button');
       btn.type='button';
       btn.className='compact-btn primary';
       btn.textContent='مشاهده و بررسی';
       btn.onclick=()=>{
        const targetId=row.id||row.conversation;
        navigate('conversations',{id:targetId});
        openConversation?.(targetId);
       };
       rowBox.append(info,btn);
       dWrap.append(rowBox);
      }
      details.append(dWrap);
     }
     ops.append(details);
    }
   }finally{
    refreshBtn.disabled=false;
   }
  }

  async function loadHours(){
   try{
    const r=await api('operations_settings');
    settings=r.settings;
    const p=panels.hours;
    p.replaceChildren();

    const form=document.createElement('form');
    form.className='admin-form';

    const h3=document.createElement('h3');
    h3.textContent='تنظیمات تقویم و ساعات کاری پشتیبانی';
    form.append(h3);

    const desc=document.createElement('p');
    desc.className='analytics-info-bar';
    desc.textContent='محاسبه زمان اولین پاسخ و آستانه‌های انتظار بر اساس این ساعات تنظیم می‌شود.';
    form.append(desc);

    const fields={};
    const makeField=(key,label,type,value)=>{
     const l=document.createElement('label');
     const sp=document.createElement('span');
     sp.textContent=label;
     l.append(sp);
     const inp=document.createElement('input');
     inp.type=type;
     if(type==='checkbox'){
      l.className='checkbox-row';
      inp.checked=!!value;
      l.replaceChildren(inp,document.createTextNode(' '+label));
     }else{
      inp.value=value;
      l.append(inp);
     }
     form.append(l);
     fields[key]=inp;
    };

    makeField('enabled','محاسبه و تفکیک آمار بر اساس ساعت کاری','checkbox',settings.enabled);
    makeField('notice','نمایش پیام خارج از ساعت کاری به کاربران','checkbox',settings.notice);
    makeField('timezone','منطقهٔ زمانی (مانند Asia/Tehran)','text',settings.timezone);
    makeField('start','ساعت شروع کار (مثال ۰۹:۰۰)','time',settings.start);
    makeField('end','ساعت پایان کار (مثال ۱۸:۰۰)','time',settings.end);
    makeField('threshold','حد انتظار مشتری (ثانیه)','number',settings.threshold);

    const daysFieldset=document.createElement('fieldset');
    daysFieldset.className='days-fieldset';
    daysFieldset.innerHTML='<legend>روزهای فعال کاری هفته</legend>';
    const dayNames=['شنبه','یکشنبه','دوشنبه','سه‌شنبه','چهارشنبه','پنجشنبه','جمعه'];
    for(const [idx,dayName]of dayNames.entries()){
     const dLabel=document.createElement('label');
     dLabel.className='checkbox-inline';
     const dInp=document.createElement('input');
     dInp.type='checkbox';
     const weekday=(idx+6)%7;dInp.value=String(weekday);
     dInp.checked=settings.days.includes(weekday);
     dLabel.append(dInp,document.createTextNode(dayName));
     daysFieldset.append(dLabel);
    }
    form.append(daysFieldset);

    const saveBtn=document.createElement('button');
    saveBtn.type='submit';
    saveBtn.className='primary';
    saveBtn.textContent='ذخیره تنظیمات ساعت کاری';
    const statusMsg=document.createElement('p');
    statusMsg.className='form-status-msg';
    form.append(saveBtn,statusMsg);

    form.onsubmit=async e=>{
     e.preventDefault();
     saveBtn.disabled=true;
     statusMsg.textContent='در حال ذخیره…';
     try{
      const nextSettings={
       revision:settings.revision,
       timezone:fields.timezone.value,
       start:fields.start.value,
       end:fields.end.value,
       threshold:Number(fields.threshold.value),
       enabled:fields.enabled.checked,
       notice:fields.notice.checked,
       days:[...daysFieldset.querySelectorAll('input:checked')].map(i=>Number(i.value))
      };
      const res=await api('operations_save',{settings:JSON.stringify(nextSettings)});
      settings=res.settings;
      statusMsg.textContent='تنظیمات ساعت کاری با موفقیت ذخیره شد.';
     }catch(err){
      statusMsg.textContent='خطا: '+err.message;
     }finally{
      saveBtn.disabled=false;
     }
    };

    p.append(form);
   }catch(e){
    warn(e);
   }
  }

  loadAll();
 }

 return {
  mount(){
   buildUI();
  },
  refresh(){
   buildUI();
  },
  destroy(){
   host.replaceChildren();
  }
 };
}
