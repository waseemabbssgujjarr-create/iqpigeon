<?php



require_once __DIR__ . '/config.php';



require_once __DIR__ . '/includes/db.php';



require_once __DIR__ . '/includes/helpers.php';



require_once __DIR__ . '/includes/auth.php';



require_once __DIR__ . '/includes/mailer.php';







function verify_email_next_url(int $userId = 0): string

{

    $next = trim((string) ($_GET['next'] ?? $_POST['next'] ?? ''));

    if ($next !== '' && str_starts_with($next, '/') && !str_starts_with($next, '//')) {

        return $next;

    }



    if ($userId > 0) {

        return client_post_auth_url($userId);

    }



    return '/client/connect-whatsapp';

}







$token = trim($_GET['token'] ?? '');



$message = '';



$error = '';



$verified = false;



$nextUrl = verify_email_next_url();



// Confirm via link (no login required)



if ($token !== '') {



    $result = verify_email_by_token($token);



    if ($result['success']) {



        $nextUrl = verify_email_next_url((int) ($result['user_id'] ?? 0));



        redirect($nextUrl);



    }



    $error = $result['message'];



}







$user = get_user();



$pendingUser = null;







if (!$verified && !$error && $user && $user['role'] === 'client') {



    $pendingUser = db_fetch(



        'SELECT id, name, email, email_verified_at FROM users WHERE id = ?',



        'i',



        [(int) $user['id']]



    );



    if ($pendingUser && !empty($pendingUser['email_verified_at'])) {



        redirect(verify_email_next_url((int) $pendingUser['id']));



    }



}







if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf($_POST['csrf_token'] ?? '')) {



    $action = $_POST['action'] ?? '';



    $nextUrl = verify_email_next_url();







    if ($action === 'resend') {



        if (!$user) {



            $error = 'Please sign in first.';



        } else {



            $sent = send_user_verification_email((int) $user['id']);



            if ($sent) {



                $message = 'Verification email sent. Check your inbox (and spam folder).';



            } else {



                $detail = function_exists('mail_last_error') ? mail_last_error() : '';



                $error = $detail !== ''



                    ? 'Could not send email: ' . $detail



                    : 'Could not send email. Check SMTP settings in config.php or Admin → Settings → Send test email.';



            }



        }



    } elseif ($action === 'verify_code') {



        if (!$user) {



            $error = 'Please sign in first.';



        } else {



            $code = trim($_POST['code'] ?? '');



            $result = verify_email_by_code((int) $user['id'], $code);



            if ($result['success']) {



                auth_issue_remember_token((int) $user['id']);



                redirect(verify_email_next_url((int) $user['id']));



            }



            $error = $result['message'];



        }



    }



}



?>



<!DOCTYPE html>



<html lang="en">



<head>



<?= page_head('Verify Email') ?>

<?= auth_assets() ?>



</head>



<body class="auth-page auth-page--v2">



    <div class="auth-page__layers" aria-hidden="true">

        <div class="auth-page__gradient"></div>

        <div class="auth-page__mesh"></div>

    </div>



    <header class="auth-page__header auth-page__header--back">



        <a href="<?= $user ? '/logout.php' : '/login' ?>" class="auth-page__back">



            <span class="material-symbols-outlined">arrow_back</span>



            <span><?= $user ? 'Sign out' : 'Back to login' ?></span>



        </a>



    </header>







    <main class="auth-page__main">



        <div class="auth-card v2-glass auth-card--v2">



            <div class="auth-hero">



                <div class="auth-hero__icon">

                    <span class="material-symbols-outlined">mail</span>

                </div>



                <h1 class="auth-hero__title">Verify your email</h1>



                <?php if ($pendingUser): ?>



                    <p class="auth-hero__subtitle">Enter the 6-digit code sent to <strong><?= sanitize($pendingUser['email']) ?></strong></p>



                <?php else: ?>



                    <p class="auth-hero__subtitle">Sign in to resend your verification email.</p>



                <?php endif; ?>



            </div>







            <?php if ($message): ?>



                <div class="auth-alert auth-alert--success"><?= sanitize($message) ?></div>



            <?php endif; ?>



            <?php if ($error): ?>



                <div class="auth-alert"><?= sanitize($error) ?></div>



            <?php endif; ?>







            <?php if ($pendingUser): ?>



                <form method="POST" class="auth-form auth-form--stacked">



                    <input type="hidden" name="csrf_token" value="<?= sanitize(csrf_token()) ?>"/>



                    <input type="hidden" name="action" value="verify_code"/>



                    <?php if (!empty($_GET['next'])): ?>



                    <input type="hidden" name="next" value="<?= sanitize($_GET['next']) ?>"/>



                    <?php endif; ?>



                    <div class="auth-field">



                        <label class="auth-label" for="verify-code">6-digit code</label>



                        <input type="text" id="verify-code" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required



                               placeholder="123456"



                               class="auth-otp-input"/>



                    </div>



                    <button type="submit" class="auth-submit v2-btn-pill v2-btn-pill--primary">



                        Verify &amp; continue



                    </button>



                </form>







                <form method="POST" class="auth-form__actions">



                    <input type="hidden" name="csrf_token" value="<?= sanitize(csrf_token()) ?>"/>



                    <input type="hidden" name="action" value="resend"/>



                    <button type="submit" class="auth-submit v2-btn-pill v2-btn-pill--ghost">



                        Resend verification email



                    </button>



                </form>



            <?php else: ?>



                <a href="/login" class="auth-submit v2-btn-pill v2-btn-pill--primary">



                    Sign in



                </a>



            <?php endif; ?>



        </div>



    </main>



    <script src="/assets/js/app.js"></script>



</body>



</html>


