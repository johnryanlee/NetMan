/**
 * NetMan — Application JavaScript
 */
'use strict';

// ── Auto-dismiss alerts after 5s ───────────────────────────────────────────
document.querySelectorAll('.alert-success').forEach(function(el) {
    setTimeout(function() {
        el.style.transition = 'opacity 0.5s';
        el.style.opacity = '0';
        setTimeout(function() { el.remove(); }, 500);
    }, 5000);
});

// ── Confirm destructive actions ────────────────────────────────────────────
document.querySelectorAll('[data-confirm]').forEach(function(el) {
    el.addEventListener('click', function(e) {
        if (!confirm(this.dataset.confirm)) e.preventDefault();
    });
});

// ── Scan type card radio selection visual ─────────────────────────────────
document.querySelectorAll('.scan-type-card input[type=radio]').forEach(function(radio) {
    radio.addEventListener('change', function() {
        document.querySelectorAll('.scan-type-body').forEach(function(b) {
            b.style.borderColor = '';
            b.style.background  = '';
        });
    });
});

// ── Range option radio → update text input ────────────────────────────────
document.querySelectorAll('.range-option input[type=radio]').forEach(function(radio) {
    radio.addEventListener('change', function() {
        var target = document.getElementById('custom_range');
        if (target) target.value = this.value;
    });
});

// ── Device table row click → navigate to detail ───────────────────────────
document.querySelectorAll('.device-row').forEach(function(row) {
    var link = row.querySelector('a.ip-link');
    if (!link) return;
    row.style.cursor = 'pointer';
    row.addEventListener('click', function(e) {
        if (e.target.tagName === 'A' || e.target.tagName === 'BUTTON') return;
        window.location = link.href;
    });
});

// ── Password match validation on install step 3 ───────────────────────────
var pw1 = document.querySelector('input[name=password]');
var pw2 = document.querySelector('input[name=password2]');
if (pw1 && pw2) {
    pw2.addEventListener('input', function() {
        if (pw1.value && pw2.value && pw1.value !== pw2.value) {
            pw2.setCustomValidity('Passwords do not match');
        } else {
            pw2.setCustomValidity('');
        }
    });
}
