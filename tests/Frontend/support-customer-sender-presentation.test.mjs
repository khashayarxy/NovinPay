import {test} from 'node:test';
import assert from 'node:assert/strict';
import {existsSync,mkdtempSync,writeFileSync,rmSync,realpathSync} from 'node:fs';
import {tmpdir} from 'node:os';
import path from 'node:path';
import {spawnSync} from 'node:child_process';
import {fileURLToPath,pathToFileURL} from 'node:url';

// Exercise the real customer renderer in Chromium; no application, network, or account access.
test('customer transcript hides human operator identity and labels only automated replies',t=>{
 const candidates=process.env.CHROME_BIN?[process.env.CHROME_BIN]:process.platform==='win32'?
  ['C:/Program Files/Google/Chrome/Application/chrome.exe','C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe']:
  ['/usr/bin/google-chrome','/usr/bin/chromium','/usr/bin/chromium-browser'];
 const executable=candidates.find(p=>existsSync(p));
 if(!executable){t.skip('Chromium is unavailable; customer-renderer browser acceptance remains required');return;}
 const directory=mkdtempSync(path.join(tmpdir(),'novinpay-customer-renderer-'));
 try{
  const renderer=pathToFileURL(fileURLToPath(new URL('../../resources/support-chat/support-client/customer-v2-renderer.js',import.meta.url))).href;
  const css=new URL('../../resources/support-chat/support-client/customer-v2.css',import.meta.url).href;
  const typingModule=new URL('../../resources/support-chat/support-client/typing.js',import.meta.url).href;
  const navigationModule=new URL('../../resources/support-chat/support-client/unread-jump.js',import.meta.url).href;
  const html=`<!doctype html><meta charset="utf-8"><link rel="stylesheet" href="${css}"><body><div id="messages" class="chat-messages"></div><script type="module">
   try {
    const {renderCustomerV2}=await import(${JSON.stringify(renderer)});
    const host=document.querySelector('#messages'),time='2026-11-08T08:00:00Z';
    const engine={role:'customer',sync:{id:'fixture',messages:new Map(),userName:'مشتری آزمایشی'},hooks:{},pendingGrace:()=>300};
    const messages=[
     {id:1,type:'admin',text:'پاسخ اپراتور اول',time,accepted_at:time,sender:{id:'a'.repeat(32),name:'اپراتور اول',avatar:'support-avatar.php?id='+'a'.repeat(32)}},
     {id:2,type:'admin',text:'پاسخ اپراتور دوم',time,accepted_at:time,sender:{id:'b'.repeat(32),name:'اپراتور دوم',avatar:'support-avatar.php?id='+'b'.repeat(32)}},
     {id:3,type:'system',origin:'faq_auto',text:'پاسخ خودکار',time,accepted_at:time},
     {id:4,type:'system',origin:'maintenance_notice',text:'پیام سیستمی عادی',time,accepted_at:time}
    ];
    const assert=(condition,message)=>{if(!condition)throw Error(message);};
    renderCustomerV2(host,messages,{engine,follow:false});
    const rows=[...host.querySelectorAll('article')];
    assert(rows.length===4,'All messages remain visible');
    for(const row of rows.slice(0,2)){
     assert(!row.querySelector('.v2-identity,.v2-sender-name,.support-sender-name,.support-sender-avatar'),'Human support message has no identity header or avatar');
     assert(row.firstElementChild?.classList.contains('v2-bubble-wrap'),'Human support bubble has no reserved header spacing');
    }
    assert(rows[2].querySelectorAll('.support-auto-label').length===1,'Automated reply has one label');
    assert(rows[2].querySelector('.support-auto-label').textContent==='پیام خودکار','Automated label uses approved text');
    assert(!rows[2].querySelector('.v2-identity,.support-sender-avatar'),'Automated reply has no avatar or identity header');
    assert(!rows[3].querySelector('.support-auto-label,.v2-identity,.support-sender-avatar'),'Non-automated system message is not mislabeled');
    const operatorEngine={...engine,role:'operator'};
    renderCustomerV2(host,messages,{engine:operatorEngine,operator:true,operatorName:'اپراتور فعلی',follow:false});
    const operatorNames=[...host.querySelectorAll('.v2-sender-name')].map(node=>node.textContent);
    assert(operatorNames.includes('اپراتور اول')&&operatorNames.includes('اپراتور دوم'),'Operator transcript retains trusted human sender names');
    const first={...messages[0],sender_group:'same',customer_read:true};
    const second={...first,id:2,accepted_at:'2026-11-08T08:01:00Z',sender:null,customer_read:false};
    renderCustomerV2(host,[first],{engine,follow:false});
    const firstBubble=host.querySelector('.v2-bubble');
    renderCustomerV2(host,[first,second],{engine,follow:false});
    const [a,b]=host.querySelectorAll('article');
    const gap=b.querySelector('.v2-bubble').getBoundingClientRect().top-firstBubble.getBoundingClientRect().bottom;
    assert(a.querySelector('.v2-bubble')===firstBubble,'New reply retains the existing bubble');
    assert(a.previousElementSibling?.classList.contains('support-new-separator')&&a.nextElementSibling===b,'Unread separator stays outside the group');
    assert(gap>=4&&gap<=6,'In-group bubble spacing is 4–6px, measured '+gap);
    assert(a.querySelector('.v2-meta-collapsed time').dateTime===time.replace('Z','.000Z'),'Earlier timestamp is retained');
    assert(!b.querySelector('.v2-meta-collapsed'),'Final timestamp stays visible');
    const pending={id:'pending',type:'user',client_message_id:'test-retry',text:'retry',_row:{uuid:'test-retry',state:'sending',created:Date.now()},_presentation_at:Date.parse(time)+60000};
    const customer={id:5,type:'user',text:'sent',accepted_at:time};
    renderCustomerV2(host,[customer,pending],{engine,follow:false});
    const retryRow=host.querySelector('[data-message-key="user:test-retry"]');
    renderCustomerV2(host,[customer,{...pending,id:6,_ack:true,accepted_at:'2026-11-08T08:01:00Z',read_receipt:true}],{engine,follow:false});
    assert(retryRow===host.querySelector('[data-message-id="6"]')&&retryRow.classList.contains('v2-grouped'),'ACK retains keyed grouped retry');
    assert(retryRow.querySelector('.support-read-state[data-state="read"]'),'Final receipt is visible');
    const {unreadJump}=await import(${JSON.stringify(navigationModule)});
    const region=document.createElement('section'),history=document.createElement('div');
    history.className='chat-messages';history.style.height='160px';region.append(history);document.body.append(region);
    const navigation=unreadJump({host:history,region,restoreOlder:true});
    const historyRows=Array.from({length:12},(_,i)=>({id:i+1,type:'admin',text:'row '+i,sender_account_group:'a'.repeat(32),
     ...(i<4?{time:'2026-11-08 11:3'+i+':00'}:{accepted_at:new Date(Date.parse(time)+i*60000).toISOString()})}));
    renderCustomerV2(history,historyRows.slice(4),{engine,follow:false});navigation.open();history.scrollTop=45;
    const anchor=navigation.before(true);
    renderCustomerV2(history,historyRows,{engine,older:true,follow:false});navigation.after(anchor,true);
    const anchored=[...history.children].find(el=>el.dataset.messageKey===anchor.key);
    assert(anchored&&Math.abs(anchored.getBoundingClientRect().top-history.getBoundingClientRect().top-anchor.offset)<1,'Prepending history preserves the reading anchor');
    assert(history.querySelector('[data-message-id="5"]').classList.contains('v2-grouped'),'Legacy and current bubbles group across the page boundary');
    navigation.destroy();region.remove();
    const {typingIndicator}=await import(${JSON.stringify(typingModule)});
    const activity=new Map();
    window.fetch=async (url,options)=>{
     const query=options.method==='POST'?options.body:new URL(url,'https://fixture.test').searchParams;
     const scope=query.get('scope'),id=query.get('user_id'),key=id+':'+scope;
     if(options.method==='POST')activity.set(key,query.get('typing')==='1');
     return new Response(JSON.stringify({typing:activity.get(id+':'+(scope==='customer'?'operator':'customer'))===true,expires_in:30}));
    };
    let conversation='one';
    const mountTyping=role=>{
     const wrapper=document.createElement('div'),input=document.createElement('textarea');
     wrapper.append(input);document.body.append(wrapper);
     const ui=typingIndicator({host:wrapper,input,role,dots:true,enabled:true,current:()=>({csrf_token:'fixture'}),conversation:()=>conversation});
     return {ui,input,label:wrapper.querySelector('[role=status]')};
    };
    const client=mountTyping('customer'),agent=mountTyping('operator');
    const tick=ms=>new Promise(resolve=>setTimeout(resolve,ms));
    const type=(view,text)=>{view.input.value=text;view.input.dispatchEvent(new Event('input'));};
    type(client,'customer draft');await tick(3100);
    assert(!agent.label.hidden&&client.label.hidden&&agent.label.querySelectorAll('i').length===3,'Customer typing is visible only to the operator');
    type(client,'');type(agent,'operator draft');await tick(3000);
    assert(agent.label.hidden&&!client.label.hidden,'Composer clear and reverse typing reach the opposite view');
    agent.ui.reset();conversation='two';await tick(3000);
    assert(client.label.hidden&&agent.label.hidden&&activity.get('one:operator')===false,'Conversation switch clears the previous binding');
    client.ui.destroy();agent.ui.destroy();
    document.body.dataset.result='CUSTOMER_SENDER_PRESENTATION_PASS';
   } catch(error) { document.body.dataset.result='CUSTOMER_SENDER_PRESENTATION_FAIL'; document.body.dataset.error=error.stack||error.message; }
  </script>`;
  const fixture=path.join(directory,'fixture.html');writeFileSync(fixture,html);
  const result=spawnSync(executable,['--headless=new','--disable-gpu','--disable-background-networking','--no-first-run','--allow-file-access-from-files','--user-data-dir='+path.join(directory,'profile'),'--virtual-time-budget=11500','--dump-dom',pathToFileURL(fixture).href],{encoding:'utf8',timeout:20000,maxBuffer:1024*1024,windowsHide:true});
  assert.ifError(result.error);assert.equal(result.status,0,'Disposable Chromium process failed');
  assert.match(result.stdout,/CUSTOMER_SENDER_PRESENTATION_PASS/,result.stdout.slice(-3000));
 } finally {
  if(realpathSync(path.dirname(directory))!==realpathSync(tmpdir())||!path.basename(directory).startsWith('novinpay-customer-renderer-'))throw Error('Unexpected test cleanup path');
  rmSync(directory,{recursive:true,force:true,maxRetries:3,retryDelay:100});
 }
});
