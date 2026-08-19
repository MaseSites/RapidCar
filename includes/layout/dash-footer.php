<?php
if (!defined('RAPIDCAR')) {
    http_response_code(403);
    exit('Direkter Zugriff nicht erlaubt.');
}
?>
        </main>
    </div>
</div>
<script src="<?= asset('js/app.js') ?>"></script>
<?php if (isset($pageScripts)) { echo $pageScripts; } ?>
</body>
</html>
