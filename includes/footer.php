<?php
declare(strict_types=1);

// Ensure variables from header exist
$userRole = $userRole ?? ($_SESSION['user_role'] ?? 'cashier');
$currentPage = $currentPage ?? basename($_SERVER['SCRIPT_NAME'] ?? '');
$navItems = $navItems ?? [];
?>
</main>

<!-- Mobile Bottom Navigation Bar (Visible only on < md screens) -->
<nav class="mobile-bottom-nav d-md-none fixed-bottom bg-white border-top shadow-lg">
    <div class="d-flex align-items-center justify-content-around h-100 px-1">
        <?php foreach ($navItems as $item): ?>
            <?php if (in_array($userRole, $item['roles'], true)): ?>
                <?php $isActive = ($currentPage === $item['file']); ?>
                <a href="<?= e($item['file']) ?>" class="bottom-nav-item d-flex flex-column align-items-center justify-content-center text-decoration-none <?= $isActive ? 'active text-primary' : 'text-secondary' ?>">
                    <div class="icon-wrap position-relative">
                        <i class="bi <?= e($item['icon']) ?> fs-5"></i>
                    </div>
                    <span class="label small fw-medium mt-1"><?= e($item['label']) ?></span>
                </a>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
</nav>

<!-- Bootstrap 5 Bundle JS CDN (Includes Popper) -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<!-- Global App Core Script -->
<script src="assets/js/app.js"></script>

<!-- Progressive Web App & Offline Sync Script -->
<script src="assets/js/pwa.js"></script>

<?php if (!empty($pageScripts) && is_array($pageScripts)): ?>
    <?php foreach ($pageScripts as $script): ?>
        <script src="<?= e($script) ?>"></script>
    <?php endforeach; ?>
<?php endif; ?>

</body>
</html>
