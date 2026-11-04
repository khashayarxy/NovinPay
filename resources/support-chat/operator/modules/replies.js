// Move the existing form; its listeners, data, revision checks and macro behavior stay owned by app.js.
export function repliesView({host,load,warn}){
 const form=document.getElementById('quickReplyForm'),home=form.parentNode;let mounted=false;
 return {
  mount(){
   if(!mounted){const header=document.createElement('div');header.className='settings-header';const copy=document.createElement('div'),title=document.createElement('h2'),description=document.createElement('p');title.textContent='پاسخ سریع';description.textContent='پاسخ‌های آماده و اقدامات اختیاری را اینجا مدیریت کنید؛ درج و ارسال از داخل گفتگو انجام می‌شود.';copy.append(title,description);header.append(copy);
    const feedback=document.createElement('p');feedback.id='quickReplyFeedback';feedback.setAttribute('role','status');const card=document.createElement('section');card.className='admin-card';form.hidden=false;card.append(form);host.append(header,feedback,card);mounted=true;}
   load().catch(warn);
  },
  destroy(){home.append(form);form.hidden=true;host.replaceChildren();mounted=false;}
 };
}
