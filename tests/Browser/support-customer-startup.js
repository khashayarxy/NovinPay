// Playwright function for the local Herd site. Session and history responses are synthetic.
async page=>{
 const origin='https://novinpaypos.test',out=[],id='user_'+('a'.repeat(32))+'_42',fixture={role:'customer',protocol_version:2,scope_id:'b'.repeat(64),session_epoch:'c'.repeat(32)};
 for(const {mobile,fail,existing} of [{mobile:false,fail:true,existing:true},{mobile:true,fail:false,existing:true},{mobile:false,fail:false,existing:false}]){
  const c=await page.context().browser().newContext({viewport:mobile?{width:390,height:844}:{width:1200,height:850},isMobile:mobile,hasTouch:mobile,reducedMotion:'reduce'});
  try{
   const p=await c.newPage(),errors=[];p.on('pageerror',e=>errors.push(e.message));
   let histories=0;const reply=(route,json,status=200)=>route.fulfill({status,json,headers:{'Cache-Control':'no-store'}});
   await c.route(origin+'/support-chat/api.php?**',async route=>{
    const u=new URL(route.request().url()),q=u.searchParams;
    if(q.has('widget_config'))return route.continue();
    if(route.request().method()!=='GET')return reply(route,{status:'success'});
    if(q.has('get_session')){await new Promise(r=>setTimeout(r,450));return reply(route,{status:'success',token:'d'.repeat(64),context:fixture,user_id:existing?id:null,draft_user_id:id});}
    if(q.has('get_csrf'))return reply(route,{token:'d'.repeat(64),context:fixture});
    if(q.has('get_conversation')){
     const n=++histories;await new Promise(r=>setTimeout(r,650));
     if(fail&&n===1)return reply(route,{status:'error',code:'service_unavailable'},503);
     const rows=existing&&!q.has('after_sequence')?[1,2].map((id,i)=>({id,sequence:id,client_message_id:null,type:'admin',text:'پیام آزمایشی '+id,time:'2026-11-08 08:0'+i+':00',customer_read:true,sender_account_group:'e'.repeat(32)})):[];
     return reply(route,{status:'success',protocol_version:2,conversation_id:id,store_epoch:'f'.repeat(32),next_after:existing?2:0,high_watermark:existing?2:0,head_sequence:existing?2:0,has_more:false,messages:rows,lifecycle:{resolved:false,episode:1}});
    }
    return route.continue();
   });
   await p.goto(origin+'/',{waitUntil:'domcontentloaded'});
   const launch=p.locator('[data-support-launcher]');await launch.evaluate(el=>el.addEventListener('click',()=>window.chatClick=performance.now(),{once:true}));await launch.click();
   const f=p.frameLocator('#supportWidgetFrame'),shell=f.locator('#chatWindow');
   await f.locator('#supportInit').waitFor({state:'visible'});
   const shellState=()=>shell.evaluate(el=>{const d=el.ownerDocument,rect=s=>{const r=d.querySelector(s).getBoundingClientRect();return [r.x,r.y,r.width,r.height];};window.firstHeader??=d.querySelector('.chat-header');return {same:window.firstHeader===d.querySelector('.chat-header'),header:rect('.chat-header'),composer:rect('#inputArea'),shell:rect('#chatWindow'),disabled:d.querySelector('#chatInput').disabled,rows:d.querySelectorAll('#messages article').length,state:el.dataset.initState};});
   const loading=await shellState();
   if(!loading.disabled||loading.rows||loading.state!=='initializing')throw Error(JSON.stringify({badLoading:loading}));
   if(fail){const retry=f.locator('[data-init-retry]');await retry.waitFor({state:'visible'});const stopped=histories;await p.waitForTimeout(350);if(histories!==stopped)throw Error('Automatic history retry');await retry.click();}
   await f.locator('#chatWindow[data-init-state=ready]').waitFor({timeout:10000});
   const ready=await shellState();
   if(!ready.same||ready.disabled||ready.rows!==(existing?2:0)||JSON.stringify(loading.header)!==JSON.stringify(ready.header)||JSON.stringify(loading.composer)!==JSON.stringify(ready.composer)||JSON.stringify(loading.shell)!==JSON.stringify(ready.shell))throw Error(JSON.stringify({loading,ready}));
   const beforeReload=histories;
   await p.reload({waitUntil:'domcontentloaded'});
   await launch.waitFor({state:'visible'});
   if(!await p.locator('#supportWidgetFrame').isHidden())throw Error('Chat reopened after refresh');
   await launch.click();
   await f.locator('.chat-close').click();
   await p.locator('#supportWidgetFrame').waitFor({state:'hidden'});
   await launch.click();
   await f.locator('#chatWindow[data-init-state=ready]').waitFor();
   if(await f.locator('#messages article').count()!==(existing?2:0))throw Error('Reload lost or duplicated history');
   if(existing&&histories-beforeReload!==1)throw Error('Reload requested initial history more than once');
   if(errors.length)throw Error(errors[0]);
   out.push({mobile,fail,existing,histories,stable:true,ready});
  }finally{await c.close();}
 }return out;
}
