// Shared by page modules and classic service workers. Atomic across tabs/workers.
globalThis.SupportAttentionStore={
    async open(name){return new Promise((resolve,reject)=>{const r=indexedDB.open(name,1);r.onupgradeneeded=()=>{for(const store of ['items','meta'])if(!r.result.objectStoreNames.contains(store))r.result.createObjectStore(store,{keyPath:'key'});};r.onsuccess=()=>resolve(r.result);r.onerror=r.onblocked=()=>reject(Error('attention_storage_unavailable'));});},
    async claim(hint){
        const c=hint.attention;if(!c||!/^([a-f0-9]{32}|legacy-zizolearn)$/.test(c.installation)||!/^[a-f0-9]{64}$/.test(c.scope)||!/^[a-f0-9]{32}$/.test(c.epoch)||!/^[a-f0-9]{32}$/.test(hint.store_epoch)||!Number.isSafeInteger(hint.sequence)||hint.sequence<1)return false;
        const name=c.installation==='legacy-zizolearn'?'zizolearn-support-outbox-v1':`support-outbox-${c.installation}-v1`,db=await this.open(name),prefix=`attention:${c.scope}:${c.epoch}:`;
        try{return await new Promise((resolve,reject)=>{
            const tx=db.transaction('meta','readwrite'),store=tx.objectStore('meta'),prefs=store.get(prefix+'preferences');let claimed=false;
            prefs.onsuccess=()=>{if(prefs.result?.value?.browser===false)return;const cursor=store.get(prefix+'cursor');cursor.onsuccess=()=>{const old=cursor.result?.value;if(old?.epoch===hint.store_epoch&&old.sequence>=hint.sequence)return;store.put({key:prefix+'cursor',value:{epoch:hint.store_epoch,sequence:hint.sequence}});claimed=true;};};
            tx.oncomplete=()=>resolve(claimed);tx.onerror=tx.onabort=()=>reject(Error('attention_storage_unavailable'));
        });}finally{db.close();}
    }
};
