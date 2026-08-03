// RideSync Custom Glassmorphic Action Confirmation Dialog
(function () {
    function ready(fn) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', fn);
        } else {
            fn();
        }
    }

    function createGlassModal(title, message, isPhraseRequired, requiredPhrase, onConfirm) {
        var existingOverlay = document.querySelector('.ridesync-confirm-overlay');
        if (existingOverlay) existingOverlay.remove();

        var overlay = document.createElement('div');
        overlay.className = 'ridesync-confirm-overlay';
        overlay.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;z-index:10000;background:rgba(15,23,42,0.85);backdrop-filter:blur(8px);display:flex;align-items:center;justify-content:center;padding:20px;opacity:0;transition:opacity 0.2s ease;';

        var modal = document.createElement('div');
        modal.style.cssText = 'background:rgba(30,41,59,0.95);border:1px solid rgba(255,255,255,0.12);box-shadow:0 20px 40px rgba(0,0,0,0.6);border-radius:16px;max-width:440px;width:100%;padding:24px;color:#f8fafc;font-family:inherit;transform:scale(0.95);transition:transform 0.2s ease;';

        var contentHtml = '<h3 style="font-size:1.15rem;font-weight:700;margin:0 0 8px 0;color:#f8fafc;">' + (title || 'Confirm Action') + '</h3>'
            + '<p style="font-size:0.9rem;color:#94a3b8;line-height:1.5;margin:0 0 20px 0;">' + message + '</p>';

        if (isPhraseRequired) {
            contentHtml += '<div style="margin-bottom:20px;"><label style="display:block;font-size:0.8rem;color:#cbd5e1;margin-bottom:6px;">Type <strong>"' + requiredPhrase + '"</strong> to confirm:</label>'
                + '<input type="text" class="modal-phrase-input" style="width:100%;padding:10px;background:rgba(15,23,42,0.8);border:1px solid rgba(255,255,255,0.15);border-radius:8px;color:#fff;font-size:0.9rem;"></div>';
        }

        contentHtml += '<div style="display:flex;justify-content:flex-end;gap:12px;">'
            + '<button type="button" class="btn-modal-cancel" style="padding:9px 16px;background:rgba(255,255,255,0.08);border:1px solid rgba(255,255,255,0.1);border-radius:8px;color:#cbd5e1;cursor:pointer;font-weight:600;">Cancel</button>'
            + '<button type="button" class="btn-modal-confirm" style="padding:9px 18px;background:#2563eb;border:none;border-radius:8px;color:#fff;cursor:pointer;font-weight:600;box-shadow:0 4px 12px rgba(37,99,235,0.3);">Confirm</button>'
            + '</div>';

        modal.innerHTML = contentHtml;
        overlay.appendChild(modal);
        document.body.appendChild(overlay);

        requestAnimationFrame(function () {
            overlay.style.opacity = '1';
            modal.style.transform = 'scale(1)';
        });

        var cancelBtn = modal.querySelector('.btn-modal-cancel');
        var confirmBtn = modal.querySelector('.btn-modal-confirm');
        var phraseInput = modal.querySelector('.modal-phrase-input');

        function closeModal() {
            overlay.style.opacity = '0';
            modal.style.transform = 'scale(0.95)';
            setTimeout(function () { overlay.remove(); }, 200);
        }

        cancelBtn.addEventListener('click', closeModal);

        confirmBtn.addEventListener('click', function () {
            if (isPhraseRequired && phraseInput) {
                if (phraseInput.value.trim().toUpperCase() !== requiredPhrase.trim().toUpperCase()) {
                    phraseInput.style.borderColor = '#f87171';
                    return;
                }
            }
            closeModal();
            onConfirm();
        });
    }

    ready(function () {
        document.addEventListener('submit', function (event) {
            var form = event.target;
            if (!form || (!form.hasAttribute('data-confirm-message') && !form.hasAttribute('data-confirm-phrase'))) {
                return;
            }

            if (form.dataset.confirmed === 'true') {
                delete form.dataset.confirmed;
                return;
            }

            event.preventDefault();
            event.stopPropagation();

            var message = form.getAttribute('data-confirm-message') || 'Are you sure you want to perform this action?';
            var requiredPhrase = form.getAttribute('data-confirm-phrase') || '';
            var isPhraseRequired = requiredPhrase !== '';

            createGlassModal('Confirmation Required', message, isPhraseRequired, requiredPhrase, function () {
                if (isPhraseRequired) {
                    var confInput = form.querySelector('input[name="confirmation_text"]');
                    if (confInput) confInput.value = requiredPhrase;
                }
                form.dataset.confirmed = 'true';
                form.submit();
            });
        }, true);
    });
})();
