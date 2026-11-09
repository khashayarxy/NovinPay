const PUBLISH_INTERVAL=4000;
const TYPING_MS=30000;
function typingRequest(url,options){
 const controller=new AbortController(),timer=setTimeout(()=>controller.abort(),5000);
 return fetch(url,{...options,signal:controller.signal}).finally(()=>clearTimeout(timer));
}

export function typingIndicator({host,input,current,conversation,role='customer',dots=false,enabled=false,visible=()=>true}){
 const text=document.createElement('small');
 const label=role==='customer'?'پشتیبان در حال تایپ است':'مشتری در حال تایپ است';
 text.hidden=true;text.setAttribute('role','status');text.className='support-typing';text.textContent=label;
 if(dots){
  text.classList.add('v2-typing');text.textContent='';text.setAttribute('aria-label',label);
  for(let i=0;i<3;i++){const dot=document.createElement('i');dot.setAttribute('aria-hidden','true');text.append(dot);}
  host.append(text);
 }else host.prepend(text);
 let incomingHead=0,own=null,expiry=null,activity=null,dead=false,busy=false,generation=0;
 let publishing=Promise.resolve();
 const hide=()=>{clearTimeout(expiry);text.hidden=true;};
 const send=(binding,value)=>{
  publishing=publishing.catch(()=>{}).then(()=>{
   if(value&&(own!==binding||dead))return;
   const age=value?Math.ceil((performance.now()-binding.inputAt)/1000):0;
   if(age>=30)return;
   const data=new URLSearchParams({scope:role,user_id:binding.id,typing:value?'1':'0',idle_seconds:String(age)});
   return typingRequest('support-typing.php',{method:'POST',credentials:'same-origin',keepalive:true,headers:{'X-CSRF-Token':binding.token},body:data});
  }).catch(()=>{});
 };
 const stop=()=>{
  clearTimeout(activity);
  if(own){const binding=own;own=null;send(binding,false);}
 };
 const publish=()=>{
  if(!own||dead)return;
  own.sentAt=performance.now();send(own,true);
 };
 const write=()=>{
  const id=conversation(),token=current()?.csrf_token;
  if(!enabled||dead||document.hidden||!visible()||!navigator.onLine||!id||!token||input.disabled||!input.value.trim()){stop();return;}
  if(own&&(own.id!==id||own.token!==token))stop();
  const now=performance.now();
  own??={id,token,sentAt:-Infinity,inputAt:now};own.inputAt=now;
  clearTimeout(activity);
  const delay=PUBLISH_INTERVAL-(now-own.sentAt);
  if(delay<=0)publish();else activity=setTimeout(publish,delay);
 };
 const reset=()=>{generation++;stop();hide();incomingHead=0;};
 const visibility=()=>{if(document.hidden||!visible())reset();};
 input.addEventListener('input',write);
 document.addEventListener('visibilitychange',visibility);
 window.addEventListener('support-widget-visibility',visibility);
 window.addEventListener('offline',reset);window.addEventListener('pagehide',reset);
 const timer=enabled?setInterval(async()=>{
  const id=conversation();
  if(dead||!id||document.hidden||!visible()||!navigator.onLine||busy)return;
  const version=generation;busy=true;
  try{
   const r=await typingRequest('support-typing.php?'+new URLSearchParams({scope:role,user_id:id}),{credentials:'same-origin',cache:'no-store'});
   if(!r.ok){if([401,403,404].includes(r.status))hide();return;}
   const b=await r.json();
   if(dead||version!==generation||id!==conversation()||document.hidden||!visible())return;
   hide();
   if(b.typing===true&&Number.isFinite(b.expires_in)&&b.expires_in>0){
    text.hidden=false;expiry=setTimeout(hide,Math.min(TYPING_MS,b.expires_in*1000));
   }
  }catch{/* Keep the last server deadline on transient failures. */}finally{busy=false;}
 },3000):null;
 return {stop,reset,received(messages){
  const head=Math.max(0,...messages.filter(m=>m.type===(role==='customer'?'admin':'user')&&Number.isSafeInteger(m.id)).map(m=>m.id));
  if(head>incomingHead){generation++;hide();}incomingHead=head;
 },destroy(){
  reset();dead=true;clearInterval(timer);input.removeEventListener('input',write);
  document.removeEventListener('visibilitychange',visibility);window.removeEventListener('support-widget-visibility',visibility);
  window.removeEventListener('offline',reset);window.removeEventListener('pagehide',reset);text.remove();
 }};
}
