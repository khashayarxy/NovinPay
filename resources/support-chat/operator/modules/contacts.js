import {mobileTable} from './mobile-table.js?v=mobile2';
// Customer Contacts module for NovinPay Operator Admin Panel V3
import {persianDate,messageDate} from '../../support-client/persian-date.js';

export function contactsView({host,api,navigate,openConversation,warn}){
 let searchTimer=null,currentQuery='',cursor=0,hasMore=false,contacts=[],loading=false;

 function formatTime(timeStr){
  if(!timeStr)return '–';
  try{
   return persianDate(messageDate({time:timeStr})).label;
  }catch{
   return timeStr;
  }
 }

 function buildUI(){
  host.replaceChildren();

  const wrap=document.createElement('div');
  wrap.className='contacts-container';

  // Header & Search
  const header=document.createElement('div');
  header.className='contacts-header';

  const headingBox=document.createElement('div');
  const h2=document.createElement('h2');
  h2.textContent='فهرست مخاطبین و مشتریان';
  const p=document.createElement('p');
  p.textContent='سوابق ارتباطات، پیام‌ها و راه‌های تماس ثبت‌شده مشتریان پشتیبانی.';
  headingBox.append(h2,p);

  const searchBox=document.createElement('div');
  searchBox.className='contacts-search-box';
  const searchInput=document.createElement('input');
  searchInput.type='search';
  searchInput.placeholder='جستجو در نام، شماره یا ایمیل…';
  searchInput.maxLength=200;
  searchInput.id='contactsSearchInput';
  searchInput.oninput=()=>{
   clearTimeout(searchTimer);
   searchTimer=setTimeout(()=>{
    currentQuery=searchInput.value.trim();
    loadContacts(false);
   },400);
  };
  searchBox.append(searchInput);

  header.append(headingBox,searchBox);
  wrap.append(header);

  // Contacts Table Card
  const card=document.createElement('div');
  card.className='admin-card';

  const tableWrap=document.createElement('div');
  tableWrap.className='report-table';
  tableWrap.id='contactsTableWrap';
  card.append(tableWrap);

  const moreBtn=document.createElement('button');
  moreBtn.type='button';
  moreBtn.className='secondary contacts-more-btn';
  moreBtn.id='contactsMoreBtn';
  moreBtn.textContent='بارگذاری موارد بیشتر';
  moreBtn.hidden=true;
  moreBtn.onclick=()=>loadContacts(true);
  card.append(moreBtn);

  wrap.append(card);
  host.append(wrap);
 }

 async function loadContacts(append=false){
  if(loading)return;
  loading=true;
  try{
   const offset=append?cursor:0;
   const res=await api('contacts',null,{q:currentQuery,offset,limit:50});
   cursor=res.next_offset||0;
   hasMore=!!res.has_more;

   if(append){
    contacts=[...contacts,...(res.contacts||[])];
   }else{
    contacts=res.contacts||[];
   }
   renderTable();
  }catch(e){
   warn(e);
  }finally{
   loading=false;
  }
 }

 function renderTable(){
  const wrap=document.getElementById('contactsTableWrap');
  const moreBtn=document.getElementById('contactsMoreBtn');
  if(!wrap)return;

  if(moreBtn)moreBtn.hidden=!hasMore;

  if(contacts.length===0){
   const empty=document.createElement('div');
   empty.className='contacts-empty';
   const icon=document.createElement('span');
   icon.className='support-icon';
   icon.textContent='people_outline';
   const p=document.createElement('p');
   p.textContent=currentQuery?'هیچ مخاطبی با این مشخصات یافت نشد.':'هنوز مخاطبی در سیستم ثبت نشده است.';
   empty.append(icon,p);
   wrap.replaceChildren(empty);
   return;
  }

  const tbl=document.createElement('table');
  const thead=document.createElement('thead');
  const headerRow=document.createElement('tr');
  for(const hText of ['نام مخاطب','تماس (ایمیل / تلفن)','آخرین پیام','تعداد پیام','زمان فعالیت','وضعیت','اقدام']){
   const th=document.createElement('th');
   th.textContent=hText;
   headerRow.append(th);
  }
  thead.append(headerRow);
  tbl.append(thead);

  const tbody=document.createElement('tbody');
  for(const c of contacts){
   const tr=document.createElement('tr');
   const name=c.name||'مهمان';
   const initials=name.trim().slice(0,1);
   const count=Number(c.message_count||0).toLocaleString('fa-IR');
   const time=formatTime(c.last_time);

   const tdName=document.createElement('td');
   const cellDiv=document.createElement('div');
   cellDiv.className='contact-user-cell';
   const av=document.createElement('span');
   av.className='account-avatar';
   av.textContent=initials;
   const strong=document.createElement('strong');
   strong.textContent=name;
   cellDiv.append(av,strong);
   tdName.append(cellDiv);

   const tdContact=document.createElement('td');
   const smallContact=document.createElement('small');
   const contactStr=[c.email,c.phone].filter(Boolean).join(' · ');
   if(contactStr){
    smallContact.textContent=contactStr;
   }else{
    const muted=document.createElement('span');
    muted.className='table-muted';
    muted.textContent='ثبت‌نشده';
    smallContact.append(muted);
   }
   tdContact.append(smallContact);

   const tdSnippet=document.createElement('td');
   const spanSnippet=document.createElement('span');
   spanSnippet.className='table-snippet';
   if(c.last_message){
    spanSnippet.textContent=c.last_message;
   }else{
    const muted=document.createElement('span');
    muted.className='table-muted';
    muted.textContent='(بدون متن)';
    spanSnippet.append(muted);
   }
   tdSnippet.append(spanSnippet);

   const tdCount=document.createElement('td');
   tdCount.textContent=count;
   if(Number.isInteger(c.automatic_message_count))tdCount.title=`مشتری: ${Number(c.customer_message_count).toLocaleString('fa-IR')} · پشتیبان انسانی: ${Number(c.operator_message_count).toLocaleString('fa-IR')} · پاسخ خودکار: ${c.automatic_message_count.toLocaleString('fa-IR')}`;

   const tdTime=document.createElement('td');
   const smallTime=document.createElement('small');
   smallTime.dir='ltr';smallTime.textContent=time;
   tdTime.append(smallTime);

   const tdStatus=document.createElement('td');
   const badge=document.createElement('span');
   if(c.status==='resolved'){
    badge.className='badge badge-success';
    badge.textContent='پایان‌یافته';
   }else if(c.status==='awaiting_reply'){
    badge.className='badge badge-urgent';
    badge.textContent='در انتظار پاسخ';
   }else if(c.unread>0){
    badge.className='badge badge-alert';
    badge.textContent='خوانده‌نشده';
   }else{
    badge.className='badge badge-normal';
    badge.textContent='جدید';
   }
   tdStatus.append(badge);

   const tdAction=document.createElement('td');
   const btn=document.createElement('button');
   btn.type='button';
   btn.className='compact-btn primary';
   btn.textContent='مشاهده گفتگو';
   btn.onclick=()=>{
    navigate('conversations',{id:c.id});
    openConversation?.(c.id);
   };
   tdAction.append(btn);

   tr.append(tdName,tdContact,tdSnippet,tdCount,tdTime,tdStatus,tdAction);
   tbody.append(tr);
  }
  tbl.append(tbody);
  wrap.replaceChildren(mobileTable(tbl));
 }

 return {
  mount(){
   buildUI();
   loadContacts(false);
  },
  refresh(){
   loadContacts(false);
  },
  destroy(){
   if(searchTimer)clearTimeout(searchTimer);
   host.replaceChildren();
  }
 };
}
