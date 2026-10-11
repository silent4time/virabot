(function () {
    var t = localStorage.getItem('panel-theme') || 'carunit';
    var bg = {
        navy: '#0F172A', carunit: '#0d0d0d', purple: '#180D2E', emerald: '#0A1F1C',
        sunset: '#1A0D0D', slate: '#080808', light: '#F1F5F9',
        linen: '#FAF7F2', mint: '#F0FDF4', lavender: '#FAF5FF'
    };
    var root = document.documentElement;
    if (t === 'navy') t = 'carunit';
    root.style.backgroundColor = bg[t] || '#0d0d0d';
    root.setAttribute('data-theme', t);
    root.style.colorScheme = (t === 'light' || t === 'linen' || t === 'mint' || t === 'lavender') ? 'light' : 'dark';
    var mtc = document.getElementById('mtc');
    if (mtc && bg[t]) mtc.content = bg[t];
    if (localStorage.getItem('panel-sb-collapsed') === '1' && window.innerWidth > 768)
        root.classList.add('sb-pre-collapsed');
}());
