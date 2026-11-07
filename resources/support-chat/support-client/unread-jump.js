// Presentation only. Read ownership, UUIDs and read acknowledgements stay in ChatEngine.
// Customer pagination restores keyed anchors here; operator pagination keeps its existing height adjustment.
export function unreadJump({host,region,latest=async()=>{},restoreOlder=false,compact=false}){
    const button=document.createElement('button'),icon=document.createElement('span'),label=document.createElement('span');
    button.type='button';button.className='support-unread-jump';button.hidden=true;button.setAttribute('aria-live','polite');
    icon.className='support-icon';icon.textContent='arrow_downward';icon.setAttribute('aria-hidden','true');button.append(label,icon);
    const viewport=compact?document.createElement('div'):null;
    if(viewport){viewport.className='support-transcript-viewport';viewport.hidden=host.hidden;host.before(viewport);viewport.append(host,button);button.classList.add('support-latest-compact');button.removeAttribute('aria-live');button.title='آخرین پیام‌ها';label.hidden=true;}else region.append(button);
    let open=false,following=true,busy=false,dead=false,readingAnchor=null,closedState=null,beyondTail=false,far=false;
    const nearBottom=()=>host.scrollHeight-host.scrollTop-host.clientHeight<=32;
    const update=()=>{if(viewport)viewport.hidden=host.hidden;const distance=host.scrollHeight-host.scrollTop-host.clientHeight;if(compact){if(distance>64)far=true;else if(distance<=32)far=false;}button.hidden=!open||(compact?!far&&!beyondTail:following&&!beyondTail);if(label.textContent!=='آخرین پیام‌ها')label.textContent='آخرین پیام‌ها';if(icon.dataset.svgIcon!=='arrow_downward'&&icon.textContent!=='arrow_downward')icon.textContent='arrow_downward';if(button.getAttribute('aria-label')!=='رفتن به آخرین پیام‌ها')button.setAttribute('aria-label','رفتن به آخرین پیام‌ها');};
    const bottom=()=>{if(dead||!open)return;following=true;host.scrollTop=host.scrollHeight;update();};
    const items=()=>[...host.querySelectorAll('[data-message-key]:not(.support-new-separator),[data-uuid]')],key=el=>el.dataset.messageKey||el.dataset.uuid;
    const capture=()=>{const b=host.getBoundingClientRect(),el=items().find(el=>el.getBoundingClientRect().bottom>b.top);return el?{key:key(el),offset:el.getBoundingClientRect().top-b.top}:null;};
    const scroll=()=>{if(!open)return;following=nearBottom();readingAnchor=capture();update();};
    const intent=event=>{if((event.type==='wheel'&&event.deltaY<0)||(event.type==='keydown'&&['PageUp','Home','ArrowUp'].includes(event.key))){following=false;update();}};
    host.addEventListener('scroll',scroll,{passive:true});host.addEventListener('wheel',intent,{passive:true});host.addEventListener('keydown',intent);
    // The floating button is a sibling of the scroller, so wheel defaults cannot reach it.
    const wheel=event=>{if(event.ctrlKey)return;const scale=event.deltaMode===1?16:event.deltaMode===2?host.clientHeight:1,delta=event.deltaY*scale;if(!delta||delta<0&&host.scrollTop<=0||delta>0&&nearBottom())return;event.preventDefault();host.scrollTop+=delta;};
    if(compact)button.addEventListener('wheel',wheel,{passive:false});
    // Late image decoding and keyboard/viewport resize follow only an opted-in tail.
    const resized=()=>{if(!open)return;if(following)bottom();else if(readingAnchor){const el=items().find(el=>key(el)===readingAnchor.key);if(el)host.scrollTop+=el.getBoundingClientRect().top-host.getBoundingClientRect().top-readingAnchor.offset;readingAnchor=capture();}};host.addEventListener('load',resized,true);
    const observer=typeof ResizeObserver!=='undefined'?new ResizeObserver(resized):null;observer?.observe(host);
    document.fonts?.ready.then(()=>{if(!dead)resized();});
    button.onclick=async()=>{if(busy)return;busy=true;button.disabled=true;try{await latest();bottom();host.focus({preventScroll:true});}catch{/* Caller retains recovery feedback and reading position. */}finally{busy=false;button.disabled=false;}};
    host.tabIndex=0;
    return {
        before(older=false){
            if(!open)return {...(closedState||{top:0,key:null,offset:0}),follow:false,hidden:true};
            const anchor=capture();
            return {follow:open&&following&&!older,top:host.scrollTop,key:anchor?.key,offset:anchor?.offset||0};
        },
        after(state,older=false){
            if(state.hidden){update();return;}
            if(state.follow){bottom();return;}
            if(!older||restoreOlder){const anchor=state.key?items().find(el=>key(el)===state.key):null;
                if(anchor)host.scrollTop+=anchor.getBoundingClientRect().top-host.getBoundingClientRect().top-state.offset;
                else host.scrollTop=state.top;}
            readingAnchor=capture();update();
        },
        pause(){following=false;readingAnchor=capture();update();},
        // Called only by the successful local enqueue hook, never by ACK/poll.
        outgoing(){if(!open)return;bottom();requestAnimationFrame(()=>{if(open&&following)bottom();});},
        follow:()=>open&&following,
        unread(){update();},
        tail(value){beyondTail=!!value;update();},
        open(){closedState=null;open=true;bottom();requestAnimationFrame(()=>{if(open&&following)bottom();});},
        resume(){open=true;if(closedState){const state=closedState,el=items().find(e=>key(e)===state.key);following=state.following;if(el)host.scrollTop+=el.getBoundingClientRect().top-host.getBoundingClientRect().top-state.offset;else host.scrollTop=state.top;closedState=null;}readingAnchor=capture();update();requestAnimationFrame(()=>{if(open&&following)bottom();});},
        close(){if(open){const anchor=capture();closedState={top:host.scrollTop,key:anchor?.key,offset:anchor?.offset||0,following};}open=false;update();},
        reset(){following=true;beyondTail=false;far=false;closedState=null;readingAnchor=null;update();},
        destroy(){dead=true;observer?.disconnect();host.removeEventListener('scroll',scroll);host.removeEventListener('wheel',intent);host.removeEventListener('keydown',intent);host.removeEventListener('load',resized,true);button.remove();if(viewport){viewport.before(host);viewport.remove();}}
    };
}
