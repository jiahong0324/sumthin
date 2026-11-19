/**
 * ============================================================================
 * File: js/chat.js
 * Project: sumthin (Tech Gadget Online Store)
 * Module: Real-Time Support Chat Engine (TASK D11)
 * Description: 3-second AJAX polling chat engine:
 *              1. Polls every 3s, pauses when document.visibilityState is hidden
 *              2. Appends messages strictly via jQuery .text() (zero XSS risk)
 *              3. Synchronizes unread badges in navigation and admin sidebar
 *              4. Smooth auto-scrolls to newest message
 *              5. Client-side char counter and 1 msg/sec send handler
 * ============================================================================
 */
$(function() {
    'use strict';

    var $chatBox    = $('#chat-messages-box');
    var $chatForm   = $('#chat-send-form');
    var $chatInput  = $('#chat-message-input');
    var $charCount  = $('#chat-char-count');
    var $sendBtn    = $('#chat-send-btn');

    var memberId    = parseInt($chatBox.data('member-id') || 0, 10);
    var lastId      = parseInt($chatBox.data('last-id') || 0, 10);
    var pollTimer   = null;
    var isSending   = false;

    // ------------------------------------------------------------------------
    // 1. Auto-scroll Helper
    // ------------------------------------------------------------------------
    function scrollToBottom(smooth) {
        if (!$chatBox.length) return;
        if (smooth) {
            $chatBox.stop().animate({ scrollTop: $chatBox[0].scrollHeight }, 200);
        } else {
            $chatBox.scrollTop($chatBox[0].scrollHeight);
        }
    }

    // Scroll to bottom on initial view
    if ($chatBox.length) {
        scrollToBottom(false);
    }

    // ------------------------------------------------------------------------
    // 2. Safe Message DOM Renderer (Strictly .text() for XSS Prevention)
    // ------------------------------------------------------------------------
    function appendChatMessage(msg) {
        if ($('[data-msg-id="' + msg.id + '"]').length > 0) {
            return; // Already in DOM
        }

        var $row = $('<div class="chat-bubble-row"></div>')
            .addClass(msg.is_me ? 'me' : 'them')
            .attr('data-msg-id', msg.id);

        var senderLabel = 'You';
        if (!msg.is_me) {
            senderLabel = (msg.sender_role === 'Admin') ? 'sumthin Support' : 'Customer';
        }

        var $sender = $('<span class="chat-sender-name"></span>').text(senderLabel);

        // SECURITY: Strictly use .text() to prevent XSS injection
        var $bubble = $('<div class="chat-bubble"></div>').text(msg.message);

        var $time = $('<span class="chat-time-tag"></span>').text(msg.time_formatted || '');

        $row.append($sender).append($bubble).append($time);

        $('.chat-empty-notice').remove();
        $chatBox.append($row);
    }

    // ------------------------------------------------------------------------
    // 3. Update Navbar & Sidebar Unread Badges
    // ------------------------------------------------------------------------
    function updateUnreadBadges(unreadCount, conversations) {
        var $adminBadge  = $('#admin-chat-badge');
        var $memberBadge = $('#member-chat-badge');

        if (unreadCount > 0) {
            $adminBadge.text(unreadCount).show();
            $memberBadge.text(unreadCount).show();
        } else {
            $adminBadge.hide();
            $memberBadge.hide();
        }

        // Update admin conversation list unread badges if provided
        if (conversations && conversations.length > 0) {
            $.each(conversations, function(i, conv) {
                var $tab = $('#conv-tab-' + conv.member_id);
                if ($tab.length) {
                    var $pill = $tab.find('.conv-unread-pill');
                    var $lastMsg = $tab.find('.chat-conv-last-msg');
                    var $time = $tab.find('.chat-conv-time');

                    if ($lastMsg.length) $lastMsg.text(conv.last_message);
                    if ($time.length) $time.text(conv.time);

                    if (conv.unread_count > 0) {
                        $pill.text(conv.unread_count).show();
                    } else {
                        $pill.hide();
                    }
                }
            });
        }
    }

    // ------------------------------------------------------------------------
    // 4. Polling Function (Every 3 seconds)
    // ------------------------------------------------------------------------
    function pollMessages() {
        // Paused if tab is not active or hidden
        if (document.visibilityState === 'hidden') {
            return;
        }

        $.ajax({
            url: (window.location.origin || '') + '/sumthin/ajax/chat_poll.php',
            type: 'GET',
            dataType: 'json',
            data: {
                after_id: lastId,
                member_id: memberId
            },
            success: function(res) {
                if (res.ok) {
                    updateUnreadBadges(res.unread_count, res.conversations);

                    if (res.messages && res.messages.length > 0) {
                        var hasNew = false;
                        $.each(res.messages, function(i, msg) {
                            if (msg.id > lastId) {
                                appendChatMessage(msg);
                                lastId = Math.max(lastId, msg.id);
                                hasNew = true;
                            }
                        });

                        if (hasNew) {
                            $chatBox.data('last-id', lastId);
                            scrollToBottom(true);
                        }
                    }
                }
            }
        });
    }

    // Start 3-second polling interval
    pollTimer = setInterval(pollMessages, 3000);

    // ------------------------------------------------------------------------
    // 5. Visibility Change Listener (Pause when hidden, resume when visible)
    // ------------------------------------------------------------------------
    document.addEventListener('visibilitychange', function() {
        if (document.visibilityState === 'visible') {
            // Immediate check upon returning to tab
            pollMessages();
            if (!pollTimer) {
                pollTimer = setInterval(pollMessages, 3000);
            }
        } else {
            clearInterval(pollTimer);
            pollTimer = null;
        }
    });

    // ------------------------------------------------------------------------
    // 6. Character Counter
    // ------------------------------------------------------------------------
    $chatInput.on('input', function() {
        var len = $(this).val().length;
        if ($charCount.length) {
            $charCount.text(len + ' / 500');
            if (len > 500) {
                $charCount.css('color', 'var(--error)');
            } else {
                $charCount.css('color', 'var(--gray)');
            }
        }
    });

    // ------------------------------------------------------------------------
    // 7. Send Message Form Submit
    // ------------------------------------------------------------------------
    $chatForm.on('submit', function(e) {
        e.preventDefault();

        var text = $.trim($chatInput.val());
        if (text === '' || isSending) return;

        isSending = true;
        $sendBtn.prop('disabled', true).css('opacity', 0.6);

        $.ajax({
            url: (window.location.origin || '') + '/sumthin/ajax/chat_send.php',
            type: 'POST',
            dataType: 'json',
            data: {
                member_id: memberId,
                message: text
            },
            success: function(res) {
                if (res.ok && res.data) {
                    appendChatMessage(res.data);
                    lastId = Math.max(lastId, res.data.id);
                    $chatBox.data('last-id', lastId);
                    $chatInput.val('');
                    if ($charCount.length) $charCount.text('0 / 500');
                    scrollToBottom(true);
                } else {
                    alert(res.message || 'Failed to send message.');
                }
            },
            error: function(xhr) {
                var msg = 'Failed to send message.';
                if (xhr.responseJSON && xhr.responseJSON.message) {
                    msg = xhr.responseJSON.message;
                } else if (xhr.status === 429) {
                    msg = 'Please slow down (Max 1 message per second).';
                }
                alert(msg);
            },
            complete: function() {
                // Short cooldown to match 1 msg/sec
                setTimeout(function() {
                    isSending = false;
                    $sendBtn.prop('disabled', false).css('opacity', 1);
                    $chatInput.focus();
                }, 800);
            }
        });
    });
});
