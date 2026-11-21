// Local public UI only. Map requests are intercepted; no provider traffic or form writes.
async page=>{
 const results=[];
 for(const width of [320,390,1440]){
  const context=await page.context().browser().newContext({viewport:{width,height:950}});
  try{
   const tab=await context.newPage(),errors=[];let requests=0;tab.on('pageerror',e=>errors.push(e.message));
   await context.route('https://neshan.org/**',route=>{requests++;return route.fulfill({contentType:'text/html',body:'<!doctype html><p>synthetic interactive map</p>'});});
   await tab.goto('https://novinpaypos.test/',{waitUntil:'domcontentloaded'});
   const panel=tab.locator('[data-map-preview]'),button=panel.locator('[data-map-activate]');
   await button.scrollIntoViewIfNeeded();await button.waitFor();await tab.waitForTimeout(200);
   if(requests||await panel.locator('iframe').count())throw Error('eager map request');
   if(await tab.locator('[data-copy-address]').count())throw Error('obsolete copy action');
   if(!await tab.getByText('لطفاً پیش از مراجعه حضوری، تماس بگیرید.',{exact:true}).isVisible())throw Error('missing visit notice');
   const google=tab.getByRole('link',{name:/گوگل مپ/});
   if(await google.getAttribute('href')!=='https://maps.app.goo.gl/RSY3QKrJAMNiqPEB8')throw Error('changed navigation destination');
   const before=await panel.boundingBox(),cta=await button.boundingBox();
   if(Math.abs((before.x+before.width/2)-(cta.x+cta.width/2))>2)throw Error('button not centered');
   if(await tab.evaluate(()=>document.documentElement.scrollWidth>innerWidth))throw Error('horizontal overflow');
   await button.focus();await button.press('Enter');
   await tab.waitForFunction(()=>document.querySelector('[data-map-preview]').dataset.mapState==='ready');
   const after=await panel.boundingBox();
   if(requests!==1||before.height!==after.height||before.width!==after.width)throw Error('duplicate request or layout shift');
   if(!await panel.locator('.np-map-fallback').isVisible())throw Error('lost external fallback');
   if(errors.length)throw Error(errors.join('; '));
   results.push({width,preActivationRequests:0,activationRequests:requests,centered:true,keyboard:true,layoutShift:0,overflow:false});
  }finally{await context.close();}
 }
 return results;
}
