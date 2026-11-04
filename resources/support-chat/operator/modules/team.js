// Account authority is Laravel: no second password store or orphan operator accounts.
export function teamView({host,api,warn}) {
 let generation=0;
 async function render(){
  const version=++generation;
  host.replaceChildren();
  const title=document.createElement('h2');title.textContent='همکاران پشتیبانی';
  const manage=document.createElement('a');manage.href='/admin/staff';manage.className='primary';manage.textContent='مدیریت همکاران و دسترسی‌ها';host.append(title,manage);
  try{const result=await api('operator_accounts');if(version!==generation)return;
   const list=document.createElement('ul');for(const account of result.accounts||[]){const row=document.createElement('li');row.textContent=account.name+' · '+(account.role==='administrator'?'مدیر':'پشتیبان')+' · '+(account.active?'فعال':'غیرفعال');list.append(row);}host.append(list);
  }catch(error){warn(error);}
 }
 return {mount(){render();},refresh(){render();},destroy(){generation++;host.replaceChildren();}};
}
