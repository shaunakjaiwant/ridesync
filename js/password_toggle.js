// RideSync Password Visibility Eye Toggle
(function () {
    function ready(fn) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', fn);
        } else {
            fn();
        }
    }

    ready(function () {
        initPasswordToggles();
    });

    function initPasswordToggles() {
        var passwordInputs = document.querySelectorAll('input[type="password"]');
        passwordInputs.forEach(function (input) {
            if (input.dataset.passwordToggleBound === 'true') {
                return;
            }
            input.dataset.passwordToggleBound = 'true';

            var parent = input.parentElement;
            if (!parent) return;

            // Make parent wrapper relative if needed
            var wrapper = document.createElement('div');
            wrapper.className = 'password-toggle-container';
            wrapper.style.position = 'relative';
            wrapper.style.display = 'block';
            wrapper.style.width = '100%';

            input.parentNode.insertBefore(wrapper, input);
            wrapper.appendChild(input);

            input.style.paddingRight = '2.5rem';

            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'password-toggle-btn';
            btn.setAttribute('aria-label', 'Show password');
            btn.style.position = 'absolute';
            btn.style.right = '0.75rem';
            btn.style.top = '50%';
            btn.style.transform = 'translateY(-50%)';
            btn.style.background = 'none';
            btn.style.border = 'none';
            btn.style.cursor = 'pointer';
            btn.style.padding = '0';
            btn.style.fontSize = '1.1rem';
            btn.style.lineHeight = '1';
            btn.style.color = '#64748b';
            btn.style.zIndex = '2';
            var eyeIcon = '<svg class="ui-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>';
            var eyeOffIcon = '<svg class="ui-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9.88 9.88a3 3 0 1 0 4.24 4.24"/><path d="M10.73 5.08A10.43 10.43 0 0 1 12 5c7 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68"/><path d="M6.61 6.61A13.52 13.52 0 0 0 2 12s3 7 10 7a9.74 9.74 0 0 0 5.39-1.61"/><line x1="2" x2="22" y1="2" y2="22"/></svg>';

            btn.innerHTML = eyeIcon;

            btn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                var isPassword = input.type === 'password';
                input.type = isPassword ? 'text' : 'password';
                btn.innerHTML = isPassword ? eyeOffIcon : eyeIcon;
                btn.setAttribute('aria-label', isPassword ? 'Hide password' : 'Show password');
            });

            wrapper.appendChild(btn);
        });
    }
})();
