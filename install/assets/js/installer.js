document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-reveal]').forEach(function (button) {
        button.addEventListener('click', function () {
            var input = button.parentElement.querySelector('input');
            if (!input) return;
            var hidden = input.type === 'password';
            input.type = hidden ? 'text' : 'password';
            button.textContent = hidden ? 'Hide' : 'Show';
            button.setAttribute('aria-label', (hidden ? 'Hide ' : 'Show ') + input.name.replaceAll('_', ' '));
        });
    });
    var installForm = document.querySelector('[data-install-form]');
    if (installForm) {
        installForm.addEventListener('submit', function () {
            var button = installForm.querySelector('button[type="submit"]');
            if (button) {
                button.disabled = true;
                button.textContent = 'Installing...';
            }
            var live = installForm.querySelector('[data-install-live]');
            if (live) live.textContent = 'Installing on the server. Keep this page open.';
        });
    }
    var strengthInput = document.querySelector('[data-password-strength]');
    var strength = document.querySelector('[data-strength]');
    if (strengthInput && strength) {
        strengthInput.addEventListener('input', function () {
            var value = strengthInput.value;
            var score = (value.length >= 12 ? 1 : 0) + (/[A-Za-z]/.test(value) ? 1 : 0) + (/\d/.test(value) ? 1 : 0) + (/[^A-Za-z0-9]/.test(value) ? 1 : 0);
            strength.textContent = score >= 4 ? 'Strong password guidance met.' : score >= 3 ? 'Good start; a symbol would make it stronger.' : 'Use 12+ characters with letters and numbers.';
            strength.dataset.score = String(score);
        });
    }
});
