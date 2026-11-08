import {test} from 'node:test';
import assert from 'node:assert/strict';
import {existsSync,mkdtempSync,readFileSync,writeFileSync,rmSync,realpathSync} from 'node:fs';
import {tmpdir} from 'node:os';
import path from 'node:path';
import {spawnSync} from 'node:child_process';
import {fileURLToPath,pathToFileURL} from 'node:url';

// Real Window timers enforce a receiver contract which Node's timers do not.
// Disposable browser profile; no application, network, login or database access.
test('real Chromium Window timers survive ownership selection, expiry and logout cleanup',t=>{
 const candidates=process.env.CHROME_BIN?[process.env.CHROME_BIN]:process.platform==='win32'?
  ['C:/Program Files/Google/Chrome/Application/chrome.exe','C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe']:
  ['/usr/bin/google-chrome','/usr/bin/chromium','/usr/bin/chromium-browser'];
 const executable=candidates.find(p=>existsSync(p));
 if(!executable){t.skip('Chromium is unavailable; native timer browser acceptance remains required');return;}
 const directory=mkdtempSync(path.join(tmpdir(),'novinpay-native-timers-'));
 try{
  const source=readFileSync(fileURLToPath(new URL('../../resources/support-chat/operator/reply-ownership.js',import.meta.url)));
  const module='data:text/javascript;base64,'+source.toString('base64');
  const html=`<!doctype html><meta charset="utf-8"><body>pending<script type="module">
   try {
    const {ReplyOwnership}=await import(${JSON.stringify(module)});
    const assert=(condition,message)=>{if(!condition)throw Error(message);};
    let changes=0;
    const model=new ReplyOwnership({changed:()=>changes++});
    model.select('conversation-a');
    model.update('conversation-a',{operator_id:'a'.repeat(32),name:'Synthetic operator',expires_at:101,server_now:100,checked_at:100000,is_owner:false});
    assert(model.blocked(),'Ownership remains enforced');
    model.select('conversation-b');assert(!model.blocked(),'Conversation isolation');
    model.select('conversation-a');assert(model.blocked(),'Reselect retained owner');
    model.clear();assert(model.states.size===0&&!model.blocked(),'Logout cleanup');
    model.select('expiry');
    model.update('expiry',{operator_id:'b'.repeat(32),name:'Synthetic operator',expires_at:101,server_now:100,checked_at:101000,is_owner:false});
    await new Promise(resolve=>globalThis.setTimeout(resolve,1100));
    assert(!model.blocked()&&changes>=7,'Native timeout expiration');
    model.clear();
    document.body.textContent=JSON.stringify({result:'NATIVE_OWNERSHIP_TIMERS_PASS'});
   } catch(error) { document.body.textContent=JSON.stringify({result:'NATIVE_OWNERSHIP_TIMERS_FAIL',message:error.message,stack:error.stack}); }
  </script>`;
  const fixture=path.join(directory,'fixture.html');writeFileSync(fixture,html);
  const result=spawnSync(executable,['--headless=new','--disable-gpu','--disable-background-networking','--no-first-run','--user-data-dir='+path.join(directory,'profile'),'--virtual-time-budget=2000','--dump-dom',pathToFileURL(fixture).href],{encoding:'utf8',timeout:20000,maxBuffer:1024*1024,windowsHide:true});
  assert.ifError(result.error);assert.equal(result.status,0,'Disposable Chromium process failed');
  assert.match(result.stdout,/NATIVE_OWNERSHIP_TIMERS_PASS/,result.stdout.slice(-2000));
 } finally {
  // Remove only this test's newly created directory, never a browser's real profile.
  if(realpathSync(path.dirname(directory))!==realpathSync(tmpdir())||!path.basename(directory).startsWith('novinpay-native-timers-'))throw Error('Unexpected test cleanup path');
  rmSync(directory,{recursive:true,force:true,maxRetries:3,retryDelay:100});
 }
});
