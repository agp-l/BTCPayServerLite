<?php
// client/views/registrace_view.php
declare(strict_types=1);
?>
<!doctype html>
<html lang="cs">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Registrace - BTCPay Lite</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    * { box-sizing: border-box; }
    
    /* Čisté pozadí bez mřížky */
    body { 
        font-family: 'Inter', sans-serif; 
        display: flex; justify-content: center; align-items: center; 
        min-height: 100vh; margin: 0; color: #17201a; 
        background-color: #fafcfa; 
    }
    
    .login-box { background: #fff; padding: 40px; border-radius: 18px; box-shadow: 0 8px 30px rgba(20,45,28,.06); width: 100%; max-width: 400px; text-align: center; border: 1px solid #e5eae7; }
    .logo-icon { font-size: 32px; color: #2fd35a; margin-bottom: 15px; }
    h1 { margin: 0 0 25px 0; font-size: 24px; font-weight: 800; letter-spacing: -0.5px; }
    
    .error { color: #ef4d4d; background: #fff0f0; padding: 12px; border-radius: 10px; font-size: 13px; margin-bottom: 20px; font-weight: 600; border: 1px solid #fee2e2; text-align: left; }
    .success { color: #13aa3d; background: #eafbef; padding: 12px; border-radius: 10px; font-size: 13px; margin-bottom: 20px; font-weight: 600; border: 1px solid #13aa3d; text-align: left; }
    
    .input-wrap { position: relative; margin-bottom: 16px; }
    .input-wrap i { position: absolute; left: 16px; top: 50%; transform: translateY(-50%); color: #748078; font-size: 14px; }
    input { width: 100%; padding: 14px 14px 14px 44px; border: 1px solid #e5eae7; border-radius: 10px; font-size: 14px; box-sizing: border-box; outline: none; transition: 0.2s; background: #fafcfa; color: #17201a; }
    input:focus { border-color: #2fd35a; background: #fff; box-shadow: inset 0 0 0 1px #2fd35a; }
    
    button { width: 100%; padding: 14px; background: #2fd35a; color: white; border: none; border-radius: 10px; font-weight: 700; font-size: 14px; cursor: pointer; transition: 0.2s; display: flex; justify-content: center; align-items: center; gap: 8px; margin-top: 10px; }
    button:hover { background: #20b948; }
    
    .footer-link { display: inline-block; margin-top: 25px; font-size: 13px; color: #748078; text-decoration: none; font-weight: 600; transition: 0.2s; }
    .footer-link:hover { color: #20b948; text-decoration: underline; }
  </style>
</head>
<body>
    <div class="login-box">
        <div class="logo-icon"><i class="fa-solid fa-user-plus"></i></div>
        <h1>Založit nový účet</h1>
        
        <?php if (!empty($error)): ?>
            <div class="error"><i class="fa-solid fa-triangle-exclamation"></i> <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if (!empty($successMsg)): ?>
            <div class="success"><i class="fa-solid fa-circle-check"></i> <?php echo htmlspecialchars($successMsg); ?></div>
            <a href="login" class="primary" style="text-decoration:none; display:block; margin-top:15px; padding:12px; background:#2fd35a; color:#fff; border-radius:10px; font-weight:700;">Přihlásit se do účtu</a>
        <?php else: ?>
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                <div class="input-wrap">
                    <i class="fa-solid fa-envelope"></i>
                    <input type="email" name="email" placeholder="E-mailová adresa" value="<?php echo htmlspecialchars($email); ?>" required>
                </div>
                <div class="input-wrap">
                    <i class="fa-solid fa-lock"></i>
                    <input type="password" name="password" placeholder="Heslo" required>
                </div>
                <div class="input-wrap">
                    <i class="fa-solid fa-lock"></i>
                    <input type="password" name="password_confirm" placeholder="Potvrzení hesla" required>
                </div>
                <button type="submit"><i class="fa-solid fa-check"></i> Dokončit registraci</button>
            </form>
            
            <a href="login" class="footer-link">Už máte účet? Přihlaste se</a>
        <?php endif; ?>
    </div>
</body>
</html>