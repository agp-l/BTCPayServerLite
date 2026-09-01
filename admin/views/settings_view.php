<?php

declare(strict_types=1);

$pageTitle = 'Nastavení - BTCPay Lite';
$activeMenu = 'settings';
require __DIR__ . '/layout/header.php';
?>
<section class="page-header">
  <div class="page-header-copy"><p class="page-eyebrow">Řízení instance</p><h1>Nastavení systému</h1><p>Centrální pravidla pro provoz této instalace.</p></div>
</section>
<?php if ($pageError !== null): ?><div class="alert alert-error" role="alert"><?php echo htmlspecialchars($pageError, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
<?php if ($toastMsg !== ''): ?><div class="alert alert-success" role="status"><?php echo htmlspecialchars($toastMsg, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
<section class="card">
  <div class="card-title"><span class="card-title-group"><i class="fa-solid fa-user-plus" aria-hidden="true"></i> Veřejné registrace</span><span class="badge <?php echo $registrationEnabled ? 's-paid' : 's-failed'; ?>"><?php echo $registrationEnabled ? 'Zapnuto' : 'Vypnuto'; ?></span></div>
  <p class="card-subtitle">Po vypnutí zůstávají existující účty a přihlášení funkční, ale nevznikne nový klient, obchod ani peněženka.</p>
  <form method="post" action="<?php echo $routeUrl('/admin/settings'); ?>" class="form-stack">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
    <input type="hidden" name="action" value="set_registration">
    <div class="field"><label for="registrationEnabled">Stav registrací</label><div class="input-wrap"><select id="registrationEnabled" name="registration_enabled"><option value="1" <?php echo $registrationEnabled ? 'selected' : ''; ?>>Povolit nové registrace</option><option value="0" <?php echo !$registrationEnabled ? 'selected' : ''; ?>>Zakázat nové registrace</option></select></div></div>
    <div class="form-actions"><button type="submit" class="primary"><i class="fa-solid fa-floppy-disk" aria-hidden="true"></i> Uložit nastavení</button></div>
  </form>
</section>
<?php require __DIR__ . '/layout/footer.php'; ?>
