import test from 'node:test';
import assert from 'node:assert/strict';
import http from 'node:http';
import {spawn} from 'node:child_process';
import {existsSync, mkdtempSync, readFileSync, rmSync} from 'node:fs';
import {tmpdir} from 'node:os';
import path from 'node:path';

const readRepo = file => readFileSync(new URL('../../' + file, import.meta.url), 'utf8');
const chrome = [process.env.CHROME_BIN, 'C:/Program Files/Google/Chrome/Application/chrome.exe',
    '/usr/bin/google-chrome', '/usr/bin/chromium', '/usr/bin/chromium-browser'].filter(Boolean).find(existsSync);
const css = ['resources/css/public.css', 'resources/css/customer-account.css', 'resources/css/app.css', 'public/css/product-experience.css']
    .map(readRepo).join('\n').replace(/^@(?:import|source)\b[^\r\n]*;/gm, '')
    .replace(/@theme\s*(?:static\s*)?\{[\s\S]*?\}/g, '').replace(/@apply\s+[^;]+;/g, '');

const runChrome = args => new Promise((resolve, reject) => {
    const child = spawn(chrome, args, {windowsHide: true, stdio: ['ignore', 'pipe', 'pipe']});
    let stdout = '';
    let stderr = '';
    const timer = setTimeout(() => { child.kill(); reject(new Error('Chromium timeout: ' + stderr.slice(-400))); }, 25000);
    child.stdout.on('data', chunk => { stdout += chunk; });
    child.stderr.on('data', chunk => { stderr += chunk; });
    child.once('error', error => { clearTimeout(timer); reject(error); });
    child.once('close', status => { clearTimeout(timer); resolve({status, stdout, stderr}); });
});

const fixture = `<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<style>${css}\n*{box-sizing:border-box}body{margin:0;background:white}.top-bar{height:64px;background:#f1f5f9}main{padding:24px 16px}.clip-card{overflow:hidden;height:100px;padding:20px 12px;border:1px solid #ddd;border-radius:14px}.short-field{margin-top:260px}.lower-field{margin-top:200px}label{display:block}</style>
<body class="public-site"><div class="top-bar">فهرست فرم</div><main><form id="form">
<div class="clip-card"><div class="np-float-field" data-float-field>
<select id="province" name="province" data-custom-select required aria-describedby="province-hint">
<option value="" disabled hidden></option><option>تهران</option><option disabled>کرمان</option><option>گیلان</option><option selected>کردستان</option><option>البرز</option><option>کرمانشاه</option><option>آذربایجان شرقی</option><option>سیستان و بلوچستان</option><option hidden>گزینه پنهان</option></select><label for="province">استان</label></div></div>
<div class="np-float-field short-field" data-float-field><select id="short" name="short" data-custom-select><option value="">انتخاب کنید</option><option value="yes">بله</option><option value="no">خیر</option></select><label for="short">انتخاب کوتاه</label></div>
<button type="button" id="outside">بستن فهرست</button><button type="reset">بازنشانی</button>
<div class="np-float-field lower-field" data-float-field><select id="lower" data-custom-select><option>تهران</option><option>البرز</option></select><label for="lower">انتخاب پایین صفحه</label></div>
</form>
<form class="compare-add-form"><label class="sr-only" for="compare-add">افزودن محصول</label><select id="compare-add" class="input" data-custom-select><option>انتخاب دستگاه کارتخوان</option><option>دستگاه کارتخوان سیار</option></select><button type="button" class="compare-add-btn">افزودن</button></form>
<form class="product-review-sort"><label class="label" for="review-sort">ترتیب دیدگاه‌ها</label><select id="review-sort" class="input" data-custom-select><option>جدیدترین</option><option>بیشترین امتیاز</option></select><button class="btn" type="button">اعمال ترتیب</button></form>
</main><script>window.testErrors=[];addEventListener('error',e=>testErrors.push(e.message));</script>
<script type="module" src="/resources/js/app.js"></script><script>
addEventListener('load',async()=>{
 const check=(value,message)=>{if(!value)throw Error(message)};
 const tick=()=>new Promise(resolve=>setTimeout(resolve,0));
 const key=(el,value)=>{const event=new KeyboardEvent('keydown',{key:value,bubbles:true,cancelable:true});el.dispatchEvent(event);return event;};
 const searchFor=(search,value)=>{search.value=value;search.dispatchEvent(new Event('input',{bubbles:true}));};
 try{
 const form=document.getElementById('form'), select=document.getElementById('province'),wrapper=select.closest('.np-cs'),trigger=wrapper.querySelector('.np-cs__trigger'),panel=wrapper.querySelector('.np-cs__panel'),search=panel.querySelector('input'),menu=panel.querySelector('ul');
 for(const [id,height] of [['compare-add',44],['review-sort',38]])check(document.getElementById(id).closest('.np-cs').querySelector('button').getBoundingClientRect().height===height,'Inline form select preserves height '+id);
 let changes=0;select.addEventListener('change',()=>changes++);
 check(trigger.textContent==='کردستان','Native old selection must display');
 check(trigger.getAttribute('aria-describedby')==='province-hint'&&trigger.getAttribute('aria-required')==='true','Native accessibility attributes');
 const originalScroll=scrollY;trigger.click();
 const r=panel.getBoundingClientRect(),t=trigger.getBoundingClientRect(),card=document.querySelector('.clip-card').getBoundingClientRect();
 check(!panel.hidden&&document.activeElement===search,'Long select opens with focused search');
 check(r.top>=t.bottom+5&&r.bottom<=innerHeight-7&&r.height<=241,'Capped downward panel inside viewport');
 check(r.left>=0&&r.right<=innerWidth&&Math.abs(r.width-t.width)<1&&Math.abs(r.left-t.left)<1,'Panel matches trigger without horizontal overflow '+JSON.stringify({panel:r.toJSON(),trigger:t.toJSON(),clientLeft:document.documentElement.clientLeft,styleLeft:panel.style.left}));
 check(r.top>=document.querySelector('.top-bar').getBoundingClientRect().bottom,'Panel must not cover top bar');
 check(r.bottom>card.bottom,'Fixture must exercise clipping ancestor');
 const hit=document.elementFromPoint(r.left+20,Math.min(r.bottom-10,card.bottom+20));
 check(panel.contains(hit),'Top layer must escape overflow:hidden card');
 check(scrollY===originalScroll,'Selected option scrolling must not move page');
 check(menu.scrollHeight>menu.clientHeight&&getComputedStyle(menu).overflowY==='auto','Options scroll within compact menu');
 searchFor(search,'گيلان');check(menu.querySelectorAll('[role="option"]').length===1&&menu.textContent.includes('گیلان'),'Arabic yeh normalization');
 searchFor(search,'كردستان');check(menu.querySelectorAll('[role="option"]').length===1&&menu.textContent.includes('کردستان'),'Arabic kaf normalization');
 check(changes===0&&select.value==='کردستان','Filtering must not select');
 searchFor(search,'مورد ناموجود');check(menu.querySelector('[role="status"]')&&select.value==='کردستان','No-result state preserves native value');
 searchFor(search,'گیلان');key(search,'Enter');
 check(select.value==='گیلان'&&changes===1&&panel.hidden&&document.activeElement===trigger,'Enter selects once and returns focus');
 check(new FormData(form).get('province')==='گیلان','Native form submission remains synced');
 trigger.click();check(!key(search,' ').defaultPrevented&&!panel.hidden,'Space remains editable in search');key(search,'Escape');
 check(panel.hidden&&trigger.getAttribute('aria-expanded')==='false','Escape closes');
 trigger.click();document.getElementById('outside').click();check(panel.hidden,'Outside click closes');
 trigger.click();key(search,'Tab');check(panel.hidden,'Tab closes without trapping');
 trigger.click();select.disabled=true;await tick();check(panel.hidden&&trigger.disabled,'Disabled mutation closes and syncs');
 check(getComputedStyle(select.closest('.np-float-field')).getPropertyValue('--np-float-bg').trim()==='#f8fafc','Disabled floating-label surface matches trigger');
 select.disabled=false;await tick();check(!trigger.disabled,'Native enable syncs');
 select.value='تهران';select.dispatchEvent(new Event('change',{bubbles:true}));check(trigger.textContent==='تهران','External native change syncs');
 select.focus();check(document.activeElement===trigger,'Native validation focus forwards to visible control');
 trigger.click();form.reset();await tick();check(panel.hidden&&select.value==='کردستان'&&trigger.textContent==='کردستان','Reset restores native and custom values');
 const short=document.getElementById('short'),shortWrap=short.closest('.np-cs'),shortTrigger=shortWrap.querySelector('button');
 shortTrigger.click();check(shortWrap.querySelector('.np-cs__search-wrap').hidden,'Six or fewer options omit search');
 key(shortTrigger,'ArrowDown');key(shortTrigger,'Enter');check(short.value==='yes','Short select keyboard navigation');
 shortTrigger.click();key(shortTrigger,'End');key(shortTrigger,'Enter');check(short.value==='no','End selects final option');
 const lower=document.getElementById('lower').closest('.np-cs');lower.querySelector('button').click();
 const lr=lower.querySelector('.np-cs__panel').getBoundingClientRect(),lt=lower.querySelector('button').getBoundingClientRect();
 check(lr.top>=lt.bottom+5&&lr.bottom<=innerHeight-7,'Low field stays downward and inside viewport '+JSON.stringify({panel:lr.toJSON(),trigger:lt.toJSON(),height:innerHeight}));
 key(lower.querySelector('button'),'Escape');
 trigger.scrollIntoView({block:'center',behavior:'instant'});trigger.click();
 select.replaceChildren(new Option('تبریز','tabriz',true,true),new Option('رشت','rasht'));await tick();
 check(trigger.textContent==='تبریز'&&search.parentElement.hidden&&menu.querySelectorAll('[role="option"]').length===2,'Dynamic options and threshold sync');
 key(trigger,'Escape');
 check(document.documentElement.scrollWidth<=innerWidth,'No page horizontal overflow');
 check(testErrors.length===0,'Application errors: '+testErrors.join(';'));
 parent.document.body.dataset.probe=JSON.stringify({ok:true,viewport:innerWidth,panelHeight:r.height,clippingEscaped:true});
 }catch(error){parent.document.body.dataset.probe=JSON.stringify({error:error.message,stack:error.stack});}
});</script></body></html>`;

test('Custom selects: Persian search, native sync, keyboard, reset, clipping and compact downward panels at exact responsive widths', async t => {
    if (!chrome) { t.skip('Chromium unavailable'); return; }
    const directory = mkdtempSync(path.join(tmpdir(), 'novinpay-custom-select-'));
    const server = http.createServer((req, res) => {
        const url = new URL(req.url, 'http://localhost');
        if (url.pathname.startsWith('/resources/js/')) {
            const name = path.posix.basename(url.pathname).replace(/\.js$/, '') + '.js';
            const file = new URL('../../resources/js/' + name, import.meta.url);
            res.writeHead(existsSync(file) ? 200 : 404, {'Content-Type': 'text/javascript; charset=utf-8'});
            res.end(existsSync(file) ? readFileSync(file, 'utf8').replace(/(from\s+['"]\.\/[^'".]+)(['"])/g, '$1.js$2') : '');
        } else {
            res.writeHead(200, {'Content-Type': 'text/html; charset=utf-8'});
            res.end(url.pathname === '/frame' ? fixture :
                `<!doctype html><body><iframe style="border:0;width:${Number(url.searchParams.get('width'))}px;height:760px" src="/frame"></iframe></body>`);
        }
    });
    try {
        await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
        for (const width of [320, 390, 1024, 1440]) {
            const result = await runChrome(['--headless=new', '--disable-gpu', '--no-sandbox',
                `--user-data-dir=${path.join(directory, String(width))}`, '--window-size=1600,1000',
                '--virtual-time-budget=1500', '--dump-dom', `http://127.0.0.1:${server.address().port}/?width=${width}`]);
            assert.equal(result.status, 0, result.stderr.slice(-400));
            const match = result.stdout.match(/data-probe="([^"]+)"/);
            assert.ok(match, `Missing probe at ${width}`);
            const probe = JSON.parse(match[1].replace(/&quot;/g, '"').replace(/&amp;/g, '&'));
            assert.equal(probe.error, undefined, JSON.stringify(probe));
            assert.equal(probe.viewport, width);
            assert.equal(probe.ok, true);
        }
    } finally {
        await new Promise(resolve => server.close(resolve));
        rmSync(directory, {recursive: true, force: true});
    }
});
