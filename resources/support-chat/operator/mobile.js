// Presentation/lifecycle only: no identity, messages or credentials are stored here.
export function mobileWorkspace({account,drawer,closeDrawer,menuButton,header,stage,stream}){
 const mobile=matchMedia('(max-width:899px)');
 const closeAccount=()=>{account.open=false;};
 document.addEventListener('pointerdown',e=>{if(!account.contains(e.target))closeAccount();});
 document.addEventListener('keydown',e=>{
  if(e.key==='Escape'&&account.open){closeAccount();account.querySelector('summary').focus();}
  if(e.key==='Tab'&&drawer.classList.contains('mobile-open')){
   const items=[...drawer.querySelectorAll('a[href],button:not([disabled])')].filter(x=>x.getClientRects().length);
   const first=items[0],last=items.at(-1);
   if(e.shiftKey&&document.activeElement===first){e.preventDefault();last?.focus();}
   else if(!e.shiftKey&&document.activeElement===last){e.preventDefault();first?.focus();}
  }
 });
 const observer=new MutationObserver(()=>{
  const opened=mobile.matches&&drawer.classList.contains('mobile-open');
  stage.inert=opened;header.querySelectorAll(':scope > *').forEach(x=>{if(x!==menuButton)x.inert=opened;});
  if(opened){closeAccount();drawer.querySelector('a[aria-current=page]')?.focus();}
 });
 observer.observe(drawer,{attributes:true,attributeFilter:['class']});
 const close=document.createElement('button');close.type='button';close.className='icon-button drawer-close mobile-only';close.setAttribute('aria-label','بستن منو');close.textContent='×';close.onclick=()=>{closeDrawer();menuButton.focus();};drawer.querySelector('.sidebar-top').append(close);
 let previousHeight=0;
 const fit=()=>{
  if(document.documentElement.dataset.operatorAuth!=='authenticated'){document.body.style.removeProperty('height');document.body.style.removeProperty('margin-top');return;}
  const v=window.visualViewport;if(v&&Math.abs(v.scale-1)>0.01)return;
  const height=Math.min(v?.height||innerHeight,innerHeight),atEnd=stream.scrollHeight-stream.scrollTop-stream.clientHeight<80;
  document.body.style.height=height+'px';document.body.style.marginTop=(v?.offsetTop||0)+'px';
  if(previousHeight!==height&&atEnd)requestAnimationFrame(()=>{stream.scrollTop=stream.scrollHeight;});
  previousHeight=height;
 };
 const schedule=()=>{fit();requestAnimationFrame(fit);};
 new MutationObserver(schedule).observe(document.documentElement,{attributes:true,attributeFilter:['data-operator-auth']});
 addEventListener('resize',schedule);window.visualViewport?.addEventListener('resize',schedule);window.visualViewport?.addEventListener('scroll',schedule);fit();
 return {closeAccount};
}
