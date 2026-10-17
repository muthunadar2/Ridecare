// Show the free-text task box only when "Something else…" is chosen.
(function () {
    var select = document.getElementById('reminder_id');
    var wrap = document.getElementById('custom-wrap');
    if (!select || !wrap) { return; }
    function sync() { wrap.hidden = select.value !== '0'; }
    select.addEventListener('change', sync);
    sync();
})();
