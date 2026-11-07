const config=await fetch('api.php?widget_config=1',{cache:'no-store'}).then(r=>r.json());
globalThis.SupportWidgetConfig=config;
document.documentElement.style.setProperty('--support-brand',config.color);
document.querySelector('.chat-heading h4').textContent='پشتیبانی '+config.name;
document.getElementById('adminStatus').textContent=config.availability;
if(config.logo){const logo=document.createElement('img');logo.src=config.logo;logo.alt='';logo.width=24;logo.height=24;document.querySelector('.chat-heading h4').prepend(logo);}
document.querySelector('.chat-btn').hidden=true;
globalThis.SupportSourcePath=parent===window?location.pathname:null;
const {mountCustomer}=await import('./customer.js?v=ux1');await mountCustomer();window.toggleChat();
globalThis.SupportWidgetHidden=parent!==window;parent.postMessage({type:'support-widget-ready'},location.origin);
addEventListener('message',event=>{if(event.origin===location.origin&&event.source===parent&&event.data?.type==='support-widget-visibility'){globalThis.SupportSourcePath=config.source_paths?.includes(event.data.source_path)?event.data.source_path:null;globalThis.SupportWidgetHidden=!event.data.visible;dispatchEvent(new CustomEvent('support-widget-visibility',{detail:{visible:event.data.visible}}));if(event.data.visible){document.getElementById('chatInput').focus();}}});
const close=window.closeChat;window.closeChat=()=>{if(parent!==window){globalThis.SupportWidgetHidden=parent!==window;parent.postMessage('support-widget-close',location.origin);}else close();};
