import {readFileSync} from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';
import assert from 'node:assert/strict';

const source=readFileSync(new URL('../../resources/support-chat/operator/sw.js',import.meta.url),'utf8');
function worker(){
 const handlers={},requests=[];
 const context={URL,Response,importScripts(){},self:{location:{origin:'https://novinpaypos.test'},registration:{scope:'https://novinpaypos.test/support'},addEventListener:(name,fn)=>handlers[name]=fn},fetch:async(...args)=>{requests.push(args);throw Error('synthetic offline');}};
 vm.runInNewContext(source,context);
 return {handlers,requests,context};
}
test('the support-scoped worker cannot intercept customer pages, APIs or POSTs',()=>{
 const {handlers,requests}=worker();let intercepted=0;
 for(const [method,mode,path] of [['GET','navigate','/'],['GET','navigate','/support-chat/support-widget.php'],['GET','cors','/support-chat/api.php'],['GET','cors','/support-chat/operator-api.php'],['POST','navigate','/support'],['GET','navigate','/support-other']]){
  handlers.fetch({request:{method,mode,url:'https://novinpaypos.test'+path},respondWith(){intercepted++;}});
 }
 assert.equal(intercepted,0);assert.equal(requests.length,0);
});
test('offline canonical panel navigation returns a noncached 503 rather than saved private HTML',async()=>{
 const {handlers,requests}=worker();let pending;
 handlers.fetch({request:{method:'GET',mode:'navigate',url:'https://novinpaypos.test/support'},respondWith(p){pending=p;}});
 const response=await pending;
 assert.equal(response.status,503);assert.equal(response.headers.get('cache-control'),'no-store');
 assert.match(await response.text(),/href="\/support"/);assert.equal(requests.length,1);
 assert.equal(requests[0][1].cache,'no-store');
});
test('operator push hints never reach customer windows in the overlapping URL prefix',async()=>{
 const {handlers,context}=worker();const received=[];let pending;
 context.fetch=async()=>({ok:true,json:async()=>({conversation:'user_'+'a'.repeat(32)+'_123'})});
 context.self.clients={matchAll:async()=>[
  {url:'https://novinpaypos.test/support-chat/support-widget.php',focused:true,visibilityState:'visible',postMessage(){received.push('customer');}},
  {url:'https://novinpaypos.test/support',focused:true,visibilityState:'visible',postMessage(){received.push('operator');}},
 ]};
 handlers.push({waitUntil(p){pending=p;}});await pending;
 assert.deepEqual(received,['operator']);
});
