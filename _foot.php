    </main>

    <!-- Site Footer -->
    <footer class="site-footer">
        <div class="container footer-inner">
            <div class="footer-col">
                <div class="footer-brand">
                    <span class="brand-badge"><?= strtoupper(substr(setting('store_name', 'sumthin'), 0, 1)) ?></span>
                    <span class="brand-text"><?= encode(setting('store_name', 'sumthin')) ?><span class="brand-dot">.</span></span>
                </div>
                <p class="footer-desc">
                    <?= encode(setting('store_tagline', 'Your premier destination for performance tech gadgets, ultra-fast GaN chargers, mechanical keyboards, ergonomic mice, and audio essentials.')) ?>
                </p>
                <div class="footer-meta">
                    <span>Customer Support: <?= encode(setting('support_email', 'support@sumthin.com')) ?> | <?= encode(setting('support_phone', '03-41450123')) ?></span>
                </div>
            </div>

            <div class="footer-col">
                <h4 class="footer-heading">Categories</h4>
                <ul class="footer-links">
                    <li><a href="<?= base_url('index.php?cat=power-banks') ?>">Power Banks</a></li>
                    <li><a href="<?= base_url('index.php?cat=keyboards') ?>">Mechanical Keyboards</a></li>
                    <li><a href="<?= base_url('index.php?cat=mice') ?>">Precision Mice</a></li>
                    <li><a href="<?= base_url('index.php?cat=audio') ?>">Audio & ANC</a></li>
                    <li><a href="<?= base_url('index.php?cat=chargers-cables') ?>">GaN Chargers & Cables</a></li>
                </ul>
            </div>

            <div class="footer-col">
                <h4 class="footer-heading">Account & Help</h4>
                <ul class="footer-links">
                    <?php if (is_logged_in()): ?>
                        <li><a href="<?= base_url('logout.php') ?>">Sign Out</a></li>
                    <?php else: ?>
                        <li><a href="<?= base_url('login.php') ?>">Sign In</a></li>
                        <li><a href="<?= base_url('register.php') ?>">Create Account</a></li>
                        <li><a href="<?= base_url('forgot_password.php') ?>">Reset Password</a></li>
                    <?php endif; ?>
                </ul>
            </div>

            <div class="footer-col">
                <h4 class="footer-heading">System Tech</h4>
                <p class="footer-tech-stack">
                    Built with PHP 8.2, MySQL PDO prepared statements, vanilla CSS3 flex/grid, and local jQuery 3.7.1.
                </p>
            </div>
        </div>

        <div class="footer-bottom">
            <div class="container footer-bottom-inner">
                <p>&copy; <?= date('Y') ?> <?= encode(setting('store_name', 'sumthin')) ?>. All rights reserved. Developed for university coursework.</p>
                <p class="footer-note">Demo environment on XAMPP (Apache + MySQL).</p>
            </div>
        </div>
    </footer>

    <!-- Local jQuery Library (Downloaded locally as required, NO CDN) -->
    <script src="<?= base_url('js/lib/jquery-3.7.1.min.js') ?>"></script>
    <!-- Custom jQuery Application Script (No inline JS event handlers) -->
    <script src="<?= base_url('js/app.js') ?>"></script>
    <?php if (file_exists(__DIR__ . '/js/admin.js')): ?>
        <!-- Admin jQuery Handlers (No inline JS) -->
        <script src="<?= base_url('js/admin.js') ?>"></script>
    <?php endif; ?>
</body>
</html>
