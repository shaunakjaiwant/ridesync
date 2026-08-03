<?php
require_once __DIR__ . '/../config/db.php';

$requestedRole = trim((string) ($_GET['role'] ?? 'rider'));
if (!in_array($requestedRole, ['rider', 'driver'], true)) {
    $requestedRole = 'rider';
}

$otpState = $_SESSION['otp_reset_state'] ?? null;
$currentStep = (int) ($otpState['step'] ?? 1);
$userEmail = htmlspecialchars($otpState['email'] ?? '');

if (!in_array($currentStep, [1, 2, 3], true)) {
    $currentStep = 1;
}

require_once __DIR__ . '/../includes/public_header.php';
?>

<section class="auth-shell auth-shell-rider">
    <aside class="auth-context-card">
        <span class="auth-kicker">Account recovery</span>
        <h1>Reset your password safely.</h1>
        <p>Follow the 3-step OTP verification process to secure and update your account password.</p>
        <div class="auth-route-strip" aria-hidden="true">
            <span style="font-weight: <?php echo $currentStep === 1 ? '700' : '400'; ?>;">1. Request</span>
            <i></i>
            <span style="font-weight: <?php echo $currentStep === 2 ? '700' : '400'; ?>;">2. Verify</span>
            <i></i>
            <span style="font-weight: <?php echo $currentStep === 3 ? '700' : '400'; ?>;">3. Reset</span>
        </div>
    </aside>

    <div class="form-container auth-panel">
        <div class="auth-role-tabs" style="display: flex; border-bottom: 2px solid #e2e8f0; margin-bottom: 1.5rem; gap: 0.5rem;">
            <a href="/ridesync/pages/forgot_password.php?role=rider" class="auth-role-tab <?php echo $requestedRole === 'rider' ? 'is-active' : ''; ?>" style="padding: 0.6rem 1rem; font-weight: <?php echo $requestedRole === 'rider' ? '600' : '500'; ?>; text-decoration: none; border-bottom: <?php echo $requestedRole === 'rider' ? '3px solid #2563eb' : 'none'; ?>; color: <?php echo $requestedRole === 'rider' ? '#2563eb' : '#64748b'; ?>;">Rider Account</a>
            <a href="/ridesync/pages/forgot_password.php?role=driver" class="auth-role-tab <?php echo $requestedRole === 'driver' ? 'is-active' : ''; ?>" style="padding: 0.6rem 1rem; font-weight: <?php echo $requestedRole === 'driver' ? '600' : '500'; ?>; text-decoration: none; border-bottom: <?php echo $requestedRole === 'driver' ? '3px solid #2563eb' : 'none'; ?>; color: <?php echo $requestedRole === 'driver' ? '#2563eb' : '#64748b'; ?>;">Driver Account</a>
        </div>

        <span class="auth-panel-eyebrow">Step <?php echo $currentStep; ?> of 3</span>
        <h1>
            <?php if ($currentStep === 1): ?>
                Forgot Password
            <?php elseif ($currentStep === 2): ?>
                Verify OTP Code
            <?php else: ?>
                Set New Password
            <?php endif; ?>
        </h1>

        <?php ridesync_flash('forgot_password_error', 'alert-error'); ?>
        <?php ridesync_flash('forgot_password_success', 'alert-success'); ?>

        <?php if ($currentStep === 1): ?>
            <!-- Step 1: Request OTP -->
            <form action="/ridesync/actions/forgot_password_action.php" method="POST" data-button-loading>
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                <input type="hidden" name="account_type" value="<?php echo htmlspecialchars($requestedRole); ?>">

                <div class="form-group">
                    <label for="email">Registered Email Address</label>
                    <input type="email" id="email" name="email" required autocomplete="email" placeholder="you@university.edu" value="<?php echo $userEmail; ?>">
                </div>

                <button type="submit" class="btn btn-primary auth-submit">Send OTP Verification Code</button>
            </form>

        <?php elseif ($currentStep === 2): ?>
            <!-- Step 2: Verify OTP -->
            <p style="margin-bottom: 1rem; color: #475569; font-size: 0.95rem;">
                Enter the 6-digit OTP code sent to <strong><?php echo $userEmail; ?></strong>.
            </p>

            <form action="/ridesync/actions/verify_otp_action.php" method="POST" id="otp-verify-form" data-button-loading style="margin-bottom: 1rem;">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                <input type="hidden" name="account_type" value="<?php echo htmlspecialchars($requestedRole); ?>">
                <input type="hidden" name="email" value="<?php echo $userEmail; ?>">
                <input type="hidden" id="otp" name="otp" value="" required>

                <div class="form-group">
                    <label>6-Digit Verification Code</label>
                    <div class="otp-digit-container" style="display: flex; gap: 0.5rem; justify-content: center; margin: 0.75rem 0 1.25rem 0;">
                        <?php for ($d = 1; $d <= 6; $d++): ?>
                            <input type="text"
                                   class="otp-digit-input"
                                   data-digit-index="<?php echo $d; ?>"
                                   maxlength="1"
                                   pattern="\d"
                                   inputmode="numeric"
                                   autocomplete="one-time-code"
                                   style="width: 2.8rem; height: 3.2rem; font-size: 1.4rem; font-weight: 700; text-align: center; border: 2px solid #cbd5e1; border-radius: 8px; transition: border-color 0.2s, box-shadow 0.2s;"
                                   <?php echo $d === 1 ? 'autofocus' : ''; ?>>
                        <?php endfor; ?>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary auth-submit" id="otp-submit-btn">Verify OTP Code</button>
            </form>

            <form action="/ridesync/actions/forgot_password_action.php" method="POST" style="display: inline;">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                <input type="hidden" name="account_type" value="<?php echo htmlspecialchars($requestedRole); ?>">
                <input type="hidden" name="email" value="<?php echo $userEmail; ?>">
                <button type="submit" id="resend-otp-btn" class="btn btn-secondary btn-sm" style="background: none; border: none; color: #2563eb; text-decoration: underline; padding: 0; cursor: pointer;">
                    Didn't receive code? Resend OTP
                </button>
                <span id="resend-timer-text" style="display: none; font-size: 0.88rem; color: #64748b; margin-left: 0.5rem;"></span>
            </form>

            <script>
                document.addEventListener('DOMContentLoaded', function() {
                    const digitInputs = Array.from(document.querySelectorAll('.otp-digit-input'));
                    const hiddenOtp = document.getElementById('otp');
                    const verifyForm = document.getElementById('otp-verify-form');

                    function updateHiddenOtp() {
                        const code = digitInputs.map(input => input.value.trim()).join('');
                        hiddenOtp.value = code;
                        return code;
                    }

                    digitInputs.forEach((input, index) => {
                        input.addEventListener('input', function(e) {
                            const val = e.target.value.replace(/\D/g, '');
                            e.target.value = val ? val[0] : '';
                            const code = updateHiddenOtp();

                            if (e.target.value && index < digitInputs.length - 1) {
                                digitInputs[index + 1].focus();
                                digitInputs[index + 1].select();
                            }

                            if (code.length === 6) {
                                verifyForm.requestSubmit();
                            }
                        });

                        input.addEventListener('keydown', function(e) {
                            if (e.key === 'Backspace' && !e.target.value && index > 0) {
                                digitInputs[index - 1].focus();
                                digitInputs[index - 1].select();
                            }
                        });

                        input.addEventListener('paste', function(e) {
                            e.preventDefault();
                            const pasteData = (e.clipboardData || window.clipboardData).getData('text').replace(/\D/g, '');
                            if (!pasteData) return;

                            for (let i = 0; i < digitInputs.length; i++) {
                                if (i < pasteData.length) {
                                    digitInputs[i].value = pasteData[i];
                                }
                            }
                            const code = updateHiddenOtp();
                            const focusIndex = Math.min(pasteData.length, digitInputs.length - 1);
                            digitInputs[focusIndex].focus();

                            if (code.length === 6) {
                                verifyForm.requestSubmit();
                            }
                        });

                        input.addEventListener('focus', function() {
                            input.style.borderColor = '#2563eb';
                            input.style.boxShadow = '0 0 0 3px rgba(37, 99, 235, 0.2)';
                        });

                        input.addEventListener('blur', function() {
                            input.style.borderColor = '#cbd5e1';
                            input.style.boxShadow = 'none';
                        });
                    });

                    // 60-second Resend Cooldown Timer
                    const resendBtn = document.getElementById('resend-otp-btn');
                    const timerText = document.getElementById('resend-timer-text');
                    let cooldown = 60;

                    resendBtn.style.pointerEvents = 'none';
                    resendBtn.style.opacity = '0.5';
                    timerText.style.display = 'inline';

                    const timerInterval = setInterval(() => {
                        cooldown--;
                        if (cooldown <= 0) {
                            clearInterval(timerInterval);
                            resendBtn.style.pointerEvents = 'auto';
                            resendBtn.style.opacity = '1';
                            timerText.style.display = 'none';
                        } else {
                            timerText.textContent = `(Resend available in ${cooldown}s)`;
                        }
                    }, 1000);
                });
            </script>

        <?php else: ?>
            <!-- Step 3: Set New Password -->
            <p style="margin-bottom: 1rem; color: #475569; font-size: 0.95rem;">
                Setting new password for <strong><?php echo $userEmail; ?></strong>.
            </p>

            <form action="/ridesync/actions/reset_password_action.php" method="POST" data-button-loading>
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                <input type="hidden" name="account_type" value="<?php echo htmlspecialchars($requestedRole); ?>">
                <input type="hidden" name="email" value="<?php echo $userEmail; ?>">

                <div class="form-group">
                    <label for="new_password">New Password</label>
                    <input type="password" id="new_password" name="new_password" required minlength="8" autocomplete="new-password" placeholder="Min 8 characters">
                </div>

                <div class="form-group">
                    <label for="confirm_password">Confirm New Password</label>
                    <input type="password" id="confirm_password" name="confirm_password" required minlength="8" autocomplete="new-password" placeholder="Re-enter new password">
                </div>

                <button type="submit" class="btn btn-primary auth-submit">Update Password</button>
            </form>
        <?php endif; ?>

        <div class="auth-link-row" style="margin-top: 1.5rem;">
            <p class="auth-switch-text">Remembered password? <a href="<?php echo $requestedRole === 'driver' ? '/ridesync/pages/driver_login.php' : '/ridesync/pages/login.php'; ?>">Return to Login</a></p>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

