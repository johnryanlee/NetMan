<?php if (!empty($show_nav)): ?>
    </main>
</div>
<?php endif; ?>

<script src="/assets/js/app.js"></script>
<?php if (!empty($page_scripts)): ?>
<?php foreach ($page_scripts as $script): ?>
<script src="<?= h($script) ?>"></script>
<?php endforeach; ?>
<?php endif; ?>
<?php if (!empty($inline_script)): ?>
<script><?= $inline_script ?></script>
<?php endif; ?>
</body>
</html>
