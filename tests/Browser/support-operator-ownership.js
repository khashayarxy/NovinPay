// Run with Chrome DevTools evaluate_script(sourcePath) on novinpaypos.test only.
// Synthetic DOM fixture: no login, conversation mutation, credentials or real messages.
async function verifyOperatorOwnershipUI() {
 if(location.hostname!=='novinpaypos.test')throw Error('Local fixture only');
 const manifest=await fetch('/build/support-chat/manifest.json').then(r=>r.json());
 const root='/build/support-chat/'+manifest.version+'/';
 document.documentElement.lang='fa';document.documentElement.dir='rtl';
 document.documentElement.dataset.supportSurface='operator';document.documentElement.dataset.supportTheme='light';
 document.head.replaceChildren();
 for(const path of ['operator/app.css','support-client/chat.css','support-client/design-tokens.css','operator/workspace.css','support-client/bplus.css','operator/mobile.css','support-client/interaction.css']){
  const link=document.createElement('link');link.rel='stylesheet';link.href=root+path;
  await new Promise((resolve,reject)=>{link.onload=resolve;link.onerror=reject;document.head.append(link);});
 }
 const style=document.createElement('style');style.textContent='body{display:block!important;overflow:auto!important;margin:0}#messageStream{position:static!important;width:100%;height:600px;overflow:auto}#chatMessages{min-height:0!important}';document.head.append(style);
 document.body.innerHTML='<main id="messageStream"><div id="chatMessages" role="log"></div></main>';
 const {renderCustomerV2}=await import(root+'support-client/customer-v2-renderer.js');
 const {conversationView}=await import(root+'support-client/engine.js');
 const host=document.getElementById('chatMessages'),sender={id:'a'.repeat(32),name:'اپراتور اول',avatar:null},other={id:'b'.repeat(32),name:'اپراتور دوم',avatar:null};
 const stamp='2026-11-08T08:00:00Z';
 const rows=[
  {id:1,type:'admin',text:'سلام',sender},
  {id:2,type:'admin',text:'یک پیام بلندتر برای بررسی ثابت بودن محل نام و راست‌چین بودن پیام‌ها در نمای کوچک و بزرگ',sender},
  {id:3,type:'admin',text:'پاسخ اپراتور دوم',sender:other},
  {id:4,type:'user',text:'پرسش مشتری'},
  {id:5,type:'admin',text:'ادامه گفتگو',sender},
  {id:6,type:'system',origin:'faq_auto',text:'پاسخ خودکار اول'},
  {id:7,type:'system',origin:'faq_auto',text:'پاسخ خودکار دوم'},
  {id:8,type:'admin',text:'سابقه پشتیبان حذف‌شده',sender:{id:'c'.repeat(32),name:'اپراتور سابق'}}
 ].map(m=>({...m,accepted_at:stamp,time:stamp,read:true}));
 const engine={role:'operator',scope:'fixture',sync:{id:'fixture',messages:new Map()},knownAcks:new Map(),operatorSender:sender,operatorSenderGroup:'own',hooks:{canSend:()=>true},transport:{presentationTime:x=>x},pendingGrace:()=>0};
 const render=messages=>renderCustomerV2(host,messages,{operator:true,operatorName:sender.name,engine,follow:false});
 render(rows);await document.fonts.ready;
 const assert=(condition,message)=>{if(!condition)throw Error(message);};
 const articles=[...host.querySelectorAll('article')],labels=[...host.querySelectorAll('.v2-sender-name')];
 assert(articles.length===8,'Message count');assert(labels.length===5,'One name per consecutive group');
 assert(labels.map(e=>e.textContent).join('|')==='اپراتور اول|اپراتور دوم|اپراتور اول|پاسخ خودکار|اپراتور سابق','Attribution');
 assert(!host.querySelector('.v2-identity img,.v2-identity .support-sender-avatar,.v2-identity .support-icon'),'No operator avatars');
 const rights=labels.map(e=>e.getBoundingClientRect().right);
 assert(Math.max(...rights)-Math.min(...rights)<1,'Fixed label right edge');
 for(const article of articles){
  const support=article.dataset.messageId!=='4',bubble=article.querySelector('.v2-bubble'),box=bubble.getBoundingClientRect(),frame=host.getBoundingClientRect();
  assert(article.classList.contains('outgoing')===support,'Support direction independent of viewer');
  assert(support?Math.abs(frame.right-box.right-parseFloat(getComputedStyle(host).paddingRight))<2:box.left<frame.left+frame.width/2,'Bubble alignment');
  assert(box.left>=0&&box.right<=innerWidth+1,'No bubble overflow');
 }
 const groupedGap=articles[1].getBoundingClientRect().top-articles[0].getBoundingClientRect().bottom;
 assert(groupedGap>=0&&groupedGap<=10,'Compact consecutive spacing');
 const colors={support:getComputedStyle(articles[0].querySelector('.v2-bubble')).backgroundColor,customer:getComputedStyle(articles[3].querySelector('.v2-bubble')).backgroundColor};
 assert(colors.support!==colors.customer,'Distinct support/customer bubbles');
 // Exercise the real optimistic -> ACK -> server renderer without changing its UUID/key.
 const view=conversationView(host,engine,{renderer:(_,messages)=>render(messages)});
 host.replaceChildren();
 const row={key:'pending',uuid:'12345678-1234-4234-8234-123456789012',scope:'fixture',conversation:'fixture',role:'operator',ordinal:1,created:Date.parse(stamp),text:'پیام آزمایشی',attachments:[],state:'pending'};
 const identities=[],nodes=[];
 const capture=()=>{identities.push(host.querySelector('.v2-sender-name')?.textContent);nodes.push(host.querySelector('article'));assert(host.querySelectorAll('article').length===1,'No duplicate bubbles');};
 view.queue([row],'fixture');capture();
 view.queue([{...row,state:'needs_action'}],'fixture');capture();
 engine.knownAcks.set(row.key,{message_id:9,accepted_at:stamp});view.accepted(row);capture();
 view.messages([{...rows[0],id:9,text:row.text,client_message_id:row.uuid}],false);capture();
 assert(identities.every(name=>name===sender.name),'No generic sender flicker');assert(nodes.every(node=>node===nodes[0]),'Stable keyed message element');
 assert(document.documentElement.scrollWidth<=innerWidth,'No horizontal overflow');
 return {result:'OPERATOR_OWNERSHIP_UI_PASS',viewport:{width:innerWidth,height:innerHeight},labels:labels.length,groupedGap,colors,stableAckElement:true};
}
