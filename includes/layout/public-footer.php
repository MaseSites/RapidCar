<?php
if (!defined('RAPIDCAR')) {
    http_response_code(403);
    exit('Direkter Zugriff nicht erlaubt.');
}
?>
<footer class="site-footer">
    <div class="inner">
        <div>© <?= date('Y') ?> RapidCar. Alle Rechte vorbehalten.</div>
        <div>
            <a href="<?= base_url('privacy.php') ?>"><?= t('nav.privacy') ?></a>
            <a href="<?= base_url('imprint.php') ?>"><?= t('nav.imprint') ?></a>
            <a href="<?= base_url('contact.php') ?>"><?= t('nav.contact') ?></a>
        </div>
    </div>
</footer>
<script src="<?= asset('js/app.js') ?>"></script>
</body>
</html>
