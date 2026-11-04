export function typingIndicator({host,input,current,conversation,role='customer',dots=false,visible=()=>true}){
 const text=document.createElement('small');text.hidden=true;text.setAttribute('role','status');text.className='support-typing';text.textContent=role==='customer'?'پشتیبان در حال تایپ است...':'مشتری در حال تایپ است...';if(dots){text.classList.add('v2-typing');text.textContent='';text.setAttribute('aria-label','پشتیبان در حال تایپ است');for(let i=0;i<3;i++){const dot=document.createElement('i');dot.setAttribute('aria-hidden','true');text.append(dot);}host.append(text);}else host.prepend(text);
 let incomingHead=0,enabled=false,last=0,busy=false,own=false,expiry=null,activity=null,dead=false;
 fetch('api.php?widget_config=1',{cache:'no-store'}).then(r=>r.json()).then(c=>enabled=c.typing===true).catch(()=>{});
 const hide=()=>{clearTimeout(expiry);text.hidden=true;};
 const send=value=>{const id=conversation();if(!enabled||!id||!current()?.csrf_token)return;const data=new URLSearchParams({scope:role,user_id:id,typing:value?'1':'0'});fetch('support-typing.php',{method:'POST',credentials:'same-origin',keepalive:true,headers:{'X-CSRF-Token':current().csrf_token},body:data}).catch(()=>{});};
 const stop=()=>{clearTimeout(activity);if(own){own=false;send(false);}};
 const write=()=>{if(dead||document.hidden||!visible()||!navigator.onLine)return;if(!input.value.trim()){stop();return;}clearTimeout(activity);activity=setTimeout(stop,5000);if(Date.now()-last<4000)return;last=Date.now();own=true;send(true);};
 const visibility=()=>{if(document.hidden){hide();stop();}};const offline=()=>{hide();stop();};
 input.addEventListener('input',write);input.addEventListener('blur',stop);document.addEventListener('visibilitychange',visibility);window.addEventListener('offline',offline);window.addEventListener('pagehide',stop);
 const timer=setInterval(async()=>{const id=conversation();if(dead||!enabled||!id||document.hidden||!visible()||!navigator.onLine||busy){if(document.hidden||!visible()||!navigator.onLine){hide();stop();}return;}busy=true;
  try{const r=await fetch('support-typing.php?'+new URLSearchParams({scope:role,user_id:id}),{credentials:'same-origin',cache:'no-store'});if(!r.ok){hide();return;}const b=await r.json();if(dead||id!==conversation()||document.hidden||!visible()){hide();return;}hide();if(b.typing===true&&Number.isFinite(b.expires_in)&&b.expires_in>0){text.hidden=false;expiry=setTimeout(hide,Math.min(7,b.expires_in)*1000);}}
  catch{hide();}finally{busy=false;}
 },3000);
 return {stop,reset(){stop();hide();incomingHead=0;last=0;},received(messages){const head=Math.max(0,...messages.filter(m=>m.type===(role==='customer'?'admin':'user')&&Number.isSafeInteger(m.id)).map(m=>m.id));if(head>incomingHead)hide();incomingHead=head;},destroy(){stop();dead=true;hide();clearInterval(timer);input.removeEventListener('input',write);input.removeEventListener('blur',stop);document.removeEventListener('visibilitychange',visibility);window.removeEventListener('offline',offline);window.removeEventListener('pagehide',stop);text.remove();}};
}
