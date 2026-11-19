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

    <!-- Floating Back to Top Button -->
    <button type="button" id="btn-back-to-top" class="btn-back-to-top" title="Scroll to Top" aria-label="Scroll to top">&#8593;</button>

    <?php 
    $is_chat_standalone = (basename($_SERVER['PHP_SELF']) === 'chat.php');
    if (!str_contains($_SERVER['REQUEST_URI'], '/admin/') && !$is_chat_standalone): 
        $foot_unread = 0;
        $foot_messages = [];
        $foot_last_msg_id = 0;
        $foot_member_id = 0;
        if (is_member()) {
            try {
                $foot_member_id = (int)$_SESSION['user']->id;
                $u_stmt = $db->prepare("SELECT COUNT(*) FROM `chat_message` WHERE `member_id` = ? AND `sender_role` = 'Admin' AND `is_read` = 0");
                $u_stmt->execute([$foot_member_id]);
                $foot_unread = (int)$u_stmt->fetchColumn();

                $m_stmt = $db->prepare("SELECT * FROM `chat_message` WHERE `member_id` = ? ORDER BY `id` ASC");
                $m_stmt->execute([$foot_member_id]);
                $foot_messages = $m_stmt->fetchAll();
                if (!empty($foot_messages)) {
                    $foot_last_msg_id = (int)end($foot_messages)->id;
                }
            } catch (Exception $e) {}
        }
    ?>
        <!-- Shopee-Style Floating Customer Support Chat Launcher (On-Page Popover) -->
        <button type="button" 
                class="floating-chat-pill" 
                id="floating-chat-pill" 
                title="Chat with Customer Service Support" 
                aria-label="Chat with Customer Service Support">
            <span class="chat-pill-icon">💬</span>
            <span class="chat-pill-text">Chat with Us</span>
            <span class="chat-pill-badge" id="float-chat-badge" style="<?= $foot_unread > 0 ? '' : 'display:none;' ?>"><?= $foot_unread ?></span>
        </button>

        <!-- Floating Chat Popup Widget (Opens on the same page, never jumps) -->
        <div id="floating-chat-widget" class="floating-chat-widget" style="display:none;" aria-hidden="true">
            <div class="floating-chat-header">
                <div class="floating-chat-header-info">
                    <span class="floating-chat-avatar">💬</span>
                    <div>
                        <div class="floating-chat-title">sumthin Help Desk</div>
                        <div class="floating-chat-status">
                            <span class="status-dot-pulse"></span>
                            <span>Specialists Online</span>
                        </div>
                    </div>
                </div>
                <div class="floating-chat-header-actions">
                    <a href="<?= base_url('chat.php') ?>" class="floating-chat-btn-icon" title="Open in full page" aria-label="Open in full page">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h6v6"></path><path d="M10 14L21 3"></path><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path></svg>
                    </a>
                    <button type="button" id="floating-chat-close" class="floating-chat-btn-icon" title="Minimize chat" aria-label="Minimize chat">
                        &times;
                    </button>
                </div>
            </div>

            <?php if (is_member()): ?>
                <div class="floating-chat-body">
                    <div class="chat-messages-container" id="chat-messages-box" 
                         data-member-id="<?= $foot_member_id ?>" 
                         data-last-id="<?= $foot_last_msg_id ?>">
                        <?php if (empty($foot_messages)): ?>
                            <div class="chat-empty-notice" style="text-align:center; padding:36px 16px; color:var(--gray); margin:auto;">
                                <div style="font-size:32px; margin-bottom:8px;">👋</div>
                                <div style="font-weight:700; color:var(--dark); font-size:14px; margin-bottom:4px;">How can we help you today?</div>
                                <div style="font-size:12px; line-height:1.4;">Send a message below and our support team will reply in real-time!</div>
                            </div>
                        <?php else: ?>
                            <?php foreach ($foot_messages as $m): ?>
                                <?php $is_me = ($m->sender_role === 'Member'); ?>
                                <div class="chat-bubble-row <?= $is_me ? 'me' : 'them' ?>" data-msg-id="<?= (int)$m->id ?>">
                                    <span class="chat-sender-name"><?= $is_me ? 'You' : 'sumthin Support' ?></span>
                                    <div class="chat-bubble"><?= encode($m->message) ?></div>
                                    <span class="chat-time-tag"><?= date('h:i A', strtotime($m->created_at)) ?></span>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <form id="chat-send-form" class="floating-chat-footer">
                    <div class="floating-chat-input-wrap">
                        <input type="text" id="chat-message-input" name="message" maxlength="500" autocomplete="off"
                               placeholder="Type your message..." class="floating-chat-input">
                        <button type="submit" id="chat-send-btn" class="floating-chat-send-btn" title="Send message" aria-label="Send message">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"></line><polygon points="22 2 15 22 11 13 2 9 22 2"></polygon></svg>
                        </button>
                    </div>
                    <div class="floating-chat-meta">
                        <span>Max 500 chars &bull; 1 msg/sec limit</span>
                        <span id="chat-char-count">0 / 500</span>
                    </div>
                </form>

            <?php elseif (is_admin()): ?>
                <div class="floating-chat-guest">
                    <div class="floating-chat-guest-icon">🛡️</div>
                    <div class="floating-chat-guest-title">Admin Support Desk</div>
                    <div class="floating-chat-guest-desc">
                        You are currently signed in as an Administrator. Open the Support Console to manage member conversations.
                    </div>
                    <a href="<?= base_url('admin/chat.php') ?>" class="btn btn-primary" style="width:100%; justify-content:center; padding:11px; font-weight:700; text-decoration:none;">
                        Open Support Desk ➔
                    </a>
                </div>

            <?php else: ?>
                <div class="floating-chat-guest">
                    <div class="floating-chat-guest-icon">👋</div>
                    <div class="floating-chat-guest-title">Live Customer Support</div>
                    <div class="floating-chat-guest-desc">
                        Chat directly with sumthin specialists regarding products, orders, and warranty assistance.
                    </div>
                    <a href="<?= base_url('login.php?redirect=' . urlencode($_SERVER['REQUEST_URI'] ?? 'index.php')) ?>" 
                       class="btn btn-primary" style="width:100%; justify-content:center; padding:11px; font-weight:700; text-decoration:none;">
                        Sign In to Chat
                    </a>
                    <div style="font-size:12px; color:var(--gray); margin-top:14px;">
                        New customer? <a href="<?= base_url('register.php') ?>" style="color:var(--primary); font-weight:600;">Register here</a>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- Local jQuery Library (Downloaded locally as required, NO CDN) -->
    <script src="<?= base_url('js/lib/jquery-3.7.1.min.js') ?>"></script>
    <?php if (file_exists(__DIR__ . '/js/lib/chart.umd.min.js')): ?>
        <!-- Local Chart.js 4.x UMD Library (No CDN) -->
        <script src="<?= base_url('js/lib/chart.umd.min.js') ?>"></script>
    <?php endif; ?>
    <!-- Custom jQuery Application Script (No inline JS event handlers) -->
    <script src="<?= base_url('js/app.js?v=' . (file_exists(__DIR__ . '/js/app.js') ? filemtime(__DIR__ . '/js/app.js') : '1')) ?>"></script>
    <?php if (file_exists(__DIR__ . '/js/admin.js')): ?>
        <!-- Admin jQuery Handlers (No inline JS) -->
        <script src="<?= base_url('js/admin.js') ?>"></script>
    <?php endif; ?>
    <?php if (file_exists(__DIR__ . '/js/product.js')): ?>
        <!-- Product Photo Slider and Drag & Drop (No inline JS) -->
        <script src="<?= base_url('js/product.js') ?>"></script>
    <?php endif; ?>
    <?php if (file_exists(__DIR__ . '/js/ajax.js')): ?>
        <!-- AJAX Add to Cart, Cart Update, Wishlist, Search Suggestions (No inline JS) -->
        <script src="<?= base_url('js/ajax.js') ?>"></script>
    <?php endif; ?>
    <?php if (file_exists(__DIR__ . '/js/chat.js')): ?>
        <!-- Member <-> Admin Live Chat Polling (No inline JS) -->
        <script src="<?= base_url('js/chat.js?v=' . (file_exists(__DIR__ . '/js/chat.js') ? filemtime(__DIR__ . '/js/chat.js') : '1')) ?>"></script>
    <?php endif; ?>
</body>
</html>
