export function themeControls(host){
 const row=document.createElement('label');row.className='support-theme-control';const label=document.createElement('span');label.textContent='ظاهر برنامه';const select=document.createElement('select');select.setAttribute('aria-label','ظاهر برنامه');
 const operator=window.SupportTheme?.surface==='operator';for(const [value,text]of [['light','روشن'],['dark','تیره'],...(operator?[['system','هماهنگ با دستگاه']]:[])])select.add(new Option(text,value));select.value=operator?window.SupportTheme?.mode||'dark':document.documentElement.dataset.supportTheme;row.append(label,select);host.prepend(row);select.onchange=()=>window.SupportTheme?.set(select.value);const changed=()=>select.value=operator?window.SupportTheme?.mode||'dark':document.documentElement.dataset.supportTheme;addEventListener('support-theme-change',changed);return {destroy(){removeEventListener('support-theme-change',changed);row.remove();}};
}
export function quickThemeToggle(host){
 const existing=host.querySelector('.support-quick-theme'),button=existing||document.createElement('button');button.type='button';button.className='support-quick-theme icon-button';
 const icon=button.querySelector('.support-icon')||document.createElement('span');icon.className='support-icon';icon.setAttribute('aria-hidden','true');if(!icon.parentElement)button.append(icon);
 const update=()=>{const dark=document.documentElement.dataset.supportTheme==='dark';icon.textContent=dark?'light_mode':'dark_mode';button.title=dark?'تغییر به حالت روشن':'تغییر به حالت تیره';button.setAttribute('aria-label',button.title);button.setAttribute('aria-pressed',String(dark));};
 button.onclick=()=>window.SupportTheme?.set(document.documentElement.dataset.supportTheme==='dark'?'light':'dark');addEventListener('support-theme-change',update);if(!existing)host.append(button);button.disabled=false;update();return button;
}
