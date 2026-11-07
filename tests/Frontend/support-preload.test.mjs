import {test} from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';

test('cold startup critical chain is discovered together with exact import URLs',()=>{
 const root=new URL('../../resources/support-chat/',import.meta.url);
 const shell=readFileSync(new URL('support-widget.php',root),'utf8');
 const names=['customer-v2','customer','engine','network','outbox'];
 for(const name of names){
  const href=`support-client/${name}.js?v=team1`;
  assert.equal(shell.split(`rel="modulepreload" href="${href}"`).length-1,1);
 }
 // A mismatched query creates a second module instance/fetch; pin actual edges.
 for(let i=0;i<names.length-1;i++){
  const parent=readFileSync(new URL(`support-client/${names[i]}.js`,root),'utf8');
  assert.ok(parent.includes(`'./${names[i+1]}.js?v=team1'`));
 }
 assert.ok(shell.indexOf('rel="modulepreload"')<shell.indexOf("import('./support-client/customer-v2.js?v=team1')"));
});
