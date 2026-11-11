(function () {
    var persian = '۰۱۲۳۴۵۶۷۸۹';
    var arabic = '٠١٢٣٤٥٦٧٨٩';
    var selector = 'input[type="tel"]';

    function ascii(value) {
        return value.replace(/[۰-۹٠-٩]/g, function (digit) {
            var index = persian.indexOf(digit);
            return String(index === -1 ? arabic.indexOf(digit) : index);
        });
    }

    function display(value) {
        return ascii(value).replace(/[0-9]/g, function (digit) { return persian[Number(digit)]; });
    }

    function format(input) {
        if (!input.matches || !input.matches(selector)) return;
        input.dir = 'ltr';
        var value = display(input.value);
        if (input.value !== value) {
            var start = input.selectionStart;
            var end = input.selectionEnd;
            var direction = input.selectionDirection;
            input.value = value;
            if (document.activeElement === input && start !== null) input.setSelectionRange(start, end, direction);
        }
        if (input.placeholder !== display(input.placeholder)) input.placeholder = display(input.placeholder);
    }

    function scan(root) {
        format(root);
        if (root.querySelectorAll) Array.prototype.forEach.call(root.querySelectorAll(selector), format);
    }

    window.NovinPayMobile = {ascii: ascii, display: display};
    ['input', 'change', 'focusin'].forEach(function (event) {
        document.addEventListener(event, function (event) { format(event.target); }, true);
    });
    document.addEventListener('formdata', function (event) {
        Array.prototype.forEach.call(event.target.elements, function (input) {
            if (input.name && input.matches(selector) && !input.matches(':disabled') && event.formData.has(input.name)) {
                event.formData.set(input.name, ascii(input.value));
            }
        });
    }, true);
    window.addEventListener('pageshow', function () { scan(document); });
    scan(document);
    if (window.MutationObserver) {
        new MutationObserver(function (records) {
            records.forEach(function (record) {
                if (record.type === 'attributes') format(record.target);
                else Array.prototype.forEach.call(record.addedNodes, scan);
            });
        }).observe(document.body, {childList: true, subtree: true, attributes: true, attributeFilter: ['value', 'placeholder']});
    }
}());
