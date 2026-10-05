    </main>
  </div>
</div>

<div class="toast" id="toast" role="status" aria-live="polite"><i class="fa-solid fa-circle-info" aria-hidden="true"></i><span id="toastMsg"></span></div>
<script src="<?php echo $routeUrl('/assets/admin.js', ['v' => substr(hash_file('sha256', __DIR__ . '/../../../assets/admin.js'), 0, 12)]); ?>" defer></script>
</body>
</html>
