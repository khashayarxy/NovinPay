// Synchronous, public presentation bootstrap. Never reads or stores identity.
(() => {
 const root=document.documentElement,surface=['operator','customer-v2'].includes(root.dataset.supportSurface)?root.dataset.supportSurface:'customer',media=matchMedia('(prefers-color-scheme: dark)');
 const modes=new Set(['light','dark','system']);let installation=null,mode=surface==='operator'?'dark':'system',defaultMode=mode;
 if(surface==='operator'){try{const direct=localStorage.getItem('support-theme:operator');if(modes.has(direct))mode=direct;}catch{}}
 const marker='support-theme-installation:'+new URL(document.currentScript.src).pathname+':'+surface;try{const previous=localStorage.getItem(marker);if(/^[a-f0-9]{32}$/.test(previous)||previous==='legacy-zizolearn')installation=previous;}catch{}
 const key=()=>`support-theme:${installation}:${surface==='operator'?'operator':'customer'}`;
 const preference=()=>{const saved=localStorage.getItem(key());if(modes.has(saved))return saved;if(surface==='operator'){const direct=localStorage.getItem('support-theme:operator');if(modes.has(direct))return direct;}if(surface!=='operator'&&installation){const previous=localStorage.getItem(`support-theme:${installation}:customer-v2`)||localStorage.getItem('theme');if(modes.has(previous)){localStorage.setItem(key(),previous);return previous;}}return null;};
 const apply=()=>{root.dataset.supportTheme=mode==='system'?(media.matches?'dark':'light'):mode;root.dataset.supportThemeMode=mode;root.dataset.supportSurface=surface;if(surface!=='operator')root.dataset.theme=root.dataset.supportTheme;dispatchEvent(new CustomEvent('support-theme-change',{detail:{surface,mode,resolved:root.dataset.supportTheme}}));};
 window.SupportTheme={get mode(){return mode;},get surface(){return surface;},configure(config){
  if(!config||!(/^[a-f0-9]{32}$/.test(config.installation)||config.installation==='legacy-zizolearn'))return;
  if(/^#[a-f0-9]{6}$/i.test(config.color)){const rgb=config.color.slice(1).match(/../g).map(v=>parseInt(v,16));const luminance=()=>rgb.map(n=>{n/=255;return n<=.04045?n/12.92:((n+.055)/1.055)**2.4;}).reduce((sum,n,i)=>sum+n*[.2126,.7152,.0722][i],0);while(1.05/(luminance()+.05)<4.5)for(let i=0;i<3;i++)rgb[i]=Math.floor(rgb[i]*.92);const hex=v=>'#'+v.map(n=>n.toString(16).padStart(2,'0')).join('');root.style.setProperty('--support-primary',hex(rgb));root.style.setProperty('--support-deep',hex(rgb.map(n=>Math.round(n*.68))));}if(/^#[a-f0-9]{6}$/i.test(config.accent))root.style.setProperty('--support-accent',config.accent);
  installation=config.installation;try{localStorage.setItem(marker,installation);}catch{}defaultMode=surface==='operator'?(config.operator_theme_default||'dark'):'system';if(!modes.has(defaultMode))defaultMode='system';mode=defaultMode;
  try{const saved=preference();if(modes.has(saved))mode=saved;}catch{}apply();
 },set(value){if(!modes.has(value))return;mode=value;if(surface==='operator')try{localStorage.setItem('support-theme:operator',mode);}catch{}if(installation)try{localStorage.setItem(key(),mode);}catch{}apply();},reset(){mode=defaultMode;apply();}};
 if(installation)try{const saved=preference();if(modes.has(saved))mode=saved;}catch{}
 addEventListener('storage',event=>{if(event.key!==key()&&event.key!=='support-theme:operator'||surface!=='operator'&&event.key!==key())return;mode=modes.has(event.newValue)?event.newValue:(surface==='operator'?'dark':'system');apply();});
 media.addEventListener?.('change',()=>{if(mode==='system')apply();});apply();
})();
