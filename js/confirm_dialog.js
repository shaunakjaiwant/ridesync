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
            if (!form || (!form.hasAttribute('data-confirm-message') && !form.hasAttribute('data-confirm-phrase'))) {
                return;
            }

            var message = form.getAttribute('data-confirm-message') || 'Are you sure you want to perform this action?';
            var requiredPhrase = form.getAttribute('data-confirm-phrase') || '';

            if (requiredPhrase !== '') {
                var promptText = message + '\n\nPlease type "' + requiredPhrase + '" to confirm:';
                var userInput = window.prompt(promptText, '');

                if (userInput === null) {
                    event.preventDefault();
                    event.stopPropagation();
                    return;
                }

                if (userInput.trim().toUpperCase() !== requiredPhrase.trim().toUpperCase()) {
                    alert('Action cancelled: Confirmation phrase did not match. Expected "' + requiredPhrase + '".');
                    event.preventDefault();
                    event.stopPropagation();
                    return;
                }

                var confInput = form.querySelector('input[name="confirmation_text"]');
                if (confInput) {
                    confInput.value = requiredPhrase;
                }
            } else {
                if (!window.confirm(message)) {
                    event.preventDefault();
                    event.stopPropagation();
                }
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
