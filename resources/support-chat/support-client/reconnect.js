// Customer-only recovery policy. No identities, tokens, drafts or messages live here.
export const transientFailure = error => !!error && (
    [408, 425, 429, 500, 502, 503, 504].includes(error.status)
    || error.status === 0 && ['network', 'timeout', 'invalid_response', 'history_unavailable'].includes(error.code)
);
export const reconnectDelay = (failures, random = Math.random, retryAfter = 0) => Math.max(
    retryAfter || 0, failures === 1 ? 0 : Math.round(Math.min(30000, 1000 * 2 ** (failures - 2)) * (.5 + random() * .5))
);
export class CustomerReconnect {
    constructor(work, {online = () => globalThis.navigator?.onLine !== false, setTimer = (fn, ms) => setTimeout(fn, ms), clearTimer = id => clearTimeout(id), now = Date.now, random = Math.random, state = () => {}, limit = 6} = {}) {
        Object.assign(this, {work, online, setTimer, clearTimer, now, random, state, limit});
        this.failures = 0; this.job = null; this.timer = null; this.terminal = false; this.notBefore = 0; this.destroyed = false;
    }
    start(reset = false) {
        if (this.destroyed || this.job || this.terminal && !reset) return this.job || Promise.resolve(false);
        if (reset) { this.terminal = false; this.failures = 0; }
        this.clearTimer(this.timer); this.timer = null;
        if (!this.online()) { this.state('offline'); return Promise.resolve(false); }
        if (this.now() < this.notBefore) { this.timer = this.setTimer(() => this.start(), this.notBefore - this.now()); return Promise.resolve(false); }
        this.state('loading');
        const job = Promise.resolve().then(() => this.work()).then(() => {
            this.failures = 0; this.notBefore = 0; this.state('ready'); return true;
        }).catch(error => {
            if (this.destroyed) return false;
            this.failures++;
            const retryable = transientFailure(error);
            if (!retryable) this.terminal = true;
            if (error.retryAfter > 0) this.notBefore = this.now() + error.retryAfter;
            if (!retryable || this.failures >= this.limit) { this.state(retryable ? 'exhausted' : 'terminal', error); return false; }
            const delay = reconnectDelay(this.failures, this.random, error.retryAfter);
            this.notBefore = this.now() + delay;
            this.state('waiting', error);
            this.timer = this.setTimer(() => this.start(), delay);
            return false;
        }).finally(() => { if (this.job === job) this.job = null; });
        this.job = job; return job;
    }
    onlineAgain() { if (!this.terminal && this.online()) return this.start(true); return Promise.resolve(false); }
    destroy() { this.destroyed = true; this.clearTimer(this.timer); }
}
