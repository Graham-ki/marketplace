</main>

<footer class="footer">
  <div class="footer-inner">
    <span>© <?= date('Y') ?> Market — come sell, come buy.</span>
    <span>Built with PHP · MySQL · vanilla JS</span>
  </div>
</footer>

<?php if (!empty($extraJs)) foreach ((array)$extraJs as $js): ?>
<script src="<?= Security::sanitize($js) ?>" defer></script>
<?php endforeach; ?>
</body>
</html> 