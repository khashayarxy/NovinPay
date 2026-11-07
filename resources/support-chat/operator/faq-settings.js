// FAQ records are existing suggested knowledge articles, not a parallel config.
export function faqSettings(host,{state,request,saved}){
 const title=document.createElement('h3');title.textContent='سؤالات متداول';const form=document.createElement('form');form.id='faqEditor';
 const list=document.createElement('select');list.id='faqList';list.setAttribute('aria-label','پرسش ذخیره‌شده');
 const question=document.createElement('input');question.id='faqQuestion';question.maxLength=160;question.required=true;
 const answer=document.createElement('textarea');answer.id='faqAnswer';answer.maxLength=12000;answer.rows=4;answer.required=true;
 const enabled=document.createElement('input');enabled.type='checkbox';enabled.id='faqEnabled';enabled.checked=true;
 const position=document.createElement('input');position.type='number';position.min=0;position.max=9999;position.value='0';position.id='faqPosition';
 for(const [label,control]of [['پرسش ذخیره‌شده',list],['پرسش',question],['پاسخ',answer],['فعال',enabled],['ترتیب نمایش',position]]){const l=document.createElement('label');l.append(label,control);form.append(l);}
 const save=document.createElement('button');save.type='submit';save.textContent='ذخیره پرسش';const remove=document.createElement('button');remove.type='button';remove.textContent='حذف پرسش';const feedback=document.createElement('small');feedback.setAttribute('role','status');form.append(save,remove,feedback);host.append(title,form);let active=null,busy=false;
 function refresh(){list.replaceChildren(new Option('پرسش تازه',''));for(const a of state().articles.filter(a=>a.suggested).sort((a,b)=>a.position-b.position))list.add(new Option(a.title+(a.published?'':' (غیرفعال)'),a.id));list.value=active||'';}
 function select(){active=list.value||null;const a=state().articles.find(a=>a.id===active);question.value=a?.title||'';answer.value=a?.body||'';enabled.checked=a?.published??true;position.value=String(a?.position||0);remove.disabled=!active;}
 list.onchange=select;refresh();select();
 async function mutate(deleting=false){if(busy)return;if(deleting&&(!active||!confirm('این پرسش از فهرست سؤالات متداول حذف شود؟')))return;busy=true;feedback.textContent='در حال ذخیره…';for(const el of form.querySelectorAll('input,textarea,select,button'))el.disabled=true;try{const old=state().articles.find(a=>a.id===active),id=active||crypto.randomUUID().replaceAll('-','');const values={id,revision:String(state().revision),...(deleting?{action:'article_delete'}:{action:'article_save',category:old?.category||'',title:question.value,body:answer.value,summary:old?.summary||'',links:JSON.stringify(old?.links||[]),published:enabled.checked?'1':'0',position:position.value,suggested:'1',featured:old?.featured?'1':'0'})};await request(values);await saved();active=deleting?null:id;refresh();select();form.dispatchEvent(new CustomEvent('support-form-saved',{bubbles:true}));feedback.textContent='ذخیره شد.';}catch(e){feedback.textContent=e.message;}finally{busy=false;for(const el of form.querySelectorAll('input,textarea,select,button'))el.disabled=false;remove.disabled=!active;}}
 form.onsubmit=e=>{e.preventDefault();mutate();};remove.onclick=()=>mutate(true);
}
