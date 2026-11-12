import {mobileTable} from './mobile-table.js?v=mobile2';
// Support Team Management module for ZizoLearn Operator Admin Panel V3
export function teamView({host,api,currentOperator,warn}){
 let accounts=[],loading=false;

 function buildUI(){
  host.replaceChildren();

  const wrap=document.createElement('div');
  wrap.className='team-container';

  // Header
  const header=document.createElement('div');
  header.className='team-header';
  const headingBox=document.createElement('div');
  const h2=document.createElement('h2');
  h2.textContent='تیم و همکاران پشتیبانی';
  const p=document.createElement('p');
  p.textContent='مدیریت دسترسی‌ها، حساب‌های اپراتوری و تعیین نقش‌های پشتیبان و مدیر.';
  headingBox.append(h2,p);

  const refreshBtn=document.createElement('button');
  refreshBtn.type='button';
  refreshBtn.className='secondary';
  refreshBtn.innerHTML='<span class="support-icon">refresh</span><span>به‌روزرسانی</span>';
  refreshBtn.onclick=()=>loadAccounts();
  header.append(headingBox,refreshBtn);
  wrap.append(header);

  // Status feedback
  const feedback=document.createElement('div');
  feedback.className='team-feedback';
  feedback.id='teamFeedback';
  feedback.setAttribute('role','status');
  wrap.append(feedback);

  const layout=document.createElement('div');
  layout.className='team-layout';

  // Left: Directory Table
  const tableCol=document.createElement('div');
  tableCol.className='team-table-col';
  const card=document.createElement('div');
  card.className='admin-card';
  card.innerHTML='<h3>فهرست اعضای تیم</h3><div class="report-table" id="teamTableWrap"></div>';
  tableCol.append(card);
  layout.append(tableCol);

  // Right: Add Account Form (if admin)
  if(currentOperator()?.role==='administrator'){
   const formCol=document.createElement('div');
   formCol.className='team-form-col';
   const formCard=document.createElement('div');
   formCard.className='admin-card';

   const form=document.createElement('form');
   form.className='admin-form';
   form.innerHTML=`
    <h3>افزودن عضو جدید</h3>
    <label>
     <span>نام کاربری (حداکثر ۸۰ کاراکتر انگلیسی)</span>
     <input type="text" id="newOpUsername" required maxlength="80" autocomplete="off" placeholder="مثال: sara_support">
    </label>
    <label>
     <span>نام نمایشی (فارسی)</span>
     <input type="text" id="newOpName" required maxlength="200" placeholder="مثال: سارا محمدی">
    </label>
    <label>
     <span>رمز عبور (حداقل ۱۲ کاراکتر)</span>
     <input type="password" id="newOpPassword" required minlength="12" maxlength="200" autocomplete="new-password">
    </label>
    <label>
     <span>نقش دسترسی</span>
     <select id="newOpRole">
      <option value="support">پشتیبان عادی (پاسخ‌گویی به گفتگوها)</option>
      <option value="administrator">مدیر سیستم (دسترسی به تمام تنظیمات و آمار)</option>
     </select>
    </label>
    <div class="form-actions-row">
     <button type="submit" class="primary" id="newOpSubmit">ساخت حساب کاربری</button>
    </div>
   `;

   form.onsubmit=async e=>{
    e.preventDefault();
    const submitBtn=form.querySelector('#newOpSubmit');
    submitBtn.disabled=true;
    try{
     const u=form.querySelector('#newOpUsername').value.trim();
     const n=form.querySelector('#newOpName').value.trim();
     const p=form.querySelector('#newOpPassword').value;
     const r=form.querySelector('#newOpRole').value;

     await api('account_add',{username:u,name:n,password:p,role:r});
     form.reset();
     showNotice('حساب کاربری جدید با موفقیت ساخته شد.');
     await loadAccounts();
    }catch(err){
     warn(err);
    }finally{
     submitBtn.disabled=false;
    }
   };

   formCard.append(form);
   formCol.append(formCard);
   layout.append(formCol);
  }

  wrap.append(layout);
  host.append(wrap);
 }

 async function loadAccounts(){
  if(loading)return;
  loading=true;
  try{
   const res=await api('accounts');
   accounts=res.accounts||[];
   renderTable();
  }catch(e){
   warn(e);
  }finally{
   loading=false;
  }
 }

 function renderTable(){
  const wrap=document.getElementById('teamTableWrap');
  if(!wrap)return;

  if(accounts.length===0){
   const empty=document.createElement('p');
   empty.className='details-empty';
   empty.textContent='هیچ حسابی یافت نشد.';
   wrap.replaceChildren(empty);
   return;
  }

  const tbl=document.createElement('table');
  const thead=document.createElement('thead');
  const headerRow=document.createElement('tr');
  for(const hText of ['نام همکار','نام کاربری','نقش','وضعیت','عملیات']){
   const th=document.createElement('th');
   th.textContent=hText;
   headerRow.append(th);
  }
  thead.append(headerRow);
  tbl.append(thead);

  const tbody=document.createElement('tbody');
  const isAdmin=currentOperator()?.role==='administrator';
  const myId=currentOperator()?.id;

  for(const a of accounts){
   const tr=document.createElement('tr');
   const name=a.name||a.username;
   const initials=name.trim().slice(0,1);

   const tdName=document.createElement('td');
   const cellDiv=document.createElement('div');
   cellDiv.className='contact-user-cell';
   const av=document.createElement('span');
   av.className='account-avatar';
   av.textContent=initials;
   const strong=document.createElement('strong');
   strong.textContent=name;
   cellDiv.append(av,strong);
   if(a.id===myId){
    const selfBadge=document.createElement('small');
    selfBadge.className='self-badge';
    selfBadge.textContent='(شما)';
    cellDiv.append(selfBadge);
   }
   tdName.append(cellDiv);

   const tdUser=document.createElement('td');
   const code=document.createElement('code');
   code.textContent=a.username||'–';
   tdUser.append(code);

   const tdRole=document.createElement('td');
   const roleBadge=document.createElement('span');
   roleBadge.className='badge '+(a.role==='administrator'?'badge-urgent':'badge-normal');
   roleBadge.textContent=a.role==='administrator'?'مدیر سیستم':'پشتیبان';
   tdRole.append(roleBadge);

   const tdStatus=document.createElement('td');
   const statusBadge=document.createElement('span');
   statusBadge.className='badge '+(a.active?'badge-success':'badge-muted');
   statusBadge.textContent=a.active?'فعال':'غیرفعال';
   tdStatus.append(statusBadge);

   const actionCell=document.createElement('td');
   if(isAdmin&&a.id!==myId){
    const revokeBtn=document.createElement('button');
    revokeBtn.type='button';
    revokeBtn.className='compact-btn danger-btn';
    revokeBtn.textContent=a.active?'لغو دسترسی':'حذف کاربر';
    revokeBtn.onclick=async()=>{
     if(!confirm(a.active?`آیا مطمئن هستید که می‌خواهید دسترسی حساب «${name}» را لغو کنید؟`:`حساب «${name}» حذف شود؟ پیام‌ها و سوابق گفتگو حفظ می‌شوند.`))return;
     revokeBtn.disabled=true;
     try{
      await api(a.active?'account_revoke':'account_delete',{id:a.id});
      showNotice(a.active?'دسترسی حساب با موفقیت لغو شد.':'کاربر حذف شد.');
      await loadAccounts();
     }catch(err){
      warn(err);
     }finally{
      revokeBtn.disabled=false;
     }
    };
    actionCell.append(revokeBtn);
   }else if(a.id===myId){
    const muted=document.createElement('small');
    muted.className='table-muted';
    muted.textContent='حساب جاری';
    actionCell.append(muted);
   }else{
    actionCell.textContent='–';
   }

   tr.append(tdName,tdUser,tdRole,tdStatus,actionCell);
   tbody.append(tr);
  }
  tbl.append(tbody);
  wrap.replaceChildren(mobileTable(tbl));
 }

 function showNotice(msg){
  const el=document.getElementById('teamFeedback');
  if(!el)return;
  el.textContent=msg;
  el.className='team-feedback is-visible';
  clearTimeout(el.timer);
  el.timer=setTimeout(()=>{
   el.className='team-feedback';
   el.textContent='';
  },4000);
 }

 return {
  mount(){
   buildUI();
   loadAccounts();
  },
  refresh(){
   loadAccounts();
  },
  destroy(){
   host.replaceChildren();
  }
 };
}
