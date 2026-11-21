// Local browser fault injection only. No live provider, account or application POST.
async page => {
 const origin='https://novinpaypos.test',results=[],flow='synthetic-flow',challenge='synthetic-challenge';
 for(const width of [390,1440])for(const scenario of ['send-lost','send-body-stall','send-expired','verify-lost','verify-replaced','verify-uncertain','expired','limited']){
  const context=await page.context().browser().newContext({viewport:{width,height:850}});
  try{
   const tab=await context.newPage(),errors=[];tab.on('pageerror',e=>errors.push(e.message));
   let posts=0,reads=0;
   await context.addInitScript(({scenario})=>{
    // Accelerate only the production request deadline; other keyboard/cooldown timers are real.
    const timer=window.setTimeout.bind(window);window.setTimeout=(fn,ms,...args)=>timer(fn,ms===12000?180:ms,...args);
    if(scenario==='send-body-stall'){
     const fetch=window.fetch.bind(window);window.fetch=(url,options)=>String(url).includes('/__reliability/send')
      ?Promise.resolve({status:200,ok:true,json:()=>new Promise(()=>{})}):fetch(url,options);
    }
   },{scenario});
   const start=scenario.startsWith('send');
   const html=`<!doctype html><html lang="fa" dir="rtl"><head><script src="/js/customer-otp.js" defer></script></head><body>
    <form action="${origin}/__reliability/${start?'send':'verify'}" method="post" ${start?'data-account-submit data-account-send':'data-account-otp'} data-login-state="${origin}/__reliability/state">
    <input name="flow" type="hidden" value="${flow}"><input name="challenge" type="hidden" value="${challenge}"><input name="request_id" type="hidden" value="synthetic-request">
    ${start?'<input name="phone" type="tel" required><p data-send-status hidden></p>':'<div class="np-otp"><input name="code" minlength="6" maxlength="6" required><div class="np-otp__slots" hidden>'+('<span></span>'.repeat(6))+'</div></div><p id="account-otp-error" hidden></p><small data-otp-pending hidden>بررسی</small>'}
    <button type="submit">ادامه</button></form></body></html>`;
   await context.route(origin+'/__reliability/page',r=>r.fulfill({contentType:'text/html',body:html}));
   await context.route(origin+'/__reliability/done',r=>r.fulfill({contentType:'text/html',body:'<p>synthetic destination</p>'}));
   await context.route(origin+'/login',r=>r.fulfill({contentType:'text/html',body:'<p>synthetic fresh login</p>'}));
   await context.route(origin+'/__reliability/state?**',r=>{
    reads++;
    if(scenario==='limited')return r.fulfill({status:429,json:{},headers:{'Retry-After':'60'}});
    return r.fulfill({json:{state:scenario==='expired'||scenario==='send-expired'?'expired':scenario==='verify-lost'?'authenticated':'challenge',challenge:scenario==='verify-replaced'?'replacement-challenge':challenge,redirect:origin+'/__reliability/done'}});
   });
   await context.route(origin+'/__reliability/send',r=>{posts++;return r.abort('failed');});
   await context.route(origin+'/__reliability/verify',r=>{posts++;return r.abort('failed');});
   await tab.goto(origin+'/__reliability/page');
   const input=tab.locator(start?'[name=phone]':'[name=code]');
   await input.fill(start?'09120000000':'123456');await input.press('Enter');
   if(scenario==='send-expired'){
    await tab.getByRole('button',{name:'شروع دوباره'}).waitFor();await input.press('Enter');await tab.waitForURL(origin+'/login');
    if(posts!==1||reads!==1)throw Error('ended send replayed');
   }else if(start||scenario==='verify-lost'||scenario==='verify-replaced'){
    await tab.waitForURL(origin+'/__reliability/done');
    if(reads!==1||posts!==(scenario==='send-body-stall'?0:1))throw Error('duplicate send or missing read-only reconciliation');
   }else{
    await tab.waitForFunction(()=>document.querySelector('form').getAttribute('aria-busy')==='false');
    if(await input.inputValue()!=='123456'||await input.getAttribute('readonly')!==null)throw Error('draft code lost or input stuck');
    await tab.waitForTimeout(250);if(posts!==1||reads!==1)throw Error('automatic verification replay');
    if(scenario==='verify-uncertain'){
     await input.press('Enter');await tab.waitForFunction(()=>document.querySelector('form').getAttribute('aria-busy')==='false');
     if(posts!==2||reads!==2)throw Error('same code explicit retry blocked');
    }else if(scenario==='expired'){
     await input.press('Enter');await tab.waitForTimeout(150);if(posts!==1)throw Error('expired session retried');
    }
   }
   if(errors.length)throw Error(errors.join('; '));
   results.push({width,scenario,posts,stateReads:reads,passed:true});
  }finally{await context.close();}
 }
 return results;
}
