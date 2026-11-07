import {test} from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';

const source=readFileSync(new URL('../../resources/support-chat/support-client/theme-init.js',import.meta.url),'utf8');
const critical=readFileSync(new URL('../../resources/support-chat/support-client/critical-shell.html',import.meta.url),'utf8').match(/<script>(try\{[^]*?)<\/script>/)[1];
const installation='a'.repeat(32);
function browser(store=new Map(),surface='customer-v2'){
 const listeners={},root={dataset:{supportSurface:surface},style:{setProperty(){}}};
 const media={matches:true,addEventListener(name,fn){listeners.media=fn;}};
 const ctx={document:{documentElement:root,currentScript:{src:'https://example.test/support-chat/support-client/theme-init.js'},baseURI:'https://example.test/support-chat/support-widget.php'},localStorage:{getItem:k=>store.get(k)??null,setItem:(k,v)=>store.set(k,v)},matchMedia:()=>media,URL,CustomEvent:class{constructor(type,options){this.type=type;this.detail=options.detail;}},dispatchEvent(){},addEventListener:(n,fn)=>listeners[n]=fn};
 ctx.window=ctx;vm.createContext(ctx);
 return {ctx,store,root,media,listeners,run:()=>vm.runInContext(source,ctx),early:()=>vm.runInContext(critical,ctx)};
}
test('dark device and unrelated site preference cannot change customer light default',()=>{
 const b=browser(new Map([['theme','dark']]));b.early();assert.equal(b.root.dataset.supportTheme,'light');b.run();
 b.ctx.SupportTheme.configure({installation,customer_theme_default:'dark'});
 assert.equal(b.root.dataset.supportTheme,'light');b.ctx.SupportTheme.set('system');assert.equal(b.ctx.SupportTheme.mode,'light');
 b.media.matches=false;b.listeners.media();assert.equal(b.root.dataset.supportTheme,'light');
});
test('manual scoped preference survives reload and is shared by loading/error shell',()=>{
 const b=browser();b.run();b.ctx.SupportTheme.configure({installation});b.ctx.SupportTheme.set('dark');
 const next=browser(b.store);next.early();assert.equal(next.root.dataset.supportTheme,'dark');next.run();next.ctx.SupportTheme.configure({installation});
 assert.equal(next.root.dataset.supportTheme,'dark');next.media.matches=false;next.listeners.media();assert.equal(next.root.dataset.supportTheme,'dark');
 next.ctx.SupportTheme.set('light');const again=browser(next.store);again.early();again.run();assert.equal(again.root.dataset.supportTheme,'light');
});
test('legacy system preference falls back to light, explicit legacy dark is retained',()=>{
 for(const [saved,expected]of [['system','light'],['dark','dark']]){
  const b=browser(new Map([[`support-theme:${installation}:customer-v2`,saved]]));b.run();b.ctx.SupportTheme.configure({installation});assert.equal(b.root.dataset.supportTheme,expected);
 }
});
test('storage denial stays light and operator system preference remains supported',()=>{
 const b=browser();b.ctx.localStorage.getItem=()=>{throw Error('denied');};b.early();b.run();assert.equal(b.root.dataset.supportTheme,'light');
 const op=browser(new Map(),'operator');op.run();op.ctx.SupportTheme.configure({installation,operator_theme_default:'system'});assert.equal(op.root.dataset.supportTheme,'dark');op.media.matches=false;op.listeners.media();assert.equal(op.root.dataset.supportTheme,'light');
});
