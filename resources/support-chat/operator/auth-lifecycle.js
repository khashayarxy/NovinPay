// Request generations and explicit presentation states; server authorization is unchanged.
export function authLifecycle({paint,clear}){
 let state='checking',version=0;const pending=new Set();
 const stale=()=>Object.assign(new Error('پاسخ مربوط به نشست پیشین است.'),{name:'AbortError',code:'stale_auth_response'});
 const invalidate=()=>{version++;for(const c of pending)c.abort();pending.clear();};
 const assert=epoch=>{if(epoch!==version)throw stale();};
 return {get state(){return state;},get version(){return version;},stale,assert,
  request(){const epoch=version,controller=new AbortController();pending.add(controller);return {epoch,signal:controller.signal,done:()=>pending.delete(controller)};},
  checking(){invalidate();state='checking';paint(state);return version;},
  authenticated(epoch){assert(epoch);state='authenticated';paint(state);},
  unauthenticated(message=''){invalidate();state='unauthenticated';clear();paint(state,message);},
  hooks(callbacks){const epoch=version;return Object.fromEntries(Object.entries(callbacks).map(([name,fn])=>[name,(...args)=>epoch===version&&state!=='unauthenticated'?fn(...args):undefined]));},
  retry(message){paint('checking',message);}
 };
}
