<?php
/**
 * footer.php — closes the layout and loads jQuery, the shared app.js and
 * the page's own script(s). Expects: $pageScripts (array of file names)
 */
declare(strict_types=1);
?>
        </div>
    </main>
</div>

<!-- Shared modal used by App.confirm() for destructive actions -->
<div class="modal" id="confirm-modal" role="dialog" aria-modal="true" aria-labelledby="confirm-title" hidden>
    <div class="modal-dialog modal-sm">
        <div class="modal-header">
            <h2 id="confirm-title">Are you sure?</h2>
        </div>
        <div class="modal-body"><p id="confirm-message"></p></div>
        <div class="modal-footer">
            <button type="button" class="btn btn-ghost" data-confirm="no">Cancel</button>
            <button type="button" class="btn btn-danger" data-confirm="yes">Confirm</button>
        </div>
    </div>
</div>

<div class="toast-stack" id="toast-stack" aria-live="polite" aria-atomic="false"></div>

<script src="assets/vendor/jquery-3.7.1.min.js"></script>
<script src="assets/js/app.js"></script>
<?php foreach ($pageScripts ?? [] as $script): ?>
<script src="assets/js/<?= e($script) ?>"></script>
<?php endforeach; ?>
</body>
</html>
