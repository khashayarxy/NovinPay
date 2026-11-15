import {test} from 'node:test';
import assert from 'node:assert/strict';
import {CustomerReconnect, reconnectDelay, transientFailure} from '../../resources/support-chat/support-client/reconnect.js';
import {Poller, RequestError} from '../../resources/support-chat/support-client/network.js';

function clock() {
 let time=0,id=0;const jobs=new Map();
 return {now:()=>time,setTimer:(fn,delay)=>{jobs.set(++id,{fn,at:time+delay});return id;},clearTimer:key=>jobs.delete(key),
  get pending(){return jobs.size;},get delay(){return Math.min(...[...jobs.values()].map(j=>j.at-time));},
  async next(){const [key,job]=[...jobs.entries()].sort((a,b)=>a[1].at-b[1].at)[0]||[];assert.ok(job,'scheduled retry');jobs.delete(key);time=job.at;await job.fn();}};
}
const network=()=>new RequestError('synthetic',0,'network');

test('100 seeded startup interruptions recover with one immediate retry and no overlapping work',async()=>{
 let totalAttempts=0;const recoveryTimes=[];
 for(let seed=1;seed<=100;seed++){
  const c=clock(),failures=seed%5+1;let attempts=0,active=0,peak=0;
  const recovery=new CustomerReconnect(async()=>{active++;peak=Math.max(peak,active);await Promise.resolve();active--;if(++attempts<=failures)throw network();},
   {...c,random:()=>seed/101});
  const first=recovery.start();assert.equal(recovery.start(),first);await first;
  assert.equal(c.delay,0);while(c.pending)await c.next();
  assert.equal(peak,1);assert.equal(attempts,failures+1);assert.equal(recovery.failures,0);
  totalAttempts+=attempts;recoveryTimes.push(c.now());recovery.destroy();
 }
 recoveryTimes.sort((a,b)=>a-b);
 console.log(JSON.stringify({scenario:'synthetic clock, not hosted latency',cycles:100,totalAttempts,medianMs:recoveryTimes[49],p95Ms:recoveryTimes[94],overlapping:0}));
});

test('persistent failure exhausts six attempts; offline resumes but permanent auth and programming errors do not',async()=>{
 for(const error of [network(),new RequestError('expired',401,'session_expired'),new Error('programming')]){
  const c=clock();let attempts=0;const recovery=new CustomerReconnect(async()=>{attempts++;throw error;},{...c});
  await recovery.start();while(c.pending)await c.next();
  assert.equal(attempts,transientFailure(error)?6:1);
  if(!transientFailure(error)){await recovery.onlineAgain();assert.equal(attempts,1);}
  recovery.destroy();
 }
 const c=clock();let online=false,attempts=0;const recovery=new CustomerReconnect(async()=>{attempts++;},{...c,online:()=>online});
 await recovery.start();assert.equal(c.pending,0);assert.equal(attempts,0);online=true;await recovery.onlineAgain();assert.equal(attempts,1);
});

test('jitter is bounded and Retry-After survives online or manual wakeups',async()=>{
 assert.equal(reconnectDelay(1,()=>1),0);assert.equal(reconnectDelay(2,()=>0),500);assert.equal(reconnectDelay(50,()=>1),30000);
 const c=clock();let attempts=0;const recovery=new CustomerReconnect(async()=>{if(++attempts===1)throw new RequestError('limited',429,'',5000);},{...c});
 await recovery.start();assert.equal(c.delay,5000);await recovery.onlineAgain();assert.equal(attempts,1);assert.equal(c.delay,5000);await c.next();assert.equal(attempts,2);
});

test('customer poller retries immediately, retains normal four-second polling, and stops on permanent failure',async()=>{
 const c=clock();let attempts=0,active=0,peak=0;
 const poller=new Poller(async()=>{active++;peak=Math.max(peak,active);await Promise.resolve();active--;if(++attempts<3)throw network();return true;},
  {...c,visible:()=>true,random:()=>0,customerRecovery:true,shouldRetry:transientFailure});
 poller.start();await c.next();assert.equal(c.delay,0);await c.next();assert.equal(c.delay,500);await c.next();assert.equal(c.delay,4000);assert.equal(peak,1);poller.stop();
 const terminal=new Poller(async()=>{throw new RequestError('expired',403);},{...c,customerRecovery:true,shouldRetry:transientFailure});
 terminal.start();await c.next();assert.equal(c.pending,0);assert.equal(terminal.resumeTransient(),false);
});

test('customer poller has a bounded failure budget and no requests while offline',async()=>{
 const c=clock();let attempts=0,online=true;
 const poller=new Poller(async()=>{attempts++;throw network();},{...c,customerRecovery:true,shouldRetry:transientFailure,online:()=>online});
 poller.start();while(c.pending)await c.next();assert.equal(attempts,6);assert.equal(poller.exhausted,true);
 online=false;assert.equal(poller.resumeTransient(),false);assert.equal(c.pending,0);
 online=true;assert.equal(poller.resumeTransient(),true);await c.next();assert.equal(attempts,7);poller.stop();
});
