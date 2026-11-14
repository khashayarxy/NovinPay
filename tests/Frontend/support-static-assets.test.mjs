import test from 'node:test';
import assert from 'node:assert/strict';
import {mkdtempSync,mkdirSync,writeFileSync,readFileSync,rmSync} from 'node:fs';
import {tmpdir} from 'node:os';
import path from 'node:path';
import {spawnSync} from 'node:child_process';

test('static publication excludes private/configuration/PHP files and versions exact bytes',()=>{
 const root=mkdtempSync(path.join(tmpdir(),'novinpay-public-assets-'));
 try{
  for(const directory of ['operator','support-client','assets/fonts','assets/icons','support','runtime'])mkdirSync(path.join(root,'resources/support-chat',directory),{recursive:true});
  const source=path.join(root,'resources/support-chat');
  writeFileSync(path.join(source,'support-widget.js'),"import './operator/app.js?v=old';fetch('api.php?action=unchanged');");
  const retrySource="let attempt=0;new URL('./customer-faq.js?v=permissions1&retry='+attempt++,import.meta.url);import('./customer.js?v=old'+attempt);";
  writeFileSync(path.join(source,'operator/app.js'),retrySource);
  for(const file of ['operator/private.php','operator/installation.env','support/bootstrap.php','runtime/state.json','support-client/widget.html'])writeFileSync(path.join(source,file),'PRIVATE_SENTINEL');
  const build=()=>{
   const result=spawnSync(process.execPath,[path.resolve('scripts/build-support-assets.mjs')],{cwd:root,encoding:'utf8'});
   assert.equal(result.status,0,result.stderr);
   return JSON.parse(readFileSync(path.join(root,'public/build/support-chat/manifest.json'),'utf8'));
  };
  const first=build();assert.deepEqual(first.files,['operator/app.js','support-widget.js']);
  assert.equal(readFileSync(path.join(root,'public/build/assets/.htaccess'),'utf8'),readFileSync(path.resolve('resources/vite-assets.htaccess'),'utf8'));
  const published=readFileSync(path.join(root,'public/build/support-chat',first.version,'support-widget.js'),'utf8');
  assert.ok(published.includes("import './operator/app.js'"));
  assert.ok(published.includes("api.php?action=unchanged"));
  assert.equal(readFileSync(path.join(root,'public/build/support-chat',first.version,'operator/app.js'),'utf8'),retrySource);
  assert.equal(build().version,first.version);
  writeFileSync(path.join(source,'operator/app.js'),'/* changed public bytes */');
  assert.notEqual(build().version,first.version);
  assert.match(readFileSync(path.join(root,'public/build/support-chat/.htaccess'),'utf8'),/max-age=31536000, immutable/);
 }finally{rmSync(root,{recursive:true,force:true});}
});
