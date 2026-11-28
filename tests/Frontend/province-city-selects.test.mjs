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
const locations = {'تهران': ['تهران', 'شهریار'], 'البرز': ['کرج', 'فردیس']};

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

function fixture(old) {
    const css = ['resources/css/public.css', 'resources/css/customer-account.css', 'resources/css/app.css']
        .map(readRepo).join('\n').replace(/^@(?:import|source)\b[^\r\n]*;/gm, '')
        .replace(/@theme\s*(?:static\s*)?\{[\s\S]*?\}/g, '').replace(/@apply\s+[^;]+;/g, '');
    const provinces = selected => '<option value=""></option>' + Object.keys(locations).map(province =>
        `<option ${selected === province ? 'selected' : ''}>${province}</option>`).join('');
    const cities = selected => '<option value=""></option>' + locations['تهران'].map(city =>
        `<option ${selected === city ? 'selected' : ''}>${city}</option>`).join('');
    const address = (id, selected) => `<form id="${id}" data-iran-address-form class="np-account-form"><div class="public-form__grid">
        <div class="np-float-field" data-float-field><select id="${id}-province" data-iran-province>${provinces(selected ? 'تهران' : '')}</select><label>استان</label></div>
        <div class="np-float-field" data-float-field><select id="${id}-city" data-iran-city data-initial-city="${selected ? 'شهریار' : ''}" ${selected ? '' : 'disabled'}>${selected ? cities('شهریار') : '<option value=""></option>'}</select><label>شهر</label></div>
        </div></form>`;
    return `<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
        <style>${css}\n*{box-sizing:border-box}body{margin:0;padding:16px}form{margin-bottom:24px}.sr-only{position:absolute;width:1px;height:1px;overflow:hidden;clip-path:inset(50%)}</style>
        <body class="public-site">${address('new-address', false)}${address('edit-address', true)}${address('old-address', true)}${address('checkout', old)}
        <form data-repair-stepper data-is-guest="0" data-initial-step="3"><div data-step="3" class="public-form__grid">
        <div class="np-float-field" data-float-field>
            <select id="repair_province" data-repair-province>${provinces(old ? 'تهران' : '')}</select><label for="repair_province">استان</label></div>
        <div class="np-float-field" data-float-field>
            <select id="repair_city" data-repair-city data-selected-city="${old ? 'شهریار' : ''}" ${old ? '' : 'disabled'}>${old ? cities('شهریار') : '<option value=""></option>'}</select><label for="repair_city">شهر</label></div>
        </div></form>
        <script type="application/json" id="iran-locations-data">${JSON.stringify(locations)}</script>
        <script type="application/json" id="repair-iran-locations" ${old ? 'data-src="/locations"' : ''}>${old ? '' : JSON.stringify(locations)}</script>
        <script>window.testErrors=[];window.addEventListener('error', e=>testErrors.push(e.message));
        ${old ? `window.fetch=()=>new Promise(resolve=>{window.releaseLocations=()=>resolve({ok:true,json:async()=>(${JSON.stringify(locations)})});});` : ''}</script>
        <script type="module" src="/resources/js/app.js"></script>
        <script>window.addEventListener('load', async()=>{
            const check=(condition,message)=>{if(!condition)throw new Error(message)};
            const tick=()=>new Promise(resolve=>setTimeout(resolve,0));
            const change=(select,value)=>{select.value=value;select.dispatchEvent(new Event('change',{bubbles:true}));};
            const disabledStyle=(select,visible=select)=>{
                visible.focus();
                const style=getComputedStyle(visible);
                check(select.disabled && visible.disabled, 'Both native and visible fields must be disabled');
                check(document.activeElement!==visible, 'Disabled city must not receive focus');
                check(style.cursor==='not-allowed','Disabled cursor');
                check(style.backgroundColor==='rgb(248, 250, 252)',select.id+': disabled surface '+style.backgroundColor);
                check(style.boxShadow==='none','Disabled city must have no focus ring');
                check(getComputedStyle(select.closest('.np-float-field')).getPropertyValue('--np-float-bg').trim()==='#f8fafc','Floating label surface');
            };
            try {
                for(const form of document.querySelectorAll('[data-iran-address-form]')){
                    const province=form.querySelector('[data-iran-province]'), city=form.querySelector('[data-iran-city]');
                    const visible=()=>city.closest('.np-float-field').querySelector('.np-cs__trigger')||city;
                    const prefilled=form.id==='edit-address'||form.id==='old-address'||(form.id==='checkout'&&${old});
                    check(city.disabled===!prefilled,form.id+': initial disabled state');
                    if(prefilled)check(city.value==='شهریار',form.id+': preserved edit/old city');
                    else disabledStyle(city,visible());
                    change(province,'البرز');await tick();
                    check(!city.disabled&&!visible().disabled,form.id+': province enables city');
                    check([...city.options].some(o=>o.value==='کرج'),form.id+': corresponding cities');
                    check(city.value==='',form.id+': province change clears previous city');
                    change(city,'کرج');visible().focus();
                    if(visible() instanceof HTMLButtonElement)visible().click();
                    change(province,'');await tick();
                    check(city.value===''&&city.options.length===1,form.id+': clear removes cities');
                    check(!city.closest('.np-float-field').querySelector('.np-cs.is-open'),form.id+': disabled menu closes');
                    disabledStyle(city,visible());
                    if(form._populateIranCities){form._populateIranCities('شهریار');check(city.value===''&&city.disabled,'Orphan city cannot bypass province');}
                    change(province,'تهران');await tick();
                    check(!city.disabled&&!visible().disabled,form.id+': re-enable city');
                }
                const repair=document.querySelector('[data-repair-stepper]'), province=repair.querySelector('[data-repair-province]'),city=repair.querySelector('[data-repair-city]'),trigger=city.closest('.np-cs').querySelector('.np-cs__trigger');
                ${old ? `check(!city.disabled&&!trigger.disabled&&city.value==='شهریار'&&trigger.textContent==='شهریار','Repair old city survives pending fetch');
                change(province,'');await tick();disabledStyle(city,trigger);window.releaseLocations();await tick();await tick();
                check(city.disabled&&trigger.disabled&&city.value==='','Late dataset must not undo cleared province');` : 'disabledStyle(city,trigger);'}
                change(province,'البرز');await tick();
                check(!city.disabled&&!trigger.disabled,'Repair enables native and searchable city');
                trigger.click();check(!city.closest('.np-cs').querySelector('.np-cs__panel').hidden,'Searchable cities open');
                check([...city.options].some(o=>o.value==='کرج'),'Repair corresponding cities');
                change(city,'کرج');
                province.value='';province.dispatchEvent(new Event('change',{bubbles:true}));
                await tick();
                check(trigger.textContent===''&&city.value==='','Repair cleared values');
                check(city.closest('.np-cs').querySelector('.np-cs__panel').hidden,'Disabled city list closes');
                disabledStyle(city,trigger);
                check(testErrors.length===0,'Application errors: '+testErrors.join(';'));
                check(document.documentElement.scrollWidth<=innerWidth,'No horizontal overflow');
                parent.document.body.dataset.probe=JSON.stringify({ok:true,viewport:innerWidth,old:${old},forms:4,repair:true});
            }catch(error){parent.document.body.dataset.probe=JSON.stringify({error:error.message,stack:error.stack});}
        });</script></body></html>`;
}

test('Province/city selectors: initial, old/edit, clear, delayed repair data and disabled styling at exact mobile/desktop widths', async t => {
    if (!chrome) { t.skip('Chromium unavailable'); return; }
    const directory = mkdtempSync(path.join(tmpdir(), 'novinpay-province-city-'));
    const server = http.createServer((req, res) => {
        const url = new URL(req.url, 'http://localhost');
        if (url.pathname.startsWith('/resources/js/')) {
            const name = path.posix.basename(url.pathname).replace(/\.js$/, '') + '.js';
            const file = new URL('../../resources/js/' + name, import.meta.url);
            res.writeHead(existsSync(file) ? 200 : 404, {'Content-Type': 'text/javascript; charset=utf-8'});
            res.end(existsSync(file) ? readFileSync(file, 'utf8').replace(/(from\s+['"]\.\/[^'".]+)(['"])/g, '$1.js$2') : '');
        } else {
            res.writeHead(200, {'Content-Type': 'text/html; charset=utf-8'});
            res.end(url.pathname === '/frame' ? fixture(url.searchParams.get('old') === '1') :
                `<!doctype html><body><iframe style="border:0;width:${Number(url.searchParams.get('width'))}px;height:1400px" src="/frame?old=${url.searchParams.get('old')}"></iframe></body>`);
        }
    });
    try {
        await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
        for (const width of [320, 390, 1024, 1440]) {
            for (const old of [0, 1]) {
                const result = await runChrome(['--headless=new', '--disable-gpu', '--no-sandbox',
                    `--user-data-dir=${path.join(directory, width + '-' + old)}`, '--window-size=1600,1000',
                    '--virtual-time-budget=1500', '--dump-dom', `http://127.0.0.1:${server.address().port}/?width=${width}&old=${old}`]);
                assert.equal(result.status, 0, result.stderr.slice(-400));
                const match = result.stdout.match(/data-probe="([^"]+)"/);
                assert.ok(match, `Missing probe at ${width}, old=${old}`);
                const probe = JSON.parse(match[1].replace(/&quot;/g, '"').replace(/&amp;/g, '&'));
                assert.equal(probe.error, undefined, JSON.stringify(probe));
                assert.equal(probe.viewport, width, 'Actual iframe viewport must match requested width');
                assert.equal(probe.ok, true);
            }
        }
    } finally {
        await new Promise(resolve => server.close(resolve));
        rmSync(directory, {recursive: true, force: true});
    }
});
