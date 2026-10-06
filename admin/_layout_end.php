  </section>
</div>

<script src="../js/app.js" defer></script>
<script>
  // Keep the active sidebar item visible on mobile when the nav scrolls
  (() => {
    const active = document.querySelector('.sidebar-link.active');
    if (active && window.matchMedia('(max-width: 900px)').matches) {
      active.scrollIntoView({ inline: 'center', block: 'nearest', behavior: 'instant' });
    }
  })();
</script>
</body>
</html>