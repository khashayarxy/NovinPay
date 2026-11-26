(function () {
    var container = document.getElementById('confettiContainer');
    if (!container || (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches)) {
        return;
    }
    var colors = ['#22c55e', '#10b981', '#098cf7', '#f59e0b', '#ec4899', '#8b5cf6'];
    for (var i = 0; i < 80; i++) {
        var piece = document.createElement('span');
        piece.className = 'confetti';
        piece.style.left = (Math.random() * 100) + '%';
        piece.style.background = colors[i % colors.length];
        piece.style.width = (6 + Math.random() * 6) + 'px';
        piece.style.height = (6 + Math.random() * 6) + 'px';
        piece.style.borderRadius = (i % 2 === 0) ? '50%' : '2px';
        piece.style.animationDuration = (2.2 + Math.random() * 2.2) + 's';
        piece.style.animationDelay = (Math.random() * 1.4) + 's';
        container.appendChild(piece);
    }
    setTimeout(function () {
        container.replaceChildren();
    }, 6500);
})();
