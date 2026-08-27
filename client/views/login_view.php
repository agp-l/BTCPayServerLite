<?php
// client/views/login_view.php
declare(strict_types=1);
?>
<!doctype html>
<html lang="cs">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Přihlášení - BTCPay Lite</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    * { box-sizing: border-box; }
    body { 
        font-family: 'Inter', sans-serif; 
        display: flex; justify-content: center; align-items: center; 
        min-height: 100vh; margin: 0; color: #17201a; 
        background-color: #fafcfa; 
        background-image: 
            radial-gradient(circle at 50% 0%, rgba(47, 211, 90, 0.12) 0%, transparent 60%),
            linear-gradient(to right, rgba(229, 234, 231, 0.7) 1px, transparent 1px),
            linear-gradient(to bottom, rgba(229, 234, 231, 0.7) 1px, transparent 1px);
        background-size: 100% 100%, 24px 24px, 24px 24px;
        background-attachment: fixed;
    }
    .login-box { background: #fff; padding: 40px; border-radius: 18px; box-shadow: 0 8px 30px rgba(20,45,28,.06); width: 100%; max-width: 380px; text-align: center; border: 1px solid #e5eae7; }
    .logo-icon { font-size: 32px; color: #2fd35a; margin-bottom: 15px; }
    h1 { margin: 0 0 25px 0; font-size: 24px; font-weight: 800; letter-spacing: -0.5px; }
    
    .error { color: #ef4d4d; background: #fff0f0; padding: 12px; border-radius: 10px; font-size: 13px; margin-bottom: 20px; font-weight: 600; border: 1px solid #fee2e2; }
    
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
        <div class="logo-icon"><i class="fa-solid fa-bolt"></i></div>
        <h1>Přihlášení do systému</h1>
        
        <?php if (!empty($error)): ?>
            <div class="error"><i class="fa-solid fa-triangle-exclamation"></i> <?php echo $error; ?></div>
        <?php endif; ?>
        
        <form method="POST" action="login.php">
            <div class="input-wrap">
                <i class="fa-solid fa-envelope"></i>
                <input type="email" name="email" placeholder="E-mailová adresa" required>
            </div>
            <div class="input-wrap">
                <i class="fa-solid fa-lock"></i>
                <input type="password" name="password" placeholder="Heslo" required>
            </div>
            <button type="submit"><i class="fa-solid fa-right-to-bracket"></i> Vstoupit</button>
        </form>
        
       <a href="../registrace.php" class="footer-link">Nemáte účet? Zaregistrujte se</a>
    </div>
</body>
</html>