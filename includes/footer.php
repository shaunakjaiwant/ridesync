<?php
require_once __DIR__ . '/asset_helper.php';
$needsMapAssets = ridesync_page_needs_map_assets();
$needsMapPicker = ridesync_page_needs_map_picker();
?>
</main>

<footer class="site-footer">
    <p>&copy; <?php echo date('Y'); ?> RideSync. Built for students, by students.</p>
</footer>

<?php if ($needsMapAssets): ?>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<?php endif; ?>
<script src="/ridesync/js/script.js"></script>
<?php if ($needsMapPicker): ?>
<script src="/ridesync/js/map_picker.js?v=<?php echo filemtime(__DIR__ . '/../js/map_picker.js'); ?>"></script>
<?php endif; ?>
</body>
</html>
