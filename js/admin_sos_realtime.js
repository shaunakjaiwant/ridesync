// RideSync Admin Command Center Real-Time SOS & Emergency Listener
(function () {
    function ready(callback) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', callback);
        } else {
            callback();
        }
    }

    ready(function () {
        initAdminSosRealtimeListener();
    });

    function initAdminSosRealtimeListener() {
        var lastAlertId = 0;
        var audioCtx = null;

        // Initialize Web Audio API context for synthesized emergency chime
        function playEmergencyChime() {
            try {
                var AudioContext = window.AudioContext || window.webkitAudioContext;
                if (!AudioContext) return;
                if (!audioCtx) audioCtx = new AudioContext();

                if (audioCtx.state === 'suspended') {
                    audioCtx.resume();
                }

                var now = audioCtx.currentTime;
                var osc1 = audioCtx.createOscillator();
                var osc2 = audioCtx.createOscillator();
                var gain = audioCtx.createGain();

                osc1.type = 'sawtooth';
                osc2.type = 'sine';

                // Two-tone high emergency pulse (880Hz to 1174Hz)
                osc1.frequency.setValueAtTime(880, now);
                osc1.frequency.setValueAtTime(1174, now + 0.15);
                osc1.frequency.setValueAtTime(880, now + 0.3);
                osc1.frequency.setValueAtTime(1174, now + 0.45);

                osc2.frequency.setValueAtTime(440, now);
                osc2.frequency.setValueAtTime(587, now + 0.15);

                gain.gain.setValueAtTime(0.3, now);
                gain.gain.exponentialRampToValueAtTime(0.001, now + 0.7);

                osc1.connect(gain);
                osc2.connect(gain);
                gain.connect(audioCtx.destination);

                osc1.start(now);
                osc2.start(now);
                osc1.stop(now + 0.75);
                osc2.stop(now + 0.75);
            } catch (e) {
                console.warn('[Admin SOS Realtime] Audio chime play warning:', e);
            }
        }

        // Native Browser Desktop Notifications
        function requestNotificationPermission() {
            if ('Notification' in window && Notification.permission === 'default') {
                Notification.requestPermission();
            }
        }

        function showDesktopNotification(title, body) {
            if ('Notification' in window && Notification.permission === 'granted') {
                try {
                    new Notification(title, {
                        body: body,
                        icon: '/ridesync/logo-mark.png',
                        requireInteraction: true
                    });
                } catch (e) {
                    console.warn('[Admin SOS Realtime] Notification trigger warning:', e);
                }
            }
        }

        // Request notification permissions on user interaction
        document.addEventListener('click', requestNotificationPermission, { once: true });

        function pollSosAlerts() {
            fetch('/ridesync/api/v1/admin_sos_check.php?since_id=' + encodeURIComponent(lastAlertId), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (!data.ok) return;

                if (lastAlertId === 0) {
                    lastAlertId = data.latest_alert_id;
                    return;
                }

                if (data.has_new && data.latest_alert_id > lastAlertId) {
                    lastAlertId = data.latest_alert_id;
                    playEmergencyChime();

                    var latestAlert = data.alerts[0];
                    var title = '🚨 CRITICAL SOS ALERT TRIGGERED!';
                    var body = 'Alert #' + latestAlert.id + ': ' + (latestAlert.triggerer_name || 'Rider') + ' on ride #' + latestAlert.ride_id;
                    showDesktopNotification(title, body);

                    // Refresh SOS panel or page if SOS panel exists
                    var container = document.querySelector('[data-admin-command-center]');
                    if (container) {
                        // Reload overview section to render new SOS emergency card
                        location.reload();
                    }
                }
            })
            .catch(function (err) {
                console.warn('[Admin SOS Realtime] Poll error:', err);
            });
        }

        // Poll every 5 seconds
        setInterval(pollSosAlerts, 5000);
    }
})();
