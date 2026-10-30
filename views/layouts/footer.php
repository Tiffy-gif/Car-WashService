<?php
declare(strict_types=1);
?>
        </main>
    </div>
</div>

<!-- Core Script (dynamic cache-buster ensures fresh JS is always loaded) -->
<script src="<?= APP_URL ?>/public/js/app.js?v=<?= filemtime(dirname(__DIR__, 2) . '/public/js/app.js') ?>"></script>
</body>
</html>
