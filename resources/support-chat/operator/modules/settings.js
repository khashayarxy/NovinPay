// Comprehensive Categorized Settings module for NovinPay Operator Admin Panel V3
import {v3Icon} from './icons.js';

export function settingsView({
 host,
 api,
 currentOperator,
 csrfToken,
 widgetUI,
 accountUI,
 preferences,
 savePreferences,
 replies,
 loadReplies,
 warn
}){
 let currentTab='appearance';
 let initialized=false;
 let wrap=null;
 const panels={};
 let tabsNav=null;
 // These existing forms are moved into the view, never cloned or discarded.
 const homes=[...document.querySelectorAll('#settingsFeedback,#appearanceSettings,#notificationForm,[data-settings-panel=account],[data-settings-panel=widget]')].map(node=>[node,node.parentNode]);

 function ensureUI(){
  if(initialized&&host.contains(wrap))return;

  host.replaceChildren();
  wrap=document.createElement('div');
  wrap.className='settings-view-container';

  // Header
  const header=document.createElement('div');
  header.className='settings-header';
  const headingBox=document.createElement('div');
  const h2=document.createElement('h2');
  h2.textContent='تنظیمات سامانه پشتیبانی';
  const p=document.createElement('p');
  p.textContent='شخصی‌سازی ظاهر ویجت، رفتار اعلان‌ها، ساعات کاری و پروفایل.';
  headingBox.append(h2,p);
  header.append(headingBox);

  const feedback=document.getElementById('settingsFeedback');
  if(feedback){
   feedback.className='settings-feedback-bar';
   header.append(feedback);
  }

  wrap.append(header);

  // Tabs Nav
  tabsNav=document.createElement('nav');
  tabsNav.className='settings-view-tabs';

  const tabDefs=[
   {key:'appearance',label:'ظاهر و برند ویجت',icon:'palette',adminOnly:true},
   {key:'notifications',label:'اعلان‌ها و صدا',icon:'bell',adminOnly:false},
   {key:'account',label:'پروفایل شخصی',icon:'person',adminOnly:false}
  ];

  const isAdmin=currentOperator()?.role==='administrator';

  for(const def of tabDefs){
   if(def.adminOnly&&!isAdmin)continue;

   const btn=document.createElement('button');
   btn.type='button';
   btn.className='settings-tab-btn';
   btn.dataset.tab=def.key;
   btn.innerHTML=`${v3Icon(def.icon)}<span>${def.label}</span>`;
   btn.setAttribute('aria-pressed','false');
   btn.onclick=()=>switchTab(def.key);
   tabsNav.append(btn);

   const panel=document.createElement('section');
   panel.className='admin-card settings-panel-card';
   panel.id=`settingsPanel_${def.key}`;
   panel.hidden=true;

   if(def.key==='appearance'){
    const h3=document.createElement('h3');
    h3.textContent='ظاهر، رنگ‌بندی و متن‌های پیش‌فرض ویجت';
    const info=document.createElement('p');
    info.className='analytics-info-bar';
    info.textContent='تنظیمات این بخش بلافاصله در ویجت گفتگوی مشتریان اعمال می‌شود.';
    panel.append(h3,info);
    const appThemes=document.getElementById('appearanceSettings');
    if(appThemes)panel.append(appThemes);
    const widgetSection=document.querySelector('[data-settings-panel=widget]');
    if(widgetSection){
     widgetSection.hidden=false;
     panel.append(widgetSection);
    }
   }else if(def.key==='notifications'){
    const h3=document.createElement('h3');
    h3.textContent='تنظیمات اعلان‌ها، هشدار صوتی و پیامک';
    const soundOrig=document.getElementById('notificationForm');
    if(soundOrig){
     soundOrig.hidden=false;
     panel.append(h3,soundOrig);
    }
   }else if(def.key==='account'){
    const h3=document.createElement('h3');
    h3.textContent='اطلاعات حساب، نام نمایشی و رمز عبور';
    const accSection=document.querySelector('[data-settings-panel=account]');
    if(accSection){
     accSection.hidden=false;
     panel.append(h3,accSection);
    }
   }

   panels[def.key]=panel;
  }

  wrap.append(tabsNav,...Object.values(panels));
  host.append(wrap);
  initialized=true;
 }

 function switchTab(tabKey){
  ensureUI();
  const isAdmin=currentOperator()?.role==='administrator';
  if(tabKey==='appearance'&&!isAdmin){
   tabKey='notifications';
  }
  if(!panels[tabKey]){
   tabKey=panels.appearance?'appearance':'notifications';
  }
  currentTab=tabKey;

  for(const [k,p]of Object.entries(panels)){
   p.hidden=k!==currentTab;
   if(k===currentTab){
    for(const child of p.querySelectorAll('section[data-settings-panel],form,#widgetEditor,#deviceNotifications')){
     child.hidden=false;
    }
   }
  }
  if(tabsNav){
   for(const b of tabsNav.children){
    b.setAttribute('aria-pressed',String(b.dataset.tab===currentTab));
   }
  }

  if(currentTab==='appearance'){
   widgetUI?.load?.().catch(warn);
  }else if(currentTab==='account'){
   accountUI?.load?.().catch(warn);

  }
 }

 return {
  mount(tab='appearance'){
   ensureUI();
   switchTab(tab);
  },
  refresh(){
   switchTab(currentTab);
  },
  destroy(){
   for(const [node,parent]of homes)parent.append(node);
   host.replaceChildren();initialized=false;wrap=null;tabsNav=null;
  }
 };
}
