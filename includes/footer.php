<?php
require_once __DIR__ . '/asset_helper.php';
$needsMapAssets = ridesync_page_needs_map_assets();
$needsMapPicker = ridesync_page_needs_map_picker();
$scriptNonce = htmlspecialchars(ridesync_csp_nonce(), ENT_QUOTES, 'UTF-8');
?>
</main>

<footer class="site-footer">
    <p>&copy; <?php echo date('Y'); ?> RideSync. Built for students, by students.</p>
</footer>

<?php if ($needsMapAssets): ?>
<script nonce="<?php echo $scriptNonce; ?>" src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<?php endif; ?>
<script nonce="<?php echo $scriptNonce; ?>" src="/ridesync/js/script.js"></script>
<?php if ($needsMapPicker): ?>
<script nonce="<?php echo $scriptNonce; ?>" src="/ridesync/js/map_picker.js?v=<?php echo filemtime(__DIR__ . '/../js/map_picker.js'); ?>"></script>
<?php endif; ?>
</body>
</html>
