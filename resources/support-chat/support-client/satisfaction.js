// Presentation consumes the existing engine/page; it owns no identity or poller.
export function satisfaction({host,stream,engine,visible,following,warn,switchConversation,hasDraft,lifecycle:onLifecycle}){
 const box=document.createElement('section');box.className='support-completion';box.hidden=true;box.setAttribute('aria-label','پایان گفتگو');host.before(box);
 const title=document.createElement('strong');title.textContent='گفتگو پایان یافت';const thanks=document.createElement('p');
 const survey=document.createElement('div'),question=document.createElement('p');question.textContent='آیا از پاسخ‌گویی پشتیبانی رضایت داشتید؟';survey.append(question);
 const choices=document.createElement('div');choices.className='support-completion-actions';survey.append(choices);
 const reason=document.createElement('select');reason.setAttribute('aria-label','دلیل نارضایتی (اختیاری)');for(const [v,t]of [['','دلیل (اختیاری)'],['unsolved','مشکلم حل نشد'],['slow','پاسخ‌گویی دیر بود'],['unhelpful','پاسخ مفید نبود'],['other','سایر']])reason.add(new Option(t,v));
 const comment=document.createElement('textarea');comment.maxLength=200;comment.rows=2;comment.placeholder='توضیح کوتاه (اختیاری)';comment.setAttribute('aria-label',comment.placeholder);
 const negative=document.createElement('div');negative.hidden=true;negative.append(reason,comment);survey.append(negative);
 const actions=document.createElement('div');actions.className='support-completion-actions';
 let page=null,offer=null,busy=false,scope=null,conversation=null,dismissed=new Set(),selected=null,bootstrapped=false,timer=null,activeEvent=null;
 const storageKey=event=>`support-csat-dismissed:${globalThis.SupportWidgetConfig?.installation||''}:${conversation||''}:${event}`;
 const dismissedBefore=event=>{if(!event)return false;if(dismissed.has(event))return true;try{return localStorage.getItem(storageKey(event))==='1'}catch{return false}};
 const dismiss=event=>{if(!event)return;dismissed.add(event);try{localStorage.setItem(storageKey(event),'1')}catch{}update();};
 const schedule=event=>{if(timer)clearTimeout(timer);timer=null;activeEvent=event;const closed=Number(page?.closed_at);if(!Number.isFinite(closed)||closed<=0)return;const delay=closed*1000+3600000-Date.now();if(delay<=0){dismiss(event);return;}timer=setTimeout(()=>{if(offer?.event===activeEvent&&!offer.rating)dismiss(activeEvent);},delay);};
 const button=(text,run,parent,cls='')=>{const b=document.createElement('button');b.type='button';b.textContent=text;if(cls)b.className=cls;b.onclick=run;parent.append(b);return b;};
 const update=()=>{
  const voted=!!offer?.rating,dismissedNow=!!offer&&dismissedBefore(offer.event);
  const stageB=!offer||voted||dismissedNow;
  thanks.hidden=!stageB;thanks.textContent=voted?'نظر شما ثبت شد.':'برای ادامه یکی از گزینه‌ها را انتخاب کنید.';
  survey.hidden=!offer||stageB;negative.hidden=selected!=='negative'||voted;
  actions.hidden=!stageB;fresh.hidden=engine()?.context?.can_issue_recovery_code===false;
  box.hidden=!page?.resolved||page.retired||!visible();
 };
 const vote=async value=>{if(busy||!offer)return;busy=true;const current=engine(),id=current.sync.id,event=offer.event,expected=current.scope;for(const b of survey.querySelectorAll('button'))b.disabled=true;
  try{const r=await current.transport.request('api.php',{data:{action:'satisfaction',user_id:id,event,value,reason:value==='negative'?reason.value:'',comment:value==='negative'?comment.value:''}});if(engine().scope===expected&&engine().sync.id===id&&offer?.event===event){offer={...offer,rating:r.rating};comment.value='';update();}}
  catch(e){if(engine().scope===expected)warn(e.message);}finally{busy=false;for(const b of survey.querySelectorAll('button'))b.disabled=false;}
 };
 button('بله',()=>{selected=null;vote('positive');},choices);button('خیر',()=>{selected='negative';update();reason.focus();},choices);button('ثبت نظر',()=>vote('negative'),negative);button('فعلاً نه',()=>{if(offer)dismiss(offer.event);},survey);
 const run=async operation=>{if(busy)return;busy=true;const current=engine(),id=current.sync.id,controls=[...host.querySelectorAll('input,textarea,button,select')].map(el=>[el,el.disabled]);for(const [el]of controls)el.disabled=true;host.setAttribute('aria-busy','true');try{
  if(operation==='new'&&(hasDraft?.()||(await current.box.list(current.scope)).some(r=>r.conversation===id&&r.state!=='accepted')))throw Error('ابتدا پیش‌نویس و پیام‌های در انتظار همین گفتگو را بررسی کنید.');
  const r=await current.transport.request('api.php',{data:{lifecycle:operation,user_id:id,event:offer?.event||''}});if(engine()!==current||current.sync.id!==id)return;onLifecycle?.(r.lifecycle);
  if(operation==='new')await switchConversation?.(r.user_id);else{page=r.lifecycle;update();current.poller.wake();}
 }catch(e){warn(e.message);}finally{busy=false;for(const [el,disabled]of controls)el.disabled=disabled;host.removeAttribute('aria-busy');current.hooks.cooldown?.(current.sync.id);}};
 button('ادامه همین گفتگو',()=>run('reopen'),actions,'support-completion-primary');
 const fresh=button('شروع گفتگوی جدید',()=>run('new'),actions,'support-completion-secondary');
 box.append(title,thanks,survey,actions);
 addEventListener('support-widget-visibility',update);addEventListener('support-chat-visibility',update);
 return {refresh:update,page(value,lifecycle){const current=engine();const key=current.scope+':'+current.sync.id;const prior=offer;if(scope!==key||offer?.event!==value?.event){offer=null;selected=null;comment.value='';reason.value='';scope=key;conversation=current.sync.id;}page=lifecycle;offer=value&&/^[a-f0-9]{32}$/.test(value.event)?value:null;if(offer&&prior?.event===offer.event&&prior.rating)offer={...offer,rating:prior.rating};
  // Refresh preserves an unanswered offer; only explicit skip or expiry dismisses it.
  if(offer)schedule(offer.event);
  bootstrapped=true;requestAnimationFrame(update);onLifecycle?.(lifecycle);},clear(){if(timer)clearTimeout(timer);timer=null;offer=null;page=null;scope=null;conversation=null;bootstrapped=true;update();}};
}
