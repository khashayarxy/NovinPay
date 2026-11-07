// The drawer owns presentation only. Authentication and outbox scopes stay in ChatEngine.
export function chatMenu(win,ui){
    const drawer=document.createElement('section');drawer.className='support-drawer';drawer.id='supportOptions';drawer.hidden=true;drawer.setAttribute('role','dialog');drawer.setAttribute('aria-modal','true');drawer.setAttribute('aria-label','گزینه‌های گفتگو');
    const top=document.createElement('div');top.className='support-drawer-header';const heading=document.createElement('h5');heading.textContent='گزینه‌های گفتگو';
    const back=document.createElement('button');back.type='button';back.textContent='بازگشت';top.append(heading,back);
    const body=document.createElement('div');body.className='support-drawer-body';const recovery=document.createElement('section');recovery.className='support-recovery-options';
    const info=document.createElement('details');info.className='support-chat-info';const summary=document.createElement('summary');summary.textContent='اطلاعات گفتگو';info.append(summary,ui.panel);
    body.append(recovery,info);drawer.append(top,body);win.append(drawer);
    const toggle=document.createElement('button');toggle.type='button';toggle.className='support-menu-toggle';toggle.innerHTML='<span class="support-icon" aria-hidden="true">settings</span>';toggle.setAttribute('aria-label','تنظیمات گفتگو');toggle.title='تنظیمات گفتگو';toggle.setAttribute('aria-controls',drawer.id);toggle.setAttribute('aria-expanded','false');
    const actions=win.querySelector('.chat-header-actions');actions.prepend(toggle);
    const logout=win.querySelector('#logoutBtn');logout.replaceChildren();const icon=document.createElement('span');icon.className='support-icon';icon.setAttribute('aria-hidden','true');icon.textContent='logout';logout.append(icon);logout.setAttribute('aria-label','خروج از گفتگو');logout.title='خروج از گفتگو';
    let origin=toggle;
    const close=()=>{drawer.hidden=true;toggle.setAttribute('aria-expanded','false');for(const el of win.children)el.inert=false;origin?.focus();};
    const open=(focusRecovery=false)=>{
        origin=document.activeElement;drawer.hidden=false;toggle.setAttribute('aria-expanded','true');
        for(const el of win.children)if(el!==drawer&&el!==win.querySelector('.chat-header'))el.inert=true;
        if(focusRecovery){const first=[...recovery.querySelectorAll('details')].find(el=>!el.hidden);if(first)first.open=true;}
        back.focus();
    };
    back.onclick=close;toggle.onclick=()=>drawer.hidden?open():close();
    win.addEventListener('keydown',e=>{
        if(drawer.hidden)return;
        if(e.key==='Escape'){e.preventDefault();e.stopPropagation();close();return;}
        if(e.key!=='Tab')return;
        const focusable=[...drawer.querySelectorAll('button,input,select,summary,textarea,a[href]')].filter(el=>!el.disabled&&el.getClientRects().length&&!el.closest('[hidden]'));
        const first=focusable[0],last=focusable[focusable.length-1];if(e.shiftKey&&document.activeElement===first){e.preventDefault();last?.focus();}else if(!e.shiftKey&&document.activeElement===last){e.preventDefault();first?.focus();}
    },true);
    return {drawer,recovery,info,summary,body,open,close,toggle};
}
