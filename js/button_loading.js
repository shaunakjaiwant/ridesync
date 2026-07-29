// RideSync Global Form Button Loading & Double-Submit Protection
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
            if (!form || form.dataset.disableLoading === 'true') {
                return;
            }

            // Find the active submit button
            var submitButtons = form.querySelectorAll('button[type="submit"], input[type="submit"]');
            submitButtons.forEach(function (btn) {
                if (btn.disabled) return;

                // Don't disable if form submission was cancelled by confirm dialog
                setTimeout(function () {
                    if (event.defaultPrevented) return;

                    btn.dataset.originalText = btn.innerHTML;
                    btn.disabled = true;

                    if (btn.tagName === 'INPUT') {
                        btn.value = 'Processing...';
                    } else {
                        btn.innerHTML = '<span>Processing...</span>';
                    }
                }, 10);
            });
        }, false);
    });
})();
