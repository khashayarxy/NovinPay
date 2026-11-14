import {test} from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';

test('immutable cache matcher excludes dynamic, private and unversioned resources', () => {
    const config = readFileSync(new URL('../../resources/vite-assets.htaccess', import.meta.url), 'utf8');
    assert.doesNotMatch(readFileSync(new URL('../../public/.htaccess', import.meta.url), 'utf8'), /novinpay_immutable/);
    const expression = config.match(/SetEnvIf Request_URI "([^"]+)" novinpay_immutable=1/);
    assert.ok(expression, 'Scoped cache rule must exist');
    const match = new RegExp(expression[1]);
    for (const url of ['/build/assets/app-Ab1234_X.css', '/build/assets/app-Ab1234_X.js', '/build/assets/Vazirmatn_wght_-Ab1234_X.woff2']) assert.ok(match.test(url), url);
    for (const url of ['/', '/account', '/admin', '/login', '/build/manifest.json', '/js/customer-otp.js', '/storage/private/file.js', '/build/support-chat/hash/support-widget.js', '/build/assets/app-Ab1234_X.html', '/build/assets/app.css']) assert.equal(match.test(url), false, url);
    assert.match(config, /Header set Cache-Control "public, max-age=31536000, immutable" env=novinpay_immutable/);
});
