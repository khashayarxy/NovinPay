// Knowledge authority supplies questions; the existing engine owns all history.
export function customerFaq({composer,current}){
 const row=document.createElement('nav');row.className='v2-suggestions';row.setAttribute('aria-label','سؤالات متداول');row.hidden=true;composer.before(row);let busy=false,loading=false,closed=false,questions=[],used=new Set(),queued=new Set(),buttons=new Map();
 let bound='',episode=1;const bind=()=>{const engine=current(),next=(engine?.scope||'')+'|'+(engine?.sync.id||'')+'|'+episode;if(next!==bound){bound=next;queued.clear();used.clear();}};
 const visible=()=>questions.some(q=>!used.has(q.id)&&!queued.has(q.id));
 const apply=()=>{bind();for(const [id,b]of buttons)b.hidden=used.has(id)||queued.has(id);row.hidden=closed||!visible();for(const b of buttons.values())b.disabled=closed||busy||b.hidden;};
 function render(){row.replaceChildren();buttons=new Map();for(const q of questions){const b=document.createElement('button');b.type='button';b.textContent=q.title;b.dataset.faqId=q.id;b.onclick=async()=>{const engine=current();if(busy||closed||!engine?.sync.id||used.has(q.id))return;busy=true;apply();try{const id=engine.sync.id;const pending=await engine.box.list(engine.scope);if(!pending.some(r=>r.conversation===id&&r.faqArticle===q.id&&r.state!=='accepted'))await engine.enqueue(q.title,[],null,q.id);if(current()!==engine||engine.sync.id!==id)return;queued.add(q.id);used.add(q.id);}catch(e){engine.hooks.warning?.(e.message);}finally{busy=false;apply();}};buttons.set(q.id,b);row.append(b);}apply();}
 async function load(){if(loading)return;loading=true;try{const r=await fetch('support-content.php?action=faq',{cache:'no-store'});if(!r.ok)return;const data=await r.json();questions=Array.isArray(data.questions)?data.questions:[];render();}finally{loading=false;}}
 row.addEventListener('wheel',e=>{if(Math.abs(e.deltaY)>Math.abs(e.deltaX)&&row.scrollWidth>row.clientWidth){row.scrollLeft-=e.deltaY;e.preventDefault();}},{passive:false});
 addEventListener('support-widget-visibility',e=>{if(e.detail?.visible)load().catch(()=>{});});load().catch(()=>{});
 return {state(value){closed=!!value;apply();},page(value){episode=value?.episode||1;bind();used=new Set(Array.isArray(value?.used_faqs)?value.used_faqs:[]);apply();}};
}
