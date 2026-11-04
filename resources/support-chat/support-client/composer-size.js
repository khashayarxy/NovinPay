// Shared presentation only: preserve the textarea, selection, draft and input events.
export function composerSize(input,{operator=false}={}){
 input.rows=1;input.classList.add('support-composer-input');let frame=0,dead=false;
 const resize=()=>{
  if(dead||!input.isConnected||!input.getClientRects().length)return;
  const style=getComputedStyle(input),line=parseFloat(style.lineHeight)||parseFloat(style.fontSize)*1.7;
  const padding=parseFloat(style.paddingTop)+parseFloat(style.paddingBottom),border=parseFloat(style.borderTopWidth)+parseFloat(style.borderBottomWidth);
  const mobile=innerWidth<600,lines=operator?(mobile?6:9):(mobile?5:7),panel=input.closest('#chatWindow,#detail')||document.body;
  const available=Math.min(panel.clientHeight||innerHeight,window.visualViewport?.height||innerHeight),minimum=Math.max(parseFloat(style.minHeight)||0,line+padding+border);
  const cap=Math.max(minimum,Math.min(lines*line+padding+border,available*.38));
  const top=input.scrollTop;input.style.height='auto';const needed=input.scrollHeight+border;
  input.style.maxHeight=cap+'px';input.style.height=Math.min(cap,Math.max(minimum,needed))+'px';input.style.overflowY=needed>cap+1?'auto':'hidden';
  if(needed<=cap+1)input.scrollTop=0;else input.scrollTop=top;
 };
 const schedule=()=>{if(dead)return;cancelAnimationFrame(frame);frame=requestAnimationFrame(resize);};
 input.addEventListener('input',resize);addEventListener('resize',schedule);window.visualViewport?.addEventListener('resize',schedule);
 const observer=typeof ResizeObserver==='undefined'?null:new ResizeObserver(schedule);observer?.observe(input.parentElement);
 document.fonts?.ready.then(schedule);document.fonts?.addEventListener('loadingdone',schedule);resize();
 return {resize,destroy(){dead=true;cancelAnimationFrame(frame);observer?.disconnect();document.fonts?.removeEventListener('loadingdone',schedule);input.removeEventListener('input',resize);removeEventListener('resize',schedule);window.visualViewport?.removeEventListener('resize',schedule);}};
}
