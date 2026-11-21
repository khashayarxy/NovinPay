// Browser regression with a synthetic conversation; never writes the local owner fixture.
async page=>{
 const origin='https://novinpaypos.test',results=[],id='user_'+('a'.repeat(32))+'_42';
 for(const width of [390,1440]){
  const context=await page.context().browser().newContext({viewport:{width,height:850}});
  try{
   const tab=await context.newPage(),errors=[];tab.on('pageerror',error=>errors.push(error.message));
   let histories=0,active=0,peak=0,deny=false,bootstrap=0;
   const fixture={role:'customer',protocol_version:2,scope_id:'b'.repeat(64),session_epoch:'c'.repeat(32)};
   await context.route(origin+'/support-chat/**',async route=>{
    const request=route.request(),url=new URL(request.url()),q=url.searchParams;
    if(!url.pathname.endsWith('.php')||url.pathname.endsWith('support-widget.php'))return route.continue();
    if(q.has('widget_config'))return route.continue();
    const reply=(json,status=200)=>route.fulfill({json,status});
    if(q.has('get_session')){bootstrap++;return reply({status:'success',token:'d'.repeat(64),context:fixture,user_id:id,draft_user_id:id});}
    if(q.has('get_csrf'))return reply({token:'d'.repeat(64),context:fixture});
    if(q.has('get_conversation')){
     histories++;active++;peak=Math.max(peak,active);await new Promise(resolve=>setTimeout(resolve,100));active--;
     if(deny)return reply({status:'error',code:'session_expired',message:'RAW_TECHNICAL_SENTINEL'},401);
     const messages=q.has('after_sequence')?[]:Array.from({length:30},(_,n)=>({id:n+1,sequence:n+1,client_message_id:null,type:'admin',text:'پیام مصنوعی برای آزمون بازیابی '+(n+1),time:'2026-11-08 08:00:00',customer_read:true,sender_account_group:'e'.repeat(32)}));
     return reply({status:'success',protocol_version:2,conversation_id:id,store_epoch:'f'.repeat(32),next_after:30,high_watermark:30,head_sequence:30,has_more:false,messages,lifecycle:{resolved:false,episode:1}});
    }
    // Deny all other writes at the network boundary. The fixture returns no real tokens/data.
    return reply({status:'success',push_enabled:false,active:true});
   });
   await tab.goto(origin+'/',{waitUntil:'domcontentloaded'});await tab.locator('[data-support-launcher]').click();
   const frame=tab.frameLocator('#supportWidgetFrame'),shell=frame.locator('#chatWindow');
   await frame.locator('#chatWindow[data-init-state=ready]').waitFor();
   await frame.locator('#chatInput').fill('پیش‌نویس حفظ شود');
   const before=await shell.evaluate(el=>{const box=el.querySelector('#messages');box.scrollTop=60;return {scroll:box.scrollTop,count:box.querySelectorAll('article').length};});
   await context.setOffline(true);
   await shell.evaluate(()=>document.dispatchEvent(new Event('visibilitychange')));
   await frame.locator('#chatWindow[data-connection-state=reconnecting]').waitFor({timeout:6000});
   if(await frame.locator('#chatInput').inputValue()!=='پیش‌نویس حفظ شود')throw Error('offline lost draft');
   const offline=await shell.evaluate(el=>({scroll:el.querySelector('#messages').scrollTop,count:el.querySelectorAll('#messages article').length}));
   if(JSON.stringify(before)!==JSON.stringify(offline))throw Error('offline changed transcript or scroll');
   const stopped=histories;await tab.waitForTimeout(250);if(histories!==stopped)throw Error('requests continued offline');
   await context.setOffline(false);
   await frame.locator('#chatWindow:not([data-connection-state])').waitFor();
   if(await frame.locator('#chatInput').inputValue()!=='پیش‌نویس حفظ شود'||bootstrap!==1||peak!==1)throw Error('reconnect reset identity/draft or overlapped polls');
   // A permanent session error stays stopped, including repeated online events.
   deny=true;await shell.evaluate(()=>document.dispatchEvent(new Event('visibilitychange')));
   await frame.locator('#chatWindow[data-connection-state=paused]').waitFor();
   const denied=histories;
   await shell.evaluate(()=>{window.dispatchEvent(new Event('online'));window.dispatchEvent(new Event('online'));});
   await tab.waitForTimeout(350);if(histories!==denied)throw Error('permanent auth retry loop');
   if((await shell.textContent()).includes('RAW_TECHNICAL_SENTINEL'))throw Error('raw server error visible');
   if(errors.length)throw Error(errors.join('; '));
   results.push({width,histories,bootstrap,peakConcurrentPolls:peak,draftPreserved:true,scrollPreserved:true,permanentAuthStopped:true});
  }catch(error){const details=await context.pages()[0]?.frames().find(f=>f.url().includes('support-widget'))?.evaluate(()=>({online:navigator.onLine,state:{...document.querySelector('#chatWindow')?.dataset},diagnostics:globalThis.SupportInitDiagnostics})).catch(()=>null);throw Error(JSON.stringify({message:error.message,width,details}));}finally{await context.close();}
 }
 return results;
}
