// Install UI never grants authentication or notification permission.
export function operatorPwa({button,dialog,registrationChanged,warn}){
 let prompt=null,installedHere=false;const $=id=>document.getElementById(id),standalone=matchMedia('(display-mode:standalone)'),installed=()=>installedHere||standalone.matches||navigator.standalone===true;
 const sync=()=>{const ios=/iPhone|iPad|iPod/.test(navigator.userAgent)||(navigator.platform==='MacIntel'&&navigator.maxTouchPoints>1);button.hidden=installed()||!prompt&&!ios;const feedback=$('pwaInstallStatus');if(feedback)feedback.textContent=installed()?'برنامه نصب شده است.':prompt?'نصب روی این دستگاه در دسترس است.':ios?'راهنمای افزودن به صفحهٔ اصلی در دسترس است.':'نصب مستقیم فعلاً در این مرورگر در دسترس نیست.';button.title=installed()?'برنامه نصب شده است':'نصب برنامه';button.setAttribute('aria-label',button.title);$('pwaInstall').hidden=installed()||!prompt;$('pwaGuide').textContent=installed()?'برنامه در حالت مستقل باز است. ورود و اتصال اینترنت همچنان لازم است.':/iPhone|iPad|iPod/.test(navigator.userAgent)||(navigator.platform==='MacIntel'&&navigator.maxTouchPoints>1)?'در Safari، دکمهٔ اشتراک‌گذاری (Share) و سپس افزودن به صفحهٔ اصلی (Add to Home Screen) را انتخاب کنید.':'از منوی مرورگر گزینهٔ «نصب برنامه» یا «افزودن به صفحهٔ اصلی» را انتخاب کنید. این گزینه در همهٔ مرورگرها یکسان نیست.';};
 button.onclick=()=>{if(button.closest('details'))button.closest('details').open=false;sync();dialog.showModal();};$('pwaClose').onclick=()=>dialog.close();dialog.addEventListener('close',()=>button.focus({preventScroll:true}));
 addEventListener('beforeinstallprompt',e=>{e.preventDefault();prompt=e;sync();});addEventListener('appinstalled',()=>{prompt=null;installedHere=true;sync();});standalone.addEventListener('change',sync);
 $('pwaInstall').onclick=async()=>{if(!prompt)return;const current=prompt;prompt=null;try{await current.prompt();await current.userChoice;}catch(e){warn(e);}sync();};sync();queueMicrotask(sync);
 if('serviceWorker'in navigator)navigator.serviceWorker.register(new URL('/support/sw.js',location.origin),{scope:'/support',updateViaCache:'none'}).then(r=>{
  registrationChanged(r);
  const waiting=()=>{$('pwaUpdate').hidden=!r.waiting;$('pwaUpdateEntry').hidden=!r.waiting;};waiting();
  r.addEventListener('updatefound',()=>r.installing?.addEventListener('statechange',()=>setTimeout(waiting,0)));
 }).catch(warn);
}
