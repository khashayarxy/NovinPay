(function () {
    var selector = 'input[type="password"]:not([readonly])';
    var eye = '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>';
    var eyeOff = '<path d="m3 3 18 18M10.6 10.7a2 2 0 0 0 2.7 2.7M6.1 6.1C3.4 8 2 12 2 12s3.5 7 10 7c1.6 0 3-.4 4.2-1M10 5.2c.6-.1 1.3-.2 2-.2 6.5 0 10 7 10 7s-.7 1.5-2 3"/>';

    function enhance(input) {
        if (input.classList.contains('np-password-input')) return;
        var wrapper = document.createElement('span');
        var button = document.createElement('span');
        var icon = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        wrapper.className = 'np-password-field';
        button.setAttribute('role', 'button');
        button.tabIndex = -1;
        button.className = 'np-password-eye';
        if (input.id) button.setAttribute('aria-controls', input.id);
        icon.setAttribute('viewBox', '0 0 24 24');
        icon.setAttribute('fill', 'none');
        icon.setAttribute('stroke', 'currentColor');
        icon.setAttribute('stroke-width', '1.8');
        icon.setAttribute('stroke-linecap', 'round');
        icon.setAttribute('stroke-linejoin', 'round');
        icon.setAttribute('aria-hidden', 'true');
        button.appendChild(icon);

        function show(visible) {
            input.type = visible ? 'text' : 'password';
            button.setAttribute('aria-label', visible ? 'پنهان کردن گذرواژه' : 'نمایش گذرواژه');
            button.setAttribute('title', button.getAttribute('aria-label'));
            button.setAttribute('aria-pressed', String(visible));
            icon.innerHTML = visible ? eyeOff : eye;
        }

        input.classList.add('np-password-input');
        input.parentNode.insertBefore(wrapper, input);
        wrapper.appendChild(input);
        wrapper.appendChild(button);
        show(false);
        button.addEventListener('mousedown', function (event) { event.preventDefault(); });
        button.addEventListener('click', function (event) {
            event.preventDefault();
            show(input.type === 'password');
        });
        button.addEventListener('keydown', function (event) {
            if (event.key !== 'Enter' && event.key !== ' ') return;
            event.preventDefault();
            show(input.type === 'password');
        });
        if (input.form) input.form.addEventListener('submit', function () { show(false); }, true);
    }

    function scan(root) {
        if (root.matches && root.matches(selector)) enhance(root);
        if (root.querySelectorAll) Array.prototype.forEach.call(root.querySelectorAll(selector), enhance);
    }

    scan(document);
    if (document.documentElement.hasAttribute('data-support-surface') && window.MutationObserver) {
        new MutationObserver(function (records) {
            records.forEach(function (record) { Array.prototype.forEach.call(record.addedNodes, scan); });
        }).observe(document.body, {childList: true, subtree: true});
    }
}());
