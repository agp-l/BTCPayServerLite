    </main>
  </div>
</div>

<div class="toast" id="toast" role="status" aria-live="polite"><i class="fa-solid fa-circle-info" aria-hidden="true"></i><span id="toastMsg"></span></div>
<script>
(() => {
  const body = document.body;
  const openButton = document.querySelector('[data-sidebar-open]');
  const closeTarget = document.querySelector('[data-sidebar-close]');
  const setSidebar = (open) => {
    body.classList.toggle('sidebar-open', open);
    if (openButton) openButton.setAttribute('aria-expanded', open ? 'true' : 'false');
  };
  if (openButton) openButton.addEventListener('click', () => setSidebar(true));
  if (closeTarget) closeTarget.addEventListener('click', () => setSidebar(false));
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') setSidebar(false);
  });
})();
</script>
</body>
</html>
