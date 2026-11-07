// Hash-based client router for NovinPay Operator Admin Panel V3
export function createRouter({views,navLinks,titleHost,isAdmin,onNavigate}){
 const ROUTE_TITLES={
  dashboard:'داشبورد',
  conversations:'گفتگوها',
  knowledge:'پرسش و پاسخ',
  contacts:'مخاطبین',
  replies:'پاسخ سریع',
  analytics:'آمار و گزارش‌ها',
  team:'تیم پشتیبانی',
  settings:'تنظیمات',
  health:'سلامت و امنیت'
 };

 let previous=null;const scrolls=new Map();
 const stage=document.getElementById('adminStage');
 const ADMIN_ONLY=new Set(['analytics','team','health','knowledge']);

 function parseHash(){
  const raw=location.hash.replace(/^#/,'').trim();
  if(!raw)return {route:'conversations',params:{}};
  // Legacy compatibility: #conversation=user_...
  if(raw.startsWith('conversation=')){
   return {route:'conversations',params:{id:raw.slice(13)}};
  }
  const [path,query]=raw.split('?');
  const params={};
  if(query){
   for(const [k,v]of new URLSearchParams(query).entries())params[k]=v;
  }
  let route=path.toLowerCase();
  if(route==='settings'&&params.tab==='replies')route='replies';
  return {route:views[route]?route:'conversations',params};
 }

 function navigate(route,params={}){
  const qs=new URLSearchParams(params).toString();
  const nextHash='#'+route+(qs?'?'+qs:'');
  if(location.hash===nextHash){
   sync();
  }else{
   location.hash=nextHash;
  }
 }

 function sync(){
  let {route,params}=parseHash();
  if(ADMIN_ONLY.has(route)&&!isAdmin()){
   route='conversations';
   if(location.hash!=='#conversations'){
    location.hash='#conversations';
    return;
   }
  }

  if(previous&&stage)scrolls.set(previous,stage.scrollTop);
  // Update nav links active state
  for(const link of navLinks){
   const target=link.dataset.nav;
   const active=target===route;
   link.setAttribute('aria-current',active?'page':'false');
   link.classList.toggle('active',active);
  }

  for(const group of document.querySelectorAll('.nav-group')){const active=!!group.querySelector('[aria-current=page]');group.classList.toggle('has-active',active);if(active)group.open=true;}

  // Update page title / breadcrumbs
  if(titleHost){
   titleHost.textContent=ROUTE_TITLES[route]||'پشتیبانی';
  }

  // Update view elements visibility
  for(const [name,el]of Object.entries(views)){
   if(!el)continue;
   const active=name===route;
   el.hidden=!active;
   el.classList.toggle('active',active);
  }

  // Notify listeners
  try{
   onNavigate?.(route,params);
   if(stage&&route!==previous)stage.scrollTop=scrolls.get(route)||0;
   previous=route;
  }catch(e){
   console.error('Route navigation error:',e);
  }
 }

 function onNavClick(e){
  if(e.defaultPrevented||e.button!==0||e.metaKey||e.ctrlKey||e.shiftKey||e.altKey)return;
  const target=e.currentTarget?.dataset?.nav;
  if(target){
   e.preventDefault();
   navigate(target);
  }
 }

 for(const link of navLinks){
  link.addEventListener('click',onNavClick);
 }

 window.addEventListener('hashchange',sync);

 return {
  init(){sync();},
  navigate,
  current:()=>parseHash().route,
  destroy(){
   window.removeEventListener('hashchange',sync);
   for(const link of navLinks){
    link.removeEventListener('click',onNavClick);
   }
  }
 };
}
