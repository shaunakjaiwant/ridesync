// Global RideSync Action Confirmation Listener
(function () {
    function ready(fn) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', fn);
        } else {
            fn();
        }
    }

    ready(function () {
        document.addEventListener('submit', function (event) {
            var form = event.target;
            if (!form || !form.hasAttribute('data-confirm-message')) {
                return;
            }

            var message = form.getAttribute('data-confirm-message') || 'Are you sure you want to perform this action?';
            if (!window.confirm(message)) {
                event.preventDefault();
                event.stopPropagation();
            }
        }, true);

        document.addEventListener('click', function (event) {
            var target = event.target.closest('[data-confirm-message]');
            if (!target || target.tagName === 'FORM') {
                return;
            }

            var message = target.getAttribute('data-confirm-message') || 'Are you sure you want to perform this action?';
            if (!window.confirm(message)) {
                event.preventDefault();
                event.stopPropagation();
            }
        }, true);
    });
})();
