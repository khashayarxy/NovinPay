(function () {
    var seal = document.querySelector('.np-enamad');
    var image = seal.querySelector('img');

    function showFallback() {
        var wasFocused = document.activeElement === image.parentNode;
        seal.setAttribute('data-enamad-image-failed', '');
        if (wasFocused) {
            seal.querySelector('.np-enamad__fallback').focus();
        }
    }

    image.addEventListener('error', showFallback);
    if (image.complete && image.naturalWidth === 0) {
        showFallback();
    }
}());
