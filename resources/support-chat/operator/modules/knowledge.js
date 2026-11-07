// Knowledge Base / FAQ module for NovinPay Operator Admin Panel V3
export function knowledgeView({host,csrfToken,warn}){
 let state=null,activeId=null,busy=false;

 async function apiRequest(body=null){
  const options={credentials:'same-origin',cache:'no-store'};
  const url='support-content-admin.php';
  if(body){
   options.method='POST';
   options.headers={'X-CSRF-Token':csrfToken()};
   options.body=new URLSearchParams(body);
  }
  const r=await fetch(url,options);
  const data=await r.json().catch(()=>({}));
  if(!r.ok){
   throw Error(data.message||'خطا در ارتباط با پرسش و پاسخ.');
  }
  return data;
 }

 async function loadState(){
  busy=true;
  try{
   state=await apiRequest();
   render();
  }catch(e){
   warn(e);
  }finally{
   busy=false;
  }
 }

 function render(){
  host.replaceChildren();

  const wrap=document.createElement('div');
  wrap.className='knowledge-container';

  // Header
  const header=document.createElement('div');
  header.className='knowledge-header';
  const headingBox=document.createElement('div');
  const h2=document.createElement('h2');
  h2.textContent='پرسش و پاسخ';
  const p=document.createElement('p');
  p.textContent='این سؤالات در صفحهٔ اول ویجت پشتیبانی به کاربران پیشنهاد می‌شوند.';
  headingBox.append(h2,p);

  const newBtn=document.createElement('button');
  newBtn.type='button';
  newBtn.className='primary';
  newBtn.innerHTML='<span class="support-icon">add</span><span>پرسش جدید</span>';
  newBtn.onclick=()=>{
   activeId=null;
   openEditor(null);
  };
  header.append(headingBox,newBtn);
  wrap.append(header);

  // Status/feedback
  const feedback=document.createElement('div');
  feedback.className='knowledge-feedback';
  feedback.id='knowledgeFeedback';
  feedback.setAttribute('role','status');
  wrap.append(feedback);

  const mainLayout=document.createElement('div');
  mainLayout.className='knowledge-layout';

  // Questions List
  const listCol=document.createElement('div');
  listCol.className='knowledge-list-col';
  const listCard=document.createElement('div');
  listCard.className='admin-card';

  const listTitle=document.createElement('h3');
  listTitle.textContent='فهرست پرسش‌ها';
  listCard.append(listTitle);

  const articles=(state?.articles||[])
   .filter(a=>a.suggested)
   .sort((a,b)=>(a.position||0)-(b.position||0));

  if(articles.length===0){
   const empty=document.createElement('div');
   empty.className='knowledge-empty';
   empty.innerHTML='<span class="support-icon">quiz</span><p>هنوز پرسشی ثبت نشده است.</p>';
   listCard.append(empty);
  }else{
   const list=document.createElement('div');
   list.className='knowledge-faq-list';
   for(const a of articles){
    const item=document.createElement('div');
    item.className='knowledge-faq-item'+(a.id===activeId?' is-active':'');

    const meta=document.createElement('div');
    meta.className='faq-item-meta';
    const title=document.createElement('strong');
    title.textContent=a.title;
    const preview=document.createElement('small');
    preview.textContent=a.body||'';
    meta.append(title,preview);

    const badges=document.createElement('div');
    badges.className='faq-item-badges';

    const statusBadge=document.createElement('span');
    statusBadge.className='badge '+(a.published?'badge-success':'badge-muted');
    statusBadge.textContent=a.published?'فعال':'پیش‌نویس';

    const posBadge=document.createElement('span');
    posBadge.className='badge badge-normal';
    posBadge.textContent='ترتیب: '+(a.position||0);

    const editBtn=document.createElement('button');
    editBtn.type='button';
    editBtn.className='compact-btn';
    editBtn.textContent='ویرایش';
    editBtn.onclick=()=>{
     activeId=a.id;
     openEditor(a);
     for(const el of list.children)el.classList.remove('is-active');
     item.classList.add('is-active');
    };

    badges.append(statusBadge,posBadge,editBtn);
    item.append(meta,badges);
    list.append(item);
   }
   listCard.append(list);
  }
  listCol.append(listCard);
  mainLayout.append(listCol);

  // Editor Form Column
  const formCol=document.createElement('div');
  formCol.className='knowledge-form-col';
  formCol.id='knowledgeFormCol';
  mainLayout.append(formCol);

  wrap.append(mainLayout);
  host.append(wrap);

  // Open first item or new form
  const first=articles[0]||null;
  activeId=first?.id||null;
  openEditor(first);
 }

 function openEditor(article){
  const col=document.getElementById('knowledgeFormCol');
  if(!col)return;
  col.replaceChildren();

  const card=document.createElement('div');
  card.className='admin-card';

  const form=document.createElement('form');
  form.className='admin-form';

  const h3=document.createElement('h3');
  h3.textContent=article?'ویرایش پرسش متداول':'افزودن پرسش جدید';
  form.append(h3);

  // Question Title
  const titleLabel=document.createElement('label');
  titleLabel.innerHTML='<span>عنوان پرسش (حداکثر ۱۶۰ نویسه)</span>';
  const titleInput=document.createElement('input');
  titleInput.type='text';
  titleInput.required=true;
  titleInput.maxLength=160;
  titleInput.value=article?.title||'';
  titleInput.placeholder='مثال: چطور می‌توانم دوره را دانلود کنم؟';
  titleLabel.append(titleInput);
  form.append(titleLabel);

  // Answer Body
  const bodyLabel=document.createElement('label');
  bodyLabel.innerHTML='<span>پاسخ کامل</span>';
  const bodyText=document.createElement('textarea');
  bodyText.required=true;
  bodyText.rows=6;
  bodyText.maxLength=12000;
  bodyText.value=article?.body||'';
  bodyText.placeholder='توضیحات پاسخ را اینجا بنویسید…';
  bodyLabel.append(bodyText);
  form.append(bodyLabel);

  // Order Position
  const posLabel=document.createElement('label');
  posLabel.innerHTML='<span>ترتیب اولویت نمایش (عدد کوچک‌تر جلوتر)</span>';
  const posInput=document.createElement('input');
  posInput.type='number';
  posInput.min='0';
  posInput.max='9999';
  posInput.value=String(article?.position||0);
  posLabel.append(posInput);
  form.append(posLabel);

  // Published toggle
  const pubLabel=document.createElement('label');
  pubLabel.className='checkbox-row';
  const pubInput=document.createElement('input');
  pubInput.type='checkbox';
  pubInput.checked=article?article.published:true;
  pubLabel.append(pubInput,document.createTextNode(' این پرسش در ویجت کاربر فعال و قابل مشاهده باشد'));
  form.append(pubLabel);

  // Action Buttons
  const btnRow=document.createElement('div');
  btnRow.className='form-actions-row';

  const saveBtn=document.createElement('button');
  saveBtn.type='submit';
  saveBtn.className='primary';
  saveBtn.textContent='ذخیره تغییرات';

  btnRow.append(saveBtn);

  if(article){
   const deleteBtn=document.createElement('button');
   deleteBtn.type='button';
   deleteBtn.className='danger-btn';
   deleteBtn.textContent='حذف این پرسش';
   deleteBtn.onclick=async()=>{
    if(!confirm('آیا از حذف این پرسش مطمئن هستید؟'))return;
    saveBtn.disabled=true;
    deleteBtn.disabled=true;
    try{
     await apiRequest({
      action:'article_delete',
      id:article.id,
      revision:String(state.revision)
     });
     showNotice('پرسش با موفقیت حذف شد.');
     activeId=null;
     await loadState();
    }catch(e){
     warn(e);
    }finally{
     saveBtn.disabled=false;
     deleteBtn.disabled=false;
    }
   };
   btnRow.append(deleteBtn);
  }

  form.append(btnRow);

  form.onsubmit=async e=>{
   e.preventDefault();
   saveBtn.disabled=true;
   const feedback=document.getElementById('knowledgeFeedback');
   if(feedback)feedback.textContent='در حال ذخیره…';
   try{
    const id=article?.id||crypto.randomUUID().replaceAll('-','');
    await apiRequest({
     action:'article_save',
     id,
     revision:String(state.revision),
     category:article?.category||'',
     title:titleInput.value.trim(),
     body:bodyText.value.trim(),
     summary:article?.summary||'',
     links:JSON.stringify(article?.links||[]),
     published:pubInput.checked?'1':'0',
     position:posInput.value||'0',
     suggested:'1',
     featured:article?.featured?'1':'0'
    });
    showNotice('اطلاعات پرسش با موفقیت ذخیره شد.');
    activeId=id;
    await loadState();
   }catch(err){
    warn(err);
   }finally{
    saveBtn.disabled=false;
   }
  };

  card.append(form);
  col.append(card);
 }

 function showNotice(msg){
  const el=document.getElementById('knowledgeFeedback');
  if(!el)return;
  el.textContent=msg;
  el.className='knowledge-feedback is-visible';
  clearTimeout(el.timer);
  el.timer=setTimeout(()=>{
   el.className='knowledge-feedback';
   el.textContent='';
  },4000);
 }

 return {
  mount(){
   loadState();
  },
  refresh(){
   loadState();
  },
  destroy(){
   host.replaceChildren();
  }
 };
}
